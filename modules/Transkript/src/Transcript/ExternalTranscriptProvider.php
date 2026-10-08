<?php
/**
 * =====================================================================
 *  ExternalTranscriptProvider – ücretli transcript API'si (5c. aşama)
 * ---------------------------------------------------------------------
 *  Örn. Supadata: IP engeli derdi yoktur, video başı ücretlidir.
 *  VARSAYILAN KAPALI: config.php → transcript.saglayicilar listesinde
 *  "harici" yoksa zincire hiç girmez. Bu sürümde yalnız iskelet;
 *  listeye eklenirse ProviderUnavailable döner ve zincir sürer.
 *  Anahtar: .env → TRANSCRIPT_API_KEY.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Transcript;

use Modules\Transkript\Kaynak\Kaynak;

final class ExternalTranscriptProvider implements TranscriptProviderInterface
{
    public function name(): string
    {
        return 'harici';
    }

    public function supports(Kaynak $kaynak): bool
    {
        return $kaynak->youtubeMu();
    }

    public function fetch(Kaynak $kaynak, array $preferredLangs): TranscriptResult
    {
        throw new ProviderUnavailable('saglayici_kapali', 'Harici transcript API\'si 5c. aşamada açılacak.');
    }
}
