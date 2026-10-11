<?php
/**
 * =====================================================================
 *  Ai – sağlayıcı seçici (fabrika)
 * ---------------------------------------------------------------------
 *  Modülün yapay zekâya açılan TEK kapısıdır. İş sınıfları hangi
 *  sağlayıcının kullanıldığını bilmez, bilmesi de gerekmez:
 *
 *      $sonuc = Ai::fromEnv()->send($mesajlar, $sistem);            // .env'deki varsayılan
 *      $sonuc = Ai::make('gemini', 'gemini-2.5-flash')->send(...);  // aşama başı model (config)
 *
 *  Map aşaması ucuz ve hızlı, birleştirme ve sohbet daha güçlü bir
 *  model kullanır; bu yüzden make() sağlayıcıyı ve modeli açıkça alır.
 *  Varsayılan seçim .env içindeki AI_PROVIDER değerine bakar:
 *
 *      evren   (varsayılan)  yurt içi, 1 Kasım 2026'ya kadar ücretsiz — evren.ssyz.org.tr
 *      gemini                ücretsiz katmanı var — aistudio.google.com
 *      groq                  ücretsiz katmanı var — console.groq.com
 *      openrouter / mistral / cerebras   ücretsiz katmanı var (OpenAI uyumlu)
 *      openai-compatible     xAI (Grok), Ollama … (AI_BASE_URL ile)
 *      claude                ücretli — console.anthropic.com
 *
 *  ---------------------------------------------------------------
 *  NEDEN VARSAYILAN ÜCRETSİZ BİR SAĞLAYICI?
 *  Bu bir öğrenme örneğidir. Denemek için kredi kartı istemek,
 *  deponun önündeki en pahalı engeldi: kodu okumak isteyen çoğu
 *  kişi çalışır hâlini hiç görmeden ayrılıyordu. Artık anahtar
 *  almak birkaç saniye sürüyor ve hiçbir şey ödemiyorsunuz.
 *
 *  ---------------------------------------------------------------
 *  YEDEK ZİNCİRİ: AI_PROVIDERS=evren,gemini,openrouter,groq verilirse
 *  Pipeline bunları YedekliSaglayici ile sırayla dener (zincir(),
 *  zincirdeKullanilabilir()). AI_PROVIDERS ve AI_PROVIDER ikisi de
 *  boşsa VARSAYILAN_ZINCIR. Anahtarı olmayan atlanır.
 *
 *  MODEL (varsayilanModel): Transkript ayarlarındaki seçim
 *  (ai.modeller.<ad>), yoksa .env <AD>_MODEL, yoksa bilinen varsayılan.
 *  AI_MODEL, AI_PROVIDER'da yazan sağlayıcınındır; AI_PROVIDER
 *  yazılmamışsa eski varsayılan Gemini'nin (EVREN'e Gemini modeli gitmez).
 *
 *  ---------------------------------------------------------------
 *  ANAHTAR ARAMA SIRASI
 *  Her sağlayıcının kendi alışılmış değişken adı vardır
 *  (GEMINI_API_KEY, GROQ_API_KEY, CLAUDE_API_KEY / ANTHROPIC_API_KEY).
 *  Hepsinin yerine geçen ortak bir AI_API_KEY de kabul edilir.
 *  Anahtar yalnızca .env'den okunur; koda, veritabanına, log'a ve
 *  yanıta yazılmaz (CLAUDE.md, kural 5).
 * ---------------------------------------------------------------------
 *  Kaynak: CilginYazilim/ai-integration-system @338353d (MIT),
 *  app/Core/Ai/Ai.php. Transkript modülüne uyarlandı: make() ile
 *  aşama başı sağlayıcı/model, CLAUDE_API_KEY adı, AiHatasi kodları.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Ai;

use App\Core\Env;
use Modules\Transkript\Ayarlar;

final class Ai
{
    /**
     * Sağlayıcı tanımları.
     *
     * key_names : anahtarın aranacağı ortam değişkenleri (sırayla)
     * default_model / base_url : o sağlayıcının makul varsayılanları
     * console   : anahtarın alınacağı adres (hata mesajlarında geçer)
     * free      : ücretsiz katmanı var mı? (arayüzde rozet için)
     *
     * @var array<string,array<string,mixed>>
     */
    private const PROVIDERS = [
        // Kullanıcı isteği (2026-10-11): yerli EVREN zincirin başında.
        'evren' => [
            'label'         => 'EVREN (SSYZ)',
            'key_names'     => ['EVREN_API_KEY'],
            'default_model' => EvrenProvider::VARSAYILAN_MODEL,
            'base_url'      => EvrenProvider::TABAN_ADRES,
            'console'       => 'evren.ssyz.org.tr',
            'free'          => true,
            /* 1 Kasım 2026'ya kadar ücretsiz: maliyet 0 (panelde girilen USD
             * fiyatı uygulanmaz). 1 Kasım 2026 sonrası fiyat CR cinsinden;
             * GET /v1/models içindeki pricing alanından okunabilir. */
            'fiyat'         => ['giris' => 0.0, 'cikis' => 0.0],
        ],
        'gemini' => [
            'label'         => 'Google Gemini',
            'key_names'     => ['GEMINI_API_KEY', 'GOOGLE_API_KEY', 'AI_API_KEY'],
            'default_model' => 'gemini-2.5-flash',
            'console'       => 'aistudio.google.com/apikey',
            'free'          => true,
        ],
        'groq' => [
            'label'         => 'Groq',
            'key_names'     => ['GROQ_API_KEY', 'AI_API_KEY'],
            'default_model' => 'openai/gpt-oss-120b',      // 2026-10: llama-3.3-70b-versatile kaldırıldı
            'base_url'      => 'https://api.groq.com/openai/v1',
            'console'       => 'console.groq.com/keys',
            'free'          => true,
        ],
        'openrouter' => [
            'label'         => 'OpenRouter',
            'key_names'     => ['OPENROUTER_API_KEY'],
            'default_model' => 'google/gemma-4-31b-it:free', // ücretsiz, JSON destekli; liste sık değişir
            'base_url'      => 'https://openrouter.ai/api/v1',
            'console'       => 'openrouter.ai/keys',
            'free'          => true,
        ],
        'mistral' => [
            'label'         => 'Mistral',
            'key_names'     => ['MISTRAL_API_KEY'],
            'default_model' => 'mistral-small-latest',
            'base_url'      => 'https://api.mistral.ai/v1',
            'console'       => 'console.mistral.ai/api-keys',
            'free'          => true,
        ],
        'cerebras' => [
            'label'         => 'Cerebras',
            'key_names'     => ['CEREBRAS_API_KEY'],
            'default_model' => 'llama-3.3-70b',
            'base_url'      => 'https://api.cerebras.ai/v1',
            'console'       => 'cloud.cerebras.ai',
            'free'          => true,
        ],
        'openai-compatible' => [
            'label'         => 'OpenAI uyumlu (xAI · OpenRouter · Ollama)',
            'key_names'     => ['AI_API_KEY', 'XAI_API_KEY', 'OPENROUTER_API_KEY'],
            'default_model' => '',
            'base_url'      => '',
            'console'       => 'sağlayıcınızın panelinden',
            'free'          => false,
        ],
        'claude' => [
            'label'         => 'Anthropic Claude',
            'key_names'     => ['CLAUDE_API_KEY', 'ANTHROPIC_API_KEY', 'AI_API_KEY'],
            'default_model' => 'claude-sonnet-5',
            'console'       => 'console.anthropic.com',
            'free'          => false,
        ],
    ];

    private const DEFAULT_PROVIDER = 'evren';

    /** AI_PROVIDERS ve AI_PROVIDER boşken denenen sıra (anahtarı olmayan atlanır). */
    public const VARSAYILAN_ZINCIR = ['evren', 'gemini', 'openrouter', 'groq'];

    /** Her sağlayıcının ortak anahtarı (AI_API_KEY): zincirde yalnız birincile verilir. */
    private const GENEL_ANAHTAR = 'AI_API_KEY';

    /* =================================================================
     *  SEÇİM
     * ============================================================== */

    /** Şu an seçili sağlayıcının anahtarı ("gemini", "claude" …). */
    public static function provider(): string
    {
        $name = strtolower(trim(Env::get('AI_PROVIDER', self::DEFAULT_PROVIDER)));

        // Tanımsız bir ad yazıldıysa sessizce varsayılana dönmek yerine
        // varsayılanı kullanıyoruz ama adı da doğruluyoruz; yazım
        // hatasının bedeli, hiç çalışmayan bir sohbet olmasın.
        return isset(self::PROVIDERS[$name]) ? $name : self::DEFAULT_PROVIDER;
    }

    /** Arayüzde göstermek için okunabilir ad. */
    public static function label(): string
    {
        return (string) self::PROVIDERS[self::provider()]['label'];
    }

    /** Seçili sağlayıcının ücretsiz katmanı var mı? */
    public static function isFree(): bool
    {
        return (bool) self::PROVIDERS[self::provider()]['free'];
    }

    /** Anahtarın alınacağı adres — kurulum uyarısında gösterilir. */
    public static function console(): string
    {
        return (string) self::PROVIDERS[self::provider()]['console'];
    }

    /** Seçili (asıl) sağlayıcının modeli: panel, .env ya da varsayılan (varsayilanModel). */
    public static function model(): string
    {
        return self::varsayilanModel(self::provider());
    }

    /**
     * Anahtar tanımlı mı? (Arayüzde kurulum uyarısı göstermek için.)
     *
     * API ANAHTARININ KENDİSİ HİÇBİR ZAMAN DÖNDÜRÜLMEZ — yalnızca
     * "var mı yok mu" bilgisi.
     */
    public static function isConfigured(?string $saglayici = null): bool
    {
        return self::key($saglayici ?? self::provider()) !== '';
    }

    /**
     * Sağlayıcının anahtarını ortamdan okur. $genel = false: ortak
     * AI_API_KEY sayılmaz. Zincirde birincil olmayan halka için böyle
     * çağrılır; yoksa Gemini'nin anahtarı Groq'a da gönderilirdi.
     */
    private static function key(string $saglayici, bool $genel = true): string
    {
        foreach ((array) (self::PROVIDERS[$saglayici]['key_names'] ?? []) as $name) {
            if (!$genel && $name === self::GENEL_ANAHTAR) {
                continue;
            }

            $value = trim(Env::get((string) $name, ''));

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /* =================================================================
     *  ÜRETİM
     * ============================================================== */

    /**
     * .env'deki varsayılan sağlayıcı ve modelle (AI_PROVIDER, AI_MODEL)
     * çalışmaya hazır bir sağlayıcı üretir.
     *
     * @throws AiHatasi Anahtar ya da ayar eksikse.
     */
    public static function fromEnv(): Provider
    {
        return self::make(self::provider(), self::model());
    }

    /**
     * Belirli bir sağlayıcı ve modelle üretir; model boşsa sağlayıcının
     * varsayılanı. Aşama başı model seçimi (config) bunu kullanır.
     *
     * @throws AiHatasi Bilinmeyen sağlayıcı ya da eksik anahtar/ayar.
     */
    public static function make(string $saglayici, string $model = '', ?int $maxTokens = null, ?int $zamanAsimi = null, bool $genelAnahtar = true): Provider
    {
        $name = strtolower(trim($saglayici));

        if (!isset(self::PROVIDERS[$name])) {
            throw new AiHatasi('Bilinmeyen AI sağlayıcısı: "' . $saglayici . '".', 'ayar_eksik');
        }

        $key = self::key($name, $genelAnahtar);

        if ($key === '') {
            $expected = (string) ((array) self::PROVIDERS[$name]['key_names'])[0];

            throw new AiHatasi(sprintf(
                '.env içinde %s tanımlı değil. Anahtar: %s',
                $expected,
                (string) self::PROVIDERS[$name]['console']
            ), 'anahtar_yok');
        }

        $model     = trim($model) !== '' ? trim($model) : self::varsayilanModel($name);
        /* Env::get boş satırda ('AI_MAX_TOKENS=') varsayılanı değil ''
         * döndürür; (int) '' = 0 sınırı 256'ya düşürür ve yanıt yarıda
         * kesilirdi. Env::int boş değerde varsayılanı verir. */
        $maxTokens  = max(256, $maxTokens ?? Env::int('AI_MAX_TOKENS', 4096));
        $zamanAsimi = max(10, $zamanAsimi ?? 120);

        return match ($name) {
            // Bütçe en az 2048 token, süre en az 180 sn (EvrenProvider kurucusu).
            'evren' => new EvrenProvider($key, $model, self::baseUrl($name), $maxTokens, $zamanAsimi),

            'claude' => new ClaudeProvider(
                $key,
                $model,
                $maxTokens,
                trim(Env::get('AI_EFFORT')) !== '' ? trim(Env::get('AI_EFFORT')) : 'medium',
                $zamanAsimi,
            ),

            'groq', 'openrouter', 'mistral', 'cerebras', 'openai-compatible' => new OpenAiCompatibleProvider(
                $key,
                $model,
                self::baseUrl($name),
                $maxTokens,
                $zamanAsimi,
            ),

            default => new GeminiProvider(
                $key,
                $model,
                $maxTokens,
                Env::bool('AI_THINKING', false),
                $zamanAsimi,
            ),
        };
    }

    /**
     * OpenAI uyumlu sağlayıcılar için taban adres.
     *
     * Önce sağlayıcının kendi değişkeni (<AD>_BASE_URL, ör. EVREN_BASE_URL),
     * sonra AI_BASE_URL (asıl sağlayıcıya ya da openai-compatible'a),
     * yoksa sağlayıcının bilinen adresi. "openai-compatible" seçilip adres
     * verilmediyse tahmin yürütmüyoruz — hangi servise bağlanacağını
     * yalnızca kullanıcı bilir.
     */
    private static function baseUrl(string $name): string
    {
        $kendi = trim(Env::get(strtoupper(str_replace('-', '_', $name)) . '_BASE_URL', ''));

        if ($kendi !== '') {
            return $kendi;
        }

        $url = trim(Env::get('AI_BASE_URL', ''));

        // Zincirde AI_BASE_URL yalnız kendi sağlayıcısına aittir (birincil ya da openai-compatible).
        if ($url !== '' && ($name === 'openai-compatible' || $name === self::provider())) {
            return $url;
        }

        $known = (string) (self::PROVIDERS[$name]['base_url'] ?? '');

        if ($known === '') {
            throw new AiHatasi(
                '.env içinde AI_BASE_URL tanımlı değil. '
                . 'openai-compatible sağlayıcısında hangi servise '
                . 'bağlanılacağını bu değer belirler (örn. https://api.x.ai/v1).',
                'ayar_eksik'
            );
        }

        return $known;
    }

    /**
     * Sağlayıcının modeli: Transkript ayarlarında o sağlayıcı için seçilen
     * model (ai.modeller.<ad>; Gemini için "ai_model" alanı, EVREN için
     * "model_evren"), yoksa .env'deki model (ortamModeli), yoksa bilinen
     * varsayılan. Zincirdeki her halka, asıl sağlayıcı dahil, bunu kullanır.
     */
    public static function varsayilanModel(string $ad): string
    {
        $panel = trim((string) Ayarlar::get('ai.modeller.' . $ad, ''));

        return $panel !== '' ? $panel : self::ortamModeli($ad);
    }

    /**
     * Panel değeri olmadan: <AD>_MODEL (ör. EVREN_MODEL, GROQ_MODEL), yoksa
     * AI_MODEL (yalnız sahibinde, aiModelSahibi), yoksa bilinen varsayılan
     * (ayarlar ekranındaki "Varsayılan: …").
     */
    public static function ortamModeli(string $ad): string
    {
        $ortam = trim(Env::get(strtoupper(str_replace('-', '_', $ad)) . '_MODEL', ''));

        if ($ortam === '' && $ad === self::aiModelSahibi()) {
            $ortam = trim(Env::get('AI_MODEL', ''));
        }

        return $ortam !== '' ? $ortam : (string) (self::PROVIDERS[$ad]['default_model'] ?? '');
    }

    /**
     * AI_MODEL hangi sağlayıcının? AI_PROVIDER yazılmışsa onun. Yazılmamışsa
     * eski varsayılan Gemini'nin: varsayılan EVREN olunca eski kurulumdaki
     * "AI_MODEL=gemini-…" EVREN'e gidip 404 almasın.
     */
    private static function aiModelSahibi(): string
    {
        return trim(Env::get('AI_PROVIDER', '')) !== '' ? self::provider() : 'gemini';
    }

    /**
     * Ayarlar ekranında model kutusunun önerileri (serbest yazılabilir;
     * ücretsiz modeller sık değişir). Gemini, EVREN ve Claude için bilinen
     * liste, ötekilerde varsayılan ve .env'deki model.
     *
     * @return list<string>
     */
    public static function modelOnerileri(string $ad): array
    {
        $liste = match ($ad) {
            'gemini' => array_values(array_filter(GeminiProvider::models(), static fn (string $m): bool => str_contains($m, 'flash'))),
            'evren'  => array_keys(EvrenProvider::MODELLER),
            'claude' => ClaudeProvider::models(),
            default  => [],
        };

        return array_values(array_unique(array_filter([self::ortamModeli($ad), (string) (self::PROVIDERS[$ad]['default_model'] ?? ''), ...$liste])));
    }

    /* =================================================================
     *  YEDEK ZİNCİRİ (YedekliSaglayici)
     * ============================================================== */

    /**
     * Denenecek sağlayıcılar, sırayla: .env AI_PROVIDERS
     * (ör. "evren,gemini,openrouter,groq"). Boşsa yalnız AI_PROVIDER;
     * o da yazılmamışsa VARSAYILAN_ZINCIR. Bilinmeyen ve tekrarlanan
     * adlar atlanır.
     *
     * @return list<string>
     */
    public static function zincir(): array
    {
        $liste = [];

        foreach (Env::list('AI_PROVIDERS', []) as $ad) {
            $ad = strtolower(trim((string) $ad));

            if (isset(self::PROVIDERS[$ad]) && !in_array($ad, $liste, true)) {
                $liste[] = $ad;
            }
        }

        if ($liste !== []) {
            return $liste;
        }

        return trim(Env::get('AI_PROVIDER', '')) !== '' ? [self::provider()] : self::VARSAYILAN_ZINCIR;
    }

    /**
     * Zincire girebilir mi: anahtarı (birincil değilse kendi adına
     * olanı) tanımlı ve gerekiyorsa taban adresi biliniyor.
     */
    public static function zincirdeKullanilabilir(string $ad, bool $birincil): bool
    {
        if (!isset(self::PROVIDERS[$ad]) || self::key($ad, $birincil) === '') {
            return false;
        }

        return $ad !== 'openai-compatible' || trim(Env::get('AI_BASE_URL', '')) !== '';
    }

    /**
     * Ayarlar ekranı için zincirin durumu (anahtar değeri yok; yalnız
     * değişkenin adı). Asıl sağlayıcıya config'te aşama modeli
     * (ai.map.model) yazıldıysa o gösterilir: Pipeline da onu kullanır.
     *
     * @return list<array{ad:string, etiket:string, tanimli:bool, model:string, dinlenme_sn:int, ucretsiz:bool, konsol:string, anahtar_adi:string}>
     */
    public static function zincirDurumu(): array
    {
        $durum = [];
        $asama = trim((string) Ayarlar::get('ai.map.model', ''));

        foreach (self::zincir() as $i => $ad) {
            $durum[] = [
                'ad'          => $ad,
                'etiket'      => (string) self::PROVIDERS[$ad]['label'],
                'tanimli'     => self::zincirdeKullanilabilir($ad, $i === 0),
                'model'       => $i === 0 && $ad === self::provider() && $asama !== '' ? $asama : self::varsayilanModel($ad),
                'dinlenme_sn' => YedekliSaglayici::dinlenmeKalan($ad),
                'ucretsiz'    => (bool) self::PROVIDERS[$ad]['free'],
                'konsol'      => (string) self::PROVIDERS[$ad]['console'],
                'anahtar_adi' => self::anahtarAdi($ad),
            ];
        }

        return $durum;
    }

    /** Okunur ad ("Groq"); bilinmiyorsa kendisi. */
    public static function etiket(string $ad): string
    {
        return (string) (self::PROVIDERS[$ad]['label'] ?? $ad);
    }

    /**
     * Metinden API anahtarlarını çıkarır: .env'deki gerçek değerler ve
     * bilinen anahtar biçimleri (AIza…, sk-…, gsk_…, evren_llm_…). Sağlayıcı
     * mesajı kayda / ekrana (yalnız yönetici) böyle girer.
     */
    public static function maskele(string $metin): string
    {
        foreach (self::PROVIDERS as $tanim) {
            foreach ((array) $tanim['key_names'] as $ad) {
                $deger = trim(Env::get((string) $ad, ''));

                if (strlen($deger) >= 8) {
                    $metin = str_replace($deger, '****', $metin);
                }
            }
        }

        return (string) preg_replace(
            // Sonuncusu gizli değil ama gereksiz: Groq hata metnindeki kuruluş kimliği.
            ['/evren_llm_[A-Za-z0-9_\-]{4,}/', '/AIza[0-9A-Za-z_\-]{20,}/', '/sk-(?:or-v1-|ant-)?[A-Za-z0-9_\-]{16,}/', '/gsk_[A-Za-z0-9]{16,}/', '/\borg_[A-Za-z0-9]{12,}/'],
            ['evren_llm_****', 'AIza****', 'sk-****', 'gsk_****', 'org_****'],
            $metin
        );
    }

    /** Anahtarın .env'deki adı (değeri değil): "EVREN_API_KEY". */
    public static function anahtarAdi(string $ad): string
    {
        return (string) (((array) (self::PROVIDERS[$ad]['key_names'] ?? []))[0] ?? '');
    }

    /**
     * Sağlayıcının sabit fiyatı (USD / 1M token): maliyet tahmininde panel
     * fiyatının yerine geçer. Yalnız tanımlıysa (EVREN: 0); yoksa null.
     *
     * @return array{giris:float, cikis:float}|null
     */
    public static function sabitFiyat(string $ad): ?array
    {
        $fiyat = self::PROVIDERS[$ad]['fiyat'] ?? null;

        return is_array($fiyat) ? ['giris' => (float) $fiyat['giris'], 'cikis' => (float) $fiyat['cikis']] : null;
    }

    /** AI_PROVIDERS'a yazılabilecek adlar (ayarlar ekranındaki açıklama için). @return list<string> */
    public static function saglayiciAdlari(): array
    {
        return array_keys(self::PROVIDERS);
    }

    /* =================================================================
     *  ARAYÜZ İÇİN
     * ============================================================== */

    /**
     * Seçili sağlayıcının bilinen modelleri. Boş dönebilir:
     * OpenAI uyumlu servislerde model listesi sağlayıcıya göre
     * değişir ve koda gömmek yanlış bilgi vermek olurdu.
     *
     * @return array<int,string>
     */
    public static function models(): array
    {
        return match (self::provider()) {
            'gemini' => GeminiProvider::models(),
            'evren'  => array_keys(EvrenProvider::MODELLER),
            'claude' => ClaudeProvider::models(),
            default  => [],
        };
    }

    /**
     * Arayüzün ihtiyaç duyduğu tüm durum bilgisi — anahtar hariç.
     *
     * @return array<string,mixed>
     */
    public static function status(): array
    {
        return [
            'provider'       => self::provider(),
            'provider_label' => self::label(),
            'free_tier'      => self::isFree(),
            'configured'     => self::isConfigured(),
            'model'          => self::model(),
            'console'        => self::console(),
        ];
    }
}
