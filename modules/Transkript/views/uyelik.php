<?php
/**
 * =====================================================================
 *  GÖRÜNÜM: Üyeliğim (panel/transkript/uyelik)
 * ---------------------------------------------------------------------
 *  Planınız ve bugünkü kullanımınız (AnalizBaslatici::hakDurumu ile
 *  AYNI sayaç), plan karşılaştırması, "Pro'ya geç" / "Süremi uzat"
 *  talebi ve ödeme geçmişi. Ödeme talimatı (yöneticinin yazdığı düz
 *  metin) yalnız talep gönderilince görünür; e() ile basılır.
 *
 *  @var Modules\Transkript\TranskriptPolicy $policy
 *  @var array<string,mixed>                 $haklar   Planlar::haklar()
 *  @var array<string,mixed>                 $hak      AnalizBaslatici::hakDurumu()
 *  @var array{aktif:bool,suresiz:bool,bitis:?string,baslangic:?string,donem:int} $ozet
 *  @var array<string,mixed>|null            $talep    son talep (bekleyen önce)
 *  @var list<array<string,mixed>>           $donemler
 *  @var list<array<string,mixed>>           $karsilastirma
 *  @var string                              $odemeBilgisi
 * =====================================================================
 */

use App\Core\View;
use Modules\Transkript\Ayarlar;
use Modules\Transkript\Uyelik\Planlar;
use Modules\Transkript\Uyelik\UyelikYonetimi;
use Modules\Transkript\Varliklar;

$plan     = $haklar['plan'];
$pro      = $plan === Planlar::PRO;
$tarih    = static fn (?string $t): string => $t === null ? '—' : date('d.m.Y', (int) strtotime($t));
$kalanGun = $ozet['bitis'] !== null ? max(0, (int) ceil(((int) strtotime($ozet['bitis']) - time()) / 86400)) : null;
$yakin    = $kalanGun !== null && $kalanGun <= max(1, (int) Ayarlar::get('uyelik.hatirlatma_gun', 3));
$bekliyor = $talep !== null && $talep['durum'] === 'bekliyor';
// Süresiz Pro ve yönetici talep göndermez (UyelikYonetimi::talepOlustur).
$talepAcik = $plan === Planlar::UCRETSIZ || ($pro && $ozet['aktif'] && !$ozet['suresiz']);
$aylik    = Planlar::tanim(Planlar::PRO)['fiyat_aylik'];
$yillik   = Planlar::tanim(Planlar::PRO)['fiyat_yillik'];

/** Bir ölçer: etiket, kullanılan / sınır; sınır 0 ise "Sınırsız". */
$olcer = static function (string $etiket, int $kullanilan, int $sinir, string $ipucu) : string {
    $oran = $sinir > 0 ? min(100, (int) round($kullanilan / $sinir * 100)) : 0;
    $dolu = $sinir > 0 && $kullanilan >= $sinir;

    return '<div class="tk-olcer' . ($dolu ? ' is-dolu' : '') . '">'
        . '<div class="tk-olcer__ust"><span>' . e($etiket) . '</span><strong>'
        . e($sinir > 0 ? $kullanilan . ' / ' . $sinir : 'Sınırsız') . '</strong></div>'
        . ($sinir > 0 ? '<span class="cy-progress" role="progressbar" aria-label="' . e($etiket) . '" aria-valuemin="0" aria-valuemax="' . $sinir . '" aria-valuenow="' . min($kullanilan, $sinir) . '"><span class="cy-progress__bar" style="width: ' . $oran . '%"></span></span>' : '')
        . '<small>' . e($ipucu) . '</small></div>';
};
?>
<link rel="stylesheet" href="<?= e(Varliklar::adres('transkript.css')) ?>">

<?php View::partial('Transkript::_parcalar/alt-menu', ['policy' => $policy, 'etkin' => 'uyelik']); ?>

