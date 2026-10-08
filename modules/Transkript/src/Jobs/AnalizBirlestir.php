<?php
/**
 * =====================================================================
 *  AnalizBirlestir – reduce, global ve kişisel özet (80 → 95)
 * ---------------------------------------------------------------------
 *  Tamamlanan parçaların JSON'ları (transcript değil) tek çağrıda
 *  birleştirilir. Hiçbir parça tamamlanamadıysa analiz kalıcı hatayla
 *  biter. Kişisel filtre MVP sonrası (4b. aşama).
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Jobs;

use App\Core\Queue\Queue;
use Modules\Transkript\Ai\AiHatasi;
use Modules\Transkript\Services\Pipeline;

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

        foreach ($this->analizler()->parcalar($this->analizId) as $parca) {
            $json = $parca['durum'] === 'tamamlandi' ? json_decode((string) $parca['sonuc_json'], true) : null;

            if (is_array($json)) {
                $sonuclar[] = $json;
            }
        }

        if ($sonuclar === []) {
            throw new AiHatasi('Hiçbir parça analiz edilemedi.', 'istek_hatasi');
        }

        $sonuc = (new Pipeline())->birlestir($sonuclar);

        $this->aiKullanim()->hepsiniKaydet($sonuc['kullanim'], 'analiz', $this->analizId);

        $this->birlikte(function () use ($sonuc): void {
            $this->analizler()->ilerle($this->analizId, 'analiz_ediliyor', 'rapor', 95, [
                'sonuc_json' => $sonuc['sonuc'],
                'model'      => $sonuc['model'],
            ]);
            Queue::push(new RaporuTamamla($this->analizId));
        });
    }
}
