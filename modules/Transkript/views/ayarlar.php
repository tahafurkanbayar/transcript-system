<?php
/**
 * =====================================================================
 *  GÖRÜNÜM: Transkript ayarları (panel/transkript/ayarlar, yönetici)
 * ---------------------------------------------------------------------
 *  Beş sekme (JS yoksa alt alta bölümler):
 *    Üyelik planları  Ücretsiz / Pro sınırları iki sütunlu tabloda,
 *                     fiyat, para birimi, ödeme talimatı
 *    Yapay zekâ       modeller, altyazı tercihi, sistemin en uzun videosu
 *    Bütçe ve maliyet herkes için günlük tavanlar, fiyat tahmini
 *    Site ve kuyruk   ana sayfa, tarayıcıdan kuyruk
 *    Durum            salt okunur: sağlayıcılar, anahtarlar (yalnız
 *                     tanımlı / tanımsız), kuyruk, son 50 çağrı
 *
 *  İlk dört sekme TEK formdur (bir "Kaydet"). Form PanelAyarlari::ALANLAR'dan
 *  çizilir; boş alan config.php'deki varsayılana döner (yer tutucuda
 *  yazar). Sağlayıcının kendi hata mesajı satırda gösterilmez (kod ve
 *  Türkçe karşılığı); maskeli kısa hali yalnız "Son hata"nın üzerinde.
 *
 *  @var array<string,string>  $degerler
 *  @var array<string,string>  $varsayilanlar
 *  @var array<string,string>  $hatalar
 *  @var array<string,mixed>   $durum     zincir: AyarController::zincirTablosu
 *  @var list<array<string,mixed>> $cagrilar son 50 yapay zekâ çağrısı
 * =====================================================================
 */

use App\Core\Auth;
use App\Core\View;
use Modules\Transkript\Ai\Ai;
use Modules\Transkript\Ai\YedekliSaglayici;
use Modules\Transkript\PanelAyarlari;
use Modules\Transkript\Services\KuyrukDurumu;
use Modules\Transkript\TranskriptPolicy;
use Modules\Transkript\Varliklar;

/* Sekmeler: ad => [başlık, simge, ne işe yarar]. */
$sekmeler = [
    'uyelik'     => ['Üyelik planları', 'star', 'Kayıt olan herkes Ücretsiz plandadır; Pro ücretli plandır (rolü "Pro Üye"). Buradaki sınırları üyeler Üyeliğim sayfasında ve fiyat tablosunda görür. Pro üyeliği Transkript → Üyelikler\'den verirsiniz.'],
    'yapay-zeka' => ['Yapay zekâ', 'zap', 'Raporu hangi modelin yazacağı, hangi altyazının kullanılacağı ve sistemin en uzun videosu. Değişiklik yeni analizlere uygulanır; biten raporlar değişmez.'],
    'butce'      => ['Bütçe ve maliyet', 'activity', 'Herkes için (yönetici dahil) günlük tavanlar ve maliyet tahmininde kullanılan fiyatlar. Ücretsiz katmanda gerçek ödeme yoktur; tahmin ücretli katman fiyatıyla yazılır.'],
    'site'       => ['Site ve kuyruk', 'globe', 'Sitenin ana sayfası ve kuyruk işçisi çalışmıyorken analizlerin nasıl ilerleyeceği.'],
    'durum'      => ['Durum', 'server', 'Salt okunur: sağlayıcıların anlık durumu, anahtarların tanımlı olup olmadığı, kuyruk işçisi ve son yapay zekâ çağrıları.'],
];
$alanlar = [
    // Zincirdeki her sağlayıcının modeli, sırasıyla (EVREN, Gemini = ai_model, OpenRouter …).
    // EVREN'in aşama modelleri EVREN modelinin hemen ardından.
    'yapay-zeka' => [...array_merge(...array_map(static fn (string $a): array => $a === 'model_evren' ? [$a, 'model_evren_bolum', 'model_evren_rapor'] : [$a],
        PanelAyarlari::modelAlanlari())), 'altyazi_dili', 'en_uzun_dk'],
    'butce'      => ['gunluk_token_tavani', 'gunluk_maliyet_tavani', 'fiyat_giris', 'fiyat_cikis'],
    'site'       => ['ana_sayfa', 'tarayici_kuyruk'],
];
/* Üyelik tablosu: satır => [Ücretsiz alanı, Pro alanı (null: "her zaman açık")]. */
$planSatirlari = [
    ['uye_analiz', null],
    ['uye_gunluk', 'pro_gunluk'],
    ['uye_saatlik', 'pro_saatlik'],
    ['uye_en_uzun_dk', 'pro_en_uzun_dk'],
    ['uye_yeniden_analiz', 'pro_yeniden_analiz'],
    ['uye_altyazi', 'pro_altyazi'],
];
$uyelikDiger = ['pro_fiyat_aylik', 'pro_fiyat_yillik', 'para_birimi', 'odeme_bilgisi'];

