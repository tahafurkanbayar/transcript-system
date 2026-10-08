<?php
/**
 * =====================================================================
 *  Parca – AI'a tek seferde giden transcript dilimi (değer nesnesi)
 * ---------------------------------------------------------------------
 *  transkript_parcalar satırına karşılık gelir: sira, baslangic_ms,
 *  bitis_ms, tahmini_token. Segmentler map isteminde satır başı
 *  [hh:mm:ss] ile yazılır (7. adım).
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Services;

use Modules\Transkript\Transcript\Segment;

final class Parca
{
    /** @param list<Segment> $segmentler */
    public function __construct(
        public readonly int $sira,
        public readonly int $baslangicMs,
        public readonly int $bitisMs,
        public readonly int $tahminiToken,
        public readonly array $segmentler,
    ) {
    }

    public function sureMs(): int
    {
        return $this->bitisMs - $this->baslangicMs;
    }
}
