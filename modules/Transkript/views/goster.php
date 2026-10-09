<?php
/**
 * =====================================================================
 *  GÖRÜNÜM: Transkript – analiz raporu
 * ---------------------------------------------------------------------
 *  $this->view('Transkript::goster', ...) ile çağrılır.
 *
 *  Yukarıdan aşağıya: kaynak kartı, (sürüyorsa) aşama ilerlemesi, kısa
 *  özet, açılır bölümler (detaylı özet, konular, önemli noktalar, zaman
 *  çizelgesi, teknik bilgiler, fikirler, iddialar, öneriler,
 *  transcript). Sağda yapışkan YouTube oynatıcısı; zaman damgasına
 *  tıklayınca o saniyeye atlar. Mobilde oynatıcı gizlenir, damga
 *  youtu.be/ID?t=… bağlantısı olarak YouTube'u açar.
 *
 *  AI ve transcript metni YALNIZCA e() ile basılır; JS textContent
 *  kullanır (CLAUDE.md, kural 7).
 *
 *  @var array<string,mixed>                 $kaynak
 *  @var array<string,mixed>                 $veri    RaporVerisi::getir()
 *  @var Modules\Transkript\TranskriptPolicy $policy
 *  @var string                              $origin  gömülü oynatıcı için
 *  @var array<string,mixed>                 $kuyruk  KuyrukDurumu::getir()
 *  @var array<string,mixed>|null            $surenAnaliz iptal düğmesi için (kullanici_id)
 * =====================================================================
 */

use App\Core\View;
use Modules\Transkript\Ayarlar;
use Modules\Transkript\Services\DisaAktarim;
use Modules\Transkript\Services\RaporVerisi;
use Modules\Transkript\Varliklar;

$kaynakId = (int) $kaynak['id'];
$youtube  = $kaynak['tur'] === 'youtube';
$videoId  = (string) $kaynak['dis_kimlik'];
$durum    = $veri['durum'];
$rapor    = $veri['rapor'];
$analiz   = $veri['analiz'];
$suruyor  = !in_array($durum['durum'], ['tamamlandi', 'hata', 'yok'], true);
$resim    = (string) ($kaynak['kucuk_resim'] ?? '');

/** Tıklanabilir zaman damgası; zamanı yoksa boş. */
$zaman = static function (mixed $at) use ($youtube, $videoId): string {
    $sn = RaporVerisi::saniye($at);

    if ($sn === null) {
        return '';
    }

    if (!$youtube) {
        return '<span class="tk-zaman">' . e((string) $at) . '</span>';
    }

    return '<a class="tk-zaman" href="' . e(RaporVerisi::youtubeBaglantisi($videoId, $sn)) . '" data-sn="' . $sn . '"'
        . ' target="_blank" rel="noopener" title="Videoda bu ana git">' . e((string) $at) . '</a>';
};

/** Paragraflara bölünmüş düz metin. */
$paragraflar = static function (string $metin): string {
    $html = '';

    foreach (preg_split('/\n{2,}/', trim($metin)) ?: [] as $p) {
        if (trim($p) !== '') {
            $html .= '<p>' . nl2br(e(trim($p)), false) . '</p>';
        }
    }

    return $html;
};

$bolumSayisi = static fn (string $alan): int => is_array($rapor[$alan] ?? null) ? count($rapor[$alan]) : 0;
$asamalar = [
    'kaynak'      => 'Video bilgisi',
    'transkript'  => 'Transcript',
    'parca'       => 'Bölümlerin analizi',
    'birlestirme' => 'Birleştirme ve rapor',
];
$asamaSirasi = array_keys($asamalar);
$simdiki = (string) ($durum['asama'] ?? 'kaynak');
$simdiki = $simdiki === 'rapor' ? 'birlestirme' : $simdiki;
$simdikiSira = array_search($simdiki, $asamaSirasi, true);
$simdikiSira = $simdikiSira === false ? 0 : $simdikiSira;
$onemliSayisi = $rapor !== null
    ? count(array_filter((array) ($rapor['important_points'] ?? []), static fn (mixed $n): bool => in_array($n['importance'] ?? '', ['critical', 'important'], true)))
    : 0;
