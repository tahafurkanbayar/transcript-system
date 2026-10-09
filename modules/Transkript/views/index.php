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
 *  @var array{satirlar:list<array<string,mixed>>,toplam:int}      $liste
 *  @var int                                                       $sayfa
 *  @var int                                                       $boyut
 *  @var array{bugun:int,token:int,maliyet:float,kaynak:int}       $istatistik
 *  @var bool                                                      $hepsi  istatistik herkesin mi
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
];
$filtreli = $filtre['q'] !== '' || $filtre['durum'] !== '';
$sayi     = static fn (int|float $n, int $basamak = 0): string => number_format((float) $n, $basamak, ',', '.');
?>
<link rel="stylesheet" href="<?= e(Varliklar::adres('transkript.css')) ?>">

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
                   placeholder="https://www.youtube.com/watch?v=…" maxlength="500">
            <button type="submit" class="btn cy-btn cy-btn--primary" id="tk_analiz_dugme">
                <?= icon('zap', 'cy-icon cy-icon--sm') ?> Analiz Et
            </button>
        </form>
        <?php /* Sonuç (önbellek uyarısı, hata) — JS doldurur. */ ?>
        <div class="tk-analiz__sonuc" id="tk_analiz_sonuc" role="status" aria-live="polite" hidden></div>
    </section>
<?php else: ?>
    <p class="cy-panel__note mb-3"><?= icon('info', 'cy-icon cy-icon--sm') ?> Analiz başlatma yetkiniz yok; analiz edilmiş videoların raporlarını görebilirsiniz.</p>
<?php endif; ?>

<div class="cy-stats mb-3" style="--cy-stats-lg: 4; --cy-stats-md: 2">
    <div class="cy-stat">
        <span class="cy-stat__icon cy-stat__icon--brand"><?= icon('calendar') ?></span>
        <span>
            <span class="cy-stat__label">Bugünkü analiz</span>
            <span class="cy-stat__value"><?= e($sayi($istatistik['bugun'])) ?></span>
            <span class="cy-stat__hint"><?= $hepsi ? 'bütün kullanıcılar' : 'sizin başlattıklarınız' ?></span>
        </span>
    </div>
    <div class="cy-stat">
        <span class="cy-stat__icon cy-stat__icon--success"><?= icon('files') ?></span>
        <span>
            <span class="cy-stat__label">Analiz edilen video</span>
            <span class="cy-stat__value"><?= e($sayi($istatistik['kaynak'])) ?></span>
            <span class="cy-stat__hint">aynı video ikinci kez ödenmez</span>
        </span>
    </div>
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
            <option value="">Tüm durumlar</option>
            <option value="hazir"<?= $filtre['durum'] === 'hazir' ? ' selected' : '' ?>>Raporu hazır</option>
            <option value="suruyor"<?= $filtre['durum'] === 'suruyor' ? ' selected' : '' ?>>Sürüyor</option>
            <option value="hata"<?= $filtre['durum'] === 'hata' ? ' selected' : '' ?>>Hata</option>
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
                <?= $filtreli
                    ? 'Bu filtreyle eşleşen analiz yok.'
                    : 'Henüz analiz yok. Yukarıdaki kutuya bir YouTube bağlantısı yapıştırarak başlayın.' ?>
            </p>
        </div>
    <?php else: ?>
        <div class="cy-table-wrap">
            <table class="table cy-table cy-table--cards w-100 tk-liste">
                <thead>
                    <tr>
                        <th scope="col" data-kart="ana">Kaynak</th>
                        <th scope="col">Tür</th>
                        <th scope="col">Süre</th>
                        <th scope="col">Durum</th>
                        <th scope="col">AI analizi</th>
                        <th scope="col">Tarih</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($liste['satirlar'] as $satir): ?>
                        <?php
                        $rapor   = url('panel/transkript/' . (int) $satir['id']);
                        $durum   = (string) $satir['durum'];
                        $suruyor = !in_array($durum, ['tamamlandi', 'hata'], true);
                        [$durumMetni, $durumRengi] = $durumAdi[$durum] ?? [$durum, ''];
                        $resim   = (string) ($satir['kucuk_resim'] ?? '');
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
                                        <strong><?= e((string) ($satir['baslik'] ?: $satir['dis_kimlik'])) ?></strong>
                                        <small><?= e((string) ($satir['kanal_adi'] ?? '')) ?></small>
                                    </span>
                                </a>
                            </td>
                            <td><?= e($turAdi[$satir['tur']] ?? (string) $satir['tur']) ?></td>
                            <td><span class="cy-mono"><?= e(RaporVerisi::sure($satir['sure_sn'] !== null ? (int) $satir['sure_sn'] : null)) ?></span></td>
                            <td>
                                <span class="cy-pill <?= e($durumRengi) ?> js-tk-durum"<?= $durum === 'hata' ? ' title="' . e(HataKodlari::mesaj($satir['hata_kodu'])) . '"' : '' ?>><?= e($durumMetni) ?></span>
                            </td>
                            <td class="js-tk-analiz">
                                <?php if ($suruyor): ?>
                                    <span class="tk-mini-ilerleme">
                                        <span class="cy-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) $satir['ilerleme'] ?>" aria-label="Analiz ilerlemesi">
                                            <span class="cy-progress__bar js-tk-cubuk" style="width: <?= (int) $satir['ilerleme'] ?>%"></span>
                                        </span>
                                        <small class="js-tk-etiket">%<?= (int) $satir['ilerleme'] ?></small>
                                    </span>
                                <?php elseif ($satir['rapor_analiz_id'] !== null): ?>
                                    <a class="cy-link" href="<?= e($rapor) ?>"><?= icon('check', 'cy-icon cy-icon--sm') ?> Hazır</a>
                                <?php else: ?>
                                    <small class="tk-hata-metni"><?= e(HataKodlari::mesaj($satir['hata_kodu'])) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><small title="<?= e((string) $satir['created_at']) ?>"><?= e(human_date((string) $satir['created_at'])) ?></small></td>
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
                'sorgu'  => array_filter(['q' => $filtre['q'], 'durum' => $filtre['durum']], static fn (string $v): bool => $v !== ''),
            ]); ?>
        </footer>
    <?php endif; ?>
</section>

<script src="<?= e(Varliklar::adres('transkript.js')) ?>" defer></script>
