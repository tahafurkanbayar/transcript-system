<?php
/**
 * =====================================================================
 *  VideoBilgisi – bir YouTube videosunun kartı (değer nesnesi)
 * ---------------------------------------------------------------------
 *  Data API'den gelince alanların hepsi dolar; oEmbed yedeğinde süre,
 *  yayın tarihi, kanal kimliği ve dil bilinmez (null). Gizlilik
 *  "bilinmiyor" kalır: oEmbed yalnızca herkese açık / liste dışı
 *  videoları döndürür.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Kaynak;

final class VideoBilgisi
{
    public function __construct(
        public readonly string $videoId,
        public readonly string $baslik,
        public readonly ?string $kanalAdi,
        public readonly ?string $kanalId,
        /** Uygulamanın saat diliminde "Y-m-d H:i:s" */
        public readonly ?string $yayinTarihi,
        public readonly ?int $sureSn,
        public readonly ?string $kucukResim,
        /** Videonun dili ("tr", "en-US"); transcript dil seçiminde kullanılır. */
        public readonly ?string $dil,
        /** public · unlisted · bilinmiyor */
        public readonly string $gizlilik,
        /** Yayında ya da planlanmış canlı yayın: transcript henüz yok. */
        public readonly bool $canliYayin,
        /** data_api · oembed */
        public readonly string $kaynak,
    ) {
    }
}
