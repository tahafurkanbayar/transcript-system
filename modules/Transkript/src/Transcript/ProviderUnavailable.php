<?php

declare(strict_types=1);

namespace Modules\Transkript\Transcript;

use Throwable;

/**
 * Sağlayıcı şu an kullanılamıyor: kapalı ya da anahtarsız (kalıcı), IP
 * engeli / PoToken / ağ ya da 5xx hatası (geçici olabilir).
 */
final class ProviderUnavailable extends TranscriptHatasi
{
    public function __construct(string $kod, string $teknikAyrinti = '', bool $gecici = false, ?Throwable $previous = null)
    {
        parent::__construct($kod, $teknikAyrinti, $gecici, $previous);
    }
}
