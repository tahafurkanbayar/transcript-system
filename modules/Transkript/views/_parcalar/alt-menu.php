<?php
/**
 * =====================================================================
 *  PARÇA: Transkript bölümleri arasında sekme menüsü
 * ---------------------------------------------------------------------
 *  Çekirdeğin sol menüsünde modülün tek bağlantısı var ("Transkript");
 *  alt sayfalar burada: Analizler · Üyeliğim (yönetici olmayan) ·
 *  Üyelikler ve Ayarlar (yönetici). Telefonda yatay kaydırılır. Sağda
 *  yönetici olmayana planı ve (Ücretsiz'de) "Pro'ya geç" bağlantısı.
 *
 *  @var Modules\Transkript\TranskriptPolicy $policy
 *  @var string                              $etkin analizler | uyelik | uyelikler | ayarlar
 * =====================================================================
 */

use App\Core\Auth;
use App\Core\Database;
use Modules\Transkript\Repositories\UyelikRepository;
use Modules\Transkript\Services\KuyrukDurumu;
use Modules\Transkript\Uyelik\Planlar;

$yonetici = $policy->isAdmin();
$bekleyen = 0;

if ($yonetici) {
    try {
        $bekleyen = (new UyelikRepository(Database::connection()))->bekleyenSayisi();
    } catch (Throwable) {
        $bekleyen = 0; // tablo henüz yok (migration bekliyor)
    }
}

$sekmeler = [['analizler', 'panel/transkript', 'files', 'Analizler', 0]];

if ($yonetici) {
    $sekmeler[] = ['uyelikler', 'panel/transkript/uyelikler', 'users', 'Üyelikler', $bekleyen];
    $sekmeler[] = ['ayarlar', 'panel/transkript/ayarlar', 'settings', 'Ayarlar', 0];
} else {
    $sekmeler[] = ['uyelik', 'panel/transkript/uyelik', 'star', 'Üyeliğim', 0];
}

$plan = $yonetici ? null : Planlar::plan(Auth::user());

/* Komut satırısız kuyruk (cowork 3. tur): bütün Transkript sayfaları
 * (ayarlar dahil) kuyruğu yürütebilsin. İşçi görülmüyorsa ve iş
 * bekliyorsa sayfa açılır açılmaz tik döngüsü başlar (assets/js). */
$tikAdresi = '';
$tikBaslat = false;

if (KuyrukDurumu::tarayicidanIslenir() && $policy->canAnalyze()) {
    $tikAdresi = url('panel/transkript/kuyruk/tik');

    try {
        $tikBaslat = !KuyrukDurumu::isciGorulduMu() && (new KuyrukDurumu(Database::connection()))->getir()['bekleyen'] > 0;
    } catch (Throwable) {
        $tikBaslat = false;
    }
}
?>
<div class="tk-altmenu-sarici"<?= $tikAdresi !== '' ? ' data-tk-tik="' . e($tikAdresi) . '" data-tk-tik-baslat="' . ($tikBaslat ? '1' : '0') . '"' : '' ?>>
    <nav class="tk-altmenu" aria-label="Transkript bölümleri">
        <?php foreach ($sekmeler as [$ad, $yol, $ikon, $etiket, $rozet]): ?>
            <a class="tk-altmenu__oge<?= $ad === $etkin ? ' is-etkin' : '' ?>" href="<?= e(url($yol)) ?>"<?= $ad === $etkin ? ' aria-current="page"' : '' ?>>
                <?= icon($ikon, 'cy-icon cy-icon--sm') ?>
                <span><?= e($etiket) ?></span>
                <?php if ($rozet > 0): ?>
                    <span class="tk-altmenu__rozet" title="Bekleyen Pro üyelik talebi"><?= (int) $rozet ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>
    <?php if ($plan !== null): ?>
        <a class="tk-plan-cip<?= $plan === Planlar::PRO ? ' is-pro' : '' ?>" href="<?= e(url('panel/transkript/uyelik')) ?>" title="Planınız ve sınırlarınız">
            <?= icon($plan === Planlar::PRO ? 'star' : 'user', 'cy-icon cy-icon--sm') ?>
            <span><?= e(Planlar::ad($plan)) ?> plan</span>
            <?php if ($plan === Planlar::UCRETSIZ && $etkin !== 'uyelik'): ?>
                <strong>Pro'ya geç →</strong>
            <?php endif; ?>
        </a>
    <?php endif; ?>
</div>
