<?php
/**
 * =====================================================================
 *  GÖRÜNÜM: EVREN kullanım şartları (panel/transkript/ayarlar/evren-sartlar)
 * ---------------------------------------------------------------------
 *  Yalnız yönetici. Şartların durumu, metni (EVREN'den; HTML olarak değil
 *  düz metin olarak basılır) ve "Okudum, onaylıyorum" formu. Onay yalnız
 *  bu formla verilir (AyarController::evrenSartOnayla).
 *
 *  @var array{onayli:?bool, guncel_surum:?int, onaylanan_surum:?int} $bilgi
 *  @var bool        $erisim  durum ucu yanıt verdi mi
 *  @var string|null $hata    yanıt vermediyse kısa sebep (anahtar maskeli)
 *  @var string|null $metin   şart metni
 *  @var array{surum:int, tarih:string}|null $kayit panelden verilen son onay
 * =====================================================================
 */

use App\Core\Auth;
use App\Core\View;
use Modules\Transkript\TranskriptPolicy;
use Modules\Transkript\Varliklar;

$surum = $bilgi['guncel_surum'];
?>
<link rel="stylesheet" href="<?= e(Varliklar::adres('transkript.css')) ?>">

<?php View::partial('Transkript::_parcalar/alt-menu', ['policy' => new TranskriptPolicy(Auth::user()), 'etkin' => 'ayarlar']); ?>

<section class="cy-card tk-sartlar">
    <header class="cy-card__header d-flex flex-wrap gap-2 justify-content-between align-items-center">
        <div>
            <h3 class="cy-section-title mb-1"><?= icon('lock', 'cy-icon cy-icon--sm') ?> EVREN LLM kullanım şartları<?= $surum !== null ? ' (v' . (int) $surum . ')' : '' ?></h3>
            <p class="cy-muted small mb-0">Anahtar: <span class="cy-mono">.env → EVREN_API_KEY</span>. Onay yalnız bu anahtar içindir; anahtar değişirse yeniden onaylanır.</p>
        </div>
        <a class="btn cy-btn cy-btn--ghost cy-btn--sm" href="<?= e(url('panel/transkript/ayarlar')) ?>#durum"><?= icon('server', 'cy-icon cy-icon--sm') ?> Duruma dön</a>
    </header>
    <div class="cy-card__body">
        <?php if (!$erisim): ?>
            <p class="cy-panel__note is-warn mb-0">EVREN şart durumu okunamadı<?= $hata !== null && $hata !== '' ? ': ' . e($hata) : '' ?>. Anahtarı ve bağlantıyı kontrol edin.</p>
        <?php elseif ($bilgi['onayli'] === true): ?>
            <p class="mb-2"><span class="cy-pill is-ok">Onaylı</span> Bu anahtar için kullanım şartları<?= $surum !== null ? ' (v' . (int) $surum . ')' : '' ?> onaylı. EVREN zincirde kullanılır.</p>
            <?php if ($kayit !== null && $kayit['tarih'] !== ''): ?>
                <p class="cy-muted small mb-0">Panelde kayıtlı: v<?= (int) $kayit['surum'] ?> · <?= e(human_date($kayit['tarih'])) ?></p>
            <?php endif; ?>
        <?php else: ?>
            <p class="mb-3"><span class="cy-pill is-warn">Onaylanmadı</span> Şartlar onaylanana kadar EVREN her isteği reddeder (403); analizler sıradaki sağlayıcıyla sürer.</p>

            <?php if ($metin !== null && $metin !== ''): ?>
                <div class="tk-sart-metni" tabindex="0" aria-label="Kullanım şartlarının metni"><?= e($metin) ?></div>
            <?php else: ?>
                <p class="cy-panel__note is-warn">Şart metni alınamadı. Metni evren.ssyz.org.tr'den okuyabilirsiniz.</p>
            <?php endif; ?>

            <?php if ($surum !== null): ?>
                <form method="post" action="<?= e(url('panel/transkript/ayarlar/evren-sartlar')) ?>" class="tk-sart-onay mt-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="surum" value="<?= (int) $surum ?>">
                    <label class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="okudum" value="1" required>
                        <span class="form-check-label">Metni okudum; EVREN LLM kullanım şartlarını (v<?= (int) $surum ?>) bu anahtar için onaylıyorum.</span>
                    </label>
                    <button type="submit" class="btn cy-btn cy-btn--primary"><?= icon('check', 'cy-icon cy-icon--sm') ?> Okudum, onaylıyorum</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>
