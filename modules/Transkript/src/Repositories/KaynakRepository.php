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
use Modules\Transkript\Services\Search;
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

    /**
     * Satırı işlem sonuna kadar kilitler (SELECT … FOR UPDATE). Aynı
     * kaynağa aynı anda gelen iki "Analiz Et" sırayla geçer; ikincisi
     * birincinin açtığı analizi görür. Yalnız açık işlem içinde anlamlı.
     */
    public function kilitle(int $id): void
    {
        $statement = $this->db->prepare('SELECT id FROM transkript_kaynaklar WHERE id = :id FOR UPDATE');
        $statement->execute([':id' => $id]);
        $statement->fetchAll();
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

    /**
     * Süre meta veriden gelmediyse (oEmbed süre vermez) transcript'in
     * son segmentinden yazılır. Meta veri süresi varsa ona dokunulmaz.
     */
    public function sureYoksaYaz(int $id, int $sureSn): void
    {
        if ($sureSn <= 0) {
            return;
        }

        $this->db->prepare('UPDATE transkript_kaynaklar SET sure_sn = :sure WHERE id = :id AND (sure_sn IS NULL OR sure_sn = 0)')
            ->execute([':sure' => $sureSn, ':id' => $id]);
    }

    /* =================================================================
     *  LİSTE (panel/transkript ve api/v1/transkript/kaynaklar)
     * ============================================================== */

    /** Liste durum filtresi → son analizin koşulu. */
    public const LISTE_DURUMLARI = ['hazir', 'suruyor', 'hata'];

    /**
     * Kaynaklar, son analizleriyle birlikte; en son analiz edilen başta.
     *
     * GÖRÜNÜRLÜK (TranskriptPolicy::canViewSource ile aynı kural):
     * YouTube kaynağı (sahip_id = 0) herkese; yüklenen dosya yalnız
     * sahibine; $hepsi (transkript.admin) her şeye.
     *
     * ARAMA başlık ve kanal adında, utf8mb4_unicode_ci ile: turkish_ci'de
     * "ai" "AI"yı bulmaz (README, "Arama neden katlanmış sütunda?").
     *
     * @param  array{tur?:string,durum?:string,q?:string} $filtre
     * @return array{satirlar: list<array<string,mixed>>, toplam: int}
     */
    public function liste(array $filtre, int $sayfa, int $boyut, ?int $gorenId, bool $hepsi): array
    {
        $kosullar     = [];
        $parametreler = [];

        if (!$hepsi) {
            $kosullar[] = '(k.sahip_id = 0 OR k.sahip_id = :ben)';
            $parametreler[':ben'] = (int) $gorenId;
        }

        $tur = (string) ($filtre['tur'] ?? '');

        if (in_array($tur, ['youtube', 'ses_dosyasi', 'video_dosyasi'], true)) {
            $kosullar[] = 'k.tur = :tur';
            $parametreler[':tur'] = $tur;
        }

        $kosullar[] = match ((string) ($filtre['durum'] ?? '')) {
            'hazir'   => 'EXISTS (SELECT 1 FROM transkript_analizler r WHERE r.kaynak_id = k.id AND r.guncel_mi = 1 AND r.durum = \'tamamlandi\')',
            'suruyor' => 'a.durum NOT IN (\'tamamlandi\', \'hata\')',
            'hata'    => 'a.durum = \'hata\'',
            default   => '1 = 1',
        };

        $q = trim((string) ($filtre['q'] ?? ''));

        if ($q !== '') {
            $kalip = '%' . Search::escapeLike(mb_substr($q, 0, 100)) . '%';
            $kosullar[] = '(k.baslik COLLATE utf8mb4_unicode_ci LIKE :q1 OR k.kanal_adi COLLATE utf8mb4_unicode_ci LIKE :q2 OR k.dis_kimlik = :q3)';
            $parametreler += [':q1' => $kalip, ':q2' => $kalip, ':q3' => $q];
        }

        $kaynak = 'FROM transkript_kaynaklar k
                   JOIN transkript_analizler a ON a.id = (SELECT MAX(a2.id) FROM transkript_analizler a2 WHERE a2.kaynak_id = k.id)
                  WHERE ' . implode(' AND ', $kosullar);

        $say = $this->db->prepare('SELECT COUNT(*) ' . $kaynak);
        $say->execute($parametreler);
        $toplam = (int) $say->fetchColumn();

        $sorgu = $this->db->prepare(
            "SELECT k.id, k.tur, k.dis_kimlik, k.baslik, k.kanal_adi, k.sure_sn, k.kucuk_resim, k.yayin_tarihi,
                    k.durum AS kaynak_durumu,
                    a.id AS analiz_id, a.durum, a.asama, a.ilerleme, a.hata_kodu, a.created_at,
                    (SELECT r.id FROM transkript_analizler r
                      WHERE r.kaynak_id = k.id AND r.guncel_mi = 1 AND r.durum = 'tamamlandi'
                      ORDER BY r.id DESC LIMIT 1) AS rapor_analiz_id
             " . $kaynak . '
             ORDER BY a.id DESC
             LIMIT :limit OFFSET :offset'
        );

        foreach ($parametreler as $ad => $deger) {
            $sorgu->bindValue($ad, $deger, is_int($deger) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }

        $boyut = max(1, min(100, $boyut));
        $sorgu->bindValue(':limit', $boyut, PDO::PARAM_INT);
        $sorgu->bindValue(':offset', max(0, ($sayfa - 1) * $boyut), PDO::PARAM_INT);
        $sorgu->execute();

        return ['satirlar' => $sorgu->fetchAll(PDO::FETCH_ASSOC), 'toplam' => $toplam];
    }

    /**
     * Özet kutucukları: bugün açılan analiz, toplam token, tahmini maliyet
     * ve analiz edilen kaynak sayısı. $kullaniciId verilirse yalnız o
     * kullanıcının başlattığı analizler (yönetici değilse).
     *
     * @return array{bugun:int, token:int, maliyet:float, kaynak:int}
     */
    public function istatistik(?int $kullaniciId): array
    {
        $kendi = $kullaniciId !== null ? ' AND a.kullanici_id = :ben' : '';
        $p     = $kullaniciId !== null ? [':ben' => $kullaniciId] : [];

        $analiz = $this->db->prepare(
            'SELECT COALESCE(SUM(a.created_at >= CURDATE()), 0) AS bugun, COUNT(DISTINCT a.kaynak_id) AS kaynak
               FROM transkript_analizler a WHERE 1 = 1' . $kendi
        );
        $analiz->execute($p);
        $a = $analiz->fetch(PDO::FETCH_ASSOC) ?: [];

        $kullanim = $this->db->prepare(
            "SELECT COALESCE(SUM(u.giris_token + u.cikis_token), 0) AS token, COALESCE(SUM(u.maliyet_usd), 0) AS maliyet
               FROM ai_kullanim_kaydi u
               JOIN transkript_analizler a ON a.id = u.ref_id
              WHERE u.modul = 'transkript' AND u.ref_tur = 'analiz'" . $kendi
        );
        $kullanim->execute($p);
        $u = $kullanim->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'bugun'   => (int) ($a['bugun'] ?? 0),
            'token'   => (int) ($u['token'] ?? 0),
            'maliyet' => round((float) ($u['maliyet'] ?? 0), 4),
            'kaynak'  => (int) ($a['kaynak'] ?? 0),
        ];
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
