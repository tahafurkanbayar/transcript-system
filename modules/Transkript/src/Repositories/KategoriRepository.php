<?php
/**
 * =====================================================================
 *  KategoriRepository – transkript_kategoriler tablosunun TEK kapısı
 * ---------------------------------------------------------------------
 *  Video (kaynak) başına bir kategori satırı (Kategori\Kategoriler).
 *  "elle" (yöneticinin düzelttiği) satırın üzerine AI yazmaz: yeniden
 *  analizde KategoriBelirle yalnız "ai" satırını yeniler.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Repositories;

use InvalidArgumentException;
use PDO;

final class KategoriRepository
{
    public const AI   = 'ai';
    public const ELLE = 'elle';

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array<string,mixed>|null etiketler çözülmüş (list<string>) */
    public function bul(int $kaynakId): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM transkript_kategoriler WHERE kaynak_id = :kaynak');
        $statement->execute([':kaynak' => $kaynakId]);
        $satir = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($satir) ? self::coz($satir) : null;
    }

    /**
     * Yazar (yoksa ekler). AI, "elle" satırın üzerine yazmaz; yazıldıysa true.
     *
     * @param array{icerik_turu: string, konu_alani: string, ikincil_alan: ?string, seviye: string, ana_konu: string, etiketler: list<string>} $k
     *        Kategoriler::dogrula() çıktısı
     */
    public function yaz(int $kaynakId, ?int $analizId, array $k, string $belirleyen, ?string $istemSurumu = null, ?string $model = null): bool
    {
        if (!in_array($belirleyen, [self::AI, self::ELLE], true)) {
            throw new InvalidArgumentException('Geçersiz belirleyen: ' . $belirleyen);
        }

        $deger = [
            ':kaynak'  => $kaynakId,
            ':analiz'  => $analizId,
            ':tur'     => $k['icerik_turu'],
            ':alan'    => $k['konu_alani'],
            ':ikinci'  => $k['ikincil_alan'],
            ':seviye'  => $k['seviye'],
            ':konu'    => mb_substr($k['ana_konu'], 0, 200),
            ':etiket'  => (string) json_encode(array_values($k['etiketler']), JSON_UNESCAPED_UNICODE),
            ':kim'     => $belirleyen,
            ':istem'   => $istemSurumu,
            ':model'   => $model !== null ? mb_substr($model, 0, 120) : null,
        ];

        $ekle = $this->db->prepare(
            'INSERT IGNORE INTO transkript_kategoriler
                (kaynak_id, analiz_id, icerik_turu, konu_alani, ikincil_alan, seviye, ana_konu, etiketler, belirleyen, istem_surumu, model)
             VALUES (:kaynak, :analiz, :tur, :alan, :ikinci, :seviye, :konu, :etiket, :kim, :istem, :model)'
        );
        $ekle->execute($deger);

        if ($ekle->rowCount() > 0) {
            return true;
        }

        // Var olan satır: AI yalnız AI satırını yeniler; yönetici her zaman.
        $guncelle = $this->db->prepare(
            'UPDATE transkript_kategoriler
                SET analiz_id = :analiz, icerik_turu = :tur, konu_alani = :alan, ikincil_alan = :ikinci, seviye = :seviye,
                    ana_konu = :konu, etiketler = :etiket, belirleyen = :kim, istem_surumu = :istem, model = :model
              WHERE kaynak_id = :kaynak' . ($belirleyen === self::AI ? " AND belirleyen = 'ai'" : '')
        );
        $guncelle->execute($deger);

        return $guncelle->rowCount() > 0 || ($this->bul($kaynakId)['belirleyen'] ?? null) === $belirleyen;
    }

    /**
     * Kategorisi olmayan (ya da $yenile ise AI'ın belirlediği) ve raporu
     * hazır kaynakların güncel analizleri (geri doldurma).
     *
     * @return list<array{kaynak_id: int, analiz_id: int, baslik: string}>
     */
    public function eksikler(bool $yenile = false, int $enCok = 1000): array
    {
        $statement = $this->db->prepare(
            "SELECT a.kaynak_id, MAX(a.id) AS analiz_id, k.baslik
               FROM transkript_analizler a
               JOIN transkript_kaynaklar k ON k.id = a.kaynak_id
          LEFT JOIN transkript_kategoriler kt ON kt.kaynak_id = a.kaynak_id
              WHERE a.guncel_mi = 1 AND a.durum = 'tamamlandi'
                AND " . ($yenile ? "(kt.id IS NULL OR kt.belirleyen = 'ai')" : 'kt.id IS NULL') . '
           GROUP BY a.kaynak_id, k.baslik
           ORDER BY a.kaynak_id
              LIMIT :sinir'
        );
        $statement->bindValue(':sinir', max(1, $enCok), PDO::PARAM_INT);
        $statement->execute();

        return array_map(static fn (array $s): array => [
            'kaynak_id' => (int) $s['kaynak_id'],
            'analiz_id' => (int) $s['analiz_id'],
            'baslik'    => (string) $s['baslik'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string,int> içerik türü → video sayısı (yönetici özeti) */
    public function turSayilari(): array
    {
        return array_map('intval', $this->db->query(
            'SELECT icerik_turu, COUNT(*) FROM transkript_kategoriler GROUP BY icerik_turu ORDER BY COUNT(*) DESC'
        )->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    /**
     * @param  array<string,mixed> $satir
     * @return array<string,mixed>
     */
    public static function coz(array $satir): array
    {
        $etiketler = json_decode((string) ($satir['etiketler'] ?? '[]'), true);
        $satir['etiketler'] = is_array($etiketler) ? array_values(array_filter($etiketler, 'is_string')) : [];

        return $satir;
    }
}
