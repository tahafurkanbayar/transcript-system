<?php
/**
 * =====================================================================
 *  GÖRÜNÜM: Ana sayfa — Transkript tanıtımı (layouts/site içinde)
 * ---------------------------------------------------------------------
 *  Denetleyici: Controllers\AnasayfaController. Bölümler: karşılama
 *  (kendi kendine oynayan ürün önizlemesi), sayılar, nasıl çalışır,
 *  raporda neler var (sekmeli vitrin), neden güvenilir, kimler için,
 *  sık sorulan sorular, son çağrı.
 *
 *  Örnek video ve rapor metinleri UYDURMA bir örnektir ("Örnek" diye
 *  işaretli); gerçek bir kayda dayanmaz. Sayfadaki bütün metinler
 *  sabittir; dinamik değerler e() ile basılır.
 *
 *  HAREKET assets/js/anasayfa.js'te. JavaScript kapalıyken ya da
 *  "hareketi azalt" seçiliyken: önizleme bitmiş raporu gösterir,
 *  vitrinin bütün sekmeleri alt alta açıktır, bölümler zaten görünür.
 *
 *  @var bool  $girisli
 *  @var bool  $analizEdebilir  girişli ve analiz başlatabilir: form
 *  @var bool  $analizVaadi     "bağlantıyı yapıştırın" denebilir mi (misafirde: yeni üye hesabı)
 *  @var bool  $apiAcik         API anahtarı alabilir mi (profile.api; misafirde: yeni üye hesabı)
 *  @var bool  $kayitAcik
 *  @var array{sayi:int,birim:string}|null $sure
 *  @var list<array{soru:string,cevap:string}> $sss
 *  @var list<array<string,mixed>>            $planlar  Uyelik\Planlar::karsilastirma() (Planlar bölümü)
 *  @var string|null                          $planim   ziyaretçinin planı (misafir / yönetici: null)
 *  @var bool                                 $yonetici
 * =====================================================================
 */

use App\Core\Setting;
use Modules\Transkript\Uyelik\Planlar;
use Modules\Transkript\Varliklar;

$siteAdi = Setting::get('site_adi', 'Transkript');
// Eski çağrılarla (testler) uyum: verilmezse önceki davranış.
$analizVaadi = $analizVaadi ?? $analizEdebilir;
$apiAcik     = $apiAcik ?? true;

/* Analiz formu GET ile panel/transkript?url=… açar (liste kutuyu doldurur).
 * Temiz adres kapalıysa eylem "?r=…" taşır; GET formu sorguyu sildiği
 * için parçaları gizli alana koyuyoruz. */
$formAdresi = url('panel/transkript');
parse_str((string) parse_url($formAdresi, PHP_URL_QUERY), $formGizli);

$eylemler = static function (string $yer) use ($girisli, $analizEdebilir, $kayitAcik, $formAdresi, $formGizli): void {
    if ($analizEdebilir) { ?>
        <form class="tka-form" method="get" action="<?= e($formAdresi) ?>" role="search" aria-label="Yeni analiz">
            <?php foreach ($formGizli as $ad => $deger): ?>
                <input type="hidden" name="<?= e((string) $ad) ?>" value="<?= e(is_string($deger) ? $deger : '') ?>">
            <?php endforeach; ?>
            <label class="visually-hidden" for="tka_url_<?= e($yer) ?>">YouTube bağlantısı</label>
            <span class="tka-form__simge" aria-hidden="true"><?= icon('youtube', 'cy-icon') ?></span>
            <input class="tka-form__girdi" id="tka_url_<?= e($yer) ?>" type="text" name="url" inputmode="url" spellcheck="false" autocomplete="off"
                   placeholder="https://www.youtube.com/watch?v=…" required>
            <button class="tka-dugme tka-dugme--ana" type="submit">Analiz et <?= icon('chevron', 'cy-icon cy-icon--sm') ?></button>
        </form>
    <?php } elseif ($girisli) { ?>
        <div class="tka-eylemler">
            <a class="tka-dugme tka-dugme--ana" href="<?= e(url('panel/transkript')) ?>">Raporlara git <?= icon('chevron', 'cy-icon cy-icon--sm') ?></a>
        </div>
    <?php } else { ?>
        <div class="tka-eylemler">
            <?php if ($kayitAcik): ?>
                <a class="tka-dugme tka-dugme--ana" href="<?= e(url('kayit')) ?>">Hesap oluşturun <?= icon('chevron', 'cy-icon cy-icon--sm') ?></a>
                <a class="tka-dugme tka-dugme--hayalet" href="<?= e(url('giris')) ?>">Giriş yapın</a>
            <?php else: ?>
                <a class="tka-dugme tka-dugme--ana" href="<?= e(url('giris')) ?>">Giriş yapın <?= icon('chevron', 'cy-icon cy-icon--sm') ?></a>
            <?php endif; ?>
        </div>
    <?php }
};

