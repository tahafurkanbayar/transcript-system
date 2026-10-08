<?php
/**
 * =====================================================================
 *  AiKullanimRepository – ai_kullanim_kaydi tablosu
 * ---------------------------------------------------------------------
 *  Her AI / ASR / ücretli transcript çağrısı bir satırdır: model,
 *  token, maliyet, süre, sonuç. Analizin toplam token ve maliyeti
 *  buradan hesaplanır (ref_tur = 'analiz', ref_id = analiz_id).
 *  Tablonun adı geneldir; modul sütunu satırın sahibini söyler.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Repositories;

use PDO;

final class AiKullanimRepository
{
    public const MODUL = 'transkript';

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @param array{saglayici:string,model:string,giris_token:int,cikis_token:int,maliyet_usd:float,sure_ms:int} $kayit
     */
    public function kaydet(array $kayit, string $refTur, ?int $refId, string $durum = 'basarili'): void
    {
        $this->db->prepare(
            'INSERT INTO ai_kullanim_kaydi
                (modul, ref_tur, ref_id, saglayici, model, giris_token, cikis_token, maliyet_usd, sure_ms, durum)
             VALUES (:modul, :ref_tur, :ref_id, :saglayici, :model, :giris, :cikis, :maliyet, :sure, :durum)'
        )->execute([
            ':modul'     => self::MODUL,
            ':ref_tur'   => $refTur,
            ':ref_id'    => $refId,
            ':saglayici' => mb_substr((string) $kayit['saglayici'], 0, 50),
            ':model'     => mb_substr((string) $kayit['model'], 0, 100),
            ':giris'     => max(0, (int) $kayit['giris_token']),
            ':cikis'     => max(0, (int) $kayit['cikis_token']),
            ':maliyet'   => max(0.0, (float) $kayit['maliyet_usd']),
            ':sure'      => max(0, (int) $kayit['sure_ms']),
            ':durum'     => $durum,
        ]);
    }

    /** @param list<array{saglayici:string,model:string,giris_token:int,cikis_token:int,maliyet_usd:float,sure_ms:int}> $kayitlar */
    public function hepsiniKaydet(array $kayitlar, string $refTur, ?int $refId): void
    {
        foreach ($kayitlar as $kayit) {
            $this->kaydet($kayit, $refTur, $refId);
        }
    }

    /** @return array{token:int,maliyet:float} Analizin toplamı */
    public function analizToplami(int $analizId): array
    {
        $statement = $this->db->prepare(
            "SELECT COALESCE(SUM(giris_token + cikis_token), 0) AS token, COALESCE(SUM(maliyet_usd), 0) AS maliyet
               FROM ai_kullanim_kaydi WHERE modul = :modul AND ref_tur = 'analiz' AND ref_id = :analiz"
        );
        $statement->execute([':modul' => self::MODUL, ':analiz' => $analizId]);

        $satir = $statement->fetch(PDO::FETCH_ASSOC) ?: [];

        return ['token' => (int) ($satir['token'] ?? 0), 'maliyet' => round((float) ($satir['maliyet'] ?? 0), 8)];
    }
}