<?php if ($plan === null): ?>
    <section class="cy-panel mb-3">
        <h3 class="cy-panel__title"><?= icon('shield', 'cy-icon cy-icon--sm') ?> Yönetici hesabı</h3>
        <p class="cy-panel__sub mb-2">Yöneticinin üyelik planı yoktur<?= $haklar['muaf'] ? ' ve kişisel analiz sınırı uygulanmaz' : '' ?>. Üyelerin gördüğü sayfa aşağıdaki plan kartlarıyla aynıdır.</p>
        <a class="btn cy-btn cy-btn--ghost cy-btn--sm" href="<?= e(url('panel/transkript/uyelikler')) ?>"><?= icon('users', 'cy-icon cy-icon--sm') ?> Üyelikleri yönet</a>
    </section>
<?php else: ?>
    <!-- PLANINIZ -->
    <section class="cy-card mb-3 tk-planim<?= $pro ? ' is-pro' : '' ?>" aria-labelledby="tk-planim-baslik">
        <div class="cy-card__body tk-planim__govde">
            <div class="tk-planim__sol">
                <span class="tk-planim__etiket">Planınız</span>
                <h2 class="tk-planim__ad" id="tk-planim-baslik"><?= icon($pro ? 'star' : 'user', 'cy-icon') ?> <?= e($haklar['ad']) ?></h2>
                <p class="tk-planim__durum<?= $yakin ? ' is-yakin' : '' ?>">
                    <?php if (!$pro): ?>
                        Ücretsiz plan; süresi yoktur.
                    <?php elseif ($ozet['suresiz'] || !$ozet['aktif']): ?>
                        Pro, süresiz.
                    <?php else: ?>
                        <?= e($tarih($ozet['bitis'])) ?> tarihine kadar geçerli
                        <strong>(<?= $kalanGun === 0 ? 'bugün bitiyor' : e((string) $kalanGun) . ' gün kaldı' ?>)</strong>
                    <?php endif; ?>
                </p>
                <?php if (!$pro && $talepAcik && !$bekliyor): ?>
                    <a class="btn cy-btn cy-btn--primary cy-btn--sm" href="#tk-pro-gec"><?= icon('star', 'cy-icon cy-icon--sm') ?> Pro'ya geç</a>
                <?php elseif ($pro && $yakin && !$bekliyor): ?>
                    <a class="btn cy-btn cy-btn--primary cy-btn--sm" href="#tk-pro-gec"><?= icon('refresh', 'cy-icon cy-icon--sm') ?> Süremi uzat</a>
                <?php endif; ?>
            </div>
            <div class="tk-planim__sag">
                <?php if (!$haklar['analiz']): ?>
                    <p class="cy-panel__note mb-0"><?= icon('info', 'cy-icon cy-icon--sm') ?>
                        Bu planda şu an video analizi kapalı; sizinle paylaşılan raporları okuyabilirsiniz. Analiz için Pro'ya geçebilirsiniz.</p>
                <?php else: ?>
                    <?= $olcer('Bugünkü yeni analiz', (int) $hak['gunluk'], (int) $hak['gunluk_sinir'], 'Her gece 00:00\'da yenilenir.') ?>
                    <?= $olcer('Bu saatteki analiz', (int) $hak['saatlik'], (int) $hak['saatlik_sinir'], 'Art arda gönderim sınırı; saat başında yenilenir.') ?>
                    <div class="tk-olcer">
                        <div class="tk-olcer__ust"><span>En uzun video</span><strong><?= e(Planlar::sureMetni((int) $haklar['en_uzun_dk'])) ?></strong></div>
                        <small>Bundan uzun videolar bu planda analiz edilmez.</small>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <footer class="cy-card__footer tk-planim__not">
            <?= icon('info', 'cy-icon cy-icon--sm') ?>
            <span>Daha önce analiz edilmiş bir videoyu eklemek hakkınızdan <strong>düşmez</strong>; rapor hemen açılır. Hak yalnız yapay zekâya yeni gönderilen video için kullanılır.</span>
        </footer>
    </section>
<?php endif; ?>

