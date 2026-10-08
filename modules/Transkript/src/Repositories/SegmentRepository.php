<?php
/**
 * =====================================================================
 *  SegmentRepository – transkript_metinler + transkript_segmentler
 * ---------------------------------------------------------------------
 *  kaydet(): bir transcript sürümünü ve segmentlerini TEK İŞLEMDE
 *  (transaction) yazar; yarım transcript kalmaz. Segmentler 500'erli
 *  çok satırlı INSERT ile gider: 3 saatlik video binlerce satırdır,
 *  tek tek INSERT veritabanını gereksiz yorar.
 *
 *  arama_metni = Search::trFold(metin): CHECK kısıtı boş bırakılmasına
 *  izin vermez (bkz. migration 230012).
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Repositories;

use Modules\Transkript\Services\Search;
use Modules\Transkript\Transcript\Segment;
use Modules\Transkript\Transcript\TranscriptResult;
use PDO;
use Throwable;

final class SegmentRepository
{
    private const TOPLU = 500;

    public function __construct(private readonly PDO $db)
    {
    }

    /** Transcript sürümünü ve segmentlerini yazar; metin_id döner. */
    public function kaydet(int $kaynakId, TranscriptResult $sonuc): int
    {
        $segmentler = $sonuc->segmentler;

        $this->db->beginTransaction();

        try {
            $metin = $this->db->prepare(
                'INSERT INTO transkript_metinler (kaynak_id, saglayici, dil, otomatik_mi, segment_sayisi, karakter_sayisi)
                 VALUES (:kaynak, :saglayici, :dil, :otomatik, :segment, :karakter)'
            );
            $metin->execute([
                ':kaynak'    => $kaynakId,
                ':saglayici' => $sonuc->saglayici,
                ':dil'       => mb_substr($sonuc->dil, 0, 10),
                ':otomatik'  => $sonuc->otomatikMi ? 1 : 0,
                ':segment'   => count($segmentler),
                ':karakter'  => $sonuc->karakterSayisi(),
            ]);

            $metinId = (int) $this->db->lastInsertId();

            foreach (array_chunk($segmentler, self::TOPLU) as $dilimNo => $dilim) {
                $this->topluYaz($metinId, $dilim, $dilimNo * self::TOPLU);
            }

            $this->db->commit();

            return $metinId;
        } catch (Throwable $e) {
            $this->db->rollBack();

            throw $e;
        }
    }

    /** @param list<Segment> $dilim */
    private function topluYaz(int $metinId, array $dilim, int $baslangicSirasi): void
    {
        $yerler = $degerler = [];

        foreach ($dilim as $i => $s) {
            $yerler[] = '(?, ?, ?, ?, ?, ?, ?)';
            array_push(
                $degerler,
                $metinId,
                $baslangicSirasi + $i + 1,
                $s->baslangicMs,
                max($s->bitisMs, $s->baslangicMs),
                $s->konusmaci !== null ? mb_substr($s->konusmaci, 0, 50) : null,
                $s->metin,
                Search::trFold($s->metin),
            );
        }

        $this->db->prepare(
            'INSERT INTO transkript_segmentler (metin_id, sira, baslangic_ms, bitis_ms, konusmaci, metin, arama_metni)
             VALUES ' . implode(', ', $yerler)
        )->execute($degerler);
    }

    /** @return array<string,mixed>|null */
    public function metin(int $metinId): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM transkript_metinler WHERE id = :id');
        $statement->execute([':id' => $metinId]);

        $satir = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($satir) ? $satir : null;
    }

    /**
     * Segmentler zaman sırasıyla; $basMs / $bitMs verilirse o aralıkta
     * (API: ?bas=&bit= saniye filtresi).
     *
     * @return list<Segment>
     */
    public function segmentler(int $metinId, ?int $basMs = null, ?int $bitMs = null): array
    {
        $sql = 'SELECT baslangic_ms, bitis_ms, metin, konusmaci FROM transkript_segmentler WHERE metin_id = :metin';
        $parametreler = [':metin' => $metinId];

        if ($basMs !== null) {
            $sql .= ' AND bitis_ms >= :bas';
            $parametreler[':bas'] = $basMs;
        }

        if ($bitMs !== null) {
            $sql .= ' AND baslangic_ms <= :bit';
            $parametreler[':bit'] = $bitMs;
        }

        $statement = $this->db->prepare($sql . ' ORDER BY baslangic_ms, sira');
        $statement->execute($parametreler);

        return array_map(
            static fn (array $satir): Segment => Segment::satirdan($satir),
            $statement->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /** Kaynağın en son transcript sürümü. @return array<string,mixed>|null */
    public function sonMetin(int $kaynakId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM transkript_metinler WHERE kaynak_id = :kaynak ORDER BY id DESC LIMIT 1'
        );
        $statement->execute([':kaynak' => $kaynakId]);

        $satir = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($satir) ? $satir : null;
    }
}
