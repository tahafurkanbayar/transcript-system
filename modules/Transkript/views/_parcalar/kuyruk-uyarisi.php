<?php
/**
 * =====================================================================
 *  PARÇA: "Kuyruk işçisi çalışmıyor" uyarısı (liste ve rapor sayfası)
 * ---------------------------------------------------------------------
 *  Sunucu ilk durumu basar; sayfa açıkken ilerleme yoklaması
 *  (kuyruk_takili) uyarıyı JS ile açıp kapatır (.js-tk-kuyruk-uyari).
 *  Komutlar yalnız yöneticiye gösterilir; diğerleri yöneticiye haber
 *  vermesi gerektiğini görür. Bkz. Services\KuyrukDurumu.
 *
 *  KOMUT SATIRISIZ KUYRUK (kuyruk.tarayicidan_isle açık, analiz yetkisi
 *  var): kutu uyarı değil bilgi olur, komut göstermez; data-tik adresini
 *  JS kullanır, işçi yokken bu sayfa işleri arka planda tek tek işletir.
 *
 *  @var array<string,mixed>                 $kuyruk KuyrukDurumu::getir()
 *  @var Modules\Transkript\TranskriptPolicy $policy
 * =====================================================================
 */

use Modules\Transkript\Services\KuyrukDurumu;

$tarayicidan = KuyrukDurumu::tarayicidanIslenir() && $policy->canAnalyze();
?>
<?php if ($tarayicidan): ?>
    <div class="cy-panel mb-3 tk-uyari tk-kuyruk-uyari js-tk-kuyruk-uyari" role="status"
         data-tik="<?= e(url('panel/transkript/kuyruk/tik')) ?>"<?= !empty($kuyruk['takildi']) ? '' : ' hidden' ?>>
        <strong><?= icon('zap', 'cy-icon cy-icon--sm') ?> Kuyruk tarayıcı üzerinden işleniyor</strong>
        <span>Kuyruk işçisi çalışmıyor; bu sayfa açık kaldıkça işler arka planda sırayla işlenir. Sayfayı kapatırsanız analiz bekler, açınca sürer.</span>
    </div>
<?php else: ?>
    <div class="cy-panel cy-panel--warn mb-3 tk-uyari tk-kuyruk-uyari js-tk-kuyruk-uyari" role="alert"<?= !empty($kuyruk['takildi']) ? '' : ' hidden' ?>>
        <strong><?= icon('alert', 'cy-icon cy-icon--sm') ?> Kuyruk işçisi çalışmıyor</strong>
        <span>Analizler sırada bekliyor ama iki dakikadır onları işleyen yok. İşçi başlayınca analiz kaldığı yerden sürer.</span>
        <?php if ($policy->isAdmin()): ?>
            <span>Proje klasöründe tek seferlik: <code><?= e(KuyrukDurumu::KOMUT_ELLE) ?></code></span>
            <span>Sürekli çalışması için Windows / XAMPP'ta <code><?= e(KuyrukDurumu::KOMUT_KUR) ?></code> dosyasına bir kez çift tıklayın (oturum açılınca gizli başlar); sunucuda cron: <code><?= e(KuyrukDurumu::KOMUT_CRON) ?></code></span>
            <?php if (KuyrukDurumu::paneldenIslenebilir()): ?>
                <span class="tk-kuyruk-uyari__eylem">
                    <button type="button" class="btn cy-btn cy-btn--ghost cy-btn--sm js-tk-kuyruk-isle" data-url="<?= e(url('panel/transkript/kuyruk/isle')) ?>">
                        <?= icon('zap', 'cy-icon cy-icon--sm') ?> <span class="js-tk-isle-metin">Şimdi işle</span>
                    </button>
                    <small class="cy-muted">Geliştirme kolaylığı: işler bu istekte çalışır; bir yapay zekâ adımı 1–2 dakika sürebilir.</small>
                </span>
            <?php endif; ?>
        <?php else: ?>
            <span>Sistem yöneticinize haber verin.</span>
        <?php endif; ?>
    </div>
<?php endif; ?>