// Hatalı alan hangi sekmede? O sekme önce açılır (JS: data-ilk).
$ilkHata = '';
foreach ($hatalar as $alan => $_) {
    $ilkHata = isset(PanelAyarlari::ALANLAR[$alan]['plan']) || in_array($alan, $uyelikDiger, true)
        ? 'uyelik'
        : (string) (array_key_first(array_filter($alanlar, static fn (array $l): bool => in_array($alan, $l, true))) ?? '');
    break;
}

/**
 * Bir ayar alanının girdisi (etiket hariç): seçim, açık/kapalı, metin, model ya da sayı.
 * $kisa: plan tablosunda (dar sütun) yer tutucu yalnız varsayılan değerdir ("2", "30").
 */
$girdi = static function (string $alan, string $erisimEtiketi = '', bool $kisa = false) use ($degerler, $varsayilanlar, $hatalar): void {
    $tanim = PanelAyarlari::ALANLAR[$alan];
    $id    = 'tk_ayar_' . $alan;
    $hata  = $hatalar[$alan] ?? null;
    $sinif = $hata !== null ? ' is-invalid' : '';
    $aria  = ' aria-describedby="' . e($id) . '_aciklama"' . ($erisimEtiketi !== '' ? ' aria-label="' . e($erisimEtiketi) . '"' : '');

    if ($tanim['tur'] === 'secim' || $tanim['tur'] === 'acik_kapali') { ?>
        <select class="form-select<?= $sinif ?>" id="<?= e($id) ?>" name="<?= e($alan) ?>"<?= $aria ?>>
            <?php foreach (PanelAyarlari::secenekler($alan) as $deger => $etiket): ?>
                <option value="<?= e((string) $deger) ?>"<?= (string) $deger === $degerler[$alan] ? ' selected' : '' ?>><?= e($etiket) ?></option>
            <?php endforeach; ?>
        </select>
    <?php } elseif ($tanim['tur'] === 'metin') { ?>
        <textarea class="form-control<?= $sinif ?>" id="<?= e($id) ?>" name="<?= e($alan) ?>" rows="4" maxlength="<?= (int) ($tanim['en_cok_karakter'] ?? 1000) ?>"
                  placeholder="Ör. Banka: … · IBAN: TR.. · Alıcı: … · Açıklama: kullanıcı adınız"<?= $aria ?>><?= e($degerler[$alan]) ?></textarea>
    <?php } elseif ($tanim['tur'] === 'model') { ?>
        <?php /* Serbest model kimliği; öneriler datalist'te (ücretsiz modeller sık değişir). */ ?>
        <input type="text" maxlength="100" spellcheck="false" autocomplete="off" list="<?= e($id) ?>_oneriler"
               class="form-control cy-mono<?= $sinif ?>" id="<?= e($id) ?>" name="<?= e($alan) ?>"
               value="<?= e($degerler[$alan]) ?>" placeholder="<?= e('Varsayılan: ' . $varsayilanlar[$alan]) ?>"<?= $aria ?>>
        <datalist id="<?= e($id) ?>_oneriler">
            <?php foreach (Ai::modelOnerileri((string) $tanim['saglayici']) as $oneri): ?>
                <option value="<?= e($oneri) ?>"></option>
            <?php endforeach; ?>
        </datalist>
    <?php } else { ?>
        <div class="tk-girdi-birim">
            <input type="text" inputmode="<?= $tanim['tur'] === 'tamsayi' ? 'numeric' : 'decimal' ?>" maxlength="20"
                   class="form-control<?= $sinif ?>" id="<?= e($id) ?>" name="<?= e($alan) ?>"
                   value="<?= e($degerler[$alan]) ?>" placeholder="<?= e(($kisa ? '' : 'Varsayılan: ') . $varsayilanlar[$alan]) ?>"<?= $aria ?>>
            <?php if (isset($tanim['birim'])): ?><span class="tk-girdi-birim__ek"><?= e($tanim['birim']) ?></span><?php endif; ?>
        </div>
    <?php }
};

