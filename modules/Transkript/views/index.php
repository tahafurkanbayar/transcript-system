<?php
/**
 * =====================================================================
 *  GÖRÜNÜM: Transkript – yeni analiz + kaynak listesi
 * ---------------------------------------------------------------------
 *  $this->view('Transkript::index', ...) ile çağrılır.
 *
 *  İSKELET (2. adım). 8. adımda: URL kutusu ve "Analiz Et", "daha önce
 *  analiz edildi" uyarısı, özet kutucukları, sunucu taraflı sayfalı
 *  tablo (bkz. docs/TEKNIK_RAPOR.md, 9. bölüm).
 *
 *  @var string $subtitle
 * =====================================================================
 */

$subtitle = $subtitle ?? '';
?>

<div class="cy-page-head">
    <div>
        <h2 class="cy-title">Transkript</h2>
        <p class="cy-subtitle"><?= e($subtitle) ?></p>
    </div>
</div>

<div class="cy-card">
    <div class="cy-card__body">
        <div class="cy-empty">
            <span class="cy-empty__icon"><?= icon('file') ?></span>
            <p class="cy-empty__text">
                Bu ekran hazırlanıyor. YouTube bağlantısıyla analiz başlatma ve
                analiz edilen kayıtların listesi burada olacak.
            </p>
        </div>
    </div>
</div>