$bolumListesi = $rapor !== null ? DisaAktarim::bolumListesi($rapor, $kaynak['sure_sn'] !== null ? (int) $kaynak['sure_sn'] : null) : '';
$indir = static fn (string $bicim): string => url('panel/transkript/' . $kaynakId . '/indir/' . $bicim);
?>
<link rel="stylesheet" href="<?= e(Varliklar::adres('transkript.css')) ?>">

<div class="tk-ust mb-3">
    <a href="<?= e(url('panel/transkript')) ?>" class="cy-link small"><?= icon('chevron', 'cy-icon cy-icon--sm tk-geri') ?> Analizler</a>
</div>

<div class="row g-3 tk-rapor" id="tk_rapor"
     data-kaynak="<?= $kaynakId ?>"
     data-transkript-url="<?= e(url('panel/transkript/' . $kaynakId . '/transkript')) ?>"
     data-video="<?= $youtube ? e($videoId) : '' ?>">

    <!-- SOL: rapor -->
    <div class="col-12 col-lg-8 order-1 order-md-2 order-lg-1">

        <!-- KAYNAK KARTI -->
        <section class="cy-panel mb-3 tk-kart">
            <?php if (str_starts_with($resim, 'https://i.ytimg.com/')): ?>
                <img class="tk-kart__resim" src="<?= e($resim) ?>" alt="" width="160" height="90">
            <?php endif; ?>
            <div class="tk-kart__govde">
                <h2 class="tk-kart__baslik"><?= e((string) ($kaynak['baslik'] ?: $videoId)) ?></h2>
                <p class="tk-kart__meta">
                    <?php if ((string) ($kaynak['kanal_adi'] ?? '') !== ''): ?>
                        <span><?= icon('user', 'cy-icon cy-icon--sm') ?> <?= e((string) $kaynak['kanal_adi']) ?></span>
                    <?php endif; ?>
                    <?php if ($kaynak['yayin_tarihi'] !== null): ?>
                        <span><?= icon('calendar', 'cy-icon cy-icon--sm') ?> <?= e(date('d.m.Y', (int) strtotime((string) $kaynak['yayin_tarihi']))) ?></span>
                    <?php endif; ?>
                    <span><?= icon('clock', 'cy-icon cy-icon--sm') ?> <?= e(RaporVerisi::sure($kaynak['sure_sn'] !== null ? (int) $kaynak['sure_sn'] : null)) ?></span>
                    <?php if ($veri['transcript'] !== null): ?>
                        <span title="Transcript kaynağı"><?= icon('file', 'cy-icon cy-icon--sm') ?>
                            <?= e(RaporVerisi::dilAdi((string) $veri['transcript']['dil'])) ?>
                            <?= $veri['transcript']['otomatik_mi'] ? 'otomatik altyazı' : 'altyazı' ?></span>
                    <?php endif; ?>
                    <?php if ($rapor !== null): ?>
                        <span title="Kısa özet, konular ve önemli noktalar"><?= icon('book', 'cy-icon cy-icon--sm') ?> ~<?= RaporVerisi::okumaDakikasi($rapor) ?> dk okuma</span>
                    <?php endif; ?>
                </p>
                <p class="tk-kart__eylem">
                    <?php if ($youtube): ?>
                        <a class="btn cy-btn cy-btn--ghost cy-btn--sm" href="<?= e(RaporVerisi::youtubeBaglantisi($videoId, 0)) ?>" target="_blank" rel="noopener">
                            <?= icon('external', 'cy-icon cy-icon--sm') ?> YouTube'da aç
                        </a>
                    <?php endif; ?>
                    <?php if ($kaynak['durum'] !== 'normal'): ?>
                        <span class="cy-pill is-danger"><?= $kaynak['durum'] === 'gizli' ? 'Video gizli' : 'Video kaldırılmış' ?></span>
                    <?php endif; ?>
                </p>
            </div>
        </section>

        <?php if ($suruyor): ?>
            <?php View::partial('Transkript::_parcalar/kuyruk-uyarisi', ['kuyruk' => $kuyruk, 'policy' => $policy]); ?>
        <?php endif; ?>

        <!-- İLERLEME (analiz sürerken; 3 sn'de bir güncellenir) -->
        <section class="cy-panel mb-3 tk-ilerleme" id="tk_ilerleme"
                 data-url="<?= e(url('panel/transkript/ilerleme/' . $kaynakId)) ?>"
                 data-izle="<?= $suruyor ? '1' : '0' ?>"<?= $suruyor ? '' : ' hidden' ?>>
            <header class="cy-panel__head">
                <h3 class="cy-panel__title"><?= icon('activity', 'cy-icon cy-icon--sm') ?> <span class="js-tk-etiket"><?= e((string) $durum['etiket']) ?></span></h3>
                <strong class="cy-mono js-tk-yuzde">%<?= (int) $durum['ilerleme'] ?></strong>
            </header>
            <span class="cy-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) $durum['ilerleme'] ?>" aria-label="Analiz ilerlemesi">
                <span class="cy-progress__bar js-tk-cubuk" style="width: <?= (int) $durum['ilerleme'] ?>%"></span>
            </span>
            <p class="tk-sirada cy-muted js-tk-sirada"<?= ($durum['sirada'] ?? null) !== null ? '' : ' hidden' ?>><?= icon('clock', 'cy-icon cy-icon--sm') ?> <span class="js-tk-sirada-metin"><?= e((string) ($durum['sirada'] ?? '')) ?></span></p>
            <ol class="tk-asamalar">
                <?php foreach ($asamalar as $anahtar => $ad): ?>
                    <?php $sira = array_search($anahtar, $asamaSirasi, true); ?>
                    <li class="tk-asama <?= $sira < $simdikiSira ? 'is-bitti' : ($sira === $simdikiSira ? 'is-suruyor' : '') ?>" data-asama="<?= e($anahtar) ?>">
                        <span class="tk-asama__isaret" aria-hidden="true"></span>
                        <span><?= e($ad) ?><?php if ($anahtar === 'parca'): ?> <small class="cy-muted js-tk-parca"><?= (int) $durum['parca']['toplam'] > 0 ? e($durum['parca']['biten'] . ' / ' . $durum['parca']['toplam']) : '' ?></small><?php endif; ?></span>
                    </li>
                <?php endforeach; ?>
            </ol>
            <p class="cy-panel__note mb-0">Sayfayı kapatabilirsiniz; analiz arka planda sürer.<?= $rapor !== null ? ' Yeni rapor hazır olana kadar aşağıda önceki rapor görünür.' : '' ?></p>
        </section>

        <?php if ($durum['durum'] === 'hata' && ($durum['hata']['kod'] ?? null) === 'iptal_edildi'): ?>
            <div class="cy-panel mb-3 tk-uyari" role="status">
                <strong><?= icon('x-circle', 'cy-icon cy-icon--sm') ?> <?= $rapor !== null ? 'Son analiz iptal edildi.' : 'Analiz iptal edildi.' ?></strong>
                <?php if ($rapor !== null): ?><span>Aşağıdaki rapor önceki başarılı analizden.</span><?php endif; ?>
            </div>
        <?php elseif ($durum['durum'] === 'hata'): ?>
            <div class="cy-panel cy-panel--warn mb-3 tk-uyari" role="alert">
                <strong><?= icon('alert', 'cy-icon cy-icon--sm') ?> <?= $rapor !== null ? 'Son analiz tamamlanamadı.' : 'Analiz tamamlanamadı.' ?></strong>
                <?php /* İç neden (kota, servis) yalnız yöneticiye ve başlatana; bkz. KaynakDurumu. */ ?>
                <span><?= e((string) ($durum['hata']['mesaj'] ?? '')) ?></span>
                <?php if ($rapor !== null): ?><span>Aşağıdaki rapor önceki başarılı analizden.</span><?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($rapor === null): ?>
            <?php if (!$suruyor): ?>
                <section class="cy-panel mb-3">
                    <div class="cy-empty">
                        <span class="cy-empty__icon"><?= icon('file') ?></span>
                        <p class="mb-0">Bu kaynak için hazır rapor yok.</p>
                    </div>
                </section>
            <?php endif; ?>
        <?php else: ?>

            <?php if ($veri['eksik'] !== []): ?>
                <p class="cy-panel__note is-warn mb-3">
                    <?= icon('alert', 'cy-icon cy-icon--sm') ?>
                    Şu aralıklar analiz edilemedi, rapor bu bölümler olmadan hazırlandı: <?= e(implode(', ', $veri['eksik'])) ?>
                </p>
            <?php endif; ?>

            <!-- KISA ÖZET (her zaman açık) -->
            <section class="cy-panel mb-3 tk-ozet">
                <header class="cy-panel__head">
                    <h3 class="cy-panel__title"><?= icon('star', 'cy-icon cy-icon--sm') ?> Kısa özet</h3>
                    <button type="button" class="cy-btn-icon" title="Kısa özeti kopyala" aria-label="Kısa özeti kopyala"
                            data-copy-target="#tk_ozet_kopya" data-copy-label="Kısa özet"><?= icon('copy', 'cy-icon cy-icon--sm') ?></button>
                </header>
                <div class="tk-metin"><?= $paragraflar((string) ($rapor['summary'] ?? '')) ?></div>
                <textarea id="tk_ozet_kopya" hidden readonly><?= e((string) ($rapor['summary'] ?? '')) ?></textarea>
            </section>

            <!-- AÇILIR BÖLÜMLER -->
            <?php if (trim((string) ($rapor['detailed_summary'] ?? '')) !== ''): ?>
                <details class="cy-panel mb-3 tk-bolum">
                    <summary class="tk-bolum__baslik"><?= icon('book', 'cy-icon cy-icon--sm') ?> Detaylı özet</summary>
                    <div class="tk-metin"><?= $paragraflar((string) $rapor['detailed_summary']) ?></div>
                </details>
            <?php endif; ?>

            <?php if ($bolumSayisi('topics') > 0): ?>
                <details class="cy-panel mb-3 tk-bolum">
                    <summary class="tk-bolum__baslik"><?= icon('files', 'cy-icon cy-icon--sm') ?> Konular <span class="cy-badge cy-badge--count"><?= $bolumSayisi('topics') ?></span></summary>
                    <ol class="tk-liste-madde">
                        <?php foreach ($rapor['topics'] as $konu): ?>
                            <li>
                                <span class="tk-madde__ust"><?= $zaman($konu['start'] ?? null) ?><?= ($konu['end'] ?? null) !== null ? '<span class="cy-muted">–</span>' . $zaman($konu['end']) : '' ?>
                                    <strong><?= e((string) $konu['title']) ?></strong></span>
                                <?php if (trim((string) ($konu['summary'] ?? '')) !== ''): ?>
                                    <span class="tk-madde__alt"><?= e((string) $konu['summary']) ?></span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                </details>
            <?php endif; ?>

            <?php if ($bolumSayisi('important_points') > 0): ?>
                <details class="cy-panel mb-3 tk-bolum js-tk-onemli" open>
                    <summary class="tk-bolum__baslik"><?= icon('alert', 'cy-icon cy-icon--sm') ?> Önemli noktalar <span class="cy-badge cy-badge--count js-tk-onem-sayac" data-toplam="<?= $bolumSayisi('important_points') ?>" data-suzgecli="<?= $onemliSayisi ?>"><?= $bolumSayisi('important_points') ?></span></summary>
                    <div class="tk-filtre-satiri">
                        <label class="tk-anahtar">
                            <input type="checkbox" class="form-check-input js-tk-onem-filtre"> Yalnız kritik ve önemli
                        </label>
                    </div>
                    <ul class="tk-liste-madde">
                        <?php foreach ($rapor['important_points'] as $nokta): ?>
                            <?php $onem = (string) ($nokta['importance'] ?? 'useful'); ?>
                            <li class="tk-onemli--<?= e($onem) ?>">
                                <span class="tk-onem tk-onem--<?= e($onem) ?>"><?= e(RaporVerisi::ONEM_ETIKETI[$onem] ?? 'Faydalı') ?></span>
                                <span class="tk-madde__govde"><?= e((string) $nokta['text']) ?> <?= $zaman($nokta['at'] ?? null) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>

            <?php if ($bolumSayisi('timestamps') > 0): ?>
                <details class="cy-panel mb-3 tk-bolum">
                    <summary class="tk-bolum__baslik"><?= icon('clock', 'cy-icon cy-icon--sm') ?> Zaman çizelgesi <span class="cy-badge cy-badge--count"><?= $bolumSayisi('timestamps') ?></span></summary>
                    <ul class="tk-liste-madde tk-cizelge">
                        <?php foreach ($rapor['timestamps'] as $an): ?>
                            <li><?= $zaman($an['at'] ?? null) ?> <span><?= e((string) $an['label']) ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>

            <?php if ($bolumSayisi('technical_information') > 0): ?>
                <details class="cy-panel mb-3 tk-bolum">
                    <summary class="tk-bolum__baslik"><?= icon('code', 'cy-icon cy-icon--sm') ?> <?= e(RaporVerisi::TEKNIK_BASLIK) ?> <span class="cy-badge cy-badge--count"><?= $bolumSayisi('technical_information') ?></span></summary>
                    <?php foreach (RaporVerisi::kategoriyeGore($rapor['technical_information']) as $kategori => $maddeler): ?>
                        <h4 class="tk-kategori"><?= e($kategori) ?></h4>
                        <ul class="tk-liste-madde">
                            <?php foreach ($maddeler as $teknik): ?>
                                <li>
                                    <span class="tk-madde__ust"><strong><?= e((string) $teknik['name']) ?></strong> <?= $zaman($teknik['at'] ?? null) ?></span>
                                    <?php if (($teknik['description'] ?? null) !== null): ?>
                                        <span class="tk-madde__alt"><?= e((string) $teknik['description']) ?></span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endforeach; ?>
                </details>
            <?php endif; ?>

            <?php if ($bolumSayisi('ideas') > 0): ?>
                <details class="cy-panel mb-3 tk-bolum">
                    <summary class="tk-bolum__baslik"><?= icon('zap', 'cy-icon cy-icon--sm') ?> Fikirler <span class="cy-badge cy-badge--count"><?= $bolumSayisi('ideas') ?></span></summary>
                    <ul class="tk-liste-madde">
                        <?php foreach ($rapor['ideas'] as $fikir): ?>
                            <li>
                                <span class="tk-madde__govde"><?= e((string) $fikir['text']) ?> <?= $zaman($fikir['at'] ?? null) ?></span>
                                <?php if (($fikir['type'] ?? null) !== null): ?>
                                    <span class="cy-chip"><?= e(RaporVerisi::ilkHarfBuyuk((string) $fikir['type'])) ?></span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>

            <?php if ($bolumSayisi('claims') > 0): ?>
                <details class="cy-panel mb-3 tk-bolum">
                    <summary class="tk-bolum__baslik"><?= icon('shield', 'cy-icon cy-icon--sm') ?> İddialar <span class="cy-badge cy-badge--count"><?= $bolumSayisi('claims') ?></span></summary>
                    <p class="cy-panel__note mt-0">Konuşmacının ileri sürdükleri; doğrulanmadı. Rozet, nasıl ifade edildiğini gösterir.</p>
                    <ul class="tk-liste-madde">
                        <?php foreach ($rapor['claims'] as $iddia): ?>
                            <?php $guven = (string) ($iddia['speaker_confidence'] ?? ''); ?>
                            <li>
                                <?php if (isset(RaporVerisi::GUVEN_ETIKETI[$guven])): ?>
                                    <span class="tk-guven tk-guven--<?= e($guven) ?>"><?= e(RaporVerisi::GUVEN_ETIKETI[$guven]) ?></span>
                                <?php endif; ?>
                                <span class="tk-madde__govde"><?= e((string) $iddia['text']) ?> <?= $zaman($iddia['at'] ?? null) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>

            <?php if ($bolumSayisi('recommendations') > 0): ?>
                <details class="cy-panel mb-3 tk-bolum">
                    <summary class="tk-bolum__baslik"><?= icon('check', 'cy-icon cy-icon--sm') ?> Öneriler <span class="cy-badge cy-badge--count"><?= $bolumSayisi('recommendations') ?></span></summary>
                    <ul class="tk-liste-madde">
                        <?php foreach ($rapor['recommendations'] as $oneri): ?>
                            <li><span class="tk-madde__govde"><?= e((string) $oneri['text']) ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($veri['transcript'] !== null): ?>
            <!-- TRANSCRIPT (açılınca yüklenir; arama: debounce + seq) -->
            <details class="cy-panel mb-3 tk-bolum" id="tk_transcript">
                <summary class="tk-bolum__baslik"><?= icon('file', 'cy-icon cy-icon--sm') ?> Transcript <span class="cy-badge cy-badge--count"><?= (int) $veri['transcript']['segment_sayisi'] ?> satır</span></summary>
                <div class="cy-input-icon mb-2">
                    <?= icon('search', 'cy-icon cy-icon--sm') ?>
                    <label class="visually-hidden" for="tk_transcript_ara">Transcript içinde ara</label>
                    <input type="search" id="tk_transcript_ara" class="form-control js-tk-ara" placeholder="Transcript içinde ara…" aria-label="Transcript içinde ara" maxlength="100" autocomplete="off">
                </div>
                <?php if ($youtube): ?>
                    <label class="tk-anahtar mb-2 tk-takip">
                        <input type="checkbox" class="form-check-input js-tk-takip" checked> Video oynarken o anki satırı göster
                    </label>
                <?php endif; ?>
                <p class="cy-panel__note mt-0 js-tk-transcript-durum" aria-live="polite">Yükleniyor…</p>
                <ol class="tk-transcript js-tk-transcript-liste"></ol>
            </details>
        <?php endif; ?>
    </div>

    <!-- SAĞ: yapışkan oynatıcı + analiz bilgisi -->
    <aside class="col-12 col-lg-4 order-2 order-md-1 order-lg-2">
        <?php if ($youtube): ?>
            <div class="tk-yapiskan">
                <div class="tk-oynatici">
                    <iframe id="tk_oynatici" src="<?= e(RaporVerisi::gomuluAdres($videoId, $origin)) ?>"
                            title="<?= e((string) ($kaynak['baslik'] ?: 'YouTube videosu')) ?>" loading="lazy"
                            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                            referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>
                </div>
                <?php if ($rapor !== null): ?>
                    <p class="tk-oynatici__not">Zaman damgalarına tıklayınca video o andan oynar.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="tk-yan">
            <?php if ($analiz !== null): ?>
                <section class="cy-panel tk-bilgi">
                    <h3 class="cy-panel__title mb-2"><?= icon('info', 'cy-icon cy-icon--sm') ?> Bu rapor</h3>
                    <dl class="tk-bilgi__liste">
                        <dt>Hazırlandı</dt><dd><?= e(human_date($analiz['bitti_at'] !== null ? (string) $analiz['bitti_at'] : null)) ?></dd>
                        <dt>Model</dt><dd class="cy-mono"><?= e($analiz['model'] !== '' ? $analiz['model'] : '—') ?></dd>
                        <dt>İstem</dt><dd class="cy-mono"><?= e($analiz['prompt_surumu'] !== '' ? $analiz['prompt_surumu'] : '—') ?></dd>
                        <dt>Transcript</dt><dd><?= $veri['transcript'] !== null ? e(RaporVerisi::dilAdi((string) $veri['transcript']['dil'])) : '—' ?></dd>
                        <?php /* Token ve maliyet yalnız yöneticiye (kullanıcı kararı: üye ve editör görmez). */ ?>
                        <?php if ($policy->canSeeAllCosts()): ?>
                            <dt>Token</dt><dd class="cy-mono"><?= e(number_format($analiz['toplam_token'], 0, ',', '.')) ?></dd>
                            <dt>Tahmini maliyet</dt><dd class="cy-mono">$<?= e(number_format($analiz['maliyet_usd'], 4, ',', '.')) ?></dd>
                        <?php endif; ?>
                    </dl>
                    <?php if (RaporVerisi::eskiIstem($analiz['prompt_surumu'])): ?>
                        <p class="cy-panel__note is-warn tk-eski-istem">
                            <?= icon('info', 'cy-icon cy-icon--sm') ?>
                            Bu rapor eski istemle (<?= e($analiz['prompt_surumu'] !== '' ? $analiz['prompt_surumu'] : 'sürümsüz') ?>) üretildi; güncel istem <?= e((string) Ayarlar::get('ai.istem_surumu', 'v1')) ?>.
                            <?= $youtube && $policy->canReanalyze() && !$suruyor ? 'Yeniden analizle güncellenebilir.' : '' ?>
                        </p>
                    <?php endif; ?>
                    <?php if ($youtube && $policy->canReanalyze() && !$suruyor): ?>
                        <button type="button" class="btn cy-btn cy-btn--ghost cy-btn--sm cy-btn--block js-tk-yeniden"
                                data-url="<?= e(url('panel/transkript/analiz')) ?>"
                                data-video="<?= e('https://www.youtube.com/watch?v=' . $videoId) ?>"
                                data-yeniden="1"
                                data-onay="Bu video yeniden analiz edilecek; yapay zekâ çağrısı yapılır. Yeni rapor hazır olana kadar bu rapor görünmeye devam eder."
                                data-onay-baslik="Yeniden analiz et" data-onay-dugme="Evet, yeniden analiz et">
                            <?= icon('refresh', 'cy-icon cy-icon--sm') ?> Yeniden analiz et
                        </button>
                    <?php endif; ?>
                </section>
            <?php elseif ($youtube && $policy->canAnalyze() && $durum['durum'] === 'hata'): ?>
                <button type="button" class="btn cy-btn cy-btn--primary cy-btn--sm cy-btn--block js-tk-yeniden"
                        data-url="<?= e(url('panel/transkript/analiz')) ?>"
                        data-video="<?= e('https://www.youtube.com/watch?v=' . $videoId) ?>" data-yeniden="0">
                    <?= icon('refresh', 'cy-icon cy-icon--sm') ?> Analizi yeniden dene
                </button>
            <?php endif; ?>

            <?php if ($rapor !== null || $veri['transcript'] !== null): ?>
                <!-- DIŞA AKTAR (AI çağrısı yok; kayıtlı rapordan üretilir) -->
                <section class="cy-panel tk-disa">
                    <h3 class="cy-panel__title mb-2"><?= icon('save', 'cy-icon cy-icon--sm') ?> Dışa aktar</h3>
                    <div class="tk-disa__liste">
                        <?php if ($rapor !== null): ?>
                            <a class="btn cy-btn cy-btn--ghost cy-btn--sm" href="<?= e($indir('rapor.md')) ?>" download><?= icon('file', 'cy-icon cy-icon--sm') ?> Rapor (Markdown)</a>
                        <?php endif; ?>
                        <?php if ($veri['transcript'] !== null): ?>
                            <a class="btn cy-btn cy-btn--ghost cy-btn--sm" href="<?= e($indir('transkript.txt')) ?>" download><?= icon('file', 'cy-icon cy-icon--sm') ?> Transcript (TXT)</a>
                            <a class="btn cy-btn cy-btn--ghost cy-btn--sm" href="<?= e($indir('transkript.srt')) ?>" download>Altyazı (SRT)</a>
                            <a class="btn cy-btn cy-btn--ghost cy-btn--sm" href="<?= e($indir('transkript.vtt')) ?>" download>Altyazı (VTT)</a>
                        <?php endif; ?>
                        <?php if ($bolumListesi !== ''): ?>
                            <button type="button" class="btn cy-btn cy-btn--ghost cy-btn--sm" data-copy-target="#tk_bolum_listesi" data-copy-label="YouTube bölüm listesi">
                                <?= icon('copy', 'cy-icon cy-icon--sm') ?> YouTube bölümlerini kopyala
                            </button>
                        <?php endif; ?>
                    </div>
                    <?php if ($bolumListesi !== ''): ?>
                        <textarea id="tk_bolum_listesi" hidden readonly><?= e($bolumListesi) ?></textarea>
                        <p class="cy-panel__note mb-0">Bölüm listesi video açıklamasına yapıştırılınca YouTube'da bölümler (chapters) oluşur.</p>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?php
            $iptalEdebilir = $surenAnaliz !== null && $policy->canCancelAnalysis($surenAnaliz);
            $silebilir     = $policy->canDeleteSource($kaynak);
            $adi           = (string) ($kaynak['baslik'] ?: $videoId);
            ?>
            <?php if ($iptalEdebilir || $silebilir): ?>
                <!-- KAYIT: iptal ve silme (TranskriptPolicy; onay penceresiyle) -->
                <section class="cy-panel tk-kayit">
                    <h3 class="cy-panel__title mb-2"><?= icon('settings', 'cy-icon cy-icon--sm') ?> Kayıt</h3>
                    <div class="tk-disa__liste">
                        <?php if ($iptalEdebilir): ?>
                            <button type="button" class="btn cy-btn cy-btn--ghost cy-btn--sm js-tk-iptal"
                                    data-url="<?= e(url('panel/transkript/' . $kaynakId . '/iptal')) ?>"
                                    data-onay="Süren analiz durdurulacak. Bitmiş bölümler korunmaz; daha sonra yeniden başlatabilirsiniz."
                                    data-onay-baslik="Analizi iptal et" data-onay-dugme="Evet, iptal et" data-onay-tehlike="1">
                                <?= icon('x-circle', 'cy-icon cy-icon--sm') ?> Analizi iptal et
                            </button>
                        <?php endif; ?>
                        <?php if ($silebilir): ?>
                            <button type="button" class="btn cy-btn cy-btn--ghost cy-btn--sm tk-tehlike js-tk-sil"
                                    data-url="<?= e(url('panel/transkript/' . $kaynakId . '/sil')) ?>"
                                    data-onay="“<?= e($adi) ?>” kalıcı olarak silinecek: raporlar, transcript ve bekleyen işler. Video herkes için ortak; yeniden analiz yapay zekâ çağrısı gerektirir."
                                    data-onay-baslik="Kaydı sil" data-onay-dugme="Evet, sil" data-onay-tehlike="1">
                                <?= icon('trash', 'cy-icon cy-icon--sm') ?> Kaydı sil
                            </button>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>
        </div>
    </aside>
</div>

<?php View::partial('Transkript::_parcalar/onay-penceresi'); ?>

<script src="<?= e(Varliklar::adres('transkript.js')) ?>" defer></script>