/* Model alanının rozeti: zincirdeki yeri ("Asıl sağlayıcı · 1. sırada") ve anahtarı yoksa uyarı. */
$zincirSirasi = array_flip(Ai::zincir());
$siraRozeti = static function (string $saglayici) use ($zincirSirasi): string {
    $sira = $zincirSirasi[$saglayici] ?? null;

    if ($sira === null) {
        return '<span class="cy-pill tk-ayar-rozet">Sırada değil</span>';
    }

    $html = $sira === 0
        ? '<span class="cy-pill is-ok tk-ayar-rozet">Asıl sağlayıcı · 1. sırada</span>'
        : '<span class="cy-pill tk-ayar-rozet">Yedek · ' . ($sira + 1) . '. sırada</span>';

    if (!Ai::zincirdeKullanilabilir($saglayici, $sira === 0)) {
        $html .= ' <span class="cy-pill is-warn tk-ayar-rozet" title="' . e(Ai::anahtarAdi($saglayici) . ' .env içinde tanımlı değil; bu sağlayıcı atlanır.') . '">Anahtar yok · atlanır</span>';
    }

    return $html;
};

/** Etiketli tam alan (sekmelerdeki iki sütunlu ızgara için). */
$alanCiz = static function (string $alan) use ($girdi, $hatalar, $siraRozeti): void {
    $tanim = PanelAyarlari::ALANLAR[$alan];
    $id    = 'tk_ayar_' . $alan;
    $hata  = $hatalar[$alan] ?? null; ?>
    <div class="col-12<?= $tanim['tur'] === 'metin' ? '' : ' col-md-6' ?>">
        <label class="form-label" for="<?= e($id) ?>">
            <?= e($tanim['etiket']) ?>
            <?php if (isset($tanim['saglayici'])): ?><?= $siraRozeti((string) $tanim['saglayici']) ?><?php endif; ?>
        </label>
        <?php $girdi($alan); ?>
        <?php if ($hata !== null): ?>
            <div class="invalid-feedback d-block" id="<?= e($id) ?>_aciklama"><?= e($hata) ?></div>
        <?php else: ?>
            <div class="form-text" id="<?= e($id) ?>_aciklama"><?= e($tanim['aciklama']) ?></div>
        <?php endif; ?>
    </div>
<?php };

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

<?php View::partial('Transkript::_parcalar/alt-menu', ['policy' => new TranskriptPolicy(Auth::user()), 'etkin' => 'ayarlar']); ?>

<nav class="tk-sekmeler js-tk-sekmeler" role="tablist" aria-label="Ayar bölümleri"<?= $ilkHata !== '' ? ' data-ilk="' . e($ilkHata) . '"' : '' ?>>
    <?php foreach ($sekmeler as $ad => [$baslik, $ikon]): ?>
        <a class="tk-sekme" href="#<?= e($ad) ?>" role="tab" id="tk-sekme-<?= e($ad) ?>" aria-controls="<?= e($ad) ?>">
            <?= icon($ikon, 'cy-icon cy-icon--sm') ?> <span><?= e($baslik) ?></span>
        </a>
    <?php endforeach; ?>
</nav>

