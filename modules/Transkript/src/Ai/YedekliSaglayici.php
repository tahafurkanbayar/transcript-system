<?php
/**
 * =====================================================================
 *  YedekliSaglayici – sıralı birden çok yapay zekâyı tek sağlayıcı gibi sunar
 * ---------------------------------------------------------------------
 *  Ücretsiz katmanların günlük kotası çabuk doluyor: uzun bir video
 *  10–20 AI çağrısı açar ve Gemini'nin ücretsiz kotası bitince analiz
 *  "ai_kota_doldu" ile duruyordu. .env'de birden çok sağlayıcı
 *  tanımlanınca (AI_PROVIDERS=gemini,openrouter,groq) bu sınıf onları
 *  sırayla dener; biri hata verirse sıradakine geçer. Pipeline yine tek
 *  bir Provider görür. Anahtarı olmayan sağlayıcı zincire hiç girmez
 *  (Pipeline::saglayiciUret).
 *
 *  DİNLENME (DinlenmeDeposu: kalıcı dosya, işçiler arasında ortak; önbellek
 *  temizlenince kaybolmaz): hata veren sağlayıcı bir süre denenmez. Süre
 *  hatanın türüne göre:
 *
 *      kota_doldu        günlük kota: bir sonraki sıfırlamaya kadar. Sağlayıcı
 *                        saati bildirirse o (EVREN: 03:00 İstanbul), yoksa
 *                        Pasifik gece yarısı (ai.yedek.kota_saat_dilimi)
 *      rate_limit        sağlayıcının dediği kadar (Retry-After), yoksa 60 sn
 *      sunucu, baglanti,
 *      zaman_asimi       kısa (120 sn)
 *      yetkisiz,
 *      model_bulunamadi  DEVRE DIŞI (6 saat ya da yönetici sıfırlayana dek);
 *                        model hatası o modele bağlıdır, .env'de model
 *                        değişince sağlayıcı yeniden denenir
 *      sartlar_onaylanmadi  DEVRE DIŞI 1 saat (EVREN kullanım şartları;
 *                        kod kendiliğinden onaylamaz, yönetici onaylar)
 *      cok_buyuk (413),
 *      dusunme_butcesi,
 *      istek_hatasi …    dinletmez: isteğe özgüdür, sıradakine geçilir
 *
 *  Dinlenen sağlayıcı DENENMEZ (süreler artık gerçek açılışı izliyor).
 *
 *  HEPSİ DÜŞERSE
 *    · geçici olarak dinlenen biri varsa → "zincir_dinleniyor", bekleme =
 *      en erken açılış. HATA DEĞİL: iş o saate ertelenir (TranskriptIsi),
 *      biten parçalar korunur.
 *    · yoksa (hepsi devre dışı ya da isteğe özgü hata) → son hatanın kodu;
 *      analiz durur, yöneticiye zincirin özeti gösterilir (ozet()).
 *
 *  BEKLEMEDEN GEÇİŞ: Arkasında başka sağlayıcı olan halka 429 / 5xx'te
 *  aynı istek içinde beklemez (HttpProvider::yenidenDenemeSiniri(0)).
 *
 *  Fikir: kullanıcının ci4 projesindeki FallbackLlmClient (aynı sahip).
 *  Fark: anahtarlar veritabanında değil, yalnız .env'de (CLAUDE.md, 5).
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Ai;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Modules\Transkript\Ayarlar;
use Throwable;

final class YedekliSaglayici implements Provider
{
    /** Hata kodu → dinlenme (sn). Listede olmayan kod dinletmez (isteğe özgü: 400, 413). */
    public const DINLENME_SN = [
        'kota_doldu'       => 3600,     // yalnız sıfırlama saati hesaplanamazsa
        'rate_limit'       => 60,       // sağlayıcı süre söylemediyse
        'yetkisiz'         => 21600,
        'model_bulunamadi' => 21600,
        'sartlar_onaylanmadi' => 3600,
        'sunucu'           => 120,
        'baglanti'         => 120,
        'zaman_asimi'      => 120,
    ];

    /** Ayar hatası: erteleme sebebi değil; süre dolana ya da yönetici sıfırlayana dek devre dışı. */
    public const DEVRE_DISI = ['yetkisiz', 'model_bulunamadi', 'sartlar_onaylanmadi'];

    private const GECICI = ['rate_limit', 'sunucu', 'baglanti', 'zaman_asimi'];

    /** Yöneticiye gösterilen kısa sebep (özet ve durum tablosu). */
    public const SEBEPLER = [
        'kota_doldu'        => 'günlük kota doldu',
        'rate_limit'        => 'hız sınırı (429)',
        'yetkisiz'          => 'anahtar geçersiz (401/403)',
        'model_bulunamadi'  => 'model yok (404)',
        'sartlar_onaylanmadi' => 'kullanım şartları onaylanmadı (403)',
        'dusunme_butcesi'   => 'düşünme bütçesi yetmedi',
        'sunucu'            => 'sunucu hatası (5xx)',
        'baglanti'          => 'bağlantı hatası',
        'zaman_asimi'       => 'zaman aşımı',
        'cok_buyuk'         => 'istek çok büyük (413)',
        'istek_hatasi'      => 'istek reddedildi (400)',
        'yanit_cozulemedi'  => 'yanıt okunamadı',
        'anahtar_yok'       => 'anahtar yok',
        'ayar_eksik'        => 'ayar eksik',
        'zincir_dinleniyor' => 'hepsi dinleniyor',
        'gecersiz'          => 'geçersiz yanıt',
        'reddedildi'        => 'model yanıtlamadı',
        'hata'              => 'hata',
    ];

    /** Son başarılı halka (sonAd / model). */
    private ?int $son = null;

    /** @var list<array{ad:string, model:string, kod:string, mesaj:string, sure_ms:int}> */
    private array $denemeler = [];

    /** @var list<string> son çağrıda istek sığmadığı için hiç denenmeyenler (GirisSiniri) */
    private array $atlananlar = [];

    /**
     * @param list<array{ad:string, saglayici:Provider, ek?:array<string,mixed>}> $halkalar öncelik sırasında
     * @param ?Closure(): int $saat test için zaman kaynağı
     */
    public function __construct(private readonly array $halkalar, private readonly ?Closure $saat = null)
    {
        if ($halkalar === []) {
            throw new AiHatasi('Yedek zincirinde hiç sağlayıcı yok.', 'ayar_eksik');
        }

        foreach ($halkalar as $i => $halka) {
            if ($i < count($halkalar) - 1 && $halka['saglayici'] instanceof HttpProvider) {
                $halka['saglayici']->yenidenDenemeSiniri(0);
            }
        }
    }

    public function send(array $messages, string $system = ''): array
    {
        return $this->dene(static fn (Provider $p, array $ek): array => $p->send($messages, $system), GirisSiniri::tahmin($system, $messages));
    }

    public function sendJson(array $messages, string $system, array $sema, array $ek = []): array
    {
        // $ek yok sayılır: her halkanın kendi eki vardır (Gemini thinkingConfig yalnız Gemini'ye).
        return $this->dene(static fn (Provider $p, array $halkaEki): array => $p->sendJson($messages, $system, $sema, $halkaEki), GirisSiniri::tahmin($system, $messages));
    }

    /** Son yanıtı veren sağlayıcının modeli; henüz yoksa ilk halkanınki. */
    public function model(): string
    {
        return $this->halkalar[$this->son ?? 0]['saglayici']->model();
    }

    /** Son yanıtı veren sağlayıcının adı ("gemini", "groq" …); kullanım kaydı bunu yazar. */
    public function sonAd(): string
    {
        return $this->halkalar[$this->son ?? 0]['ad'];
    }

    /** @return list<string> zincirin sırası */
    public function adlar(): array
    {
        return array_map(static fn (array $h): string => $h['ad'], $this->halkalar);
    }

    /**
     * Son çağrıda düşen sağlayıcılar (başarılı olan hariç). Pipeline
     * bunları kullanım kaydına "hata" satırı olarak yazar.
     *
     * @return list<array{ad:string, model:string, kod:string, mesaj:string, sure_ms:int}>
     */
    public function denemeler(): array
    {
        return $this->denemeler;
    }

    /** @return list<string> son çağrıda isteğe sığmadığı için atlananlar */
    public function atlananlar(): array
    {
        return $this->atlananlar;
    }

    /* =================================================================
     *  DİNLENME (DinlenmeDeposu: kalıcı dosya; bütün işçiler aynı bilgiyi görür)
     * ============================================================== */

    /**
     * Dinlenme kaydı: bitiş, sebep kodu, model, başlangıç. Yoksa null.
     *
     * @return array{bitis:int, kod:string, model:string, zaman:int}|null
     */
    public static function dinlenmeKaydi(string $ad): ?array
    {
        $kayit = DinlenmeDeposu::al($ad);

        return match (true) {
            is_array($kayit) && is_int($kayit['bitis'] ?? null) => [
                'bitis' => $kayit['bitis'],
                'kod'   => (string) ($kayit['kod'] ?? ''),
                'model' => (string) ($kayit['model'] ?? ''),
                'zaman' => (int) ($kayit['zaman'] ?? 0),
            ],
            is_int($kayit) => ['bitis' => $kayit, 'kod' => '', 'model' => '', 'zaman' => 0],   // eski biçim
            default        => null,
        };
    }

    /**
     * Sağlayıcı dinleniyorsa kalan saniye; dinlenmiyorsa 0. Model hatası
     * yalnız o modele bağlıdır: $model başkaysa dinlenme sayılmaz.
     */
    public static function dinlenmeKalan(string $ad, ?int $simdi = null, ?string $model = null): int
    {
        $kayit = self::dinlenmeKaydi($ad);

        if ($kayit === null) {
            return 0;
        }

        if ($model !== null && $kayit['kod'] === 'model_bulunamadi' && $kayit['model'] !== '' && $kayit['model'] !== $model) {
            return 0;
        }

        return max(0, $kayit['bitis'] - ($simdi ?? time()));
    }

    /**
     * Hata koduna göre dinlendirir.
     *
     * @param ?int $bekleme sağlayıcının önerdiği süre (Retry-After; günlük
     *                      kotada yenilenmeye kalan süre, bkz. HttpProvider::hataBeklemesi)
     * @return int dinlenme süresi (sn); 0 → dinlenmez
     */
    public static function dinlendir(string $ad, string $kod, ?int $simdi = null, ?int $bekleme = null, string $model = ''): int
    {
        $simdi ??= time();
        $sure  = match (true) {
            $kod === 'kota_doldu' && (int) $bekleme > 0 => min((int) $bekleme, 2 * 86400),
            $kod === 'kota_doldu'                       => self::kotaSifirlanmasinaKadar($simdi),
            $kod === 'rate_limit' && (int) $bekleme > 0 => min((int) $bekleme, 6 * 3600),
            default                                     => self::dinlenmeSuresi($kod),
        };

        if ($sure > 0) {
            DinlenmeDeposu::yaz($ad, ['bitis' => $simdi + $sure, 'kod' => $kod, 'model' => $model, 'zaman' => $simdi, 'sakla' => time() + $sure]);
        }

        return $sure;
    }

    public static function dinlenmeyiBitir(string $ad): void
    {
        DinlenmeDeposu::sil($ad);
    }

    public static function dinlenmeSuresi(string $kod): int
    {
        $ayar = (array) Ayarlar::get('ai.yedek.dinlenme_sn', []);

        return max(0, (int) ($ayar[$kod] ?? self::DINLENME_SN[$kod] ?? 0));
    }

    /**
     * Günlük kotanın sıfırlanmasına kalan süre (+1 dk pay). Gemini'nin
     * ücretsiz kotası Pasifik saatiyle gece yarısı yenilenir (Türkiye'de
     * yaz saatinde 10:00, kışın 11:00).
     */
    public static function kotaSifirlanmasinaKadar(int $simdi): int
    {
        try {
            $bolge = new DateTimeZone((string) Ayarlar::get('ai.yedek.kota_saat_dilimi', 'America/Los_Angeles'));
        } catch (Throwable) {
            return self::dinlenmeSuresi('kota_doldu');
        }

        $yarin = (new DateTimeImmutable('@' . $simdi))->setTimezone($bolge)->modify('tomorrow');

        return max(60, $yarin->getTimestamp() - $simdi + 60);
    }

    /* =================================================================
     *  ZİNCİR
     * ============================================================== */

    /**
     * @param Closure(Provider, array<string,mixed>): array<string,mixed> $cagri
     * @param int $boyut isteğin yaklaşık token sayısı (GirisSiniri::tahmin)
     * @return array<string,mixed>
     */
    private function dene(Closure $cagri, int $boyut = 0): array
    {
        $this->denemeler  = [];
        $this->atlananlar = [];
        $simdi = $this->saat !== null ? ($this->saat)() : time();
        $son   = null;

        foreach ($this->halkalar as $i => $halka) {
            $model = $halka['saglayici']->model();

            if (self::dinlenmeKalan($halka['ad'], $simdi, $model) > 0) {
                continue;
            }

            // İstek bu sağlayıcının sınırını aşıyor: hiç gönderilmez, sağlayıcı dinlenmez.
            if ($boyut > 0 && !GirisSiniri::sigar($halka['ad'], $model, $boyut)) {
                $this->atlananlar[] = $halka['ad'];

                continue;
            }

            $bas = hrtime(true);

            try {
                $yanit = $cagri($halka['saglayici'], (array) ($halka['ek'] ?? []));

                $this->son = $i;

                return $yanit;
            } catch (AiHatasi $e) {
                $son = $e;
                $this->denemeler[] = [
                    'ad'      => $halka['ad'],
                    'model'   => $model,
                    'kod'     => $e->kod,
                    'mesaj'   => $e->getMessage(),
                    'sure_ms' => (int) ((hrtime(true) - $bas) / 1_000_000),
                ];

                /* 413: isteğin %80'i sınır olur; aynı boyut bu sağlayıcıya
                 * yeniden gitmez (birleştirme küçük gruplara böler). Dakikalık
                 * kota (Groq TPM) ise sağlayıcı 60 sn dinlenir: sıradaki
                 * küçük istek dolu dakikaya gitmesin. Öteki 413 dinletmez. */
                if (GirisSiniri::buyukIstekHatasi($e)) {
                    $dakikalik = GirisSiniri::dakikalik($e->getMessage());
                    GirisSiniri::ogren($halka['ad'], $model, $boyut, $simdi, $dakikalik);

                    if ($dakikalik) {
                        self::dinlendir($halka['ad'], 'rate_limit', $simdi, GirisSiniri::DAKIKALIK_DINLENME_SN, $model);
                    }

                    continue;
                }

                self::dinlendir($halka['ad'], $e->kod, $simdi, $e->bekleme, $model);
            }
        }

        throw $this->sonuc($son, $simdi, $boyut);
    }

    /**
     * Hiçbir halka yanıt vermedi. Bu isteği alabilecek (sınırı yeten) ve
     * geçici olarak dinlenen biri varsa iş ertelenir ("zincir_dinleniyor",
     * en erken açılış). İstek açık olanların hepsine büyükse ve alabilecek
     * kimse yoksa "cok_buyuk" (birleştirme kademeliye geçer); yoksa son hata.
     */
    private function sonuc(?AiHatasi $son, int $simdi, int $boyut = 0): AiHatasi
    {
        $acilis = [];

        foreach ($this->halkalar as $halka) {
            $model = $halka['saglayici']->model();
            $kayit = self::dinlenmeKaydi($halka['ad']);
            $kalan = self::dinlenmeKalan($halka['ad'], $simdi, $model);

            if ($kalan > 0 && !in_array($kayit['kod'] ?? '', self::DEVRE_DISI, true) && ($boyut === 0 || GirisSiniri::sigar($halka['ad'], $model, $boyut))) {
                $acilis[] = $kalan;
            }
        }

        $ozet  = self::teknikOzet($this->denemeler);
        $buyuk = $this->atlananlar !== [] || in_array('cok_buyuk', array_column($this->denemeler, 'kod'), true);

        if ($acilis !== []) {
            return new AiHatasi(
                'Yedek zincirindeki sağlayıcılar dinleniyor; en erken ' . min($acilis) . ' sn sonra açılıyor.'
                    . ($buyuk ? ' İstek (~' . $boyut . ' token) açık sağlayıcıların sınırını aşıyor: ' . implode(', ', $this->atlananlar) . '.' : '')
                    . ($ozet !== '' ? ' ' . $ozet : ''),
                'zincir_dinleniyor', $son?->httpDurumu ?? 0, $son, min($acilis), $buyuk ? AiHatasi::SEBEP_BUYUK_GIRDI : null
            );
        }

        if ($buyuk) {
            return new AiHatasi(
                'İstek (~' . $boyut . ' token) bu işi alabilecek sağlayıcıların sınırını aşıyor'
                    . ($this->atlananlar !== [] ? ' (atlanan: ' . implode(', ', $this->atlananlar) . ')' : '') . '.' . ($ozet !== '' ? ' ' . $ozet : ''),
                'cok_buyuk', $son?->httpDurumu ?? 413, $son, null, AiHatasi::SEBEP_BUYUK_GIRDI
            );
        }

        return self::hepsiDustu($this->denemeler, $son);
    }

    /** @param list<array{ad:string, model:string, kod:string, mesaj:string, sure_ms:int}> $denemeler */
    public static function hepsiDustu(array $denemeler, ?AiHatasi $son): AiHatasi
    {
        $kodlar = array_column($denemeler, 'kod');
        $gecici = array_values(array_intersect($kodlar, self::GECICI));

        $kod = match (true) {
            $gecici !== []                                              => $gecici[0],
            $kodlar !== [] && array_unique($kodlar) === ['kota_doldu'] => 'kota_doldu',
            default                                                     => $son?->kod ?? 'ayar_eksik',
        };

        return new AiHatasi('Yedek zincirindeki bütün sağlayıcılar başarısız: ' . self::teknikOzet($denemeler), $kod, $son?->httpDurumu ?? 0, $son);
    }

    /** Log için: her denemenin kodu ve sağlayıcının kendi mesajı. */
    private static function teknikOzet(array $denemeler): string
    {
        return implode(' | ', array_map(
            static fn (array $d): string => sprintf('%s (%s): %s', $d['ad'], $d['kod'], mb_strimwidth($d['mesaj'], 0, 160, '…')),
            $denemeler
        ));
    }

    /* =================================================================
     *  YÖNETİCİ İÇİN ÖZET (sağlayıcı mesajı yok; yalnız kod ve saat)
     * ============================================================== */

    /**
     * "Gemini: günlük kota doldu (açılış yarın 10:01) · OpenRouter: model yok
     * (404) (devre dışı, açılış 03:05) · Mistral: anahtar yok". Analiz bitince hata_ozeti sütununa
     * yazılır; yalnız yöneticiye gösterilir.
     *
     * @param list<array{ad:string, etiket:string, tanimli:bool, model:string}> $zincir Ai::zincirDurumu()
     */
    public static function ozet(array $zincir, ?int $simdi = null): string
    {
        $simdi ??= time();
        $parcalar = [];

        foreach ($zincir as $halka) {
            if (!$halka['tanimli']) {
                $parcalar[] = $halka['etiket'] . ': anahtar yok';
                continue;
            }

            $kayit = self::dinlenmeKaydi($halka['ad']);
            $kalan = self::dinlenmeKalan($halka['ad'], $simdi, $halka['model']);

            if ($kayit === null || $kalan <= 0) {
                continue;
            }

            $parcalar[] = sprintf('%s: %s (%saçılış %s)', $halka['etiket'], self::SEBEPLER[$kayit['kod']] ?? 'hata',
                in_array($kayit['kod'], self::DEVRE_DISI, true) ? 'devre dışı, ' : '', self::saat($kayit['bitis'], $simdi));
        }

        return mb_substr(implode(' · ', $parcalar), 0, 500);
    }

    /** "14:05" ya da başka günse "yarın 10:01". */
    public static function saat(int $zaman, ?int $simdi = null): string
    {
        $simdi ??= time();

        return (date('Y-m-d', $zaman) === date('Y-m-d', $simdi) ? '' : (date('Y-m-d', $zaman) === date('Y-m-d', $simdi + 86400) ? 'yarın ' : date('d.m ', $zaman)))
            . date('H:i', $zaman);
    }

    /**
     * Yöneticinin "Dinlenmeleri sıfırla" düğmesi: zincirdekiler yeniden
     * denenir. GÜNLÜK KOTASI dolanlar ($kotaDahil = false iken) korunur:
     * sıfırlamak kotayı açmaz, her analiz o sağlayıcıyı boşuna dener ve
     * ekran yanlışlıkla "Hazır" der (cowork 6. tur K2). Anahtara ücretli
     * katman eklendiyse yönetici kutuyu işaretleyip onları da sıfırlar.
     *
     * @param  list<string> $adlar
     * @return list<string> sıfırlananlar
     */
    public static function hepsiniSifirla(array $adlar, bool $kotaDahil = false): array
    {
        $sifirlanan = [];

        foreach ($adlar as $ad) {
            // Öğrenilen giriş sınırı da silinir (katman yükseltildiyse büyük istek yeniden denensin).
            GirisSiniri::unut((string) $ad);

            $kayit = self::dinlenmeKaydi((string) $ad);

            if (!$kotaDahil && $kayit !== null && $kayit['kod'] === 'kota_doldu' && self::dinlenmeKalan((string) $ad) > 0) {
                continue;
            }

            self::dinlenmeyiBitir((string) $ad);
            $sifirlanan[] = (string) $ad;
        }

        return $sifirlanan;
    }
}
