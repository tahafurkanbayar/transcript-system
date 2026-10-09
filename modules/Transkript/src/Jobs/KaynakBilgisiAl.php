<?php
/**
 * =====================================================================
 *  KaynakBilgisiAl – video kartı ve gizli / silinmiş kontrolü (0 → 5)
 * ---------------------------------------------------------------------
 *  YouTube'da başlık, kanal, süre, görsel (YoutubeMetadata). Gizli,
 *  silinmiş, bulunamayan video ve canlı yayın kalıcı hatadır; meta veri
 *  servisinin geçici hatası yeniden denenir. Dosya kaynağında (5b)
 *  biçim / boyut / süre kontrolü burada yapılacak.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Jobs;

use App\Core\Queue\Queue;
use Modules\Transkript\Kaynak\KaynakHatasi;
use Modules\Transkript\Kaynak\YoutubeMetadata;
use Modules\Transkript\Services\AnalizBaslatici;
use Modules\Transkript\Transcript\TranscriptNotFound;

final class KaynakBilgisiAl extends TranskriptIsi
{
    protected const ASAMA = 'kaynak';

    protected function calis(array $analiz): void
    {
        $kaynak = $this->kaynaklar()->bul((int) $analiz['kaynak_id'])
            ?? throw new KaynakHatasi('video_bulunamadi', 'Kaynak satırı yok: ' . $analiz['kaynak_id']);

        if ($kaynak['tur'] === 'youtube') {
            $bilgi = YoutubeMetadata::fromEnv()->getir((string) $kaynak['dis_kimlik']);

            if ($bilgi->canliYayin) {
                throw new TranscriptNotFound('Canlı ya da planlanmış yayın: ' . $kaynak['dis_kimlik'], 'canli_yayin');
            }

            $this->kaynaklar()->bilgiyiYaz((int) $kaynak['id'], $bilgi);

            // Süre ancak şimdi öğrenildi (oEmbed vermez, Data API verir).
            if (AnalizBaslatici::cokUzun($bilgi->sureSn)) {
                throw new KaynakHatasi('video_cok_uzun', sprintf('Video %d sn; sınır analiz.en_uzun_dk', (int) $bilgi->sureSn));
            }
        }

        $this->birlikte(function (): void {
            $this->analizler()->ilerle($this->analizId, 'transkript_aliniyor', 'transkript', 5);
            Queue::push(new TranskriptAl($this->analizId));
        });
    }
}