<form method="post" action="<?= e(url('panel/transkript/ayarlar')) ?>" novalidate class="tk-ayar-form">
    <?= csrf_field() ?>
    <input type="hidden" name="sekme" value="" class="js-tk-sekme-girdi">

    <!-- ÜYELİK PLANLARI -->
    <section class="cy-card tk-sekme-panel" id="uyelik" role="tabpanel" aria-labelledby="tk-sekme-uyelik">
        <header class="cy-card__header">
            <h3 class="cy-section-title mb-1"><?= icon('star', 'cy-icon cy-icon--sm') ?> <?= e($sekmeler['uyelik'][0]) ?></h3>
            <p class="cy-muted small mb-0"><?= e($sekmeler['uyelik'][2]) ?></p>
        </header>
        <div class="cy-card__body">
            <div class="tk-plan-ayar" role="table" aria-label="Plan sınırları">
                <div class="tk-plan-ayar__bas" role="row">
                    <span role="columnheader">Ne?</span>
                    <span role="columnheader"><?= icon('user', 'cy-icon cy-icon--sm') ?> Ücretsiz</span>
                    <span role="columnheader"><?= icon('star', 'cy-icon cy-icon--sm') ?> Pro</span>
                </div>
                <?php foreach ($planSatirlari as [$uyeAlan, $proAlan]): ?>
                    <?php $tanim = PanelAyarlari::ALANLAR[$uyeAlan]; ?>
                    <div class="tk-plan-ayar__satir" role="row">
                        <div class="tk-plan-ayar__etiket" role="rowheader">
                            <strong><?= e($tanim['etiket']) ?></strong>
                            <small id="tk_ayar_<?= e($uyeAlan) ?>_aciklama"><?= e($tanim['aciklama']) ?></small>
                        </div>
                        <?php foreach (['Ücretsiz' => $uyeAlan, 'Pro' => $proAlan] as $planAdi => $alan): ?>
                            <div class="tk-plan-ayar__deger" role="cell" data-plan="<?= e($planAdi) ?>">
                                <?php if ($alan === null): ?>
                                    <span class="tk-plan-ayar__sabit"><?= icon('check', 'cy-icon cy-icon--sm') ?> Her zaman açık</span>
                                <?php else: ?>
                                    <?php $girdi($alan, $planAdi . ': ' . PanelAyarlari::ALANLAR[$alan]['etiket'], true); ?>
                                    <?php if (isset($hatalar[$alan])): ?>
                                        <div class="invalid-feedback d-block"><?= e($hatalar[$alan]) ?></div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <p class="cy-muted small mt-2 mb-0">Boş kutu varsayılanı kullanır (gri yazan değer). 0 = sınır yok.</p>

            <h4 class="tk-alt-baslik mt-4">Fiyat ve ödeme</h4>
            <p class="cy-muted small">Çevrim içi ödeme yok: üye talep gönderir, aşağıdaki talimatla öder, siz Üyelikler'den onaylarsınız.</p>
            <div class="row g-3">
                <?php foreach ($uyelikDiger as $alan) { $alanCiz($alan); } ?>
            </div>
        </div>
    </section>

    <?php foreach ($alanlar as $ad => $liste): ?>
        <section class="cy-card tk-sekme-panel" id="<?= e($ad) ?>" role="tabpanel" aria-labelledby="tk-sekme-<?= e($ad) ?>">
            <header class="cy-card__header">
                <h3 class="cy-section-title mb-1"><?= icon($sekmeler[$ad][1], 'cy-icon cy-icon--sm') ?> <?= e($sekmeler[$ad][0]) ?></h3>
                <p class="cy-muted small mb-0"><?= e($sekmeler[$ad][2]) ?></p>
            </header>
            <div class="cy-card__body">
                <div class="row g-3">
                    <?php foreach ($liste as $alan) { $alanCiz($alan); } ?>
                </div>
            </div>
        </section>
    <?php endforeach; ?>

    <div class="tk-kaydet-cubugu js-tk-kaydet">
        <span class="cy-muted small">Boş bırakılan alan varsayılana döner. Değişiklik yeni analizlere uygulanır.</span>
        <button type="submit" class="btn cy-btn cy-btn--primary"><?= icon('save', 'cy-icon cy-icon--sm') ?> Kaydet</button>
    </div>
</form>

