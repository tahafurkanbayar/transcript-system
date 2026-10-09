<?php
/**
 * =====================================================================
 *  GÖRÜNÜM: Transkript – yeni analiz + kaynak listesi
 * ---------------------------------------------------------------------
 *  $this->view('Transkript::index', ...) ile çağrılır.
 *
 *  Üstte URL kutusu (AJAX; sonuç alanını assets/js/transkript.js
 *  textContent ile doldurur), özet kutucukları, sunucu taraflı sayfalı
 *  tablo. Süren analizin satırı 3 saniyede bir ilerleme ucundan
 *  güncellenir. Bütün metin e() ile basılır.
 *
 *  @var Modules\Transkript\TranskriptPolicy                     $policy
 *  @var array{q:string,durum:string}                              $filtre
 *  @var string                                                    $varsayilanDurum  analiz başlatamayana 'hazir'
 *  @var array{satirlar:list<array<string,mixed>>,toplam:int}      $liste
 *  @var int                                                       $sayfa
 *  @var int                                                       $boyut
 *  @var array{bugun:int,token:int,maliyet:float,kaynak:int,suruyor:int,hata:int} $istatistik
 *  @var bool                                                      $hepsi  yönetici: herkesin sayıları + token / maliyet kartları
 *  @var array<string,mixed>                                       $kuyruk KuyrukDurumu::getir()
 * =====================================================================
 */

use App\Core\View;
use Modules\Transkript\HataKodlari;
use Modules\Transkript\Services\RaporVerisi;
use Modules\Transkript\Varliklar;

$turAdi = ['youtube' => 'YouTube', 'ses_dosyasi' => 'Ses', 'video_dosyasi' => 'Video'];
$durumAdi = [
    'bekliyor'            => ['Sırada', ''],
    'transkript_aliniyor' => ['Transcript alınıyor', 'is-info'],
    'transkript_hazir'    => ['Transcript hazır', 'is-info'],
    'analiz_ediliyor'     => ['Analiz ediliyor', 'is-info'],
    'tamamlandi'          => ['Tamamlandı', 'is-ok'],
    'hata'                => ['Hata', 'is-danger'],
    'iptal'               => ['İptal edildi', ''],
];
$varsayilanDurum = $varsayilanDurum ?? '';
$filtreli = $filtre['q'] !== '' || $filtre['durum'] !== $varsayilanDurum;
// Adreste taşınan durum: varsayılan "hazir" iken "Tüm durumlar" boş değil "hepsi"dir.
$durumParam = $filtre['durum'] !== '' ? $filtre['durum'] : ($varsayilanDurum !== '' ? 'hepsi' : '');
$sayi     = static fn (int|float $n, int $basamak = 0): string => number_format((float) $n, $basamak, ',', '.');
?>
<link rel="stylesheet" href="<?= e(Varliklar::adres('transkript.css')) ?>">

<?php View::partial('Transkript::_parcalar/kuyruk-uyarisi', ['kuyruk' => $kuyruk, 'policy' => $policy]); ?>

<?php if ($policy->isAdmin()): ?>
    <div class="tk-ust tk-ust--sag mb-3">
        <?php if (($bekleyen ?? 0) > 0): ?>
            <?php /* Geçici sebeple (kota, servis) duran analizler kaldığı yerden sürer; biten bölümler korunur. */ ?>
            <form method="post" action="<?= e(url('panel/transkript/yeniden-dene')) ?>" class="d-inline">
                <?= csrf_field() ?>
                <button type="submit" class="btn cy-btn cy-btn--ghost cy-btn--sm" title="Kota ya da servis yüzünden duran analizler kaldığı yerden sürer">
                    <?= icon('refresh', 'cy-icon cy-icon--sm') ?> Bekleyenleri yeniden dene (<?= (int) $bekleyen ?>)
                </button>
            </form>
        <?php endif; ?>
        <a class="btn cy-btn cy-btn--ghost cy-btn--sm" href="<?= e(url('panel/transkript/ayarlar')) ?>"><?= icon('settings', 'cy-icon cy-icon--sm') ?> Transkript ayarları</a>
    </div>