<!-- PLANLAR -->
<section class="mb-3" aria-labelledby="tk-planlar-baslik">
    <div class="tk-bolum-baslik">
        <h3 class="cy-section-title mb-1" id="tk-planlar-baslik">Planları karşılaştırın</h3>
        <p class="cy-muted small mb-0">Sınırlar yöneticinin ayarlarından gelir; burada gördüğünüz, şu an geçerli olandır.</p>
    </div>
    <?php View::partial('Transkript::_parcalar/plan-tablosu', [
        'karsilastirma' => $karsilastirma,
        'etkinPlan'     => $plan,
        'eylemler'      => $plan === Planlar::UCRETSIZ && !$bekliyor ? [Planlar::PRO => ['#tk-pro-gec', 'Pro\'ya geç']] : [],
    ]); ?>
</section>

<?php if ($plan !== null && ($talepAcik || $bekliyor)): ?>
    <!-- PRO'YA GEÇ / SÜREMİ UZAT -->
    <section class="cy-card mb-3" id="tk-pro-gec" aria-labelledby="tk-pro-gec-baslik">
        <header class="cy-card__header">
            <h3 class="cy-section-title mb-1" id="tk-pro-gec-baslik"><?= icon('star', 'cy-icon cy-icon--sm') ?> <?= $pro ? 'Pro sürenizi uzatın' : 'Pro\'ya geçin' ?></h3>
            <p class="cy-muted small mb-0">Çevrim içi ödeme henüz yok: talep gönderirsiniz, ödeme talimatıyla ödersiniz, yönetici onaylayınca <?= $pro ? 'süreniz uzar' : 'Pro hemen açılır' ?> ve e-posta alırsınız.</p>
        </header>
        <div class="cy-card__body">
            <?php if ($bekliyor): ?>
                <div class="tk-talep-durum">
                    <span class="cy-pill is-warn">Onay bekliyor</span>
                    <p class="mb-1"><strong><?= (int) $talep['sure_ay'] ?> aylık</strong> talebiniz <?= e(human_date((string) $talep['created_at'])) ?> alındı.</p>
                    <?php if ((string) $talep['mesaj'] !== ''): ?>
                        <p class="cy-muted small mb-0">Notunuz: <?= e((string) $talep['mesaj']) ?></p>
                    <?php endif; ?>
                </div>
                <h4 class="tk-alt-baslik">Ödeme talimatı</h4>
                <?php if ($odemeBilgisi !== ''): ?>
                    <div class="tk-odeme-bilgisi"><?= e($odemeBilgisi) ?></div>
                    <p class="cy-muted small mt-2 mb-3">Açıklamaya kullanıcı adınızı (<strong>@<?= e((string) (\App\Core\Auth::user()?->kullaniciAdi ?? '')) ?></strong>) yazmanız onayı hızlandırır.</p>
                <?php else: ?>
                    <p class="cy-panel__note mb-3"><?= icon('info', 'cy-icon cy-icon--sm') ?> Yönetici ödeme bilgisini size iletecek; iletişim sayfasından da yazabilirsiniz.</p>
                <?php endif; ?>
                <form method="post" action="<?= e(url('panel/transkript/uyelik/talebi-geri-cek')) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn cy-btn cy-btn--ghost cy-btn--sm js-tk-form-onay"
                            data-onay="Bekleyen talebiniz geri çekilecek. Ödeme yaptıysanız önce yöneticiye yazın." data-onay-baslik="Talebi geri çek" data-onay-dugme="Evet, geri çek">
                        <?= icon('close', 'cy-icon cy-icon--sm') ?> Talebi geri çek
                    </button>
                </form>
            <?php else: ?>
                <?php if ($talep !== null && $talep['durum'] === 'reddedildi'): ?>
                    <p class="cy-panel__note is-warn mb-3"><?= icon('info', 'cy-icon cy-icon--sm') ?>
                        Önceki talebiniz (<?= e($tarih((string) $talep['created_at'])) ?>) onaylanmadı.<?= (string) $talep['yanit'] !== '' ? ' Yöneticinin notu: ' . e((string) $talep['yanit']) : '' ?></p>
                <?php endif; ?>
                <ol class="tk-adimlar">
                    <li><strong>Süreyi seçip talep gönderin.</strong> <span>Yöneticiye bildirim gider.</span></li>
                    <li><strong>Ödeme talimatıyla ödeyin.</strong> <span>Talimat talepten sonra bu sayfada görünür.</span></li>
                    <li><strong>Yönetici onaylar.</strong> <span><?= $pro ? 'Süreniz mevcut bitişin üstüne eklenir.' : 'Pro hemen açılır; e-postayla haber verilir.' ?></span></li>
                </ol>
                <form method="post" action="<?= e(url('panel/transkript/uyelik/talep')) ?>" class="tk-talep-form" novalidate>
                    <?= csrf_field() ?>
                    <div class="row g-3">
                        <div class="col-12 col-md-5">
                            <label class="form-label" for="tk_sure_ay">Süre</label>
                            <select class="form-select" id="tk_sure_ay" name="sure_ay" aria-describedby="tk_sure_ay_aciklama">
                                <?php foreach (UyelikYonetimi::TALEP_SURELERI as $ay): ?>
                                    <?php
                                    $tutar = $ay === 12 && $yillik > 0 ? $yillik : ($aylik > 0 ? $aylik * $ay : 0.0);
                                    ?>
                                    <option value="<?= $ay ?>"<?= $ay === 1 ? ' selected' : '' ?>><?= $ay ?> ay<?= $tutar > 0 ? ' — ' . e(Planlar::tutar($tutar)) : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text" id="tk_sure_ay_aciklama"><?= $aylik > 0 ? 'Tutar ödeme talimatıyla birlikte netleşir.' : 'Fiyatı yönetici ödeme talimatında bildirir.' ?></div>
                        </div>
                        <div class="col-12 col-md-7">
                            <label class="form-label" for="tk_talep_mesaj">Not <span class="cy-muted small">(isteğe bağlı)</span></label>
                            <textarea class="form-control" id="tk_talep_mesaj" name="mesaj" rows="2" maxlength="500"
                                      placeholder="Ör. fatura bilgisi, ödeme yapacağınız gün" aria-describedby="tk_talep_mesaj_aciklama"></textarea>
                            <div class="form-text" id="tk_talep_mesaj_aciklama">Yalnız yönetici görür. Kart bilgisi ya da parola yazmayın.</div>
                        </div>
                    </div>
                    <button type="submit" class="btn cy-btn cy-btn--primary mt-3"><?= icon('send', 'cy-icon cy-icon--sm') ?> Talep gönder</button>
                </form>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>