<!-- DURUM (salt okunur) -->
<div class="tk-sekme-panel" id="durum" role="tabpanel" aria-labelledby="tk-sekme-durum">
<div class="row g-3">
    <div class="col-12 col-xl-5">
        <section class="cy-panel h-100">
            <h3 class="cy-panel__title mb-2"><?= icon('server', 'cy-icon cy-icon--sm') ?> Genel durum</h3>
            <dl class="tk-bilgi__liste">
                <dt>Yapay zekâ</dt><dd><?= e($durum['saglayici']) ?></dd>
                <dt>API anahtarları</dt><dd>
                    <?php /* Zincirdeki her sağlayıcı: değişkenin adı ve tanımlı mı (değer asla). */ ?>
                    <ul class="tk-anahtarlar">
                        <?php foreach ($durum['zincir'] as $halka): ?>
                            <li>
                                <span><?= e($halka['etiket']) ?></span>
                                <?php if (($halka['anahtar_adi'] ?? '') !== ''): ?><span class="cy-mono small cy-muted"><?= e($halka['anahtar_adi']) ?></span><?php endif; ?>
                                <?= $tanimli((bool) $halka['tanimli']) ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </dd>
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
                    <?php else: ?>
                        <span class="cy-muted small">Yok. Kotası dolunca analiz kota yenilenene kadar bekler.</span>
                    <?php endif; ?>
                </dd>
                <dt>YouTube Data API</dt><dd><?= $tanimli($durum['yt_anahtar']) ?></dd>
                <dt>Transcript sırası</dt><dd class="cy-mono"><?= e($durum['transcript'] !== '' ? $durum['transcript'] : '—') ?></dd>
                <dt>İstem sürümü</dt><dd class="cy-mono"><?= e($durum['istem']) ?></dd>
                <?php
                $kuyruk     = $durum['kuyruk'];
                $sonGorulme = $kuyruk['son_gorulme_sn'];
                $isciVar    = $sonGorulme !== null && $sonGorulme <= KuyrukDurumu::ESIK_SN;
                ?>
                <dt>Kuyruk işçisi</dt>
                <dd>
                    <?= $kuyruk['takildi']
                        ? '<span class="cy-pill is-danger">Çalışmıyor</span>'
                        : ($isciVar || (int) ($kuyruk['calisan'] ?? 0) > 0 ? '<span class="cy-pill is-ok">Çalışıyor</span>'
                            : ($sonGorulme !== null ? '<span class="cy-pill is-warn">Görülmüyor</span>' : '<span class="cy-pill">Henüz görülmedi</span>')) ?>
                    <small class="d-block">
                        Son görülme: <?= $sonGorulme === null ? 'hiç' : e($sonGorulme < 60 ? $sonGorulme . ' sn önce' : ($sonGorulme < 3600 ? intdiv($sonGorulme, 60) . ' dk önce' : human_date(date('Y-m-d H:i:s', time() - $sonGorulme)))) ?>
                        · bekleyen <?= (int) ($kuyruk['bekleyen'] ?? 0) ?> · çalışan <?= (int) ($kuyruk['calisan'] ?? 0) ?>
                        · <span<?= ($kuyruk['takili'] ?? 0) > 0 ? ' class="text-danger" title="Nabzı 5 dakikadır gelmeyen iş: işçi bir sonraki turda kuyruğa geri bırakır (kaldığı yerden sürer)."' : '' ?>>takılı <?= (int) ($kuyruk['takili'] ?? 0) ?></span>
                    </small>
                </dd>
            </dl>
            <?php if (!$isciVar && (int) ($kuyruk['calisan'] ?? 0) === 0): ?>
                <div class="tk-komutlar mt-2">
                    <p class="small mb-1">Kalıcı işçi (Windows / XAMPP / Laragon): proje klasöründeki <code><?= e(KuyrukDurumu::KOMUT_KUR) ?></code> dosyasına bir kez çift tıklayın. Oturum açılınca gizli başlar, kapanırsa 5 dakika içinde kendiliğinden yeniden başlar.</p>
                    <?php foreach ([['Tek seferlik (komut satırı)', KuyrukDurumu::KOMUT_ELLE], ['Pencerede sürekli', KuyrukDurumu::KOMUT_WINDOWS], ['Sunucuda cron', KuyrukDurumu::KOMUT_CRON]] as [$komutAdi, $komut]): ?>
                        <div class="tk-komut">
                            <span class="small cy-muted"><?= e($komutAdi) ?></span>
                            <code class="cy-mono"><?= e($komut) ?></code>
                            <button type="button" class="btn cy-btn cy-btn--ghost cy-btn--sm js-tk-kopyala" data-kopya="<?= e($komut) ?>" aria-label="<?= e($komutAdi . ' komutunu kopyala') ?>"><?= icon('copy', 'cy-icon cy-icon--sm') ?> Kopyala</button>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
    <div class="col-12 col-xl-7">
        <section class="cy-panel h-100">
            <h3 class="cy-panel__title mb-2"><?= icon('lock', 'cy-icon cy-icon--sm') ?> Anahtarlar nerede?</h3>
            <p class="cy-panel__note mb-0">
                API anahtarları yalnızca sunucudaki <span class="cy-mono">.env</span> dosyasında durur; burada değeri gösterilmez ve değiştirilemez.
                Asıl sağlayıcı <span class="cy-mono">AI_PROVIDER</span> (varsayılan <span class="cy-mono">evren</span>), anahtarlar
                <span class="cy-mono">EVREN_API_KEY</span> / <span class="cy-mono">GEMINI_API_KEY</span> / <span class="cy-mono">CLAUDE_API_KEY</span>.
                Yedek sırası şu an <span class="cy-mono">AI_PROVIDERS=<?= e(implode(',', array_column($durum['zincir'], 'ad'))) ?></span>
                ve her birinin anahtarı (<span class="cy-mono">OPENROUTER_API_KEY</span> …); biri kota ya da hata verirse sıradaki denenir.
                Kullanılabilecek adlar: <span class="cy-mono"><?= e(implode(', ', Ai::saglayiciAdlari())) ?></span>.
            </p>
            <p class="cy-panel__note mb-0 mt-2">
                EVREN'in LLM anahtarı <span class="cy-mono">evren_llm_</span> ile başlar. Kullanım şartları anahtar başınadır ve ilk
                çağrıdan önce bir kez onaylanır: aşağıdaki sağlayıcı tablosunda EVREN satırındaki <strong>Şartları oku ve onayla</strong>.
                Onaylanmamışsa EVREN bir saat atlanır, sıradaki çalışır.
            </p>
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
        <?php
        /* Günlük kotası dolan sağlayıcıyı sıfırlamak işe yaramaz: ilk analizde
         * aynı hatayı alıp yeniden dinlenir ve o ana dek ekran yanlışlıkla
         * "Hazır" der (cowork 5. tur A11, 6. tur K2). Varsayılan olarak
         * korunur; yalnız kutu işaretlenirse sıfırlanır (ücretli katman). */
        $kotaDolu = array_values(array_filter($durum['zincir'], static fn (array $h): bool => ($h['kod'] ?? '') === 'kota_doldu'));
        $sifirlaOnay = 'Dinlenen ve devre dışı sağlayıcılar hemen yeniden denenecek. Anahtarı ya da modeli düzelttiyseniz kullanın.';

        if ($kotaDolu !== []) {
            $sifirlaOnay .= ' ' . implode(' ', array_map(
                static fn (array $h): string => $h['etiket'] . ' günlük kotası ' . $h['acilis'] . ' civarında açılır;',
                $kotaDolu
            )) . ' kutuyu işaretlemediyseniz o sıfırlanmaz.';
        }
        ?>
        <form method="post" action="<?= e(url('panel/transkript/ayarlar/dinlenme-sifirla')) ?>" class="tk-sifirla">
            <?= csrf_field() ?>
            <?php if ($kotaDolu !== []): ?>
                <label class="form-check small mb-0" title="Anahtara ücretli katman eklediyseniz">
                    <input class="form-check-input" type="checkbox" name="kota_dahil" value="1">
                    <span class="form-check-label">Günlük kotası dolanları da sıfırla</span>
                </label>
            <?php endif; ?>
            <button type="submit" class="btn cy-btn cy-btn--ghost cy-btn--sm js-tk-form-onay" title="Anahtarı ya da modeli düzelttiyseniz beklemeden yeniden denensin"
                    data-onay="<?= e($sifirlaOnay) ?>" data-onay-baslik="Dinlenmeleri sıfırla" data-onay-dugme="Evet, sıfırla">
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
                            <?php /* "auto": gerçekte hangi model yanıtladı (son başarılı çağrı), tek satır. */ ?>
                            <?php $gorunenModel = (string) ($halka['gorunen_model'] ?? $halka['model']); ?>
                            <span class="cy-mono small d-block cy-muted"<?= $gorunenModel !== $halka['model'] ? ' title="Son başarılı çağrıda yanıt veren model"' : '' ?>><?= e($gorunenModel !== '' ? $gorunenModel : '—') ?></span>
                        </td>
                        <td>
                            <span class="cy-pill <?= e($halRengi) ?>"><?= e($halMetni) ?></span>
                            <?php if ($halka['acilis'] !== null): ?>
                                <small class="d-block"><?= e($halka['sebep']) ?> · açılış <?= e($halka['acilis']) ?> (kalan <?= e((string) $halka['kalan_dk']) ?> dk)</small>
                            <?php endif; ?>
                            <?php if ($halka['ad'] === 'evren'): ?>
                                <?php
                                /* Şartlar: panelden onaylandıysa sürüm ve tarih; değilse (ya da son
                                 * çağrı şart yüzünden reddedildiyse) "Şartları oku ve onayla". */
                                $sartKaydi = $durum['evren_sart'] ?? null;
                                $gereken   = (int) ($durum['evren_sart_gereken'] ?? 0);
                                // EVREN yeni esaslı sürüm yayımladıysa (403'ün istediği > onaylı) yeniden onay gerekir.
                                $sartReddi = ($halka['kod'] ?? '') === 'sartlar_onaylanmadi' || ($sartKaydi !== null && $gereken > (int) $sartKaydi['surum']);
                                ?>
                                <?php if ($sartKaydi !== null && !$sartReddi): ?>
                                    <small class="tk-sart-durum">Şartlar: <span class="cy-pill is-ok">Onaylı</span> v<?= (int) $sartKaydi['surum'] ?><?= $sartKaydi['tarih'] !== '' ? ' · ' . e(date('d.m.Y H:i', (int) strtotime($sartKaydi['tarih']))) : '' ?>
                                        · <a class="cy-link" href="<?= e(url('panel/transkript/ayarlar/evren-sartlar')) ?>">kontrol et</a></small>
                                <?php elseif ($halka['tanimli']): ?>
                                    <?php if ($sartKaydi !== null && $gereken > (int) $sartKaydi['surum']): ?>
                                        <small class="tk-sart-durum"><span class="cy-pill is-warn">Yeni şart sürümü v<?= $gereken ?> onay bekliyor</span> (onaylı: v<?= (int) $sartKaydi['surum'] ?>)</small>
                                    <?php endif; ?>
                                    <a class="btn cy-btn cy-btn--primary cy-btn--sm mt-2" href="<?= e(url('panel/transkript/ayarlar/evren-sartlar')) ?>"><?= icon('lock', 'cy-icon cy-icon--sm') ?> Şartları oku ve onayla</a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td><small><?= e($zaman($halka['son_basari'])) ?></small></td>
                        <td<?= ($halka['son_hata_ayrinti'] ?? null) !== null ? ' title="' . e((string) $halka['son_hata_ayrinti']) . '"' : '' ?><?= !empty($halka['hata_cozuldu']) ? ' class="tk-hata-cozuldu"' : '' ?>>
                            <small><?= e($zaman($halka['son_hata'])) ?><?= !empty($halka['hata_cozuldu']) ? ' (çözüldü)' : '' ?></small>
                            <?php if ($halka['son_hata_kodu'] !== null): ?>
                                <small class="d-block cy-muted"><?= e($sebepAdi($halka['son_hata_kodu'])) ?><?= ($halka['son_hata_ayrinti'] ?? null) !== null ? ' ' . icon('info', 'cy-icon cy-icon--sm') : '' ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <small><?= e($sayi((int) $halka['bugun_cagri'])) ?> çağrı · <?= e($sayi((int) $halka['bugun_token'])) ?> token</small>
                            <?php $kota = $halka['ad'] === 'evren' ? ($durum['evren_kota'] ?? null) : null; ?>
                            <?php if ($kota !== null && (int) $kota['limit'] > 0 && (int) $kota['kalan'] < 0.1 * (int) $kota['limit']): ?>
                                <span class="cy-pill is-warn d-inline-block mt-1" title="Kota 03:00'te yenilenir; o zamana kadar zincirde sıradaki sağlayıcı kullanılır.">Günlük kotanın %10'undan azı kaldı</span>
                            <?php endif; ?>
                            <?php if ($halka['ad'] === 'evren'): ?>
                                <?php $ucretsizSon = $durum['evren_ucretsiz_son'] ?? null; ?>
                                <small class="d-block cy-muted" title="Fiyatlar GET /v1/models'ten (CR / 1M token) günde bir okunur.">
                                    <?= !empty($durum['evren_ucretli']) ? 'Ücretli dönem: maliyet CR cinsinden' : 'Ücretsiz: ' . e($ucretsizSon !== null ? date('d.m.Y', (int) strtotime((string) $ucretsizSon)) : '1 Kasım 2026') . ' sonrası ücretli' ?><?= isset($durum['evren_aylik_tahmin']) ? ' · son 7 gün ' . e($sayi((int) $durum['evren_aylik_tahmin']['token_7gun'])) . ' token (aylık ≈ ' . e($sayi((int) $durum['evren_aylik_tahmin']['token_ay'])) . ')' : '' ?>
                                </small>
                            <?php endif; ?>
                            <?php if ($kota !== null): ?>
                                <small class="d-block cy-muted" title="Son EVREN yanıtının bildirdiği; 03:00'te yenilenir">
                                    Kalan günlük kota: <?= e($sayi((int) $kota['kalan'])) ?><?= (int) $kota['limit'] > 0 ? ' / ' . e($sayi((int) $kota['limit'])) : '' ?> token
                                    (<?= e(date('H:i', (int) $kota['zaman'])) ?> itibarıyla)
                                </small>
                            <?php endif; ?>
                        </td>
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
                            <?php
                            /* Zaman: çağrının KENDİ başlangıcı (0.10.0). Model: istenen → yanıttaki (aynıysa tek). */
                            $istenen = (string) ($c['istenen_model'] ?? '');
                            $yanitModeli = (string) $c['model'];
                            $modelMetni = $istenen === '' || $istenen === $yanitModeli || str_starts_with($yanitModeli, $istenen . ' → ') ? $yanitModeli : $istenen . ' → ' . $yanitModeli;
                            $onbellek = (int) ($c['onbellek_token'] ?? 0);
                            $tokenAyrinti = 'giriş ' . $sayi((int) $c['giris_token']) . ' · çıkış ' . $sayi((int) $c['cikis_token'])
                                . (($c['dusunme_token'] ?? null) !== null ? ' · düşünme ' . $sayi((int) $c['dusunme_token']) : '')
                                . ($onbellek > 0 ? ' · önbellek ' . $sayi($onbellek) : '');
                            ?>
                            <td><small class="cy-mono" title="<?= e('Başlangıç ' . (string) $c['created_at'] . (($c['bitis_at'] ?? null) !== null ? ' · bitiş ' . (string) $c['bitis_at'] : '')) ?>"><?= e(date('d.m H:i:s', (int) strtotime((string) $c['created_at']))) ?></small></td>
                            <td>
                                <?= e((string) $c['saglayici']) ?>
                                <?php if (($c['asama'] ?? '') !== ''): ?><small class="cy-muted"> · <?= e($c['asama'] === 'map' ? 'bölüm' : 'rapor') ?></small><?php endif; ?>
                                <small class="cy-mono d-block cy-muted"><?= e($modelMetni) ?></small>
                            </td>
                            <td>
                                <?php /* Analiz sayfasının adresindeki numara (kaynak); iş (analiz) no üzerine gelince. */ ?>
                                <?php if ($c['kaynak_id'] !== null): ?>
                                    <a class="cy-link" href="<?= e(url('panel/transkript/' . (int) $c['kaynak_id'])) ?>" title="İş no: <?= (int) $c['ref_id'] ?>">#<?= (int) $c['kaynak_id'] ?></a>
                                <?php else: ?>
                                    <span class="cy-muted" title="İş no"><?= $c['ref_id'] !== null ? 'İş #' . (int) $c['ref_id'] : '—' ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <small<?= ($c['ilk_token_ms'] ?? null) !== null ? ' title="İlk token ' . e(number_format(((int) $c['ilk_token_ms']) / 1000, 1, ',', '.')) . ' sn' . (!empty($c['akis']) ? ' · akış' : '') . '"' : '' ?>><?= e(number_format(((int) $c['sure_ms']) / 1000, 1, ',', '.')) ?> sn</small>
                            </td>
                            <td>
                                <small title="<?= e($tokenAyrinti) ?>"><?= e($sayi((int) $c['giris_token'] + (int) $c['cikis_token'])) ?></small>
                                <?php if ($onbellek > 0 && (int) $c['giris_token'] > 0): ?>
                                    <small class="d-block cy-muted">önbellek %<?= (int) round(100 * $onbellek / (int) $c['giris_token']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="cy-pill <?= $basarili ? 'is-ok' : 'is-danger' ?>"<?= !$basarili && ($c['hata_ayrinti'] ?? null) !== null ? ' title="' . e((string) $c['hata_ayrinti']) . '"' : '' ?>>
                                    <?= e($basarili ? 'Başarılı' : $sebepAdi((string) ($c['hata_kodu'] ?? $c['durum']))) ?>
                                </span>
                                <?php if (($c['bitis_nedeni'] ?? '') !== '' && $c['bitis_nedeni'] !== 'stop'): ?>
                                    <small class="d-block cy-muted cy-mono">finish: <?= e((string) $c['bitis_nedeni']) ?></small>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
</div>

<?php View::partial('Transkript::_parcalar/onay-penceresi'); ?>

<script src="<?= e(Varliklar::adres('transkript.js')) ?>" defer></script>
