<?php
/**
 * =====================================================================
 *  TranscriptResult – bir sağlayıcının döndürdüğü transcript
 * ---------------------------------------------------------------------
 *  $saglayici transkript_metinler.saglayici sütununa yazılır:
 *      youtube_elle · youtube_otomatik · gemini · harici · asr
 *
 *  $aiKullanimi: sağlayıcı ücretli bir AI çağrısı yaptıysa (Gemini)
 *  her çağrının modeli, token'ı ve maliyeti; iş bunları
 *  ai_kullanim_kaydi tablosuna yazar (7. adım).
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Transcript;

final class TranscriptResult
{
    /**
     * @param list<Segment>                                                                  $segmentler
     * @param list<array{saglayici:string,model:string,giris_token:int,cikis_token:int,maliyet_usd:float,sure_ms:int}> $aiKullanimi
     */
    public function __construct(
        public readonly array $segmentler,
        public readonly string $dil,
        public readonly string $saglayici,
        public readonly bool $otomatikMi,
        public readonly array $aiKullanimi = [],
    ) {
    }

    public function karakterSayisi(): int
    {
        return array_sum(array_map(static fn (Segment $s): int => mb_strlen($s->metin), $this->segmentler));
    }
}
