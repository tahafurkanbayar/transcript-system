<?php
/**
 * =====================================================================
 *  GÖRÜNÜM: Transkript ayarları (panel/transkript/ayarlar, yönetici)
 * ---------------------------------------------------------------------
 *  Form PanelAyarlari::ALANLAR'dan çizilir. Boş alan config.php'deki
 *  varsayılana döner (yer tutucuda yazar). Sağda salt okunur durum:
 *  sağlayıcı, anahtarlar (yalnız tanımlı / tanımsız), kuyruk.
 *
 *  @var array<string,string>  $degerler
 *  @var array<string,string>  $varsayilanlar
 *  @var array<string,string>  $hatalar
 *  @var array<string,mixed>   $durum     zincir: AyarController::zincirTablosu
 *  @var list<array<string,mixed>> $cagrilar son 50 yapay zekâ çağrısı
 *
 *  Altta iki tablo: yapay zekâ sağlayıcıları (durum, açılış saati, son
 *  başarı / hata, bugünkü çağrı) ve son 50 çağrının günlüğü. Sağlayıcının
 *  kendi hata mesajı gösterilmez; yalnız kod ve Türkçe karşılığı.
 * =====================================================================
 */

use Modules\Transkript\Ai\YedekliSaglayici;

use Modules\Transkript\PanelAyarlari;
use Modules\Transkript\Services\KuyrukDurumu;
use Modules\Transkript\Varliklar;

$gruplar = [
    'Yapay zekâ ve transcript' => ['ai_model', 'altyazi_dili', 'en_uzun_dk'],
    'Kotalar'                  => ['gunluk_sinir', 'saatlik_sinir', 'gunluk_token_tavani', 'gunluk_maliyet_tavani'],
    'Maliyet tahmini (1M token)' => ['fiyat_giris', 'fiyat_cikis'],
    'Ana sayfa'                => ['ana_sayfa'],
    'Kuyruk'                   => ['tarayici_kuyruk'],
];
$tanimli = static fn (bool $var): string => $var
    ? '<span class="cy-pill is-ok">Tanımlı</span>'
    : '<span class="cy-pill is-danger">Tanımsız</span>';

/** Sağlayıcının hâli: rozet metni ve rengi. */
$hal = static fn (array $h): array => match ($h['hal']) {
    'atlandi'    => ['Atlandı (anahtar yok)', ''],
    'devre_disi' => ['Devre dışı', 'is-danger'],
    'dinleniyor' => ['Dinleniyor', 'is-warn'],
    default      => ['Hazır', 'is-ok'],
};
$sebepAdi = static fn (?string $kod): string => $kod === null ? '' : (YedekliSaglayici::SEBEPLER[$kod] ?? $kod);
$zaman    = static fn (?string $t): string => $t === null ? '—' : human_date($t);
$sayi     = static fn (int $n): string => number_format($n, 0, ',', '.');
?>
<link rel="stylesheet" href="<?= e(Varliklar::adres('transkript.css')) ?>">

