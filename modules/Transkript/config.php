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
     *  saglayici / model boşsa .env'deki AI_PROVIDER / AI_MODEL, o da
     *  boşsa sağlayıcının varsayılanı (Gemini: gemini-2.5-flash).
     *  Aşamaya ayrı model vermek için buraya yazın, ör.
     *      'model' => 'gemini-2.5-flash-lite'
     *
     *  ÜCRETSİZ KATMAN: Gemini anahtarının projesinde faturalandırma
     *  kapalıysa istekler ücretsizdir ama dakikalık / günlük kotası
     *  vardır. Dakika sınırında istek bekleyip yeniden dener; günlük
     *  kota dolunca analiz "ai_kota_doldu" hatasıyla durur. Maliyet
     *  sütunları yine ücretli katman fiyatıyla TAHMİN yazılır.
     *
     *  gemini_ek: yalnız Gemini'de generationConfig'e eklenir. Düşünme
     *  (thinking) token'ları çıkış bütçesinden yer ve faturalanır;
     *  map'te kapalı (0), reduce'ta sınırlı. thinkingBudget 0 yalnız
     *  Flash modellerinde geçerlidir; Pro'ya geçilirse değiştirin.
     *
     *  istem_surumu: prompts/<ad>.<surum>.txt; analizle birlikte saklanır.
     * -------------------------------------------------------------- */
    'ai' => [
        'istem_surumu' => 'v2',

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
    ],

    /* -----------------------------------------------------------------
     *  ANALİZ BAŞLATMA (Services\AnalizBaslatici)
     * -----------------------------------------------------------------
     *  Yeni AI işi açan istek kullanıcı başına saatte bu kadarla
     *  sınırlıdır (panel ve API ortak sayaç). Önbellekten dönen ya da
     *  sürmekte olan analize düşen istek sayılmaz. 0 → sınır yok.
     * -------------------------------------------------------------- */
    'analiz' => [
        'saatlik_sinir' => 20,

        // Bundan uzun video analiz edilmez (dakika; 0 = sınırsız). Süre
        // biliniyorsa istekte, bilinmiyorsa video bilgisi ya da transcript
        // gelince, yapay zekâya gitmeden önce denetlenir.
        'en_uzun_dk' => 180,
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
     * -------------------------------------------------------------- */
    'kuyruk' => [
        'zamanlanmis_gorev' => true,
        'butce_sn'          => 240,
        'en_cok_is'         => 50,
        'panelden_isle'     => false,
    ],
];
