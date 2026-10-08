<?php

declare(strict_types=1);

namespace Modules\Transkript\Transcript;

use Throwable;

/** Bu sağlayıcıda transcript yok (altyazı kapalı, istenen dilde iz yok, boş çıktı). Kalıcı. */
final class TranscriptNotFound extends TranscriptHatasi
{
    public function __construct(string $teknikAyrinti = '', string $kod = 'transkript_bulunamadi', ?Throwable $previous = null)
    {
        parent::__construct($kod, $teknikAyrinti, false, $previous);
    }
}
