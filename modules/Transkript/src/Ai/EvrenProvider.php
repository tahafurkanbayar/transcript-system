<?php
/**
 * =====================================================================
 *  EvrenProvider – EVREN LLM API (SSYZ, yurt içi)
 * ---------------------------------------------------------------------
 *      POST https://evren-llmapi.ssyz.org.tr/v1/chat/completions
 *
 *  Tel biçimi OpenAI uyumludur (OpenAiCompatibleProvider: Bearer
 *  anahtar, chat/completions, usage.prompt_tokens / completion_tokens).
 *  Farkı hata, kota ve düşünme davranışındadır:
 *
 *    · DÜŞÜNME BÜTÇESİ: akıl yürüten modellerde düşünme ve yanıt AYNI
 *      max_tokens havuzundan harcanır. En az 2048 (EN_AZ_TOKEN); bölüm
 *      analizi 8192, birleştirme daha fazlasını verir (config ai.*).
 *      İçerik boş ve finish_reason "length" ise bütçe düşünmeye gitmiştir:
 *      "dusunme_butcesi" hatası, zincir sıradakine geçer. reasoning /
 *      reasoning_content alanları kullanılmaz; yalnız message.content.
 *    · JSON: response_format json_object. 400 dönerse bir kez
 *      parametresiz denenir; JSON metinden ayıklanır (ResultValidator,
 *      ``` çitleri dahil).
 *    · 403 + terms_not_accepted: kullanım şartları onaylanmamış. Kod
 *      kendiliğinden ONAYLAMAZ (sözleşme kullanıcının; yönetici Durum →
 *      "Şartları oku ve onayla", EvrenHesap); 1 saat devre dışı.
 *    · 401: anahtar geçersiz ("yetkisiz", uzun dinlenme).
 *    · 429: x-evren-daily-remaining-tokens 0 ise (ya da gövdedeki
 *      error.evren kalan kotayı 0 gösteriyorsa) günlük hesap kotası
 *      (15M token) doldu → bir sonraki 03:00'e (Europe/Istanbul) kadar;
 *      değilse dakikalık sınır (500k TPM) → Retry-After ya da 60 sn.
 *    · 503: anlık doluluk → "sunucu" (2 dk), sıradakine geçilir.
 *    · Bağlantı 10 sn, toplam en az 180 sn (uzun düşünme).
 *
 *  MALİYET: 1 Kasım 2026'ya kadar ücretsiz; girdi ve çıktı fiyatı 0
 *  (Ai::sabitFiyat, panelde girilen USD fiyatı EVREN'e uygulanmaz).
 *  1 Kasım 2026 sonrası fiyat CR cinsinden; GET /v1/models içindeki
 *  pricing alanından okunabilir. USD fiyatlarıyla karıştırılmamalı.
 *
 *  ANAHTAR: yalnız .env EVREN_API_KEY. LLM anahtarı "evren_llm_" ile
 *  başlar ("evren_" ile başlayan platform anahtarı burada çalışmaz).
 *  Sağlayıcının mesajında geçerse maskelenir (evren_llm_****).
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Ai;

use App\Core\Cache\Cache;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class EvrenProvider extends OpenAiCompatibleProvider
{
    public const TABAN_ADRES = 'https://evren-llmapi.ssyz.org.tr/v1';

    public const VARSAYILAN_MODEL = 'glm-5.3';

    /**
     * Panelde seçilebilen sohbet modelleri: sunucu tarafında yalnız bunlar
     * kabul edilir (PanelAyarlari, "model_evren"). deepseek-v4-flash yok:
     * 1 Kasım 2026'da kaldırılıyor.
     *
     * @var array<string,string> kimlik => kısa açıklama
     */
    public const MODELLER = [
        'glm-5.3'             => 'amiral model, 512K bağlam',
        'deepseek-v4.1-flash' => 'hızlı, 1M bağlam',
        'mimo-v2.6-pro'       => 'filonun en büyüğü, 1M bağlam',
        'qwen3.8-flash-next'  => 'en yüksek eşzamanlılık, 256K bağlam',
        'gemma-4-31b'         => 'düşük gecikme, 256K bağlam',
        'auto'                => 'geçit kendisi seçer',
    ];

    /** Düşünme ve yanıt aynı havuzdan: daha azında yanıt boş kalır. */
    public const EN_AZ_TOKEN = 2048;

    /** Uzun düşünme: istek süresi sınırı en az bu kadar (sn). */
    public const EN_AZ_SURE = 180;

    /** Son yanıttaki kalan günlük token (Ayarlar → Durum). */
    public const KOTA_ONBELLEK = 'transkript:evren_kota';

    protected const BAGLANTI_ZAMAN_ASIMI = 10;

    /** Günlük hesap kotası bu saatte yenilenir. */
    private const KOTA_SAAT_DILIMI = 'Europe/Istanbul';
    private const KOTA_SAATI       = 3;

    /** @var array<string,string> son HTTP yanıtının başlıkları (küçük harf) */
    private array $basliklar = [];

    public function __construct(
        string $apiKey,
        string $model,
        string $baseUrl = self::TABAN_ADRES,
        int $maxTokens = 8192,
        int $timeout = self::EN_AZ_SURE,
    ) {
        parent::__construct($apiKey, $model, $baseUrl, max(self::EN_AZ_TOKEN, $maxTokens), max(self::EN_AZ_SURE, $timeout));
    }

    /* =================================================================
     *  İSTEK VE YANIT
     * ============================================================== */

    /**
     * JSON modu reddedilirse (400) bir kez response_format olmadan
     * sorulur; istemler zaten "yalnız JSON" der ve Pipeline JSON'u
     * metinden ayıklar.
     */
    public function sendJson(array $messages, string $system, array $sema, array $ek = []): array
    {
        try {
            return parent::sendJson($messages, $system, $sema, $ek);
        } catch (AiHatasi $e) {
            if ($e->httpDurumu !== 400 || $e->kod !== 'istek_hatasi') {
                throw $e;
            }

            return $this->send($messages, $system);
        }
    }

    /**
     * @param  array<string,mixed> $response
     * @return array<string,mixed>
     */
    protected function normalize(array $response): array
    {
        $sonuc = parent::normalize($response);

        // Düşünme metni kullanılmaz; rapor yalnız message.content'ten.
        $sonuc['thinking'] = '';

        /* "auto"da geçit modeli kendisi seçer; yanıtın "model" alanı (ya da
         * x-evren-routed-model) gerçek modeli söyler. Kayıtta ve raporda
         * "auto → zai/glm-5.3-fp8" görünsün (cowork testi 2026-10-11). */
        $gercek = trim((string) ($response['model'] ?? $this->basliklar['x-evren-routed-model'] ?? ''));

        if ($this->modelName === 'auto' && $gercek !== '' && $gercek !== 'auto') {
            $sonuc['model'] = mb_substr('auto → ' . $gercek, 0, 100);
        }

        if ($sonuc['text'] === '' && $sonuc['stop_reason'] === 'max_tokens') {
            throw new AiHatasi(sprintf(
                'EVREN yanıtı boş: düşünme bütçesi yetmedi (max_tokens %d, model %s). Bütçeyi artırın ya da daha hızlı bir model seçin.',
                $this->maxTokens,
                $this->modelName
            ), 'dusunme_butcesi');
        }

        return $sonuc;
    }

    /* =================================================================
     *  HATALAR VE KOTA
     * ============================================================== */

    /** @param array<string,string> $headers */
    protected function basliklariIsle(int $status, array $headers): void
    {
        $this->basliklar = $headers;
        $kota = self::kotaBasligi($headers);

        if ($kota === null) {
            return;
        }

        try {
            Cache::put(self::KOTA_ONBELLEK, $kota + ['zaman' => time()], 2 * 86400);
        } catch (Throwable) {
            // Durum ekranı için bilgi; yazılamazsa çağrı yine sürer.
        }
    }

    protected function errorCode(int $status, string $raw): string
    {
        return match (true) {
            $status === 403 && self::sartlarOnaylanmadi($raw) => 'sartlar_onaylanmadi',
            $status === 429 && $this->gunlukKotaBitti($raw)   => 'kota_doldu',
            default                                           => parent::errorCode($status, $raw),
        };
    }

    /** Günlük kota dolduysa aynı istekte yeniden denemek boşuna. */
    protected function retryable(int $status, string $raw): bool
    {
        return parent::retryable($status, $raw) && !($status === 429 && $this->gunlukKotaBitti($raw));
    }

    /** Günlük kotada bekleme: bir sonraki yenilenmeye kadar (zincir bu kadar dinlendirir). */
    protected function hataBeklemesi(string $kod, ?int $bekle): ?int
    {
        return $kod === 'kota_doldu' ? $this->kotaYenilenmesineKadar() : $bekle;
    }

    protected function describeError(int $status, string $raw): string
    {
        $hata  = self::hataGovdesi($raw);
        $mesaj = trim((string) ($hata['message'] ?? ''));
        $kod   = $this->errorCode($status, $raw);

        $ipucu = match (true) {
            $kod === 'sartlar_onaylanmadi' => 'EVREN kullanım şartları bu anahtar için onaylanmamış — Transkript ayarları → Durum → "Şartları oku ve onayla".',
            $status === 401                => 'EVREN API anahtarı geçersiz. .env içindeki EVREN_API_KEY değerini kontrol edin (LLM anahtarı "evren_llm_" ile başlar).',
            $status === 403                => 'EVREN bu anahtara erişim izni vermedi (403). Hesabı ve anahtarı kontrol edin.',
            $kod === 'kota_doldu'          => 'EVREN günlük hesap kotası (15M token) doldu; 03:00\'te (İstanbul) yenilenir.',
            $status === 429                => 'EVREN dakikalık sınırı (500k token) aşıldı; kısa süre sonra yeniden denenir.',
            $status === 503                => 'EVREN sunucuları şu an dolu (503); sıradaki sağlayıcı denenir.',
            $status === 400                => 'EVREN isteği reddetti (400). Model ya da parametreler hatalı olabilir.',
            $status === 404                => 'EVREN modeli bulunamadı (404). Transkript ayarlarındaki EVREN modelini kontrol edin.',
            default                        => 'EVREN API hatası (HTTP ' . $status . ').',
        };

        // Sağlayıcının ek alanı (kalan kota, bekleme) yalnız log'a gider.
        $ek = isset($hata['evren']) ? ' · evren: ' . (string) json_encode($hata['evren'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';

        return $this->maskele(($mesaj === '' ? $ipucu : $ipucu . ' — ' . $mesaj) . $ek);
    }

    /**
     * Günlük kotanın yenilenmesine kalan süre (+1 dk pay): yanıttaki
     * x-evren-daily-reset okunabiliyorsa o, yoksa bir sonraki 03:00
     * (Europe/Istanbul).
     */
    public function kotaYenilenmesineKadar(?int $simdi = null): int
    {
        $simdi ??= time();
        $baslik = trim($this->basliklar['x-evren-daily-reset'] ?? '');
        $zaman  = match (true) {
            $baslik === ''                     => null,
            ctype_digit($baslik)               => match (true) {
                strlen($baslik) >= 13 => intdiv((int) $baslik, 1000),   // milisaniye
                strlen($baslik) >= 10 => (int) $baslik,                 // Unix saniyesi
                default               => $simdi + (int) $baslik,        // kalan saniye
            },
            default                            => strtotime($baslik) ?: null,
        };

        if ($zaman !== null && $zaman > $simdi && $zaman - $simdi <= 2 * 86400) {
            return $zaman - $simdi + 60;
        }

        return self::sonrakiYenilenme($simdi) - $simdi + 60;
    }

    /** Bir sonraki 03:00 (Europe/Istanbul), Unix zamanı. */
    public static function sonrakiYenilenme(int $simdi): int
    {
        $an    = (new DateTimeImmutable('@' . $simdi))->setTimezone(new DateTimeZone(self::KOTA_SAAT_DILIMI));
        $sonra = $an->setTime(self::KOTA_SAATI, 0);

        return ($sonra->getTimestamp() > $simdi ? $sonra : $sonra->modify('+1 day'))->getTimestamp();
    }

    /**
     * Yanıt başlığındaki günlük kota; başlık yoksa null.
     *
     * @param  array<string,string> $headers
     * @return array{kalan:int, limit:int}|null
     */
    public static function kotaBasligi(array $headers): ?array
    {
        $kalan = $headers['x-evren-daily-remaining-tokens'] ?? null;

        if (!is_numeric($kalan)) {
            return null;
        }

        $limit = $headers['x-evren-daily-limit-tokens'] ?? null;

        return ['kalan' => max(0, (int) $kalan), 'limit' => is_numeric($limit) ? (int) $limit : 0];
    }

    /**
     * Ayarlar → Durum: son EVREN yanıtının bildirdiği kalan günlük token.
     * Panel isteğinde API'ye gidilmez (CLAUDE.md, kural 6). Kayıt son
     * yenilenmeden eskiyse null (kota o arada doldu sayılmaz).
     *
     * @return array{kalan:int, limit:int, zaman:int}|null
     */
    public static function sonKota(?int $simdi = null): ?array
    {
        try {
            $kayit = Cache::get(self::KOTA_ONBELLEK);
        } catch (Throwable) {
            return null;
        }

        $simdi ??= time();

        if (!is_array($kayit) || !is_int($kayit['zaman'] ?? null) || $kayit['zaman'] < self::sonrakiYenilenme($simdi) - 86400) {
            return null;
        }

        return ['kalan' => (int) ($kayit['kalan'] ?? 0), 'limit' => (int) ($kayit['limit'] ?? 0), 'zaman' => $kayit['zaman']];
    }

    /** 429'un sebebi günlük hesap kotası mı (dakikalık sınır değil)? */
    private function gunlukKotaBitti(string $raw): bool
    {
        $kalan = $this->basliklar['x-evren-daily-remaining-tokens'] ?? null;

        if (is_numeric($kalan)) {
            return (float) $kalan <= 0;
        }

        foreach ((array) (self::hataGovdesi($raw)['evren'] ?? []) as $ad => $deger) {
            $ad = strtolower((string) $ad);

            if (str_contains($ad, 'daily') && str_contains($ad, 'remaining') && is_numeric($deger)) {
                return (float) $deger <= 0;
            }
        }

        return false;
    }

    private static function sartlarOnaylanmadi(string $raw): bool
    {
        return str_contains($raw, 'terms_not_accepted');
    }

    /** @return array<string,mixed> yanıt gövdesindeki "error" nesnesi; yoksa boş */
    private static function hataGovdesi(string $raw): array
    {
        $cozulmus = json_decode($raw, true);

        return is_array($cozulmus) && is_array($cozulmus['error'] ?? null) ? $cozulmus['error'] : [];
    }

    /** Anahtar log'a ve hata mesajına geçmez. */
    private function maskele(string $metin): string
    {
        if ($this->apiKey !== '') {
            $metin = str_replace($this->apiKey, 'evren_llm_****', $metin);
        }

        return (string) preg_replace('/evren_llm_[A-Za-z0-9_\-]{4,}/', 'evren_llm_****', $metin);
    }
}
