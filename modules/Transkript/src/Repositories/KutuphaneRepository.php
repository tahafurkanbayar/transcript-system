<?php
/**
 * =====================================================================
 *  KutuphaneRepository – transkript_kutuphane tablosunun TEK kapısı
 * ---------------------------------------------------------------------
 *  Kişisel kütüphane: kullanıcının analiz ettiği ya da önbellekten
 *  eklediği YouTube kaynakları. Kaynak ve analiz ortak önbellektir;
 *  bu tablo yalnız "kim görür?" sorusunu cevaplar (TranskriptPolicy,
 *  KaynakRepository::gorunurluk).
 *
 *  ekleme_turu "baslatti" "onbellek"in üstündedir: önbellekten eklenen
 *  videoyu sonra kendisi yeniden analiz eden kullanıcının satırı
 *  "baslatti" olur; tersi olmaz.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Repositories;

use InvalidArgumentException;
use PDO;

final class KutuphaneRepository
{
    public const BASLATTI = 'baslatti';
    public const ONBELLEK = 'onbellek';

    public function __construct(private readonly PDO $db)
    {
    }

    /** Kaynağı kullanıcının kütüphanesine ekler; zaten varsa yalnız türü yükselir. */
    public function ekle(int $kullaniciId, int $kaynakId, string $tur): void
    {
        if (!in_array($tur, [self::BASLATTI, self::ONBELLEK], true)) {
            throw new InvalidArgumentException('Geçersiz ekleme türü: ' . $tur);
        }

        if ($kullaniciId < 1 || $kaynakId < 1) {
            return;
        }

        $this->db->prepare(
            "INSERT INTO transkript_kutuphane (kullanici_id, kaynak_id, ekleme_turu)
             VALUES (:kullanici, :kaynak, :tur)
             ON DUPLICATE KEY UPDATE ekleme_turu = IF(:tur2 = 'baslatti', 'baslatti', ekleme_turu)"
        )->execute([':kullanici' => $kullaniciId, ':kaynak' => $kaynakId, ':tur' => $tur, ':tur2' => $tur]);
    }

    /** "Kütüphanemden çıkar": yalnız bu kullanıcının satırı silinir. Silindiyse true. */
    public function cikar(int $kullaniciId, int $kaynakId): bool
    {
        $sorgu = $this->db->prepare('DELETE FROM transkript_kutuphane WHERE kullanici_id = :kullanici AND kaynak_id = :kaynak');
        $sorgu->execute([':kullanici' => $kullaniciId, ':kaynak' => $kaynakId]);

        return $sorgu->rowCount() > 0;
    }

    public function iceriyor(int $kullaniciId, int $kaynakId): bool
    {
        if ($kullaniciId < 1 || $kaynakId < 1) {
            return false;
        }

        $sorgu = $this->db->prepare('SELECT 1 FROM transkript_kutuphane WHERE kullanici_id = :kullanici AND kaynak_id = :kaynak LIMIT 1');
        $sorgu->execute([':kullanici' => $kullaniciId, ':kaynak' => $kaynakId]);

        return $sorgu->fetchColumn() !== false;
    }

    /** Kaynak kaç kullanıcının kütüphanesinde ($haric hariç); "Tamamen sil" uyarısı için. */
    public function sahipSayisi(int $kaynakId, ?int $haric = null): int
    {
        $sorgu = $this->db->prepare('SELECT COUNT(*) FROM transkript_kutuphane WHERE kaynak_id = :kaynak AND kullanici_id <> :haric');
        $sorgu->execute([':kaynak' => $kaynakId, ':haric' => (int) $haric]);

        return (int) $sorgu->fetchColumn();
    }

    /** Kullanıcının bugün kütüphanesine eklediği kaynak sayısı (analiz ya da önbellek). */
    public function bugunEklenen(int $kullaniciId): int
    {
        $sorgu = $this->db->prepare('SELECT COUNT(*) FROM transkript_kutuphane WHERE kullanici_id = :kullanici AND created_at >= CURDATE()');
        $sorgu->execute([':kullanici' => $kullaniciId]);

        return (int) $sorgu->fetchColumn();
    }
}