<?php endif; ?>

<?php if ($policy->canAnalyze()): ?>
    <section class="cy-panel mb-3 tk-analiz" id="tk_analiz"
             data-url="<?= e(url('panel/transkript/analiz')) ?>"
             data-yeniden="<?= $policy->canReanalyze() ? '1' : '0' ?>">
        <header class="cy-panel__head">
            <div>
                <h3 class="cy-panel__title"><?= icon('zap', 'cy-icon cy-icon--sm') ?> Yeni analiz</h3>
                <p class="cy-panel__sub">YouTube bağlantısını yapıştırın; transcript alınır, bölüm bölüm analiz edilip rapora dönüştürülür.</p>
            </div>
        </header>
        <form class="tk-analiz__form" id="tk_analiz_form" novalidate>
            <label class="visually-hidden" for="tk_url">YouTube bağlantısı</label>
            <input type="url" class="form-control" id="tk_url" name="url" required inputmode="url" autocomplete="off"
                   placeholder="https://www.youtube.com/watch?v=…" maxlength="500" value="<?= e($onUrl ?? '') ?>"<?= ($onUrl ?? '') !== '' ? ' data-hazir="1"' : '' ?>>
            <button type="submit" class="btn cy-btn cy-btn--primary" id="tk_analiz_dugme">
                <?= icon('zap', 'cy-icon cy-icon--sm') ?> Analiz Et
            </button>
        </form>
        <?php /* Sonuç (önbellek uyarısı, hata) — JS doldurur. */ ?>
        <div class="tk-analiz__sonuc" id="tk_analiz_sonuc" role="status" aria-live="polite" hidden></div>
        <details class="tk-kisayol">
            <summary>Kısayol: YouTube'dan tek tıkla gönder</summary>
            <p class="cy-panel__note mb-2">
                Masaüstü: aşağıdaki düğmeyi yer imleri çubuğuna sürükleyin; YouTube'da bir videodayken tıklayınca bu sayfa bağlantı dolu açılır.
                Telefon: <span class="cy-mono"><?= e(url_full('panel/transkript', ['url' => ''])) ?></span> sonuna video bağlantısını ekleyerek açabilirsiniz.
            </p>
            <?php /* Sürüklenmek içindir; bu sayfada tıklamayı CSP zaten engeller. */ ?>
            <a class="btn cy-btn cy-btn--ghost cy-btn--sm" href="<?= e("javascript:location.href='" . url_full('panel/transkript', ['url' => '']) . "'+encodeURIComponent(location.href)") ?>"
               title="Yer imleri çubuğuna sürükleyin"><?= icon('zap', 'cy-icon cy-icon--sm') ?> Transkript'e gönder</a>
        </details>
    </section>
<?php else: ?>
    <p class="cy-panel__note mb-3"><?= icon('info', 'cy-icon cy-icon--sm') ?> Analiz başlatma yetkiniz yok; analiz edilmiş videoların raporlarını görebilirsiniz.</p>
<?php endif; ?>