<div class="tk-ust mb-3">
    <a href="<?= e(url('panel/transkript')) ?>" class="cy-link small"><?= icon('chevron', 'cy-icon cy-icon--sm tk-geri') ?> Analizler</a>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-8">
        <form method="post" action="<?= e(url('panel/transkript/ayarlar')) ?>" novalidate>
            <?= csrf_field() ?>

            <div class="cy-card">
                <div class="cy-card__body">
                    <?php foreach ($gruplar as $baslik => $alanlar): ?>
                        <h3 class="cy-section-title mt-1 mb-3"><?= e($baslik) ?></h3>
                        <div class="row g-3 mb-4">
                            <?php foreach ($alanlar as $alan): ?>
                                <?php
                                $tanim = PanelAyarlari::ALANLAR[$alan];
                                $id    = 'tk_ayar_' . $alan;
                                $hata  = $hatalar[$alan] ?? null;
                                ?>
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="<?= e($id) ?>">
                                        <?= e($tanim['etiket']) ?><?php if (isset($tanim['birim'])): ?> <span class="cy-muted small">(<?= e($tanim['birim']) ?>)</span><?php endif; ?>
                                    </label>
                                    <?php if ($tanim['tur'] === 'secim'): ?>
                                        <select class="form-select<?= $hata !== null ? ' is-invalid' : '' ?>" id="<?= e($id) ?>" name="<?= e($alan) ?>" aria-describedby="<?= e($id) ?>_aciklama">
                                            <?php foreach (PanelAyarlari::secenekler($alan) as $deger => $etiket): ?>
                                                <option value="<?= e((string) $deger) ?>"<?= (string) $deger === $degerler[$alan] ? ' selected' : '' ?>><?= e($etiket) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php else: ?>
                                        <input type="text" inputmode="<?= $tanim['tur'] === 'tamsayi' ? 'numeric' : 'decimal' ?>" maxlength="20"
                                               class="form-control<?= $hata !== null ? ' is-invalid' : '' ?>" id="<?= e($id) ?>" name="<?= e($alan) ?>"
                                               value="<?= e($degerler[$alan]) ?>" placeholder="<?= e('Varsayılan: ' . $varsayilanlar[$alan]) ?>"
                                               aria-describedby="<?= e($id) ?>_aciklama">
                                    <?php endif; ?>
                                    <?php if ($hata !== null): ?>
                                        <div class="invalid-feedback d-block" id="<?= e($id) ?>_aciklama"><?= e($hata) ?></div>
                                    <?php else: ?>
                                        <div class="form-text" id="<?= e($id) ?>_aciklama"><?= e($tanim['aciklama']) ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="cy-card__footer cy-save-bar d-flex flex-wrap gap-2 justify-content-between align-items-center">
                    <span class="cy-muted small cy-hide-xs">Boş bırakılan alan varsayılana (config.php) döner. Yeni analizlere uygulanır.</span>
                    <button type="submit" class="btn cy-btn cy-btn--primary"><?= icon('save', 'cy-icon cy-icon--sm') ?> Kaydet</button>
                </div>
            </div>
        </form>
    </div>

    <div class="col-12 col-xl-4">
        <section class="cy-panel">
            <h3 class="cy-panel__title mb-2"><?= icon('server', 'cy-icon cy-icon--sm') ?> Durum</h3>
            <dl class="tk-bilgi__liste">
                <dt>Yapay zekâ</dt><dd><?= e($durum['saglayici']) ?></dd>
                <dt>API anahtarı</dt><dd><?= $tanimli($durum['ai_anahtar']) ?></dd>
                <dt>Yedek sırası</dt><dd>
                    <?php if (count($durum['zincir']) > 1): ?>
                        <ol class="tk-zincir">
                            <?php foreach ($durum['zincir'] as $halka): ?>
                                <?php [$halMetni, $halRengi] = $hal($halka); ?>
                                <li>
                                    <strong><?= e($halka['etiket']) ?></strong>
                                    <span class="cy-pill <?= e($halRengi) ?>"><?= e($halMetni) ?><?= $halka['acilis'] !== null ? ' · ' . e($halka['acilis']) : '' ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                        <a class="cy-link small" href="#tk-saglayicilar">Ayrıntı ve çağrı günlüğü</a>
                    <?php else: ?>
                        <span class="cy-muted small">Yok. Kotası dolunca analiz kota yenilenene kadar bekler.</span>
                    <?php endif; ?>
                </dd>
                <dt>YouTube Data API</dt><dd><?= $tanimli($durum['yt_anahtar']) ?></dd>
                <dt>Transcript sırası</dt><dd class="cy-mono"><?= e($durum['transcript'] !== '' ? $durum['transcript'] : '—') ?></dd>
                <dt>İstem sürümü</dt><dd class="cy-mono"><?= e($durum['istem']) ?></dd>
                <dt>Kuyruk işçisi</dt><dd><?= $durum['kuyruk']['takildi']
                    ? '<span class="cy-pill is-danger">Çalışmıyor</span>'
                    : ($durum['kuyruk']['son_gorulme_sn'] !== null || $durum['kuyruk']['calisan'] > 0
                        ? '<span class="cy-pill is-ok">Görüldü</span>'
                        : '<span class="cy-pill">Henüz görülmedi</span>') ?></dd>
            </dl>
            <p class="cy-panel__note mb-0">
                Anahtarlar yalnızca sunucudaki <span class="cy-mono">.env</span> dosyasında durur; burada değeri gösterilmez ve değiştirilemez.
                Sağlayıcı <span class="cy-mono">AI_PROVIDER</span>, anahtar <span class="cy-mono">GEMINI_API_KEY</span> / <span class="cy-mono">CLAUDE_API_KEY</span>.
                Yedek: <span class="cy-mono">AI_PROVIDERS=gemini,openrouter,mistral,groq</span> ve her birinin anahtarı
                (<span class="cy-mono">OPENROUTER_API_KEY</span> …); biri kota ya da hata verirse sıradaki denenir.
            </p>
            <?php if ($durum['kuyruk']['takildi']): ?>
                <p class="cy-panel__note is-warn mt-2 mb-0">İşçiyi başlatmak için proje klasöründe: <code><?= e(KuyrukDurumu::KOMUT_ELLE) ?></code></p>
            <?php endif; ?>
        </section>
    </div>
</div>

