<?php

declare(strict_types=1);

namespace Modules\Transkript\Transcript;

use Throwable;

/** Hız sınırı (HTTP 429, reCAPTCHA sayfası). Geçicidir; iş bekleyip yeniden dener. */
final class RateLimited extends TranscriptHatasi
{
    public function __construct(string $teknikAyrinti = '', ?Throwable $previous = null)
    {
        parent::__construct('rate_limit', $teknikAyrinti, true, $previous);
    }
}
