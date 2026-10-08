<?php
/**
 * =====================================================================
 *  KaynakRepository – transkript_kaynaklar tablosunun TEK kapısı
 * ---------------------------------------------------------------------
 *  SQL yalnızca burada yazılır ve her sorgu hazırlıklıdır.
 *
 *  BUL YA DA OLUŞTUR, YARIŞSIZ: aynı URL'yi iki kişi aynı anda girerse
 *  "önce SELECT, yoksa INSERT" iki satır açmaya çalışır, ikincisi
 *  UNIQUE'e çarpar. Bunun yerine tek bir
 *      INSERT … ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)
 *  çalışır: satır varsa onun kimliği döner, yoksa yenisi açılır.
 *
 *  CACHE: YouTube kaynağı herkes için ortaktır (sahip_id = 0). Güncel ve
 *  tamamlanmış bir analizi varsa yeni AI çağrısı yapılmaz.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Repositories;

use InvalidArgumentException;
use Modules\Transkript\Kaynak\UrlParser;
use Modules\Transkript\Kaynak\VideoBilgisi;
use PDO;
use RuntimeException;

final class KaynakRepository
{
    public const DURUMLAR = ['normal', 'gizli', 'silinmis'];

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * YouTube kaynağını bulur ya da açar (sahip_id = 0, herkese ortak).
     *
     * @return array<string,mixed>
     */
    public function youtubeBulVeyaOlustur(string $videoId, ?int $olusturanId): array
    {
        if (!UrlParser::gecerliKimlik($videoId)) {
            throw new InvalidArgumentException('Geçersiz video kimliği.');
        }

        $statement = $this->db->prepare(
            "INSERT INTO transkript_kaynaklar (tur, dis_kimlik, sahip_id, olusturan_id)
             VALUES ('youtube', :kimlik, 0, :olusturan)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)"
        );
        $statement->execute([':kimlik' => $videoId, ':olusturan' => $olusturanId]);

        $id = (int) $this->db->lastInsertId();

        return $this->bul($id) ?? throw new RuntimeException('Kaynak açılamadı: ' . $videoId);
    }

    /** @return array<string,mixed>|null */
    public function bul(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM transkript_kaynaklar WHERE id = :id');
        $statement->execute([':id' => $id]);

        $satir = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($satir) ? $satir : null;
    }

    /** @return array<string,mixed>|null */
    public function youtubeBul(string $videoId): ?array
    {
        $statement = $this->db->prepare(
            "SELECT * FROM transkript_kaynaklar
              WHERE tur = 'youtube' AND dis_kimlik = :kimlik AND sahip_id = 0"
        );
        $statement->execute([':kimlik' => $videoId]);

        $satir = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($satir) ? $satir : null;
    }

    /** Video kartını yazar; kaynak yeniden görünür olduysa durumu normale döner. */
    public function bilgiyiYaz(int $id, VideoBilgisi $bilgi): void
    {
        $statement = $this->db->prepare(
            "UPDATE transkript_kaynaklar
                SET baslik = :baslik, kanal_adi = :kanal_adi, kanal_id = :kanal_id,
                    yayin_tarihi = :yayin_tarihi, sure_sn = :sure_sn, kucuk_resim = :kucuk_resim,
                    dil = :dil, durum = 'normal'
              WHERE id = :id"
        );
        $statement->execute([
            ':baslik'       => mb_substr($bilgi->baslik, 0, 255),
            ':kanal_adi'    => $bilgi->kanalAdi !== null ? mb_substr($bilgi->kanalAdi, 0, 255) : null,
            ':kanal_id'     => $bilgi->kanalId !== null && preg_match('/^[\x20-\x7E]{1,64}$/', $bilgi->kanalId) ? $bilgi->kanalId : null,
            ':yayin_tarihi' => $bilgi->yayinTarihi,
            ':sure_sn'      => $bilgi->sureSn,
            ':kucuk_resim'  => $bilgi->kucukResim !== null ? mb_substr($bilgi->kucukResim, 0, 500) : null,
            ':dil'          => $bilgi->dil,
            ':id'           => $id,
        ]);
    }

    /** gizli / silinmiş video (KaynakHatasi) kaydı. */
    public function durumYaz(int $id, string $durum): void
    {
        if (!in_array($durum, self::DURUMLAR, true)) {
            throw new InvalidArgumentException('Geçersiz kaynak durumu: ' . $durum);
        }

        $statement = $this->db->prepare('UPDATE transkript_kaynaklar SET durum = :durum WHERE id = :id');
        $statement->execute([':durum' => $durum, ':id' => $id]);
    }

    /**
     * Cache: güncel ve tamamlanmış analiz. Varsa yeni AI çağrısı yapılmaz.
     *
     * @return array<string,mixed>|null
     */
    public function guncelTamamlanmisAnaliz(int $kaynakId): ?array
    {
        $statement = $this->db->prepare(
            "SELECT * FROM transkript_analizler
              WHERE kaynak_id = :kaynak AND guncel_mi = 1 AND durum = 'tamamlandi'
              ORDER BY id DESC LIMIT 1"
        );
        $statement->execute([':kaynak' => $kaynakId]);

        $satir = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($satir) ? $satir : null;
    }

    /**
     * Sürmekte olan analiz: "Analiz Et"e iki kez basılınca ikinci iş açılmasın.
     *
     * @return array<string,mixed>|null
     */
    public function suregelenAnaliz(int $kaynakId): ?array
    {
        $statement = $this->db->prepare(
            "SELECT * FROM transkript_analizler
              WHERE kaynak_id = :kaynak AND durum NOT IN ('tamamlandi', 'hata')
              ORDER BY id DESC LIMIT 1"
        );
        $statement->execute([':kaynak' => $kaynakId]);

        $satir = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($satir) ? $satir : null;
    }
}