$adimlar = [
    $analizVaadi
        ? ['link', 'Bağlantıyı yapıştırın', 'YouTube bağlantısı ya da kısa adresi yeter. Oynatma listesi ve kanal bağlantısı ayırt edilir; var olmayan video daha başlamadan reddedilir.']
        : ['link', 'Bağlantı eklenir', 'Analiz edilecek videonun YouTube bağlantısı ya da kısa adresi eklenir. Oynatma listesi ve kanal bağlantısı ayırt edilir; var olmayan video daha başlamadan reddedilir.'],
    ['file',  'Transcript çıkarılır', 'Videonun altyazısı zaman damgalarıyla okunur, temizlenir ve aranabilir hâle gelir. Görüntü ya da ses indirilmez.'],
    ['zap',   'Bölüm bölüm analiz', 'Uzun video 8–10 dakikalık, birbirine bağlanan parçalara ayrılır; her parça ayrı incelenir, sonra tek raporda birleşir.'],
    ['check', 'Rapor hazır', 'Özet, önemli noktalar, konular ve iddialar tek sayfada. Bir maddeye tıklayın, video o saniyeden oynasın.'],
];

$sekmeler = [
    ['ozet',      'star',   'Özet',                     'Kısası ve detaylısı'],
    ['noktalar',  'alert',  'Önemli noktalar',          'Kritik, önemli, faydalı'],
    ['konular',   'clock',  'Konular ve zaman çizelgesi', 'Videonun haritası'],
    ['kavramlar', 'code',   'Kavramlar',                'Terimler ve açıklamaları'],
    ['iddialar',  'shield', 'İddialar',                 'Ayrı ve işaretli'],
    ['fikirler',  'zap',    'Fikirler ve öneriler',     'Uygulanabilir çıkarımlar'],
    ['transcript', 'search', 'Aranabilir transcript',   'Satır satır, saniyesiyle'],
];

$kimler = [
    ['book',   'Öğrenciler ve akademisyenler', 'Ders kayıtlarını ve konferansları baştan izlemeden çalışın; alıntıyı saniyesiyle not alın.'],
    ['search', 'Araştırmacılar ve gazeteciler', 'Röportaj ve oturumlardaki iddiaları ayıklayın; her birinin videoda nerede söylendiğini görün.'],
    ['star',   'İçerik üreticileri', 'Kendi videolarınız için bölüm başlıkları ve zaman damgalı açıklama metnine hazır bir taslak alın.'],
    ['users',  'Ekipler ve eğitim birimleri', 'Webinar ve eğitim kayıtlarını ekibin okuyup arayabileceği raporlara dönüştürün.'],
];
?>
<link rel="stylesheet" href="<?= e(Varliklar::adres('anasayfa.css')) ?>">

