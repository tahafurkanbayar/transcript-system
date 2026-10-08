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
];