<?php if ($donemler !== []): ?>
    <!-- ÖDEME GEÇMİŞİ -->
    <section class="cy-card mb-3" aria-labelledby="tk-donem-baslik">
        <header class="cy-card__header">
            <h3 class="cy-section-title mb-0" id="tk-donem-baslik"><?= icon('clock', 'cy-icon cy-icon--sm') ?> Pro dönemleriniz</h3>
        </header>
        <div class="cy-table-wrap">
            <table class="table cy-table cy-table--cards w-100">
                <thead>
                    <tr>
                        <th scope="col" data-kart="ana">Dönem</th>
                        <th scope="col">Tutar</th>
                        <th scope="col">Durum</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($donemler as $d): ?>
                        <tr>
                            <td><?= e($tarih((string) $d['baslangic'])) ?> → <?= e($d['bitis'] !== null ? $tarih((string) $d['bitis']) : 'süresiz') ?></td>
                            <td><?= $d['tutar'] !== null ? e(Planlar::tutar((float) $d['tutar'], (string) $d['para_birimi'])) : '—' ?></td>
                            <td><?= match ((string) $d['durum']) {
                                'aktif' => '<span class="cy-pill is-ok">Aktif</span>',
                                'bitti' => '<span class="cy-pill">Bitti</span>',
                                default => '<span class="cy-pill is-danger">Kapatıldı</span>',
                            } ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php View::partial('Transkript::_parcalar/onay-penceresi'); ?>

<script src="<?= e(Varliklar::adres('transkript.js')) ?>" defer></script>
