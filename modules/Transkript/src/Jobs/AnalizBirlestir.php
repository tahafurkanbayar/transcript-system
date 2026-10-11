<?php
/**
 * =====================================================================
 *  AnalizBirlestir – reduce, global ve kişisel özet (80 → 95)
 * ---------------------------------------------------------------------
 *  Tamamlanan parçaların JSON'ları (transcript değil) tek çağrıda
 *  birleştirilir; zaman damgaları bütün transcript'in gerçek
 *  segmentlerine çekilir. Düşen parçaların aralığı isteme "eksik"
 *  olarak yazılır (model o aralık için bir şey uydurmasın). Hiçbir
 *  parça tamamlanamadıysa analiz kalıcı hatayla biter. Kişisel filtre
 *  MVP sonrası (4b. aşama).
 *
 *  KADEMELİ (2026-10-11, cowork testi: 1:17'lik video %80'de takıldı,
 *  girdi Groq'a sığmadı): girdi o an açık sağlayıcıların sınırını
 *  aşıyorsa bölümler gruplar hâlinde ara özete çevrilir, sonra ara
 *  özetler birleştirilir. Ara özetler transkript_analizler.ara_sonuclar'da.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Jobs;

use App\Core\Cache\Cache;
use App\Core\Log\Logger;
use App\Core\Queue\Queue;
use Modules\Transkript\Ai\AiHatasi;
use Modules\Transkript\Ayarlar;
use Modules\Transkript\Services\ModelOzeti;
use Modules\Transkript\Services\Pipeline;
use Modules\Transkript\Zaman;

final class AnalizBirlestir extends TranskriptIsi
{
    protected const ASAMA = 'birlestirme';

    /** Kademeli birleştirmenin ilerlemesi (Cache; KaynakDurumu gösterir). */
    public const KADEMELI_ONEKI = 'transkript.kademeli.';

    public function tries(): int
    {
        return 2;
    }

    protected function calis(array $analiz): void
    {
        $sonuclar = [];
        $eksik    = [];
        $bitMs    = 0;
        $modeller = [];

        foreach ($this->analizler()->parcalar($this->analizId) as $parca) {
            $bitMs = max($bitMs, (int) $parca['bitis_ms']);
            $json  = $parca['durum'] === 'tamamlandi' ? json_decode((string) $parca['sonuc_json'], true) : null;

            if (is_array($json)) {
                $sonuclar[] = $json;
                $modeller[] = ['saglayici' => $parca['saglayici'] ?? null, 'model' => $parca['model'] ?? null];
            } else {
                $eksik[] = Zaman::yaz((int) $parca['baslangic_ms']) . '–' . Zaman::yaz((int) $parca['bitis_ms']);
            }
        }

        if ($sonuclar === []) {
            throw new AiHatasi('Hiçbir parça analiz edilemedi.', 'istek_hatasi');
        }

        /* Girdi o an açık sağlayıcılara sığmıyorsa kademeli: bölümler gruplar
         * hâlinde ara özete, sonra son rapora (Pipeline::birlestirKademeli).
         * Ara özetler veritabanında; iş ertelenip dönerse baştan üretilmez. */
        $sonuc = $this->pipeline()->birlestirKademeli(
            $sonuclar,
            $bitMs,
            $this->segmentler()->baslangiclar((int) $analiz['metin_id']),
            $this->baglam($analiz) + ['eksik' => $eksik, 'kelime' => $this->kelimeSayisi((int) $analiz['metin_id'])],
            static fn (): ?int => Pipeline::kapasite(),
            $this->analizler()->araSonuclar($this->analizId),
            fn (array $ara) => $this->analizler()->araSonucYaz($this->analizId, $ara),
            function (int $seviye, int $biten, int $toplam): void {
                Cache::put(self::KADEMELI_ONEKI . $this->analizId, ['seviye' => $seviye, 'biten' => $biten, 'toplam' => $toplam], 86400);
                $this->analizler()->ilerlemeYaz($this->analizId, 80 + min(14, $seviye * 3 + intdiv(4 * $biten, max(1, $toplam))));
            },
        );

        if ($sonuc['uyarilar'] !== [] || $sonuc['kademeli']) {
            Logger::info($sonuc['kademeli'] ? 'Birleştirme kademeli yapıldı' : 'Birleştirme sonucu düzeltildi', [
                'analiz' => $this->analizId, 'model' => $sonuc['model'], 'uyarilar' => $sonuc['uyarilar'],
            ], 'transkript');
        }

        $this->birlikte(function () use ($sonuc, $modeller): void {
            $this->analizler()->ilerle($this->analizId, 'analiz_ediliyor', 'rapor', 95, [
                'sonuc_json'    => $sonuc['sonuc'],
                'model'         => $sonuc['model'],
                // Raporun bütün modelleri: bölümler, ara özetler, birleştirme.
                'modeller'      => ModelOzeti::olustur($modeller, $sonuc['ara_modeller'], $sonuc['saglayici'], $sonuc['model']),
                'prompt_surumu' => (string) Ayarlar::get('ai.istem_surumu', 'v1'),
                'ara_sonuclar'  => null,
            ]);
            Queue::push(new RaporuTamamla($this->analizId));
        });

        Cache::forget(self::KADEMELI_ONEKI . $this->analizId);
    }

    /** Transcript'in toplam kelime sayısı (istemdeki uzunluk kuralı için). */
    private function kelimeSayisi(int $metinId): int
    {
        $toplam = 0;

        foreach ($this->segmentler()->segmentler($metinId) as $segment) {
            $toplam += Pipeline::kelimeSayisi($segment->metin);
        }

        return $toplam;
    }
}
