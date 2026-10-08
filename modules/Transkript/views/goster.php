<?php
/**
 * =====================================================================
 *  GÖRÜNÜM: Transkript – analiz raporu
 * ---------------------------------------------------------------------
 *  $this->view('Transkript::goster', ...) ile çağrılır.
 *
 *  İSKELET (2. adım). 8. adımda yukarıdan aşağıya: kaynak kartı ve
 *  ilerleme, kısa özet, sana özel, açılır bölümler (detaylı özet,
 *  önemli noktalar, zaman çizelgesi, teknik bilgiler, fikirler,
 *  iddialar), gömülü oynatıcı. AI ve transcript metni yalnızca e() ile
 *  basılır, HTML olarak basılmaz.
 *
 *  @var int $kaynakId
 * =====================================================================
 */

$kaynakId = (int) ($kaynakId ?? 0);
?>

<div class="cy-page-head">
    <div>
        <h2 class="cy-title">Analiz raporu</h2>
        <p class="cy-subtitle">Kaynak #<?= $kaynakId ?></p>
    </div>
    <a href="<?= e(url('panel/transkript')) ?>" class="btn cy-btn cy-btn--ghost">Listeye dön</a>
</div>

<div class="cy-card">
    <div class="cy-card__body">
        <div class="cy-empty">
            <span class="cy-empty__icon"><?= icon('file') ?></span>
            <p class="cy-empty__text">Rapor ekranı hazırlanıyor.</p>
        </div>
    </div>
</div>
