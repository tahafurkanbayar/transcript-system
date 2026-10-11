<?php
/**
 * =====================================================================
 *  Transkript MODÜLÜ – Ayarlar             →  Ayarlar::get('parca.hedef_token')
 * ---------------------------------------------------------------------
 *  Çekirdeğin config/ klasörü modül dosyalarını okumaz; bu dosyayı
 *  Modules\Transkript\Ayarlar bir kez yükler.
 *
 *  ANAHTAR YOK: API anahtarları yalnızca .env'de durur (CLAUDE.md,
 *  kural 5). Burada yalnız davranış ayarları ve .env'den okunan,
 *  gizli olmayan seçimler vardır.
 * =====================================================================
 */

declare(strict_types=1);

use App\Core\Env;

return [

    /* -----------------------------------------------------------------
     *  TRANSCRIPT ZİNCİRİ (docs/TEKNIK_RAPOR.md, 5. bölüm)
     * -----------------------------------------------------------------
     *  Sağlayıcılar bu sırayla denenir; ilk başarılı sonuç kaydedilir.
     *  Kaynağın türünü desteklemeyen sağlayıcı atlanır.
     *      youtube  YouTube altyazısı (elle ya da otomatik), ücretsiz
     *      gemini   Gemini'ye YouTube URL'si (ücretli; GEMINI_API_KEY)
     *      harici   ücretli transcript API'si (5c. aşama, şimdilik kapalı)
     *      asr      yüklenen dosya için Whisper ve benzeri (5b. aşama)
     *  .env: TRANSCRIPT_PROVIDERS=youtube,gemini
     * -------------------------------------------------------------- */
    'transcript' => [
        'saglayicilar' => Env::list('TRANSCRIPT_PROVIDERS', ['youtube', 'gemini']),

        /* Dil sırası: önce bu diller (yalnız elle girilmiş altyazı),
         * sonra videonun orijinal dili, sonra İngilizce. İngilizce
         * video İngilizce transcript'le analiz edilir; rapor Türkçe. */
        'tercih_diller' => ['tr'],

        'youtube' => [
            // InnerTube "player" ucunun istemcisi. YouTube eski sürümleri
            // reddetmeye başlarsa yalnız buradaki sürüm güncellenir.
            'istemci'    => ['clientName' => 'ANDROID', 'clientVersion' => '20.10.38'],
            'zaman_asimi' => 20,
        ],

        'gemini' => [
            'model' => trim(Env::get('GEMINI_TRANSCRIPT_MODEL')) !== '' ? trim(Env::get('GEMINI_TRANSCRIPT_MODEL')) : 'gemini-2.5-flash',
            // Uzun video bu uzunlukta pencerelere bölünür (videoMetadata
            // startOffset / endOffset); her pencerenin çıktısı max token
            // sınırına sığar.
            'pencere_dakika' => 20,
            'max_token'      => 16000,
            // generationConfig eki. Yazıya dökmek için görüntü kalitesi ve
            // düşünme gerekmez; ikisi de maliyeti düşürür. thinkingBudget 0
            // yalnız Flash modellerinde geçerlidir; Pro'ya geçilirse silin.
            'ek' => [
                'mediaResolution' => 'MEDIA_RESOLUTION_LOW',
                'thinkingConfig'  => ['thinkingBudget' => 0],
            ],
        ],
    ],

    /* -----------------------------------------------------------------
     *  TEMİZLEME (Services\Cleaner)
     * -------------------------------------------------------------- */
    'temizleme' => [
        // Çok kısa segmentler bu uzunlukta cümle bloklarına birleştirilir.
        'blok_en_az_sn' => 15,
        'blok_en_cok_sn' => 30,
    ],

    /* -----------------------------------------------------------------
     *  PARÇALAMA (Services\Chunker)
     * -----------------------------------------------------------------
     *  Hedef ~8–10 dakika ya da ~4.000 token, hangisi önce dolarsa;
     *  30 saniye örtüşme. Cümle ortasından bölünmez.
     * -------------------------------------------------------------- */
    'parca' => [
        'en_az_sn'     => 480,
        'en_cok_sn'    => 600,
        'hedef_token'  => 4000,
        'ortusme_sn'   => 30,
        // Token tahmini: karakter / bu sayı. Türkçe İngilizceden fazla
        // token tutar; 3,5 ikisi için de temkinli bir orta değer.
        'karakter_basina_token' => 3.5,
    ],

    /* -----------------------------------------------------------------
     *  YAPAY ZEKÂ AŞAMALARI (Services\Pipeline)
     * -----------------------------------------------------------------
     *  map     her parça ayrı (paralel); hızlı ve ucuz model yeter
     *  reduce  parça sonuçlarını tek rapora birleştirir; daha uzun çıktı
     *
     *  saglayici boşsa zincirin ilki (.env AI_PROVIDERS; varsayılan EVREN).
     *  model boşsa sağlayıcının Transkript ayarlarındaki modeli (EVREN
     *  modeli, Gemini modeli …), yoksa .env <AD>_MODEL, yoksa varsayılanı
     *  (EVREN: qwen3.8-flash-next, Gemini: gemini-2.5-flash). Asıl sağlayıcıya aşama
     *  başı ayrı model vermek için buraya yazın, ör.
     *      'model' => 'deepseek-v4.1-flash'
     *
     *  ÜCRETSİZ KATMAN: Gemini anahtarının projesinde faturalandırma
     *  kapalıysa istekler ücretsizdir ama dakikalık / günlük kotası
     *  vardır. Dakika sınırında istek bekleyip yeniden dener; günlük
     *  kota dolunca iş kota yenilenene kadar ERTELENİR (hata olmaz,
     *  biten parçalar korunur; en çok erteleme_gun gün). Maliyet
     *  sütunları yine ücretli katman fiyatıyla TAHMİN yazılır.
     *
     *  gemini_ek: yalnız Gemini'de generationConfig'e eklenir. Düşünme
     *  (thinking) token'ları çıkış bütçesinden yer ve faturalanır;
     *  map'te kapalı (0), reduce'ta sınırlı. thinkingBudget 0 yalnız
     *  Flash modellerinde geçerlidir; Pro'ya geçilirse değiştirin.
     *
     *  istem_surumu: prompts/<ad>.<surum>.txt; analizle birlikte saklanır.
     *
     *  YEDEK ZİNCİRİ (Ai\YedekliSaglayici): .env'de
     *      AI_PROVIDERS=evren,gemini,openrouter,groq
     *  verilirse sağlayıcılar bu sırayla denenir; biri kota / hata verirse
     *  sıradakine geçilir (boşsa aynı sıra, Ai::VARSAYILAN_ZINCIR).
     *  Anahtarı (EVREN_API_KEY, GEMINI_API_KEY, OPENROUTER_API_KEY,
     *  MISTRAL_API_KEY, GROQ_API_KEY, CEREBRAS_API_KEY) tanımlı olmayan
     *  atlanır. Her sağlayıcı kendi modelini kullanır (yukarıda).
     *  EVREN akıl yürüten modellerde max_token düşünmeyle paylaşılır; en
     *  az 2048 verilir, süre en az 180 sn (Ai\EvrenProvider).
     *  yedek.dinlenme_sn: hata veren sağlayıcı bu kadar saniye denenmez
     *  (bütün işçiler için); 0 ya da listede yoksa dinlenmez. Günlük
     *  kota bu listeye bakmaz: sağlayıcının bildirdiği yenilenmeye
     *  (EVREN 03:00 İstanbul), yoksa kota_saat_dilimi'nde gece yarısına.
     *  Hepsi dinlenirken iş hata vermez, en erken açılışa ertelenir.
     *  Anahtar ve model hatası "devre dışı"dır; Transkript ayarları →
     *  Durum'daki "Dinlenmeleri sıfırla" ile hemen yeniden denenir.
     * -------------------------------------------------------------- */
    'ai' => [
        'yedek' => [
            'dinlenme_sn' => [
                'kota_doldu'       => 3600,   // yalnız sıfırlama saati hesaplanamazsa
                'rate_limit'       => 60,     // sağlayıcı Retry-After vermediyse
                'yetkisiz'         => 21600,  // anahtar geçersiz / faturalandırma kapalı: devre dışı
                'model_bulunamadi' => 21600,  // model kaldırılmış: devre dışı (yalnız o model)
                'sartlar_onaylanmadi' => 3600, // EVREN kullanım şartları onaylanmamış: devre dışı
                'sunucu'           => 120,
                'baglanti'         => 120,
                'zaman_asimi'      => 120,
            ],
            // Gemini ücretsiz kotası Pasifik saatiyle gece yarısı yenilenir.
            'kota_saat_dilimi' => 'America/Los_Angeles',
            // İş en çok bu kadar gün ertelenir; sonra hatayla biter.
            'erteleme_gun'     => 2,
            /* Bir isteğin bütün zincir denemeleri en çok bu kadar sürer (sn);
             * aşılırsa kalan halkalar denenmez, iş 1 dk sonra yeniden kuyruğa
             * girer. 11.10.2026: EVREN 180 + OpenRouter 1 + Groq 187 sn = 6 dk. */
            'zincir_en_cok_sn' => 900,
        ],

        /* EVREN (Ai\EvrenProvider). Aşama modeli boşsa Transkript ayarlarındaki
         * EVREN modeli. yedek_model: asıl EVREN modeli zaman aşımı, düşünme
         * bütçesi ya da 503 verince BAŞKA sağlayıcıya geçmeden önce bir kez
         * denenen hızlı EVREN modeli ('' = kapalı); .env EVREN_YEDEK_MODEL ezer.
         * Ayar "auto" ise analizin ilk yanıtındaki metin modeli o analize
         * sabitlenir (transkript_analizler.evren_model). */
        'evren' => [
            'bolum_modeli' => '',
            'rapor_modeli' => '',
            'yedek_model'  => 'gemma-4-31b',
        ],

        'istem_surumu' => 'v3',

        /* Sağlayıcı başına en büyük istek, yaklaşık GİRİŞ token'ı (Ai\GirisSiniri).
         * Bundan büyük istek o sağlayıcıya gönderilmez (sıradakine geçilir,
         * sağlayıcı dinlenmez). 0 = bilinmiyor / sınır yok. .env'de
         * <AD>_MAX_INPUT_TOKENS (ör. GROQ_MAX_INPUT_TOKENS=12000) bunu ezer.
         * Sağlayıcı 413 / bağlam aşımı verirse o boyut 7 gün sınır olur. */
        'giris_siniri' => [
            'evren'      => 200000,   // modeller 256K–1M bağlam; en küçüğe göre pay bırakıldı
            'gemini'     => 900000,   // 1M bağlam
            'openrouter' => 100000,   // ücretsiz modellerin çoğu 128K
            'groq'       => 7000,     // ücretsiz katman: dakikada 8000 token (TPM), istek başına da geçerli
            'mistral'    => 100000,
            'cerebras'   => 7000,     // ücretsiz katman bağlamı 8K
            'claude'     => 180000,
        ],

        /* Kademeli birleştirme (Jobs\AnalizBirlestir): birleştirme girdisi açık
         * sağlayıcıların sınırını aşınca bölümler en çok grup_en_cok'luk
         * gruplarda ara özete çevrilir, sonra ara özetler birleştirilir. */
        'kademeli' => [
            'grup_en_cok' => 4,
            'seviye_en_cok' => 4,
        ],

        /* Sağlayıcı başına model (Ai::varsayilanModel), ör. 'evren' =>
         * 'deepseek-v4.1-flash'. Boşsa .env <AD>_MODEL, o da yoksa
         * sağlayıcının varsayılanı. Panelden: Transkript ayarları → Yapay
         * zekâ (EVREN modeli, Google Gemini modeli …). */
        'modeller' => [],

        'map' => [
            'saglayici'   => '',
            'model'       => '',
            'max_token'   => 8192,
            'zaman_asimi' => 120,
            'gemini_ek'   => ['thinkingConfig' => ['thinkingBudget' => 0]],
        ],

        'reduce' => [
            'saglayici'   => '',
            'model'       => '',
            'max_token'   => 24000,
            'zaman_asimi' => 240,
            'gemini_ek'   => ['thinkingConfig' => ['thinkingBudget' => 4096]],
        ],

        // Onarım isteğinde modele geri verilen bozuk yanıtın en çok uzunluğu.
        'onarim_en_cok_karakter' => 6000,

        // Maliyet tahmini için 1M token fiyatı (USD). 0: sağlayıcının kodda
        // duran fiyat tablosu (PRICING). Panelden de değiştirilebilir.
        'fiyat' => ['giris' => 0.0, 'cikis' => 0.0],
    ],

    /* -----------------------------------------------------------------
     *  ANALİZ BAŞLATMA VE GENEL TAVANLAR (Services\AnalizBaslatici)
     * -----------------------------------------------------------------
     *  Kişi başına sınırlar ÜYELİK PLANINDADIR (aşağıda "uyelik"). Burada
     *  herkes için geçerli tavanlar durur. Yalnız YENİ AI işi açan istek
     *  sayılır; önbellekten dönen ya da sürmekte olan analize düşen istek
     *  sayılmaz (ikinci AI çağrısı yok). Aşılınca 429 + Retry-After ve
     *  Türkçe mesaj. 0 → sınır yok.
     *
     *  yonetici_muaf        transkript.admin plan sınırlarına takılmaz
     *  gunluk_token_tavani  bütün kullanıcılar, bugün harcanan token
     *  gunluk_maliyet_tavani bütün kullanıcılar, bugünkü tahmini maliyet
     *                       (USD, ücretli katman fiyatıyla). Genel tavan
     *                       bütçedir: yöneticiye de uygulanır.
     *  en_uzun_dk           SİSTEMİN en uzun videosu (yönetici dahil);
     *                       planın süre sınırı bunu aşamaz.
     * -------------------------------------------------------------- */
    'analiz' => [
        'yonetici_muaf'         => true,
        'gunluk_token_tavani'   => 0,
        'gunluk_maliyet_tavani' => 5.0,

        // Bundan uzun video analiz edilmez (dakika; 0 = sınırsız). Süre
        // biliniyorsa istekte, bilinmiyorsa video bilgisi ya da transcript
        // gelince, yapay zekâya gitmeden önce denetlenir.
        'en_uzun_dk' => 180,
    ],

    /* -----------------------------------------------------------------
     *  ÜYELİK PLANLARI (Uyelik\Planlar; kullanıcı isteği 2026-10-10)
     * -----------------------------------------------------------------
     *  Plan ROLDEN gelir; ayrı bir "plan" sütunu yoktur:
     *      uye     Ücretsiz plan: kayıt olan herkes. Kısıtlı analiz hakkı.
     *      editor  Pro plan (ücretli): daha yüksek sınırlar ve ek özellikler.
     *      admin   plan yok; yonetici_muaf açıksa kişisel sınırı yoktur.
     *
     *  Pro üyelik yönetici panelinden verilir (Transkript → Üyelikler):
     *  ödeme alınınca (havale / EFT …) yönetici süreyi girer, rol "editor"
     *  olur; süre bitince rol kendiliğinden "uye"ye döner
     *  (Uyelik\UyelikYonetimi). Üye "Üyeliğim" sayfasından talep gönderir.
     *  Çevrim içi ödeme (iyzico, PayTR, Stripe…) henüz yok.
     *
     *  analiz          bu plan analiz başlatır mı (Pro'da her zaman açık)
     *  gunluk_sinir    günde başlatılan analiz (bugün başlattığı)
     *  saatlik_sinir   saatte (panel ve API ortak); günlükten küçük olmalı
     *  en_uzun_dk      planın en uzun videosu; sistemin tavanını aşamaz
     *  yeniden_analiz  kendi raporunu yeniden yapay zekâya gönderebilir
     *  altyazi_indir   SRT / VTT altyazı indirme (rapor ve metin herkese)
     *  fiyat_aylik     görünen fiyat; 0 → "fiyat için iletişime geçin"
     *  fiyat_yillik    0 → yıllık seçenek gösterilmez
     *
     *  para_birimi     fiyatların yanında yazar (TL, USD, EUR)
     *  odeme_bilgisi   "Pro'ya geç" talebinden sonra üyeye gösterilen
     *                  ödeme talimatı (IBAN, alıcı adı…); yönetici panelden
     *                  yazar. Gizli bilgi değildir ama KİŞİSEL veri
     *                  olabilir; yalnız giriş yapmış kullanıcıya görünür.
     *  hatirlatma_gun  Pro süresinin bitmesine bu kadar gün kala e-posta
     * -------------------------------------------------------------- */
    'uyelik' => [
        'planlar' => [
            'uye' => [
                'analiz'         => true,
                'gunluk_sinir'   => 2,
                'saatlik_sinir'  => 1,
                'en_uzun_dk'     => 30,
                'yeniden_analiz' => false,
                'altyazi_indir'  => false,
                'fiyat_aylik'    => 0,
                'fiyat_yillik'   => 0,
            ],
            'editor' => [
                'analiz'         => true,
                'gunluk_sinir'   => 20,
                'saatlik_sinir'  => 5,
                'en_uzun_dk'     => 180,
                'yeniden_analiz' => true,
                'altyazi_indir'  => true,
                'fiyat_aylik'    => 0,
                'fiyat_yillik'   => 0,
            ],
        ],
        'para_birimi'    => 'TL',
        'odeme_bilgisi'  => '',
        'hatirlatma_gun' => 3,
    ],

    /* -----------------------------------------------------------------
     *  KUYRUK (events.php → "transkript-kuyrugu" zamanlanmış görevi)
     * -----------------------------------------------------------------
     *  Görev her dakika tetiklenir ve "transkript" kuyruğunu bu süre
     *  boyunca işler; süre dolunca elindeki işi bitirip çıkar. Önceki
     *  çalışma sürerken yenisi başlamaz (görev kilidi). Kalıcı bir
     *  işçi (php cy queue:work) çalıştırılıyorsa görevi kapatın.
     *
     *  panelden_isle: cron'u olmayan barındırma için yedek. Açıksa
     *  yönetici, işçi çalışmıyor uyarısındaki "Şimdi işle" düğmesiyle
     *  sıradaki işleri istek içinde çalıştırır (en çok ~20 sn; bir AI
     *  adımı sürerse istek daha uzun sürer). Geliştirme ortamında
     *  (APP_ENV yayın değilse) bu ayar olmadan da açıktır.
     *
     *  tarayicidan_isle: komut satırı açmadan. İşçi görülmüyorsa ve iş
     *  bekliyorsa, açık panel sayfasının ilerleme yoklaması arka planda
     *  POST panel/transkript/kuyruk/tik atar; sunucu veritabanı kilidiyle
     *  TEK bir işi çalıştırır (sekme kapansa da o işi bitirir). null:
     *  geliştirme ortamında açık, yayında kapalı. Transkript ayarlarından
     *  değiştirilir. Kalıcı çözüm işçidir (kuyruk-isci-kur.bat).
     * -------------------------------------------------------------- */
    'kuyruk' => [
        /* İş nabzı (Jobs\IsNabzi, 30 sn'de bir) bu kadar saniye gelmeyen
         * "transkript" işi ölmüştür: kuyruğa geri bırakılır, aşama / parça
         * kilidi devralınır (cowork 3. tur: 5 dk). .env QUEUE_TIMEOUT daha
         * küçükse o geçerli. */
        'nabiz_esik_sn' => 300,
        'zamanlanmis_gorev' => true,
        'butce_sn'          => 240,
        'en_cok_is'         => 50,
        'panelden_isle'     => false,
        'tarayicidan_isle'  => null,
    ],

    /* -----------------------------------------------------------------
     *  ANA SAYFA (events.php → App\Core\HomePage)
     * -----------------------------------------------------------------
     *  Açıkken sitenin ana sayfası modülün tanıtım sayfasıdır
     *  (views/anasayfa.php). Kapalıyken çekirdeğin Panel → Ayarlar →
     *  Ana Sayfa'dan beslenen genel vitrini görünür. Panelden de
     *  değiştirilir (Transkript ayarları → Ana sayfa).
     * -------------------------------------------------------------- */
    'anasayfa' => [
        'tanitim' => true,
    ],
];
