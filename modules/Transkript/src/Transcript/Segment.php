<?php
/**
 * =====================================================================
 *  Segment – zaman damgalı tek transcript satırı (değer nesnesi)
 * ---------------------------------------------------------------------
 *  Süreler milisaniye tam sayıdır: kayan nokta saniye (1.2 + 2.16)
 *  toplanırken kayar ve rapordaki zaman damgası videoda yanlış yere
 *  atlardı.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Transcript;

final class Segment
{
    public function __construct(
        public readonly int $baslangicMs,
        public readonly int $bitisMs,
        public readonly string $metin,
        /** ASR'de konuşmacı ayrımı; YouTube altyazısında null */
        public readonly ?string $konusmaci = null,
    ) {
    }

    public function sureMs(): int
    {
        return max(0, $this->bitisMs - $this->baslangicMs);
    }

    /** @param array<string,mixed> $satir transkript_segmentler satırı */
    public static function satirdan(array $satir): self
    {
        return new self(
            (int) $satir['baslangic_ms'],
            (int) $satir['bitis_ms'],
            (string) $satir['metin'],
            isset($satir['konusmaci']) && $satir['konusmaci'] !== '' ? (string) $satir['konusmaci'] : null,
        );
    }
}
