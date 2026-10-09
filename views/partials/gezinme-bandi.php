<?php
/**
 * =====================================================================
 *  PARÇA: Kullanıcı olarak gezinme bandı (panel, site ve hata sayfaları)
 * ---------------------------------------------------------------------
 *  Yönetici bir üyenin gözünden geziyorsa her sayfanın altında durur;
 *  kimin hesabında olunduğunu söyler ve tek düğmeyle yöneticiye döndürür.
 *  Yan menü sabit olduğu için üste değil, alta yüzer.
 * =====================================================================
 */

use App\Core\Auth;

$asil    = Auth::impersonator();
$gezilen = Auth::user();

if ($asil === null || $gezilen === null) {
    return;
}
?>
<div class="cy-gezinme" role="status" aria-live="polite">
    <span class="cy-gezinme__metin">
        <?= icon('eye', 'cy-icon cy-icon--sm') ?>
        <span><strong><?= e($gezilen->fullName()) ?></strong> (<?= e($gezilen->roleLabel()) ?>) olarak geziyorsunuz</span>
    </span>
    <form method="post" action="<?= e(url('oturum/yoneticiye-don')) ?>" class="m-0">
        <?= csrf_field() ?>
        <button type="submit" class="cy-gezinme__dugme">
            <?= icon('logout', 'cy-icon cy-icon--sm') ?> Yönetici hesabına dön
        </button>
    </form>
</div>