<div class="tka">

    <!-- ================= KARŞILAMA ================= -->
    <section class="tka-hero" aria-labelledby="tka-baslik">
        <div class="tka-hero__zemin" aria-hidden="true">
            <span class="tka-isik tka-isik--1"></span>
            <span class="tka-isik tka-isik--2"></span>
            <span class="tka-isik tka-isik--3"></span>
            <span class="tka-izgara"></span>
        </div>

        <div class="tka-kap tka-hero__ic">
            <div class="tka-hero__metin">
                <a class="tka-rozet" href="#nasil">
                    <span class="tka-rozet__nokta" aria-hidden="true"></span>
                    YouTube bağlantısından Türkçe rapora
                    <?= icon('chevron', 'cy-icon cy-icon--sm') ?>
                </a>

                <?php /* Metin kaynakta BİR kez geçer (arama motoru çift görmesin);
                         ekran okuyucu dönen kelimeyi değil aria-label'ı okur. */ ?>
                <h1 class="tka-hero__baslik" id="tka-baslik" aria-label="Uzun videoları dakikalar içinde okuyun.">
                    <span class="tka-donen"
                          data-tka-donen="<?= e(json_encode(['Uzun videoları', 'Konferansları', 'Ders kayıtlarını', 'Podcast\'leri', 'Röportajları', 'Panelleri'], JSON_UNESCAPED_UNICODE)) ?>">Uzun videoları</span>
                    <span class="tka-vurgu">dakikalar içinde</span> okuyun.
                </h1>

                <p class="tka-hero__giris">
                    <?php if ($analizVaadi): ?>
                        YouTube bağlantısını yapıştırın: <?= e($siteAdi) ?>, videonun zaman damgalı dökümünü çıkarır ve
                        yapay zekâyla Türkçe bir rapora dönüştürür. Özet, önemli noktalar, konular, kavramlar ve
                        iddialar; maddeler videodaki saniyesine bağlı.
                    <?php else: ?>
                        <?= e($siteAdi) ?>, YouTube videolarının zaman damgalı dökümünü çıkarır ve yapay zekâyla Türkçe
                        bir rapora dönüştürür. Analiz edilmiş videoların raporunu okuyun, transcript'te arayın, dört
                        biçimde indirin; maddeler videodaki saniyesine bağlı.
                    <?php endif; ?>
                </p>

                <?php $eylemler('ust'); ?>

                <ul class="tka-guven" aria-label="Kısaca">
                    <li><?= icon('check', 'cy-icon cy-icon--sm') ?> Video ya da ses indirilmez</li>
                    <li><?= icon('check', 'cy-icon cy-icon--sm') ?> Her dilde video, Türkçe rapor</li>
                    <li><?= icon('check', 'cy-icon cy-icon--sm') ?> Analiz arka planda sürer</li>
                </ul>
            </div>

            <div class="tka-hero__gorsel">
                <p class="visually-hidden">
                    Örnek önizleme: bir YouTube bağlantısı yapıştırılıyor, transcript alınıyor, yapay zekâ bölümleri
                    inceliyor ve kısa özet, önemli noktalar ve konu şeridinden oluşan rapor beliriyor.
                </p>
                <div class="tka-demo" data-tka-demo data-sahne="3" data-adim="5" aria-hidden="true">
                    <div class="tka-demo__pencere">
                        <div class="tka-demo__cubuk">
                            <span></span><span></span><span></span>
                            <em>transkript · yeni analiz</em>
                            <b class="tka-demo__ornek">Örnek</b>
                        </div>

                        <div class="tka-demo__giris">
                            <span class="tka-demo__kutu">
                                <?= icon('youtube', 'cy-icon tka-demo__yt') ?>
                                <span class="tka-demo__url" data-tka-yaz>https://youtu.be/7Xq2mLp9eRk</span><i class="tka-imlec"></i>
                            </span>
                            <span class="tka-demo__dugme">Analiz et</span>
                        </div>

                        <div class="tka-demo__sahne">
                            <div class="tka-demo__ipucu">
                                <?= icon('link', 'cy-icon') ?>
                                <span>Bir YouTube bağlantısı yapıştırın</span>
                            </div>

                            <div class="tka-demo__ilerleme">
                                <ol class="tka-demo__adimlar">
                                    <li><i></i><span>Video bilgisi alındı <small>Yapay Zekâ Çağında Öğrenmek · 48:12</small></span></li>
                                    <li><i></i><span>Transcript alındı <small>1.284 satır · Türkçe altyazı</small></span></li>
                                    <li><i></i><span>Bölümler analiz ediliyor <small>6 parça, 30 sn örtüşmeyle</small></span></li>
                                    <li><i></i><span>Rapor birleştirildi <small>zaman damgaları denetlendi</small></span></li>
                                </ol>
                                <div class="tka-demo__bar"><span></span></div>
                            </div>

                            <div class="tka-demo__rapor">
                                <div class="tka-demo__video" style="--i:0">
                                    <span class="tka-demo__kapak"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5.5v13l11-6.5z" fill="currentColor"/></svg><em>48:12</em></span>
                                    <span class="tka-demo__kunye">
                                        <strong>Yapay Zekâ Çağında Öğrenmek</strong>
                                        <small>Panel kaydı · Türkçe · 4 konu · 14 madde</small>
                                    </span>
                                </div>
                                <div class="tka-demo__blok" style="--i:1">
                                    <h4><?= icon('star', 'cy-icon cy-icon--sm') ?> Kısa özet</h4>
                                    <p>Konuşmacılar yapay zekânın öğretmenin yerini almayacağını, ama zamanını nasıl kullandığını değiştireceğini savunuyor. Ölçme yöntemlerinin yeniden tasarlanması öne çıkıyor.</p>
                                </div>
                                <ul class="tka-demo__noktalar">
                                    <li style="--i:2"><span class="tka-onem tka-onem--kritik">Kritik</span><span>Ödevler yalnız ezberi ölçerse anlamını yitirir</span><span class="tka-zaman">04:12</span></li>
                                    <li style="--i:3"><span class="tka-onem tka-onem--onemli">Önemli</span><span>Uyarlanabilir alıştırma takılan yeri buluyor</span><span class="tka-zaman">17:40</span></li>
                                    <li style="--i:4"><span class="tka-onem tka-onem--faydali">Faydalı</span><span>Soru bankası hazırlığı yarıya iniyor</span><span class="tka-zaman">31:05</span></li>
                                </ul>
                                <div class="tka-demo__serit" style="--i:5" title="Konular">
                                    <span style="--g:20%"></span><span style="--g:26%"></span><span style="--g:30%"></span><span style="--g:24%"></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <span class="tka-yuzen tka-yuzen--1"><?= icon('clock', 'cy-icon cy-icon--sm') ?> 17:40'a atla</span>
                    <span class="tka-yuzen tka-yuzen--2"><?= icon('shield', 'cy-icon cy-icon--sm') ?> Zaman damgaları denetlendi</span>
                    <span class="tka-yuzen tka-yuzen--3"><?= icon('zap', 'cy-icon cy-icon--sm') ?> Önbellekten anında</span>
                </div>
            </div>
        </div>
    </section>

    <!-- ================= SAYILAR ================= -->
    <section class="tka-sayilar" aria-label="Sayılarla">
        <div class="tka-kap">
            <ul class="tka-sayilar__liste" data-tka-belir>
                <li>
                    <?php if ($sure !== null): ?>
                        <strong><span data-tka-say="<?= (int) $sure['sayi'] ?>"><?= (int) $sure['sayi'] ?></span> <small><?= e($sure['birim']) ?></small></strong>
                        <span>uzunluğa kadar video</span>
                    <?php else: ?>
                        <strong>Sınırsız</strong>
                        <span>video uzunluğu</span>
                    <?php endif; ?>
                </li>
                <li>
                    <strong><span data-tka-say="10">10</span></strong>
                    <span>rapor bölümü</span>
                </li>
                <li>
                    <strong><span data-tka-say="4">4</span></strong>
                    <span>dışa aktarma biçimi <small>MD · TXT · SRT · VTT</small></span>
                </li>
                <li>
                    <strong><span>0</span></strong>
                    <span>indirilen video ya da ses</span>
                </li>
            </ul>
        </div>
    </section>

    <!-- ================= NASIL ÇALIŞIR ================= -->
    <section class="tka-bolum" id="nasil" aria-labelledby="tka-nasil-baslik">
        <div class="tka-kap">
            <header class="tka-bas" data-tka-belir>
                <span class="tka-ust-baslik">Nasıl çalışır?</span>
                <h2 id="tka-nasil-baslik">Bağlantıdan rapora dört adım</h2>
                <p><?= $analizVaadi
                    ? 'Siz başka işinize bakarken analiz arka planda ilerler. Sayfayı kapatsanız da kaldığı yerden sürer.'
                    : 'Analiz arka planda ilerler; rapor hazır olunca listede görünür.' ?></p>
            </header>

            <ol class="tka-adimlar" data-tka-belir>
                <?php foreach ($adimlar as $i => [$ikon, $baslik, $metin]): ?>
                    <li class="tka-adim" style="--i:<?= $i ?>">
                        <span class="tka-adim__simge"><?= icon($ikon) ?><b><?= $i + 1 ?></b></span>
                        <h3><?= e($baslik) ?></h3>
                        <p><?= e($metin) ?></p>
                    </li>
                <?php endforeach; ?>
            </ol>
        </div>
    </section>

    <!-- ================= RAPORDA NELER VAR ================= -->
    <section class="tka-bolum tka-bolum--ara" id="rapor" aria-labelledby="tka-rapor-baslik">
        <div class="tka-kap">
            <header class="tka-bas" data-tka-belir>
                <span class="tka-ust-baslik">Raporda neler var?</span>
                <h2 id="tka-rapor-baslik">Bir saatlik video, beş dakikalık okuma</h2>
                <p>Rapor videonun uzunluğuna göre kısalır ya da uzar; kısa videoda gereksiz madde üretilmez. Aşağıdaki örnek 48 dakikalık bir panelden.</p>
            </header>

            <div class="tka-vitrin" data-tka-vitrin data-tka-belir>
                <div class="tka-vitrin__sekmeler" role="tablist" aria-label="Rapor bölümleri">
                    <?php foreach ($sekmeler as $i => [$id, $ikon, $baslik, $alt]): ?>
                        <button type="button" class="tka-sekme" role="tab" id="tka-sekme-<?= e($id) ?>" aria-controls="tka-panel-<?= e($id) ?>"
                                aria-selected="<?= $i === 0 ? 'true' : 'false' ?>" tabindex="<?= $i === 0 ? '0' : '-1' ?>">
                            <span class="tka-sekme__simge"><?= icon($ikon, 'cy-icon cy-icon--sm') ?></span>
                            <span class="tka-sekme__metin"><strong><?= e($baslik) ?></strong><small><?= e($alt) ?></small></span>
                            <span class="tka-sekme__sure" aria-hidden="true"></span>
                        </button>
                    <?php endforeach; ?>
                </div>

                <div class="tka-vitrin__sahne">
                    <div class="tka-vitrin__ust" aria-hidden="true">
                        <span class="tka-demo__kapak tka-demo__kapak--kucuk"><svg viewBox="0 0 24 24"><path d="M8 5.5v13l11-6.5z" fill="currentColor"/></svg></span>
                        <span><strong>Yapay Zekâ Çağında Öğrenmek</strong><small>Örnek rapor · 48:12</small></span>
                    </div>

                    <div class="tka-panel" role="tabpanel" id="tka-panel-ozet" aria-labelledby="tka-sekme-ozet" tabindex="0">
                        <h3><?= icon('star', 'cy-icon cy-icon--sm') ?> Kısa özet</h3>
                        <p class="tka-panel__vurgu">Konuşmacılar yapay zekânın öğretmenin yerini almayacağını, ama öğretmenin zamanını nasıl kullandığını kökten değiştireceğini savunuyor. Kişiye göre uyarlanan alıştırmalar ve ölçme yöntemlerinin yeniden tasarlanması öne çıkıyor.</p>
                        <h3><?= icon('book', 'cy-icon cy-icon--sm') ?> Detaylı özet</h3>
                        <p>Panel, yapay zekânın sınıfta zaten kullanıldığı tespitiyle açılıyor. İlk bölümde öğrencinin takıldığı yeri bulup zorluğu ayarlayan alıştırmalar örneklerle anlatılıyor. Ardından ödev ve sınavların neyi ölçtüğü sorgulanıyor; konuşmacılar ezberi değil, bilgiyi kullanmayı ölçen görevler öneriyor…</p>
                    </div>

                    <div class="tka-panel" role="tabpanel" id="tka-panel-noktalar" aria-labelledby="tka-sekme-noktalar" tabindex="0">
                        <h3><?= icon('alert', 'cy-icon cy-icon--sm') ?> Önemli noktalar</h3>
                        <ul class="tka-maddeler">
                            <li><span class="tka-onem tka-onem--kritik">Kritik</span><p>Ödev ve sınavlar yapay zekânın kolayca yaptığı işleri ölçmeye devam ederse anlamını yitirir.</p><span class="tka-zaman">04:12</span></li>
                            <li><span class="tka-onem tka-onem--onemli">Önemli</span><p>Uyarlanabilir alıştırmalar öğrencinin takıldığı yeri bulup zorluğu ona göre ayarlıyor.</p><span class="tka-zaman">17:40</span></li>
                            <li><span class="tka-onem tka-onem--onemli">Önemli</span><p>Yapay zekâ kullanımı yasaklamak yerine sınıf kurallarıyla görünür kılınmalı.</p><span class="tka-zaman">26:18</span></li>
                            <li><span class="tka-onem tka-onem--faydali">Faydalı</span><p>Öğretmenler soru bankası ve ders planı hazırlığında belirgin zaman kazanıyor.</p><span class="tka-zaman">31:05</span></li>
                        </ul>
                        <p class="tka-panel__not"><?= icon('filter', 'cy-icon cy-icon--sm') ?> "Yalnız kritik ve önemli" süzgeci tercihinizi hatırlar.</p>
                    </div>

                    <div class="tka-panel" role="tabpanel" id="tka-panel-konular" aria-labelledby="tka-sekme-konular" tabindex="0">
                        <h3><?= icon('clock', 'cy-icon cy-icon--sm') ?> Konular</h3>
                        <div class="tka-serit" aria-hidden="true">
                            <span style="--g:20%"></span><span style="--g:26%"></span><span style="--g:30%"></span><span style="--g:24%"></span>
                        </div>
                        <ol class="tka-konular">
                            <li><span class="tka-zaman">00:00</span><span><strong>Açılış: neden şimdi?</strong><small>00:00 – 09:30</small></span></li>
                            <li><span class="tka-zaman">09:30</span><span><strong>Kişiye göre öğrenme</strong><small>09:30 – 22:15</small></span></li>
                            <li><span class="tka-zaman">22:15</span><span><strong>Ölçme ve değerlendirme</strong><small>22:15 – 36:40</small></span></li>
                            <li><span class="tka-zaman">36:40</span><span><strong>Soru-cevap</strong><small>36:40 – 48:12</small></span></li>
                        </ol>
                        <p class="tka-panel__not"><?= icon('check', 'cy-icon cy-icon--sm') ?> Konular ilk saniyeden son saniyeye boşluksuz ve çakışmasız.</p>
                    </div>

                    <div class="tka-panel" role="tabpanel" id="tka-panel-kavramlar" aria-labelledby="tka-sekme-kavramlar" tabindex="0">
                        <h3><?= icon('code', 'cy-icon cy-icon--sm') ?> Kavramlar ve teknik bilgiler</h3>
                        <dl class="tka-kavramlar">
                            <div><dt>Uyarlanabilir öğrenme <span class="tka-zaman">14:20</span></dt><dd>Öğrencinin cevaplarına göre içeriğin zorluğunu ve sırasını değiştiren yöntem.</dd></div>
                            <div><dt>Biçimlendirici değerlendirme <span class="tka-zaman">24:05</span></dt><dd>Not vermek için değil, öğrenme sürerken yön göstermek için yapılan ölçme.</dd></div>
                            <div><dt>Büyük dil modeli <span class="tka-zaman">06:48</span></dt><dd>Metni anlayıp yeni metin üretebilen, çok büyük metin derlemleriyle eğitilmiş yapay zekâ modeli.</dd></div>
                        </dl>
                    </div>

                    <div class="tka-panel" role="tabpanel" id="tka-panel-iddialar" aria-labelledby="tka-sekme-iddialar" tabindex="0">
                        <h3><?= icon('shield', 'cy-icon cy-icon--sm') ?> İddialar</h3>
                        <ul class="tka-maddeler">
                            <li><span class="tka-guvenlik tka-guvenlik--kesin">Kesin ifade</span><p>Öğrencilerin yarısından fazlası ödevlerinde yapay zekâ kullanıyor.</p><span class="tka-zaman">12:03</span></li>
                            <li><span class="tka-guvenlik tka-guvenlik--gorus">Görüş</span><p>Klasik yazılı sınavlar birkaç yıl içinde büyük ölçüde değişecek.</p><span class="tka-zaman">27:30</span></li>
                            <li><span class="tka-guvenlik tka-guvenlik--tahmin">Tahmin</span><p>Kişisel yapay zekâ öğretmenleri ders kitabının yerini alabilir.</p><span class="tka-zaman">41:10</span></li>
                        </ul>
                        <p class="tka-panel__not tka-panel__not--uyari"><?= icon('info', 'cy-icon cy-icon--sm') ?> İddialar doğrulanmamıştır: zaman damgasından kaynağına gidip kontrol edin.</p>
                    </div>

                    <div class="tka-panel" role="tabpanel" id="tka-panel-fikirler" aria-labelledby="tka-sekme-fikirler" tabindex="0">
                        <h3><?= icon('zap', 'cy-icon cy-icon--sm') ?> Fikirler</h3>
                        <ul class="tka-maddeler tka-maddeler--sade">
                            <li><p>Sınavları "kaynak açık" yapın: ölçülen şey bilgiye ulaşmak değil, onu kullanmak olsun.</p><span class="tka-zaman">33:12</span></li>
                            <li><p>Öğrencinin yapay zekâyla yaptığı çalışmanın sürecini de değerlendirmeye katın.</p><span class="tka-zaman">35:02</span></li>
                        </ul>
                        <h3><?= icon('check', 'cy-icon cy-icon--sm') ?> Öneriler</h3>
                        <ul class="tka-maddeler tka-maddeler--sade">
                            <li><p>Derste yapay zekâ kullanımına dair kuralları öğrencilerle birlikte yazın.</p><span class="tka-zaman">38:55</span></li>
                        </ul>
                    </div>

                    <div class="tka-panel" role="tabpanel" id="tka-panel-transcript" aria-labelledby="tka-sekme-transcript" tabindex="0">
                        <h3><?= icon('file', 'cy-icon cy-icon--sm') ?> Transcript</h3>
                        <div class="tka-arama" aria-hidden="true">
                            <?= icon('search', 'cy-icon cy-icon--sm') ?><span>olcme</span><small>3 sonuç</small>
                        </div>
                        <ul class="tka-satirlar">
                            <li><span class="tka-zaman">22:18</span><p>…asıl büyük değişikliği <mark>ölçme</mark> ve değerlendirme tarafında göreceğiz.</p></li>
                            <li><span class="tka-zaman">24:05</span><p>Biçimlendirici <mark>ölçme</mark> notu değil, öğrencinin yönünü önemser.</p></li>
                            <li><span class="tka-zaman">35:51</span><p><mark>Ölçme</mark> ölçütlerini öğrencilerle baştan paylaşmak gerekiyor.</p></li>
                        </ul>
                        <p class="tka-panel__not"><?= icon('info', 'cy-icon cy-icon--sm') ?> Türkçe karakter yazmanız gerekmez: "olcme" yazın, "ölçme" de "ÖLÇME" de bulunsun.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ================= NEDEN GÜVENİLİR ================= -->
    <section class="tka-bolum" id="ozellikler" aria-labelledby="tka-ozellik-baslik">
        <div class="tka-kap">
            <header class="tka-bas" data-tka-belir>
                <span class="tka-ust-baslik">Neden güvenebilirsiniz?</span>
                <h2 id="tka-ozellik-baslik">Hızlı olduğu kadar denetlenebilir</h2>
                <p>Yapay zekâ özet çıkarmakta iyidir, kaynak göstermekte değil. <?= e($siteAdi) ?> bu açığı kapatmak için tasarlandı.</p>
            </header>

            <div class="tka-bento">
                <article class="tka-kart tka-kart--genis" data-tka-belir data-tka-isik>
                    <span class="tka-kart__simge"><?= icon('clock') ?></span>
                    <h3>Maddeler saniyesine bağlı</h3>
                    <p>Yapay zekânın verdiği zaman damgası transcript'teki en yakın gerçek satıra çekilir; karşılığı olmayan damga rapora girmez. Tıklayınca gömülü oynatıcı o ana atlar.</p>
                    <div class="tka-oynatici" aria-hidden="true">
                        <span class="tka-oynatici__dugme"><svg viewBox="0 0 24 24"><path d="M8 5.5v13l11-6.5z" fill="currentColor"/></svg></span>
                        <span class="tka-oynatici__cubuk"><span class="tka-oynatici__dolu"></span><i style="--x:9%"></i><i style="--x:37%"></i><i style="--x:64%"></i></span>
                        <span class="tka-zaman">17:40</span>
                    </div>
                </article>

                <article class="tka-kart" data-tka-belir data-tka-isik>
                    <span class="tka-kart__simge"><?= icon('shield') ?></span>
                    <h3>Uydurmaya karşı denetim</h3>
                    <p>Yapay zekâ çıktısı şemadan geçmeden kaydedilmez. Bozuk madde atılır, iddialar ayrı tutulur ve işaretlenir.</p>
                    <ul class="tka-kontrol" aria-hidden="true">
                        <li><?= icon('check', 'cy-icon cy-icon--sm') ?> Şema</li>
                        <li><?= icon('check', 'cy-icon cy-icon--sm') ?> Zaman</li>
                        <li><?= icon('check', 'cy-icon cy-icon--sm') ?> Konu aralığı</li>
                    </ul>
                </article>

                <article class="tka-kart" data-tka-belir data-tka-isik>
                    <span class="tka-kart__simge"><?= icon('files') ?></span>
                    <h3>Uzun videoda da tutarlı</h3>
                    <p>Video, bağlamı kaybetmemek için 30 saniye örtüşen parçalara ayrılır; her parçanın sonucu tek raporda birleşir.</p>
                </article>

                <article class="tka-kart tka-kart--genis" data-tka-belir data-tka-isik>
                    <span class="tka-kart__simge"><?= icon('search') ?></span>
                    <h3>Transcript'in tamamında arama</h3>
                    <p>Türkçe karakterleri dert etmeyin: "isik" yazın, "ışık" ve "IŞIK" da bulunsun. Sonuçlar vurgulanır; satıra tıklayınca video oradan oynar.</p>
                    <div class="tka-mini-arama" aria-hidden="true">
                        <span class="tka-mini-arama__kutu"><?= icon('search', 'cy-icon cy-icon--sm') ?> isik</span>
                        <span class="tka-mini-arama__sonuc"><mark>ışık</mark></span>
                        <span class="tka-mini-arama__sonuc"><mark>IŞIK</mark></span>
                        <span class="tka-mini-arama__sonuc"><mark>Işık</mark></span>
                    </div>
                </article>

                <article class="tka-kart" data-tka-belir data-tka-isik>
                    <span class="tka-kart__simge"><?= icon('zap') ?></span>
                    <h3>Bir kez analiz, sonra anında</h3>
                    <p>Daha önce analiz edilmiş video yeniden yapay zekâya gönderilmez; hazır rapor saniyesinde açılır.</p>
                </article>

                <article class="tka-kart" data-tka-belir data-tka-isik>
                    <span class="tka-kart__simge"><?= icon('save') ?></span>
                    <h3>Dört biçimde dışa aktarım</h3>
                    <p>Rapor Markdown, transcript düz metin ya da SRT / VTT altyazı dosyası olarak iner.</p>
                    <ul class="tka-bicimler" aria-hidden="true"><li>.md</li><li>.txt</li><li>.srt</li><li>.vtt</li></ul>
                </article>

                <article class="tka-kart" data-tka-belir data-tka-isik>
                    <span class="tka-kart__simge"><?= icon('code') ?></span>
                    <h3>Geliştiriciler için API</h3>
                    <?php /* Anahtarlar Hesabım → API Anahtarları'nda; o bölüm de bu yetkiye (profile.api) bakar. */ ?>
                    <?php if ($apiAcik): ?>
                        <p><?= $analizVaadi ? 'Anahtarla analiz başlatın; raporu' : 'Raporu' ?> ve transcript'i JSON olarak alın, kendi uygulamanıza bağlayın.</p>
                        <code class="tka-kod" aria-hidden="true">GET /api/v1/transkript/kaynaklar/42/ozet</code>
                        <a class="tka-kart__baglanti" href="<?= e(url($girisli ? 'panel/hesabim' : 'giris')) ?>">
                            <?= $girisli ? 'API anahtarı alın' : 'Giriş yapıp anahtar alın' ?> <?= icon('chevron', 'cy-icon cy-icon--sm') ?>
                        </a>
                    <?php else: ?>
                        <p>Raporu ve transcript'i JSON olarak alın, kendi uygulamanıza bağlayın. API anahtarını analiz hesapları kendileri oluşturur; hesabınıza bu yetkinin verilmesi için bize yazın.</p>
                        <code class="tka-kod" aria-hidden="true">GET /api/v1/transkript/kaynaklar/42/ozet</code>
                        <a class="tka-kart__baglanti" href="<?= e(url('iletisim')) ?>">
                            API erişimi için bize yazın <?= icon('chevron', 'cy-icon cy-icon--sm') ?>
                        </a>
                    <?php endif; ?>
                </article>
            </div>
        </div>
    </section>

    <!-- ================= KİMLER İÇİN ================= -->
    <section class="tka-bolum tka-bolum--ara" id="kimler" aria-labelledby="tka-kimler-baslik">
        <div class="tka-kap">
            <header class="tka-bas" data-tka-belir>
                <span class="tka-ust-baslik">Kimler için?</span>
                <h2 id="tka-kimler-baslik">Videodan bilgi çıkaran herkes için</h2>
            </header>

            <div class="tka-kimler">
                <?php foreach ($kimler as $i => [$ikon, $baslik, $metin]): ?>
                    <article class="tka-kim" data-tka-belir style="--i:<?= $i ?>">
                        <span class="tka-kim__simge"><?= icon($ikon) ?></span>
                        <h3><?= e($baslik) ?></h3>
                        <p><?= e($metin) ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ================= PLANLAR (Uyelik\Planlar; sınırlar panelden) ================= -->
    <?php
    $planlar ??= [];
    $planim  ??= null;
    $yonetici ??= false;
    $proFiyat = Planlar::fiyatMetni(Planlar::PRO);
    $proYillik = Planlar::fiyatMetni(Planlar::PRO, 'yillik');
    /* Kartın düğmesi: misafire kayıt, Ücretsiz üyeye Pro'ya geçiş, kendi planında düğme yok. */
    $planEylemi = static function (string $plan) use ($girisli, $kayitAcik, $planim, $yonetici): ?array {
        return match (true) {
            $yonetici, $plan === $planim                => null,
            !$girisli && $plan === Planlar::UCRETSIZ    => $kayitAcik ? [url('kayit'), 'Ücretsiz kaydolun'] : [url('giris'), 'Giriş yapın'],
            !$girisli                                   => $kayitAcik ? [url('kayit'), 'Kaydolun, sonra Pro\'ya geçin'] : [url('giris'), 'Giriş yapın'],
            $plan === Planlar::PRO                      => [url('panel/transkript/uyelik'), 'Pro\'ya geçin'],
            default                                     => null,
        };
    };
    ?>
    <?php if ($planlar !== []): ?>
    <section class="tka-bolum" id="planlar" aria-labelledby="tka-planlar-baslik">
        <div class="tka-kap">
            <header class="tka-bas" data-tka-belir>
                <span class="tka-ust-baslik">Planlar</span>
                <h2 id="tka-planlar-baslik">Ücretsiz başlayın, ihtiyaç olunca Pro</h2>
                <p>Kayıt olan herkes Ücretsiz plandadır. Daha önce analiz edilmiş bir videoyu açmak hiçbir planda hakkınızdan düşmez.</p>
            </header>

            <div class="tka-planlar">
                <?php foreach (Planlar::ADLAR as $plan => $ad): ?>
                    <?php $pro = $plan === Planlar::PRO; $eylem = $planEylemi($plan); ?>
                    <article class="tka-plan<?= $pro ? ' tka-plan--pro' : '' ?>" data-tka-belir aria-labelledby="tka-plan-<?= e($plan) ?>">
                        <?php if ($pro): ?><span class="tka-plan__serit">Daha çok analiz</span><?php endif; ?>
                        <h3 id="tka-plan-<?= e($plan) ?>"><?= icon($pro ? 'star' : 'user', 'cy-icon cy-icon--sm') ?> <?= e($ad) ?>
                            <?php if ($plan === $planim): ?><span class="tka-plan__rozet">Planınız</span><?php endif; ?></h3>
                        <p class="tka-plan__fiyat">
                            <?php if (!$pro): ?>
                                <strong>0 <?= e(Planlar::paraBirimi() === 'TL' ? '₺' : Planlar::PARA_BIRIMLERI[Planlar::paraBirimi()]) ?></strong><small>kayıt olan herkese, süresiz</small>
                            <?php elseif ($proFiyat !== ''): ?>
                                <strong><?= e($proFiyat) ?></strong><?php if ($proYillik !== ''): ?><small>ya da <?= e($proYillik) ?></small><?php endif; ?>
                            <?php else: ?>
                                <strong class="tka-plan__sor">Fiyat için bize yazın</strong>
                            <?php endif; ?>
                        </p>
                        <ul class="tka-plan__liste">
                            <?php foreach ($planlar as $satir): ?>
                                <?php $deger = $satir['degerler'][$plan] ?? false; ?>
                                <li class="<?= $deger === false ? 'is-yok' : 'is-var' ?>">
                                    <?= icon($deger === false ? 'close' : 'check', 'cy-icon cy-icon--sm') ?>
                                    <span><?= e($satir['etiket']) ?><?php if (is_string($deger)): ?>: <strong><?= e($deger) ?></strong><?php endif; ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php if ($eylem !== null): ?>
                            <a class="tka-dugme <?= $pro ? 'tka-dugme--ana' : 'tka-dugme--cizgi' ?> tka-plan__dugme" href="<?= e($eylem[0]) ?>"><?= e($eylem[1]) ?> <?= icon('chevron', 'cy-icon cy-icon--sm') ?></a>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php if ($proFiyat === ''): ?>
                <p class="tka-planlar__not" data-tka-belir>Çevrim içi ödeme henüz yok: kaydolduktan sonra panelde <strong>Üyeliğim → Pro'ya geç</strong> ile talep gönderin; ödeme talimatı orada görünür, ödeme görülünce Pro açılır.</p>
            <?php endif; ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- ================= SIK SORULAN SORULAR ================= -->
    <section class="tka-bolum" id="sss" aria-labelledby="tka-sss-baslik">
        <div class="tka-kap tka-sss">
            <header class="tka-bas tka-bas--sol" data-tka-belir>
                <span class="tka-ust-baslik">Sık sorulan sorular</span>
                <h2 id="tka-sss-baslik">Merak edilenler</h2>
                <p>Aradığınız cevap burada yoksa bize yazın; en kısa sürede dönelim.</p>
                <a class="tka-dugme tka-dugme--cizgi" href="<?= e(url('iletisim')) ?>"><?= icon('mail', 'cy-icon cy-icon--sm') ?> İletişime geçin</a>
            </header>

            <div class="tka-sss__liste" data-tka-belir>
                <?php foreach ($sss as $i => $s): ?>
                    <details class="tka-soru"<?= $i === 0 ? ' open' : '' ?>>
                        <summary><?= e($s['soru']) ?><span class="tka-soru__arti" aria-hidden="true"></span></summary>
                        <div class="tka-soru__cevap"><p><?= e($s['cevap']) ?></p></div>
                    </details>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ================= SON ÇAĞRI ================= -->
    <section class="tka-son" aria-labelledby="tka-son-baslik">
        <div class="tka-kap">
            <div class="tka-son__kutu" data-tka-belir>
                <span class="tka-son__isik" aria-hidden="true"></span>
                <h2 id="tka-son-baslik">Bir saatlik videoya bir saat ayırmayın.</h2>
                <p><?= $analizVaadi
                    ? 'Bağlantıyı yapıştırın, gerisi bize kalsın. Rapor hazır olunca sizi bekliyor olacak.'
                    : 'Raporu okuyun, aradığınız cümleyi transcript\'te bulun, videoyu tam o saniyeden açın.' ?></p>
                <?php $eylemler('alt'); ?>
            </div>
        </div>
    </section>
</div>

<script src="<?= e(Varliklar::adres('anasayfa.js')) ?>" defer></script>
