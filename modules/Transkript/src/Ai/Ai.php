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
 *      gemini  (varsayılan)  ücretsiz katmanı var — aistudio.google.com
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
 *  YEDEK ZİNCİRİ: AI_PROVIDERS=gemini,openrouter,groq verilirse
 *  Pipeline bunları YedekliSaglayici ile sırayla dener (zincir(),
 *  zincirdeKullanilabilir()). Sağlayıcı başına model <AD>_MODEL.
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
            'default_model' => 'llama-3.3-70b-versatile',
            'base_url'      => 'https://api.groq.com/openai/v1',
            'console'       => 'console.groq.com/keys',
            'free'          => true,
        ],
        'openrouter' => [
            'label'         => 'OpenRouter',
            'key_names'     => ['OPENROUTER_API_KEY'],
            'default_model' => 'meta-llama/llama-3.3-70b-instruct:free',
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

    private const DEFAULT_PROVIDER = 'gemini';

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

    /** Kullanılacak model adı. */
    public static function model(): string
    {
        $model = trim(Env::get('AI_MODEL', ''));

        return $model !== ''
            ? $model
            : (string) self::PROVIDERS[self::provider()]['default_model'];
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
     * .env'deki AI_BASE_URL her zaman kazanır; yoksa sağlayıcının
     * bilinen adresi kullanılır. "openai-compatible" seçilip adres
     * verilmediyse tahmin yürütmüyoruz — hangi servise bağlanacağını
     * yalnızca kullanıcı bilir.
     */
    private static function baseUrl(string $name): string
    {
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
     * Sağlayıcının modeli: <AD>_MODEL (ör. GROQ_MODEL, OPENROUTER_MODEL)
     * varsa o, yoksa bilinen varsayılan. AI_MODEL yalnız birincil
     * sağlayıcıya aittir (model()); burada okunmaz.
     */
    public static function varsayilanModel(string $ad): string
    {
        $ortam = trim(Env::get(strtoupper(str_replace('-', '_', $ad)) . '_MODEL', ''));

        return $ortam !== '' ? $ortam : (string) (self::PROVIDERS[$ad]['default_model'] ?? '');
    }

    /* =================================================================
     *  YEDEK ZİNCİRİ (YedekliSaglayici)
     * ============================================================== */

    /**
     * Denenecek sağlayıcılar, sırayla: .env AI_PROVIDERS
     * (ör. "gemini,openrouter,groq"). Boşsa yalnız AI_PROVIDER.
     * Bilinmeyen ve tekrarlanan adlar atlanır.
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

        return $liste !== [] ? $liste : [self::provider()];
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
     * Ayarlar ekranı için zincirin durumu (anahtar değeri yok).
     *
     * @return list<array{ad:string, etiket:string, tanimli:bool, model:string, dinlenme_sn:int, ucretsiz:bool, konsol:string}>
     */
    public static function zincirDurumu(): array
    {
        $durum = [];

        foreach (self::zincir() as $i => $ad) {
            $durum[] = [
                'ad'          => $ad,
                'etiket'      => (string) self::PROVIDERS[$ad]['label'],
                'tanimli'     => self::zincirdeKullanilabilir($ad, $i === 0),
                'model'       => $i === 0 && $ad === self::provider() ? self::model() : self::varsayilanModel($ad),
                'dinlenme_sn' => YedekliSaglayici::dinlenmeKalan($ad),
                'ucretsiz'    => (bool) self::PROVIDERS[$ad]['free'],
                'konsol'      => (string) self::PROVIDERS[$ad]['console'],
            ];
        }

        return $durum;
    }

    /** Okunur ad ("Groq"); bilinmiyorsa kendisi. */
    public static function etiket(string $ad): string
    {
        return (string) (self::PROVIDERS[$ad]['label'] ?? $ad);
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
