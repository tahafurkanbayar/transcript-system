<?php
/**
 * =====================================================================
 *  TranscriptHatasi – sağlayıcı hatalarının ortak tabanı
 * ---------------------------------------------------------------------
 *  Rapordaki üç hata türü (docs/TEKNIK_RAPOR.md, 5. bölüm):
 *
 *      TranscriptNotFound   bu sağlayıcıda transcript yok
 *      ProviderUnavailable  sağlayıcı çalışmıyor / kapalı / engellendi
 *      RateLimited          hız sınırı
 *
 *  Üçünde de zincir bir sonraki sağlayıcıya geçer. Videonun kendisiyle
 *  ilgili kesin bir sonuç (gizli, silinmiş) ise KaynakHatasi'dır ve
 *  zinciri durdurur: hiçbir sağlayıcı gizli videoyu okuyamaz.
 *
 *  getMessage() teknik ayrıntıdır, yalnız log'a yazılır.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Transcript;

use RuntimeException;
use Throwable;

abstract class TranscriptHatasi extends RuntimeException
{
    public function __construct(
        public readonly string $kod,
        string $teknikAyrinti = '',
        private readonly bool $gecici = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($teknikAyrinti !== '' ? $teknikAyrinti : $kod, 0, $previous);
    }

    /** Aynı sağlayıcı biraz sonra yeniden denenirse geçebilir mi? */
    public function yenidenDenenebilir(): bool
    {
        return $this->gecici;
    }
}
