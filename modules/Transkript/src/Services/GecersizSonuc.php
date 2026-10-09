<?php
/**
 * =====================================================================
 *  GecersizSonuc – AI yanıtı şemaya uymuyor
 * ---------------------------------------------------------------------
 *  ResultValidator fırlatır; Pipeline sorunları onarım isteğine koyar
 *  ("şu alanlar eksik / hatalı, yalnız geçerli JSON döndür"). Onarımdan
 *  sonra da geçersizse parça kalıcı hatayla düşer.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Services;

use RuntimeException;

final class GecersizSonuc extends RuntimeException
{
    /** @param list<string> $sorunlar */
    public function __construct(public readonly array $sorunlar)
    {
        parent::__construct(implode(' ', $sorunlar));
    }
}
