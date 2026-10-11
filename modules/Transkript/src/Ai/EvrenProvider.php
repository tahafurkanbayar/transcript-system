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
 *    · AKIŞ (0.10.0, EVREN_STREAM, varsayılan açık): "stream": true +
 *      include_usage; SseAkisi parçaları birleştirir. Üç ayrı sınır:
 *      bağlantı 15 sn, BOŞTA KALMA 60 sn (EVREN_BOSTA_SN; keep-alive
 *      veri sayılmaz; ilk token'a kadar 120 sn — EVREN_ILK_TOKEN_SN —,
 *      hiç bayt gelmezse 30 sn), toplam süre giriş boyutuyla ölçeklenir (120 sn +
 *      40 token/sn, en çok 900; EVREN_TOPLAM_SN ezer). Akış kapalıysa eski
 *      tek sınır (en az 180 sn). 11.10.2026'da 27 çağrının 4'ü tam 180,0
 *      sn'de zaman aşımına uğradı (cowork 3. tur, docs/evren).
 *    · DÜŞÜNME (EVREN_THINKING = off | low | default, varsayılan low):
 *      reasoning_effort destekleyen modellere "low" (mimo-v2.6-pro off'ta
 *      "none"). Model reddederse (400) parametresiz bir kez yeniden.
 *      Düşünme bütçesi yetmezse (boş içerik + length) max_tokens ×2 ile
 *      bir kez yeniden (bağlamı aşmadan).
 *    · ÖLÇÜM: her yanıtın "olcum" alanı (ilk token, keep-alive, bitiş
 *      nedeni, düşünme / önbellek token'ı, kalan kota) günlüğe yazılır.
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
use App\Core\Env;
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
        'auto'                => 'geçit seçer — yavaş / tutarsız olabilir, analiz başına sabitlenir',
    ];

    /** Bağlam (token), GET /v1/models (11.10.2026). auto yalnız 32K. */
    public const BAGLAM = [
        'glm-5.3'             => 524288,
        'deepseek-v4.1-flash' => 1048576,
        'mimo-v2.6-pro'       => 1048576,
        'qwen3.8-flash-next'  => 262144,
        'gemma-4-31b'         => 262144,
        'qwen3-vl-30b'        => 262144,
        'deepseek-v4-flash'   => 1048576,
        'auto'                => 32768,
    ];

    /** Bağlamın yanıta ayrılan payı (auto'nun varsayılan çıktısı); giriş sınırı = bağlam - pay. */
    public const CIKTI_PAYI = 16384;

    /** reasoning_effort'u destekleyen (düşünen) modeller (/v1/models capabilities). */
    public const DUSUNEN = ['glm-5.3', 'deepseek-v4.1-flash', 'mimo-v2.6-pro', 'qwen3.8-flash-next', 'deepseek-v4-flash'];

    /** Metin işine sabitlenmeyecek modeller: görsel model ve 1 Kasım 2026'da kalkan. */
    public const METIN_DISI = ['qwen3-vl-30b', 'deepseek-v4-flash'];

    /** Yanıttaki repo adı → kısa ad ("auto → zai/glm-5.3-fp8" → glm-5.3). */
    public const KISA_ADLAR = [
        'zai/glm-5.3-fp8'              => 'glm-5.3',
        'zai/glm-5.3'                  => 'glm-5.3',
        'deepseek/deepseek-v4.1-flash' => 'deepseek-v4.1-flash',
        'xiaomi/mimo-v2.6-pro'         => 'mimo-v2.6-pro',
        'qwen/qwen3.8-flash-next'      => 'qwen3.8-flash-next',
        'google/gemma-4-31b'           => 'gemma-4-31b',
        'qwen/qwen3-vl-30b-a3b'        => 'qwen3-vl-30b',
        'deepseek/deepseek-v4-flash'   => 'deepseek-v4-flash',
    ];

    /** Düşünme ayarı (.env EVREN_THINKING). */
    public const DUSUNME_AYARLARI = ['off', 'low', 'default'];
    public const VARSAYILAN_DUSUNME = 'low';

    /** Akışta veri gelmeden en çok (sn): EVREN 10 sn'de bir keep-alive gönderir, o veri sayılmaz. */
    public const BOSTA_SN = 60;

    /** İlk token'a kadar en çok (sn; kuyrukta bekleyiş, keep-alive gelirken). */
    public const ILK_TOKEN_SN = 120;

    /** Akışlı istekte toplam süre: taban + giriş token'ı / hız, üst sınır (sn). */
    public const TOPLAM_TABAN_SN  = 120;
    public const TOPLAM_TOKEN_HIZ = 40;
    public const TOPLAM_EN_COK_SN = 900;

    /** Düşünme ve yanıt aynı havuzdan: daha azında yanıt boş kalır. */
    public const EN_AZ_TOKEN = 2048;

    /** Uzun düşünme: istek süresi sınırı en az bu kadar (sn). */
    public const EN_AZ_SURE = 180;

    /** Son yanıttaki kalan günlük token (Ayarlar → Durum). */
    public const KOTA_ONBELLEK = 'transkript:evren_kota';

    protected const BAGLANTI_ZAMAN_ASIMI = 15;

    /** Günlük hesap kotası bu saatte yenilenir. */
    private const KOTA_SAAT_DILIMI = 'Europe/Istanbul';
    private const KOTA_SAATI       = 3;

    /** @var array<string,string> son HTTP yanıtının başlıkları (küçük harf) */
    private array $basliklar = [];

    /** @var array<string,true> bu süreçte reasoning_effort'u 400'le reddeden modeller */
    private static array $dusunmeReddi = [];

    /** @var array<string,true> bu süreçte json_schema'yı 400'le reddeden modeller (json_object'e düşülür) */
    private static array $semaReddi = [];

    /** dusunme_butcesi sonrası tek yeniden denemenin bütçesi (null: maxTokens). */
    private ?int $butce = null;

    /** @var array{akis?:bool, dusunme?:string, bosta_sn?:int, toplam_sn?:int, sema?:bool} ölçüm aracının .env yerine verdiği ayarlar */
    private array $ayar = [];

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
     * 400'de önce düşünme parametresi olmadan, yine olmazsa response_format
     * olmadan sorulur (istemler zaten "yalnız JSON" der; Pipeline JSON'u
     * metinden ayıklar). Düşünme bütçesi yetmezse bir kez max_tokens ×2.
     */
    public function sendJson(array $messages, string $system, array $sema, array $ek = []): array
    {
        return $this->butceyleDene(function () use ($messages, $system, $sema, $ek): array {
            try {
                return $this->dusunmesizDene(fn (): array => parent::sendJson($messages, $system, $sema, $ek));
            } catch (AiHatasi $e) {
                if ($e->httpDurumu !== 400 || $e->kod !== 'istek_hatasi') {
                    throw $e;
                }

                return $this->dusunmesizDene(fn (): array => parent::send($messages, $system));
            }
        });
    }

    public function send(array $messages, string $system = ''): array
    {
        return $this->butceyleDene(fn (): array => $this->dusunmesizDene(fn (): array => parent::send($messages, $system)));
    }

    /**
     * 400: model isteğe bağlı bir parametreyi reddediyor olabilir. Sırayla
     * (bu süreçte bir kez öğrenilir): önce reasoning_effort, sonra
     * json_schema (json_object'e düşülür). Yine 400 ise hata yukarı gider.
     */
    private function dusunmesizDene(\Closure $istek): array
    {
        for (;;) {
            try {
                return $istek();
            } catch (AiHatasi $e) {
                $kisa = self::kisaAd($this->modelName);

                if ($e->httpDurumu !== 400 || $e->kod !== 'istek_hatasi') {
                    throw $e;
                }

                if (!isset(self::$dusunmeReddi[$kisa]) && self::dusunmeParametreleri($this->modelName, $this->dusunme()) !== []) {
                    self::$dusunmeReddi[$kisa] = true;
                } elseif (!isset(self::$semaReddi[$kisa]) && $this->semaKullanilir()) {
                    self::$semaReddi[$kisa] = true;
                } else {
                    throw $e;
                }
            }
        }
    }

    /**
     * JSON biçimi: json_schema (strict) — model alanları şemadaki adlarla ve
     * her maddede "at" ile yazar (11.10.2026: json_object'te gemma
     * "point" / "claim" yazdı, "at" koymadı; maddeler atıldı). auto
     * parametre almaz; reddeden model json_object'e düşer.
     *
     * @param  array<string,mixed> $payload
     * @param  array<string,mixed> $sema
     * @param  array<string,mixed> $ek
     * @return array<string,mixed>
     */
    protected function jsonPayload(array $payload, array $sema, array $ek): array
    {
        if (!$this->semaKullanilir() || $sema === []) {
            return parent::jsonPayload($payload, $sema, $ek);
        }

        $payload['response_format'] = ['type' => 'json_schema', 'json_schema' => ['name' => 'cikti', 'strict' => true, 'schema' => self::katiSema($sema)]];

        return $payload;
    }

    /** json_schema gönderilir mi: .env EVREN_JSON_SCHEMA (açık), auto değil, model reddetmedi. */
    private function semaKullanilir(): bool
    {
        $kisa = self::kisaAd($this->modelName);

        return ($this->ayar['sema'] ?? Env::bool('EVREN_JSON_SCHEMA', true)) && $kisa !== 'auto' && !isset(self::$semaReddi[$kisa]);
    }

    /**
     * Strict şema: her nesnede additionalProperties false (OpenAI / vLLM
     * kuralı). Şemalarda bütün özellikler zaten "required".
     *
     * @param  array<string,mixed> $sema
     * @return array<string,mixed>
     */
    public static function katiSema(array $sema): array
    {
        if (($sema['type'] ?? '') === 'object') {
            $sema['additionalProperties'] = false;

            foreach ((array) ($sema['properties'] ?? []) as $ad => $alt) {
                $sema['properties'][$ad] = is_array($alt) ? self::katiSema($alt) : $alt;
            }
        }

        if (($sema['type'] ?? '') === 'array' && is_array($sema['items'] ?? null)) {
            $sema['items'] = self::katiSema($sema['items']);
        }

        return $sema;
    }

    /** Düşünme bütçesi yetmedi: aynı modelde bir kez max_tokens ×2 (bağlamı aşmadan). */
    private function butceyleDene(\Closure $istek): array
    {
        try {
            return $istek();
        } catch (AiHatasi $e) {
            $baglam = self::BAGLAM[self::kisaAd($this->modelName)] ?? 0;
            $yeni   = $this->maxTokens * 2;

            if ($e->kod !== 'dusunme_butcesi' || $this->butce !== null || ($baglam > 0 && $yeni > $baglam - 4096)) {
                throw $e;
            }

            $this->butce = $yeni;

            try {
                return $istek();
            } finally {
                $this->butce = null;
            }
        }
    }

    /**
     * Gövde: bütçe, düşünme ayarı, akış.
     *
     * @return array<string,mixed>
     */
    protected function payload(array $messages, string $system): array
    {
        $govde = parent::payload($messages, $system);
        $govde['max_tokens'] = $this->butce ?? $this->maxTokens;

        if (!isset(self::$dusunmeReddi[self::kisaAd($this->modelName)])) {
            $govde += self::dusunmeParametreleri($this->modelName, $this->dusunme());
        }

        if ($this->akis()) {
            $govde['stream']         = true;
            $govde['stream_options'] = ['include_usage' => true];
        }

        return $govde;
    }

    /**
     * Ölçüm kancası (bin/evren-olcum.php): .env'e dokunmadan bu sağlayıcının
     * akış, düşünme ve süre ayarlarını verir. Üretim kodu çağırmaz.
     *
     * @param array{akis?:bool, dusunme?:string, bosta_sn?:int, toplam_sn?:int} $ayar
     */
    public function olcumAyari(array $ayar): static
    {
        $this->ayar = $ayar;

        return $this;
    }

    /** Bu sağlayıcının düşünme ayarı: ölçüm kancası, yoksa .env EVREN_THINKING. */
    public function dusunme(): string
    {
        $ayar = (string) ($this->ayar['dusunme'] ?? '');

        return in_array($ayar, self::DUSUNME_AYARLARI, true) ? $ayar : self::dusunmeAyari();
    }

    /* =================================================================
     *  AKIŞ VE SÜRE SINIRLARI (HttpProvider kancaları)
     * ============================================================== */

    protected function akis(): bool
    {
        return array_key_exists('akis', $this->ayar) ? (bool) $this->ayar['akis'] : Env::bool('EVREN_STREAM', true);
    }

    protected function bostaSiniri(): int
    {
        return max(15, (int) ($this->ayar['bosta_sn'] ?? Env::int('EVREN_BOSTA_SN', self::BOSTA_SN)));
    }

    /**
     * İlk token'a kadar en çok (sn): glm-5.3 yoğunken 50 sn kuyrukta bekledi
     * (10 keep-alive, ölçüm 11.10.2026); bağlantı canlıysa (keep-alive) bu
     * süre beklenir, hiç bayt gelmezse 30 sn'de kesilir.
     */
    protected function ilkTokenSiniri(): int
    {
        return max($this->bostaSiniri(), Env::int('EVREN_ILK_TOKEN_SN', self::ILK_TOKEN_SN));
    }

    /** Akışta: 120 sn + giriş token'ı / 40, en çok 900 (EVREN_TOPLAM_SN ezer); akışsız: eski tek sınır. */
    protected function toplamSure(string $govde): int
    {
        if (!$this->akis()) {
            return $this->timeout;
        }

        $ayar = (int) ($this->ayar['toplam_sn'] ?? Env::int('EVREN_TOPLAM_SN', 0));

        return $ayar > 0 ? max(60, $ayar) : self::olcekliSure(GirisSiniri::metinBoyutu($govde), $this->timeout);
    }

    /** Giriş boyutuna göre toplam süre (sn). */
    public static function olcekliSure(int $girisToken, int $enAz = self::EN_AZ_SURE): int
    {
        return min(self::TOPLAM_EN_COK_SN, max($enAz, self::TOPLAM_TABAN_SN + intdiv(max(0, $girisToken), self::TOPLAM_TOKEN_HIZ)));
    }

    /**
     * Kesilen istek EVREN'de kotadan düşer (sunucu istem işledi, belki
     * yanıt da üretti). Giriş: isteğin metni (karakter / 3,5); çıkış: gelen
     * akış parçaları (yanıt + düşünme karakteri / 3,5). "tahmini" işaretli.
     *
     * @return array<string,mixed>
     */
    protected function kismiKullanim(string $istek, string $kismiYanit): array
    {
        $govde   = json_decode($istek, true);
        $mesajlar = is_array($govde['messages'] ?? null) ? $govde['messages'] : [];
        $giris   = GirisSiniri::tahmin('', array_map(static fn ($m): array => ['content' => is_string($m['content'] ?? null) ? $m['content'] : ''], $mesajlar));
        $cikis   = 0;

        if ($this->akis() && $kismiYanit !== '') {
            try {
                $akis = SseAkisi::coz($kismiYanit);
                $cikis = GirisSiniri::metinBoyutu(mb_strlen((string) ($akis['yanit']['choices'][0]['message']['content'] ?? '')) + $akis['dusunme_karakter']);
            } catch (\Throwable) {
                $cikis = 0;
            }
        }

        return ['giris_token' => $giris, 'cikis_token' => $cikis, 'tahmini' => true, 'istenen_model' => $this->modelName];
    }

    /** @return array<string,mixed>|null */
    protected function akisiCoz(string $raw): ?array
    {
        $akis = SseAkisi::coz($raw);

        $this->olcum['keepalive']        = max((int) ($this->olcum['keepalive'] ?? 0), $akis['keepalive']);
        $this->olcum['dusunme_karakter'] = $akis['dusunme_karakter'];

        if ($akis['hata'] !== null) {
            throw new AiHatasi($this->maskele('EVREN akışı hata verdi: ' . (string) ($akis['hata']['message'] ?? json_encode($akis['hata']))),
                'sunucu', (int) ($this->olcum['http'] ?? 0), null, null, null, $this->olcum);
        }

        return $akis['yanit'];
    }

    /* =================================================================
     *  MODEL BİLGİSİ
     * ============================================================== */

    /** "auto → zai/glm-5.3-fp8", "zai/glm-5.3-fp8", "glm-5.3" → "glm-5.3". */
    public static function kisaAd(string $model): string
    {
        $model = strtolower(trim((string) preg_replace('/^auto\s*→\s*/u', '', trim($model))));

        if (isset(self::KISA_ADLAR[$model])) {
            return self::KISA_ADLAR[$model];
        }

        $son = str_contains($model, '/') ? substr($model, strrpos($model, '/') + 1) : $model;

        return (string) preg_replace('/-(fp8|bf16|awq|int4|int8)$/', '', $son);
    }

    /** Metin işi için sabitlenebilir mi (panel listesinde, görsel / kalkan değil, auto değil)? */
    public static function metinModeliMi(string $model): bool
    {
        $kisa = self::kisaAd($model);

        return isset(self::MODELLER[$kisa]) && $kisa !== 'auto' && !in_array($kisa, self::METIN_DISI, true);
    }

    /** Modelin giriş sınırı (token): bağlam - yanıt payı; bilinmeyen modelde 0 (sınır yok). */
    public static function girisSiniri(string $model): int
    {
        $baglam = self::BAGLAM[self::kisaAd($model)] ?? 0;

        return $baglam > 0 ? max(1024, $baglam - self::CIKTI_PAYI) : 0;
    }

    /** Testler ve uzun süren işçi: öğrenilen "reasoning_effort / json_schema reddi" unutulur. */
    public static function dusunmeReddiniUnut(): void
    {
        self::$dusunmeReddi = [];
        self::$semaReddi    = [];
    }

    /** .env EVREN_THINKING: off | low | default (geçersizse low). */
    public static function dusunmeAyari(): string
    {
        $ayar = strtolower(trim(Env::get('EVREN_THINKING', self::VARSAYILAN_DUSUNME)));

        return in_array($ayar, self::DUSUNME_AYARLARI, true) ? $ayar : self::VARSAYILAN_DUSUNME;
    }

    /**
     * Düşünme parametreleri. default → yok; düşünmeyen model (gemma, auto)
     * → yok; low → reasoning_effort "low"; off → mimo "none", ötekiler
     * "low" (en düşük desteklenen; /v1/models "none"u yalnız mimo için bildiriyor).
     *
     * @return array<string,string>
     */
    public static function dusunmeParametreleri(string $model, string $ayar): array
    {
        $kisa = self::kisaAd($model);

        if ($ayar === 'default' || !in_array($kisa, self::DUSUNEN, true)) {
            return [];
        }

        return ['reasoning_effort' => $ayar === 'off' && $kisa === 'mimo-v2.6-pro' ? 'none' : 'low'];
    }

    /**
     * @param  array<string,mixed> $response
     * @return array<string,mixed>
     */
    protected function normalize(array $response): array
    {
        $sonuc = parent::normalize($response);
        $usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];

        // Günlük ve ölçüm aracı için (rapora girmez).
        $sonuc['olcum'] = $this->olcum + [
            'istenen_model'  => $this->modelName,
            'max_token'      => $this->butce ?? $this->maxTokens,
            'bitis_nedeni'   => (string) ($response['choices'][0]['finish_reason'] ?? ''),
            'dusunme_token'  => isset($usage['completion_tokens_details']['reasoning_tokens']) ? (int) $usage['completion_tokens_details']['reasoning_tokens'] : null,
            'onbellek_token' => isset($usage['prompt_tokens_details']['cached_tokens']) ? (int) $usage['prompt_tokens_details']['cached_tokens'] : null,
            'kalan_kota'     => is_numeric($this->basliklar['x-evren-daily-remaining-tokens'] ?? null) ? (int) $this->basliklar['x-evren-daily-remaining-tokens'] : null,
            'giris_token'    => (int) ($usage['prompt_tokens'] ?? 0),
            'cikis_token'    => (int) ($usage['completion_tokens'] ?? 0),
        ];

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
            // Harcanan token kayda girsin (kota farkı), bkz. AiHatasi::$olcum.
            throw new AiHatasi(sprintf(
                'EVREN yanıtı boş: düşünme bütçesi yetmedi (max_tokens %d, model %s). Bütçeyi artırın ya da daha hızlı bir model seçin.',
                $this->butce ?? $this->maxTokens,
                $this->modelName
            ), 'dusunme_butcesi', 0, null, null, null, $sonuc['olcum']);
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

    /** 403 terms_not_accepted gövdesindeki gereken şart sürümü (Durum: "yeni şart sürümü onay bekliyor"). */
    public const SART_GEREKEN = 'transkript:evren_sart_gereken';

    protected function errorCode(int $status, string $raw): string
    {
        if ($status === 403 && self::sartlarOnaylanmadi($raw)) {
            $gereken = (int) (self::hataGovdesi($raw)['evren']['required_terms_version'] ?? 0);

            try {
                if ($gereken > 0) {
                    Cache::put(self::SART_GEREKEN, $gereken, 30 * 86400);
                }
            } catch (Throwable) {
            }
        }

        return match (true) {
            $status === 403 && self::sartlarOnaylanmadi($raw) => 'sartlar_onaylanmadi',
            $status === 429 && $this->gunlukKotaBitti($raw)   => 'kota_doldu',
            default                                           => parent::errorCode($status, $raw),
        };
    }

    /**
     * 503 (anlık sunucu doluluğu, EVREN rehberi): zincirde arkasında başka
     * sağlayıcı olsa da aynı modelde 3 kez, 2 / 4 / 8 sn arayla (backoff).
     */
    protected function durumDenemeSiniri(int $status): int
    {
        return $status === 503 ? max(3, parent::durumDenemeSiniri($status)) : parent::durumDenemeSiniri($status);
    }

    /** Sunucu süre söylemediyse 2, 4, 8 sn (EVREN rehberi); söylediyse ona uyulur. */
    protected function backoff(int $attempt, ?int $retryAfter): void
    {
        if ($retryAfter !== null) {
            parent::backoff($attempt, $retryAfter);

            return;
        }

        self::uyu(min(8, 2 ** max(1, $attempt)));
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
