<?php
/**
 * =====================================================================
 *  PARÇA: onay penceresi (silme, iptal, yeniden analiz)
 * ---------------------------------------------------------------------
 *  Panelin geri kalanındaki silme pencereleriyle aynı biçim (cy-modal,
 *  bkz. views/mail/modals.php). Tarayıcının confirm() kutusu
 *  kullanılmaz. Düğme şunları taşır, transkript.js doldurur:
 *
 *      data-onay="Soru metni"            (zorunlu; yoksa sorulmaz)
 *      data-onay-baslik="Kaydı sil"      data-onay-dugme="Evet, sil"
 *      data-onay-tehlike="1"             (kırmızı onay düğmesi)
 *
 *  Metin textContent ile basılır.
 * =====================================================================
 */
?>
<div class="modal fade cy-modal" id="tk_onay" tabindex="-1" aria-labelledby="tk_onay_baslik" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title js-tk-onay-baslik" id="tk_onay_baslik">Emin misiniz?</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0 js-tk-onay-metin"></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn cy-btn cy-btn--ghost cy-btn--sm" data-bs-dismiss="modal">Vazgeç</button>
                <button type="button" class="btn cy-btn cy-btn--primary cy-btn--sm js-tk-onay-evet">Devam et</button>
            </div>
        </div>
    </div>
</div>
