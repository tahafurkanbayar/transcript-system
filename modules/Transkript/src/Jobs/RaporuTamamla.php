<?php
/**
 * =====================================================================
 *  RaporuTamamla – raporu tablolara yazar (95 → 100)
 * ---------------------------------------------------------------------
 *  Birleştirme sonucundan analiz maddeleri (konu, nokta, teknik, iddia,
 *  fikir, zaman) üretilir; özetler, toplam token ve maliyet yazılır,
 *  analiz güncel yapılır ve aynı kaynağın eski analizleri güncellikten
 *  düşer. Hepsi tek işlemde: yarım rapor yazılmaz; iş iki kez çalışırsa
 *  maddeler silinip yeniden yazılır.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Jobs;

use App\Core\Log\Logger;
use Modules\Transkript\Ai\AiHatasi;
use Modules\Transkript\Services\Pipeline;

final class RaporuTamamla extends TranskriptIsi
{
    protected const ASAMA = 'rapor';

    protected function calis(array $analiz): void
    {
        $rapor = json_decode((string) ($analiz['sonuc_json'] ?? ''), true);

        if (!is_array($rapor)) {
            throw new AiHatasi('Birleştirme sonucu okunamadı.', 'istek_hatasi');
        }

        $toplam = $this->aiKullanim()->analizToplami($this->analizId);
        $ogeler = Pipeline::ogeler($rapor);

        $this->birlikte(function () use ($analiz, $rapor, $ogeler, $toplam): void {
            $this->analizler()->raporuYaz(
                $this->analizId,
                (int) $analiz['kaynak_id'],
                $rapor,
                trim((string) ($rapor['summary'] ?? '')),
                trim((string) ($rapor['detailed_summary'] ?? '')),
                $ogeler,
                $toplam,
            );
        });

        $sayi = $this->analizler()->parcaSayilari($this->analizId);

        Logger::info('Analiz tamamlandı', [
            'analiz'       => $this->analizId,
            'kaynak'       => (int) $analiz['kaynak_id'],
            'madde'        => count($ogeler),
            'parca'        => $sayi['toplam'],
            'hatali_parca' => $sayi['hatali'],
            // "token" adı Logger'da maskelenir (gizli anahtar sanılır).
            'toplam_token' => $toplam['token'],
            'maliyet_usd'  => $toplam['maliyet'],
        ], 'transkript');
    }
}
