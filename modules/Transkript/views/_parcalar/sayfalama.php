<?php
/**
 * =====================================================================
 *  PARÇA: sunucu taraflı sayfalama (Bootstrap .pagination)
 * ---------------------------------------------------------------------
 *  View::partial('Transkript::_parcalar/sayfalama', [...])
 *
 *  @var int                  $sayfa
 *  @var int                  $toplam
 *  @var int                  $boyut
 *  @var string               $yol     url() yolu (panel/transkript)
 *  @var array<string,string> $sorgu   korunacak filtreler (q, durum)
 * =====================================================================
 */

$sonSayfa = max(1, (int) ceil($toplam / max(1, $boyut)));

if ($sonSayfa <= 1) {
    return;
}

$adres = static fn (int $s): string => url($yol, array_filter($sorgu + ['sayfa' => $s > 1 ? (string) $s : ''], static fn (string $v): bool => $v !== ''));

// Kısa liste: ilk, son, seçilinin iki yanı; aralar "…"
$gosterilen = array_unique(array_filter(
    [1, $sayfa - 2, $sayfa - 1, $sayfa, $sayfa + 1, $sayfa + 2, $sonSayfa],
    static fn (int $s): bool => $s >= 1 && $s <= $sonSayfa
));
sort($gosterilen);
?>
<nav class="tk-sayfalama" aria-label="Sayfalar">
    <ul class="pagination pagination-sm mb-0">
        <li class="page-item<?= $sayfa <= 1 ? ' disabled' : '' ?>">
            <a class="page-link" href="<?= e($adres(max(1, $sayfa - 1))) ?>" aria-label="Önceki sayfa">‹</a>
        </li>
        <?php $onceki = 0; ?>
        <?php foreach ($gosterilen as $s): ?>
            <?php if ($s - $onceki > 1): ?>
                <li class="page-item disabled"><span class="page-link">…</span></li>
            <?php endif; ?>
            <li class="page-item<?= $s === $sayfa ? ' active' : '' ?>">
                <a class="page-link" href="<?= e($adres($s)) ?>"<?= $s === $sayfa ? ' aria-current="page"' : '' ?>><?= $s ?></a>
            </li>
            <?php $onceki = $s; ?>
        <?php endforeach; ?>
        <li class="page-item<?= $sayfa >= $sonSayfa ? ' disabled' : '' ?>">
            <a class="page-link" href="<?= e($adres(min($sonSayfa, $sayfa + 1))) ?>" aria-label="Sonraki sayfa">›</a>
        </li>
    </ul>
</nav>
