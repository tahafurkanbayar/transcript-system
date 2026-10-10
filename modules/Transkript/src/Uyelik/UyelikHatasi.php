<?php
/**
 * =====================================================================
 *  UyelikHatasi – üyelik işlemi reddedildi (Türkçe mesaj + HTTP kodu)
 * ---------------------------------------------------------------------
 *  Mesaj kullanıcıya gösterilir; teknik ayrıntı taşımaz.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Uyelik;

use RuntimeException;

final class UyelikHatasi extends RuntimeException
{
    public function __construct(string $mesaj, public readonly int $durum = 422)
    {
        parent::__construct($mesaj);
    }
}