<?php $analizci = $policy->canAnalyze(); ?>
<div class="cy-stats mb-3" style="--cy-stats-lg: <?= $hepsi ? 4 : 2 ?>; --cy-stats-md: 2">
    <div class="cy-stat">
        <span class="cy-stat__icon cy-stat__icon--success"><?= icon('files') ?></span>
        <span>
            <span class="cy-stat__label">Raporu hazır video</span>
            <span class="cy-stat__value"><?= e($sayi($istatistik['kaynak'])) ?></span>
            <span class="cy-stat__hint"><?php
                // Süren / hatalı sayısı yalnız analiz başlatabilene (yeniden deneyebilir).
                $durumlar = $analizci ? array_filter([
                    $istatistik['suruyor'] > 0 ? $sayi($istatistik['suruyor']) . ' sürüyor' : '',
                    $istatistik['hata'] > 0 ? $sayi($istatistik['hata']) . ' hata' : '',
                ]) : [];
                echo e($durumlar !== [] ? implode(' · ', $durumlar) : ($analizci ? 'aynı video ikinci kez analiz edilmez' : 'okuyabileceğiniz raporlar'));
            ?></span>
        </span>
    </div>
    <div class="cy-stat">
        <span class="cy-stat__icon cy-stat__icon--brand"><?= icon('calendar') ?></span>
        <span>
            <?php /* Yönetici analiz sayısını (yeniden analiz dahil), öteki roller farklı videoyu görür. */ ?>
            <span class="cy-stat__label"><?= $hepsi ? 'Bugün tamamlanan analiz' : 'Bugün hazırlanan rapor' ?></span>
            <span class="cy-stat__value"><?= e($sayi($istatistik['bugun'])) ?></span>
            <span class="cy-stat__hint"><?= $hepsi ? 'bütün kullanıcılar, yeniden analiz dahil' : 'farklı video; yeniden analiz sayılmaz' ?></span>
        </span>
    </div>
    <?php /* Token ve maliyet yalnız yöneticiye (kullanıcı kararı: üye ve editör görmez). */ ?>
    <?php if ($hepsi): ?>
    <div class="cy-stat">
        <span class="cy-stat__icon cy-stat__icon--warning"><?= icon('activity') ?></span>
        <span>
            <span class="cy-stat__label">Toplam token</span>
            <span class="cy-stat__value"><?= e($sayi($istatistik['token'])) ?></span>
            <span class="cy-stat__hint">giriş + çıkış, bütün AI çağrıları</span>
        </span>
    </div>
    <div class="cy-stat">
        <span class="cy-stat__icon cy-stat__icon--danger"><?= icon('server') ?></span>
        <span>
            <span class="cy-stat__label">Tahmini maliyet</span>
            <span class="cy-stat__value">$<?= e($sayi($istatistik['maliyet'], 4)) ?></span>
            <span class="cy-stat__hint">ücretli katman fiyatıyla; ücretsiz katmanda 0</span>
        </span>
    </div>
    <?php endif; ?>
</div>