<!-- YAPAY ZEKÂ SAĞLAYICILARI (yedek zinciri) -->
<section class="cy-card mt-3" id="tk-saglayicilar" aria-labelledby="tk-saglayicilar-baslik">
    <header class="cy-card__header d-flex flex-wrap gap-2 justify-content-between align-items-center">
        <div>
            <h3 class="cy-section-title mb-1" id="tk-saglayicilar-baslik"><?= icon('zap', 'cy-icon cy-icon--sm') ?> Yapay zekâ sağlayıcıları</h3>
            <p class="cy-muted small mb-0">Sırayla denenir. Dinlenen sağlayıcı açılış saatine kadar atlanır; hepsi dinlenirken analiz hata vermez, en erken açılışta kendiliğinden sürer.</p>
        </div>
        <form method="post" action="<?= e(url('panel/transkript/ayarlar/dinlenme-sifirla')) ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn cy-btn cy-btn--ghost cy-btn--sm" title="Anahtarı ya da modeli düzelttiyseniz beklemeden yeniden denensin">
                <?= icon('refresh', 'cy-icon cy-icon--sm') ?> Dinlenmeleri sıfırla
            </button>
        </form>
    </header>
    <div class="cy-table-wrap">
        <table class="table cy-table cy-table--cards w-100 tk-saglayici-tablo">
            <thead>
                <tr>
                    <th scope="col" data-kart="ana">Sağlayıcı</th>
                    <th scope="col">Durum</th>
                    <th scope="col">Son başarı</th>
                    <th scope="col">Son hata</th>
                    <th scope="col">Bugün</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($durum['zincir'] as $sira => $halka): ?>
                    <?php [$halMetni, $halRengi] = $hal($halka); ?>
                    <tr>
                        <td>
                            <strong><?= e(($sira + 1) . '. ' . $halka['etiket']) ?></strong>
                            <span class="cy-mono small d-block cy-muted"><?= e($halka['model'] !== '' ? $halka['model'] : '—') ?></span>
                        </td>
                        <td>
                            <span class="cy-pill <?= e($halRengi) ?>"><?= e($halMetni) ?></span>
                            <?php if ($halka['acilis'] !== null): ?>
                                <small class="d-block"><?= e($halka['sebep']) ?> · açılış <?= e($halka['acilis']) ?> (kalan <?= e((string) $halka['kalan_dk']) ?> dk)</small>
                            <?php endif; ?>
                        </td>
                        <td><small><?= e($zaman($halka['son_basari'])) ?></small></td>
                        <td>
                            <small><?= e($zaman($halka['son_hata'])) ?></small>
                            <?php if ($halka['son_hata_kodu'] !== null): ?>
                                <small class="d-block cy-muted"><?= e($sebepAdi($halka['son_hata_kodu'])) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><small><?= e($sayi((int) $halka['bugun_cagri'])) ?> çağrı · <?= e($sayi((int) $halka['bugun_token'])) ?> token</small></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- ÇAĞRI GÜNLÜĞÜ (son 50) -->
<section class="cy-card mt-3" aria-labelledby="tk-gunluk-baslik">
    <header class="cy-card__header">
        <h3 class="cy-section-title mb-1" id="tk-gunluk-baslik"><?= icon('clock', 'cy-icon cy-icon--sm') ?> Son 50 yapay zekâ çağrısı</h3>
    </header>
    <?php if ($cagrilar === []): ?>
        <div class="cy-empty"><p class="mb-0">Henüz yapay zekâ çağrısı yok.</p></div>
    <?php else: ?>
        <div class="cy-table-wrap">
            <table class="table cy-table cy-table--cards w-100 tk-gunluk">
                <thead>
                    <tr>
                        <th scope="col" data-kart="ana">Zaman</th>
                        <th scope="col">Sağlayıcı</th>
                        <th scope="col">Analiz</th>
                        <th scope="col">Süre</th>
                        <th scope="col">Token</th>
                        <th scope="col">Sonuç</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cagrilar as $c): ?>
                        <?php $basarili = $c['durum'] === 'basarili'; ?>
                        <tr>
                            <td><small class="cy-mono" title="<?= e((string) $c['created_at']) ?>"><?= e(date('d.m H:i:s', (int) strtotime((string) $c['created_at']))) ?></small></td>
                            <td>
                                <?= e((string) $c['saglayici']) ?>
                                <small class="cy-mono d-block cy-muted"><?= e((string) $c['model']) ?></small>
                            </td>
                            <td>
                                <?php if ($c['kaynak_id'] !== null): ?>
                                    <a class="cy-link" href="<?= e(url('panel/transkript/' . (int) $c['kaynak_id'])) ?>">#<?= (int) $c['ref_id'] ?></a>
                                <?php else: ?>
                                    <span class="cy-muted"><?= $c['ref_id'] !== null ? '#' . (int) $c['ref_id'] : '—' ?></span>
                                <?php endif; ?>
                            </td>
                            <td><small><?= e(number_format(((int) $c['sure_ms']) / 1000, 1, ',', '.')) ?> sn</small></td>
                            <td><small><?= e($sayi((int) $c['giris_token'] + (int) $c['cikis_token'])) ?></small></td>
                            <td>
                                <span class="cy-pill <?= $basarili ? 'is-ok' : 'is-danger' ?>">
                                    <?= e($basarili ? 'Başarılı' : $sebepAdi((string) ($c['hata_kodu'] ?? $c['durum']))) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
