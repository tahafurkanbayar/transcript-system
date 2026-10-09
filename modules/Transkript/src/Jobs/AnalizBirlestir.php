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
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Jobs;

use App\Core\Log\Logger;
use App\Core\Queue\Queue;
use Modules\Transkript\Ai\AiHatasi;
use Modules\Transkript\Ayarlar;
use Modules\Transkript\Zaman;

final class AnalizBirlestir extends TranskriptIsi
{
    protected const ASAMA = 'birlestirme';

    public function tries(): int
    {
        return 2;
    }

    protected function calis(array $analiz): void
    {
        $sonuclar = [];
        $eksik    = [];
        $bitMs    = 0;

        foreach ($this->analizler()->parcalar($this->analizId) as $parca) {
            $bitMs = max($bitMs, (int) $parca['bitis_ms']);
            $json  = $parca['durum'] === 'tamamlandi' ? json_decode((string) $parca['sonuc_json'], true) : null;

            if (is_array($json)) {
                $sonuclar[] = $json;
            } else {
                $eksik[] = Zaman::yaz((int) $parca['baslangic_ms']) . '–' . Zaman::yaz((int) $parca['bitis_ms']);
            }
        }

        if ($sonuclar === []) {
            throw new AiHatasi('Hiçbir parça analiz edilemedi.', 'istek_hatasi');
        }

        $sonuc = $this->pipeline()->birlestir(
            $sonuclar,
            $bitMs,
            $this->segmentler()->baslangiclar((int) $analiz['metin_id']),
            $this->baglam($analiz) + ['eksik' => $eksik],
        );

        if ($sonuc['uyarilar'] !== []) {
            Logger::info('Birleştirme sonucu düzeltildi', [
                'analiz' => $this->analizId, 'model' => $sonuc['model'], 'uyarilar' => $sonuc['uyarilar'],
            ], 'transkript');
        }

        $this->birlikte(function () use ($sonuc): void {
            $this->analizler()->ilerle($this->analizId, 'analiz_ediliyor', 'rapor', 95, [
                'sonuc_json'    => $sonuc['sonuc'],
                'model'         => $sonuc['model'],
                'prompt_surumu' => (string) Ayarlar::get('ai.istem_surumu', 'v1'),
            ]);
            Queue::push(new RaporuTamamla($this->analizId));
        });
    }
}