<section class="cy-card">
    <form class="cy-toolbar" method="get" action="<?= e(url('panel/transkript')) ?>" role="search">
        <div class="cy-toolbar__search">
            <div class="cy-input-icon">
                <?= icon('search', 'cy-icon cy-icon--sm') ?>
                <input type="search" class="form-control" name="q" value="<?= e($filtre['q']) ?>"
                       placeholder="Başlık, kanal ya da video kimliği…" aria-label="Analizlerde ara" maxlength="100">
            </div>
        </div>
        <select class="form-select tk-filtre" name="durum" aria-label="Duruma göre filtrele">
            <option value="<?= $varsayilanDurum !== '' ? 'hepsi' : '' ?>"<?= $filtre['durum'] === '' ? ' selected' : '' ?>>Tüm durumlar</option>
            <option value="hazir"<?= $filtre['durum'] === 'hazir' ? ' selected' : '' ?>>Raporu hazır</option>
            <option value="suruyor"<?= $filtre['durum'] === 'suruyor' ? ' selected' : '' ?>>Sürüyor</option>
            <option value="hata"<?= $filtre['durum'] === 'hata' ? ' selected' : '' ?>>Hata</option>
            <option value="iptal"<?= $filtre['durum'] === 'iptal' ? ' selected' : '' ?>>İptal edildi</option>
        </select>
        <button type="submit" class="btn cy-btn cy-btn--ghost cy-btn--sm"><?= icon('filter', 'cy-icon cy-icon--sm') ?> Uygula</button>
        <?php if ($filtreli): ?>
            <a class="cy-link small" href="<?= e(url('panel/transkript')) ?>">Temizle</a>
        <?php endif; ?>
    </form>

    <?php if ($liste['satirlar'] === []): ?>
        <div class="cy-empty">
            <span class="cy-empty__icon"><?= icon($filtreli ? 'search' : 'file') ?></span>
            <p class="mb-0">
                <?= match (true) {
                    $filtreli                => 'Bu filtreyle eşleşen analiz yok.',
                    $policy->canAnalyze()    => 'Henüz analiz yok. Yukarıdaki kutuya bir YouTube bağlantısı yapıştırarak başlayın.',
                    default                  => 'Henüz hazır rapor yok. Analiz edilen videolar burada görünecek.',
                } ?>
            </p>
        </div>
    <?php else: ?>
        <div class="cy-table-wrap">
            <table class="table cy-table cy-table--cards w-100 tk-liste">
                <thead>
                    <tr>
                        <th scope="col" data-kart="ana">Kaynak</th>
                        <th scope="col">Süre</th>
                        <th scope="col">Durum</th>
                        <th scope="col">Tarih</th>
                        <th scope="col" class="text-end" data-kart="islem"><span class="visually-hidden">İşlemler</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($liste['satirlar'] as $satir): ?>
                        <?php
                        $kaynakId = (int) $satir['id'];
                        $rapor   = url('panel/transkript/' . $kaynakId);
                        $durum   = (string) $satir['durum'];
                        $suruyor = !in_array($durum, ['tamamlandi', 'hata'], true);
                        $iptal   = $durum === 'hata' && $satir['hata_kodu'] === 'iptal_edildi';
                        [$durumMetni, $durumRengi] = $durumAdi[$iptal ? 'iptal' : $durum] ?? [$durum, ''];
                        $resim   = (string) ($satir['kucuk_resim'] ?? '');
                        $adi     = (string) ($satir['baslik'] ?: $satir['dis_kimlik']);
                        ?>
                        <tr<?= $suruyor ? ' data-ilerleme="' . e(url('panel/transkript/ilerleme/' . (int) $satir['id'])) . '"' : '' ?>>
                            <td>
                                <a class="tk-kaynak" href="<?= e($rapor) ?>">
                                    <?php if (str_starts_with($resim, 'https://i.ytimg.com/')): ?>
                                        <img class="tk-kaynak__resim" src="<?= e($resim) ?>" alt="" loading="lazy" width="96" height="54">
                                    <?php else: ?>
                                        <span class="tk-kaynak__resim tk-kaynak__resim--bos"><?= icon('file', 'cy-icon cy-icon--sm') ?></span>
                                    <?php endif; ?>
                                    <span class="tk-kaynak__metin">
                                        <strong><?= e($adi) ?></strong>
                                        <small>
                                            <?php if ($satir['tur'] !== 'youtube'): ?><span class="cy-chip"><?= e($turAdi[$satir['tur']] ?? (string) $satir['tur']) ?></span> <?php endif; ?>
                                            <?= e((string) ($satir['kanal_adi'] ?? '')) ?>
                                        </small>
                                    </span>
                                </a>
                            </td>
                            <td><span class="cy-mono"><?= e(RaporVerisi::sure($satir['sure_sn'] !== null ? (int) $satir['sure_sn'] : null)) ?></span></td>
                            <td class="js-tk-analiz">
                                <span class="tk-durum-hucre">
                                    <span class="cy-pill <?= e($durumRengi) ?> js-tk-durum"><?= e($durumMetni) ?></span>
                                    <?php if ($suruyor): ?>
                                        <span class="tk-mini-ilerleme">
                                            <span class="cy-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) $satir['ilerleme'] ?>" aria-label="Analiz ilerlemesi">
                                                <span class="cy-progress__bar js-tk-cubuk" style="width: <?= (int) $satir['ilerleme'] ?>%"></span>
                                            </span>
                                            <small class="js-tk-etiket">%<?= (int) $satir['ilerleme'] ?></small>
                                        </span>
                                    <?php elseif ($satir['rapor_analiz_id'] !== null): ?>
                                        <?php if ($durum === 'hata'): ?>
                                            <small class="cy-muted">Son deneme: <?= e(HataKodlari::gorunenMesaj($satir['hata_kodu'], $policy->canSeeErrorDetail($satir), $policy->isAdmin())) ?> Önceki rapor duruyor.</small>
                                        <?php else: ?>
                                            <a class="cy-link small" href="<?= e($rapor) ?>"><?= icon('check', 'cy-icon cy-icon--sm') ?> Rapor hazır</a>
                                        <?php endif; ?>
                                    <?php elseif (!$iptal): ?>
                                        <?php /* Kota / servis nedeni yalnız yöneticiye ve başlatana (HataKodlari::gorunenMesaj). */ ?>
                                        <small class="tk-hata-metni"><?= e(HataKodlari::gorunenMesaj($satir['hata_kodu'], $policy->canSeeErrorDetail($satir), $policy->isAdmin())) ?></small>
                                        <?php if ($policy->isAdmin() && ($satir['hata_ozeti'] ?? null) !== null && $satir['hata_ozeti'] !== ''): ?>
                                            <small class="tk-zincir-ozeti"><?= e((string) $satir['hata_ozeti']) ?></small>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </span>
                            </td>
                            <td><small title="<?= e((string) $satir['created_at']) ?>"><?= e(human_date((string) $satir['created_at'])) ?></small></td>
                            <td class="text-end">
                                <div class="cy-row-actions">
                                    <?php if ($durum === 'hata' && $satir['tur'] === 'youtube' && $policy->canAnalyze()): ?>
                                        <button type="button" class="cy-btn-icon js-tk-yeniden" title="Yeniden dene" aria-label="<?= e($adi) ?>: yeniden dene"
                                                data-url="<?= e(url('panel/transkript/analiz')) ?>"
                                                data-video="<?= e('https://www.youtube.com/watch?v=' . $satir['dis_kimlik']) ?>" data-yeniden="0"><?= icon('refresh', 'cy-icon cy-icon--sm') ?></button>
                                    <?php endif; ?>
                                    <?php if ($suruyor && $policy->canCancelAnalysis($satir)): ?>
                                        <button type="button" class="cy-btn-icon js-tk-iptal" title="Analizi iptal et" aria-label="<?= e($adi) ?>: analizi iptal et"
                                                data-url="<?= e(url('panel/transkript/' . $kaynakId . '/iptal')) ?>"
                                                data-onay="“<?= e($adi) ?>” analizi durdurulacak. Bitmiş bölümler korunmaz; daha sonra yeniden başlatabilirsiniz."
                                                data-onay-baslik="Analizi iptal et" data-onay-dugme="Evet, iptal et" data-onay-tehlike="1"><?= icon('x-circle', 'cy-icon cy-icon--sm') ?></button>
                                    <?php endif; ?>
                                    <?php if ($policy->canDeleteSource($satir)): ?>
                                        <button type="button" class="cy-btn-icon cy-btn-icon--danger js-tk-sil" title="Sil" aria-label="<?= e($adi) ?>: sil"
                                                data-url="<?= e(url('panel/transkript/' . $kaynakId . '/sil')) ?>"
                                                data-onay="“<?= e($adi) ?>” kalıcı olarak silinecek: raporlar, transcript ve bekleyen işler. Video herkes için ortak; yeniden analiz yapay zekâ çağrısı gerektirir."
                                                data-onay-baslik="Kaydı sil" data-onay-dugme="Evet, sil" data-onay-tehlike="1"><?= icon('trash', 'cy-icon cy-icon--sm') ?></button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <footer class="tk-liste__alt">
            <small class="cy-muted"><?= e($sayi($liste['toplam'])) ?> kayıt</small>
            <?php View::partial('Transkript::_parcalar/sayfalama', [
                'sayfa'  => $sayfa,
                'toplam' => $liste['toplam'],
                'boyut'  => $boyut,
                'yol'    => 'panel/transkript',
                'sorgu'  => array_filter(['q' => $filtre['q'], 'durum' => $durumParam], static fn (string $v): bool => $v !== ''),
            ]); ?>
        </footer>
    <?php endif; ?>
</section>

<?php View::partial('Transkript::_parcalar/onay-penceresi'); ?>

<script src="<?= e(Varliklar::adres('transkript.js')) ?>" defer></script>
