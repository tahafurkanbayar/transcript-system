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
 *  @var array<string,mixed>   $durum
 * =====================================================================
 */

use Modules\Transkript\PanelAyarlari;
use Modules\Transkript\Services\KuyrukDurumu;
use Modules\Transkript\Varliklar;

$gruplar = [
    'Yapay zekâ ve transcript' => ['ai_model', 'altyazi_dili', 'en_uzun_dk'],
    'Kotalar'                  => ['gunluk_sinir', 'saatlik_sinir', 'gunluk_token_tavani', 'gunluk_maliyet_tavani'],
    'Maliyet tahmini (1M token)' => ['fiyat_giris', 'fiyat_cikis'],
];
$tanimli = static fn (bool $var): string => $var
    ? '<span class="cy-pill is-ok">Tanımlı</span>'
    : '<span class="cy-pill is-danger">Tanımsız</span>';
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
            </p>
            <?php if ($durum['kuyruk']['takildi']): ?>
                <p class="cy-panel__note is-warn mt-2 mb-0">İşçiyi başlatmak için proje klasöründe: <code><?= e(KuyrukDurumu::KOMUT_ELLE) ?></code></p>
            <?php endif; ?>
        </section>
    </div>
</div>
