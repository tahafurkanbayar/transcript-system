<?php
/**
 * =====================================================================
 *  TRANSKRİPT MODÜLÜ BİRİM TESTLERİ – Veritabanı ve ağ GEREKTİRMEZ
 * ---------------------------------------------------------------------
 *      php tests/transkript.php
 *
 *  tests/unit.php'deki dogrula() desenini izler; PHPUnit gerekmez.
 *  Gerçek AI / YouTube isteği YAPMAZ (CLAUDE.md, kural 10).
 *
 *  Bölümler: modül künyesi, TranskriptPolicy, rota erişim matrisi,
 *  migration'lar (SQL sahte bir PDO ile yakalanır, veritabanına gitmez).
 *  AI sağlayıcıları (normalize, hata kodları; ağa gidilmez) ve Search.
 *  Sonraki adımlarda: UrlParser, Chunker, ResultValidator.
 *
 *  Çıkış kodu: 0 → hepsi geçti, 1 → en az bir test kaldı.
 * =====================================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('CY_BASE', dirname(__DIR__));
define('CY_START', microtime(true));

require CY_BASE . '/app/bootstrap.php';

use App\Core\Database\Migration;
use App\Core\Env;
use App\Core\Modules\Module;
use App\Core\Modules\Modules;
use App\Core\Setting;
use App\Models\Role;
use App\Models\User;
use Modules\Transkript\Ai\Ai;
use Modules\Transkript\Ai\AiHatasi;
use Modules\Transkript\Ai\ClaudeProvider;
use Modules\Transkript\Ai\GeminiProvider;
use Modules\Transkript\Ai\OpenAiCompatibleProvider;
use Modules\Transkript\Services\Search;
use Modules\Transkript\TranskriptPolicy;

$gecti = 0;
$kaldi = 0;

function dogrula(string $ad, bool $kosul, string $ayrinti = ''): void
{
    global $gecti, $kaldi;

    if ($kosul) {
        $gecti++;
        echo "  ✔ $ad\n";

        return;
    }

    $kaldi++;
    echo "  ✖ $ad" . ($ayrinti !== '' ? "\n      → $ayrinti" : '') . "\n";
}

const MODUL_YOLU = CY_BASE . '/modules/Transkript';

echo "\nTranskript modülü birim testleri\n";

/* ---------------------------------------------------------------- */
echo "\nModül künyesi (module.json)\n";

$kunye = json_decode((string) @file_get_contents(MODUL_YOLU . '/module.json'), true);

dogrula('module.json geçerli JSON', is_array($kunye));
$kunye = is_array($kunye) ? $kunye : [];

dogrula('Başlık "Transkript", kurulumda açık gelir', ($kunye['baslik'] ?? null) === 'Transkript' && ($kunye['kurulumda_acik'] ?? null) === true);
dogrula('Menü panel/transkript, transkript.view ister',
    ($kunye['menu']['route'] ?? null) === 'panel/transkript' && ($kunye['menu']['can'] ?? null) === 'transkript.view');

$modul = Module::fromDirectory(MODUL_YOLU, true);
dogrula('Editör: view + analyze + chat', Modules::collectAbilities([$modul], 'editor') === ['transkript.view', 'transkript.analyze', 'transkript.chat']);
dogrula('Üye: view + chat (analiz başlatamaz, maliyet oluşturamaz)', Modules::collectAbilities([$modul], 'uye') === ['transkript.view', 'transkript.chat']);

$dagitilan = array_merge(...array_values($kunye['yetkiler'] ?? []));
dogrula('transkript.admin hiçbir role dağıtılmaz (yalnız yönetici)', !in_array('transkript.admin', $dagitilan, true));

/* Policy ve rota testleri modülün AÇIK olduğu durumu ister: ayarlar
 * bellek içi SQLite'tan okunur (tests/unit.php'deki Ornek testi gibi). */
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "\n  (pdo_sqlite yok; policy, rota ve migration testleri atlandı)\n";
    printf("\n%d geçti · %d kaldı\n\n", $gecti, $kaldi);
    exit($kaldi > 0 ? 1 : 0);
}

$bellek = new PDO('sqlite::memory:');
$bellek->exec('CREATE TABLE ayarlar (anahtar TEXT, deger TEXT)');
$bellek->exec("INSERT INTO ayarlar VALUES ('aktif_moduller', '[\"Transkript\"]')");
Setting::load($bellek);
Modules::forget();
Modules::boot();

/* ---------------------------------------------------------------- */
echo "\nTranskriptPolicy (kayıt sahibine bağlı kurallar)\n";

// Modül sınıfları Modules::boot() ile yüklenebilir olur.
dogrula('Dağıtılan her yetki TranskriptPolicy::YETKILER\'de tanımlı', array_diff($dagitilan, array_keys(TranskriptPolicy::YETKILER)) === []);

$yonetici = new User(id: 1, ad: 'Y', soyad: 'Y', kullaniciAdi: 'y', eposta: 'y@ornek.com', rol: Role::ADMIN);
$editor   = new User(id: 2, ad: 'E', soyad: 'E', kullaniciAdi: 'e', eposta: 'e@ornek.com', rol: 'editor');
$uye      = new User(id: 3, ad: 'U', soyad: 'U', kullaniciAdi: 'u', eposta: 'u@ornek.com', rol: Role::MEMBER);

$pY = new TranskriptPolicy($yonetici);
$pE = new TranskriptPolicy($editor);
$pU = new TranskriptPolicy($uye);
$pM = new TranskriptPolicy(null);

$youtube    = ['tur' => 'youtube', 'sahip_id' => 0];
$uyeDosyasi = ['tur' => 'ses_dosyasi', 'sahip_id' => 3];

dogrula('YouTube kaynağı ortak: yönetici, editör ve üye görür', $pY->canViewSource($youtube) && $pE->canViewSource($youtube) && $pU->canViewSource($youtube));
dogrula('Yüklenen dosyayı sahibi ve yönetici görür', $pU->canViewSource($uyeDosyasi) && $pY->canViewSource($uyeDosyasi));
dogrula('Editör başkasının yüklediği dosyayı göremez', !$pE->canViewSource($uyeDosyasi));
dogrula('Giriş yapmamış kullanıcı hiçbir kaynağı göremez', !$pM->canViewSource($youtube) && !$pM->canViewSource($uyeDosyasi));
dogrula('Analizi editör ve yönetici başlatır, üye başlatamaz', $pY->canAnalyze() && $pE->canAnalyze() && !$pU->canAnalyze());
dogrula('Yeniden analiz ve tüm maliyet yalnız yöneticide',
    $pY->canReanalyze() && !$pE->canReanalyze() && !$pU->canReanalyze() && $pY->canSeeAllCosts() && !$pE->canSeeAllCosts());
dogrula('Üye görebildiği kaynağa soru sorar', $pU->canChatAbout($youtube) && $pU->canChatAbout($uyeDosyasi));
dogrula('Editör başkasının dosyasına soru soramaz', !$pE->canChatAbout($uyeDosyasi));

$uyeSohbeti = ['kullanici_id' => 3];
dogrula('Sohbeti sahibi ve yönetici okur, editör okuyamaz', $pU->canAccessChat($uyeSohbeti) && $pY->canAccessChat($uyeSohbeti) && !$pE->canAccessChat($uyeSohbeti));
dogrula('Kişisel özet yalnız sahibine ve yöneticiye açık',
    $pU->canViewPersonalSummary($uyeSohbeti) && $pY->canViewPersonalSummary($uyeSohbeti) && !$pE->canViewPersonalSummary($uyeSohbeti));
dogrula('Sahibi 0 olan sohbet kimseye "senin" sayılmaz', !$pU->canAccessChat(['kullanici_id' => 0]) && !$pM->canAccessChat(['kullanici_id' => 0]));

/* ---------------------------------------------------------------- */
echo "\nRota erişim matrisi (M misafir · U üye · E editör · Y yönetici)\n";

require_once CY_BASE . '/tests/rol-matrisi-hesap.php';

/* Çekirdek rotaları da yüklenir: modül rotası çekirdekteki bir adresle
 * çakışırsa "ilk kayıt kazanır" kuralıyla düşer ve burada eksik görünür. */
$rotaTablosu = (static function (): App\Core\Router {
    $router = require CY_BASE . '/routes/web.php';
    Modules::loadRoutes($router);

    return $router;
})()->table();

$beklenen = [
    'GET panel/transkript'                                   => 'UEY',
    'POST panel/transkript/analiz'                           => 'EY',
    'GET panel/transkript/ilerleme/{kaynak_id}'              => 'UEY',
    'GET panel/transkript/{kaynak_id}'                       => 'UEY',
    'POST panel/transkript/{kaynak_id}/sohbet'               => 'UEY',
    'POST api/v1/transkript/analiz'                          => 'EY',
    'POST api/v1/transkript/dosya'                           => 'EY',
    'GET api/v1/transkript/kaynaklar'                        => 'UEY',
    'GET api/v1/transkript/kaynaklar/{kaynak_id}'            => 'UEY',
    'GET api/v1/transkript/kaynaklar/{kaynak_id}/ozet'       => 'UEY',
    'GET api/v1/transkript/kaynaklar/{kaynak_id}/transkript' => 'UEY',
    'POST api/v1/transkript/kaynaklar/{kaynak_id}/sohbet'    => 'UEY',
    'GET api/v1/transkript/istatistik'                       => 'Y',
];

$gercek = [];
$modulRotalari = [];

foreach ($rotaTablosu as $rota) {
    if (!str_starts_with($rota['controller'], 'Modules\\Transkript\\')) {
        continue;
    }

    $modulRotalari[] = $rota;
    $gercek[$rota['verb'] . ' ' . $rota['path']] = cy_rota_erisimi($rota['middleware']);
}

$eksik  = array_keys(array_diff_key($beklenen, $gercek));
$fazla  = array_keys(array_diff_key($gercek, $beklenen));
$farkli = [];

foreach (array_intersect_key($gercek, $beklenen) as $anahtar => $erisim) {
    if ($erisim !== $beklenen[$anahtar]) {
        $farkli[] = $anahtar . ' (beklenen ' . $beklenen[$anahtar] . ', gerçek ' . $erisim . ')';
    }
}

dogrula('Beklenen her rota tanımlı ve çekirdekle çakışmıyor (' . count($beklenen) . ' rota)', $eksik === [], 'Eksik: ' . implode(', ', $eksik));
dogrula('Matriste olmayan modül rotası yok', $fazla === [], 'Matrise ekleyin: ' . implode(', ', $fazla));
dogrula('Her rotanın erişimi matrisle aynı', $farkli === [], implode('; ', $farkli));

$sorunlu = [];

foreach ($modulRotalari as $rota) {
    $mw    = $rota['middleware'];
    $panel = str_starts_with($rota['path'], 'panel/');

    if (!in_array('installed', $mw, true)) {
        $sorunlu[] = $rota['path'] . ' (installed yok)';
    }
    if ($panel && !in_array('auth', $mw, true)) {
        $sorunlu[] = $rota['path'] . ' (auth yok)';
    }
    if ($panel && $rota['verb'] === 'POST' && !in_array('csrf', $mw, true)) {
        $sorunlu[] = $rota['path'] . ' (csrf yok)';
    }
    if (!$panel && !in_array('api', $mw, true)) {
        $sorunlu[] = $rota['path'] . ' (api anahtarı istemiyor)';
    }
}

dogrula('Panel rotaları giriş, POST\'lar CSRF, API rotaları anahtar ister', $sorunlu === [], implode(', ', $sorunlu));

Setting::flush();
Modules::forget();

/* ---------------------------------------------------------------- */
echo "\nMigration'lar (SQL yakalanır, veritabanına gidilmez)\n";

/**
 * Sorguları kaydeden sahte bağlantı. Migration yalnızca prepare()
 * kullanır. information_schema sorularına ("tablo / sütun / indeks var
 * mı?") SahteSorgu::$varMi cevap verir; diğer sorgular boş sonuç döner.
 */
final class SahteSorgu extends PDOStatement
{
    /** information_schema sorusunun cevabı: bağlanan parametrelere (:tablo, :sutun, :indeks) göre 0/1. */
    public static ?Closure $varMi = null;

    /** @var array<string,mixed> */
    private array $baglanan = [];

    protected function __construct()
    {
    }

    /** Parametreler sahte sorguda yok; yalnızca saklanır. */
    public function execute(?array $params = null): bool
    {
        $this->baglanan = $params ?? [];

        return parent::execute();
    }

    public function fetchColumn(int $column = 0): mixed
    {
        if (str_contains($this->queryString, 'information_schema') && self::$varMi !== null) {
            return (self::$varMi)($this->baglanan);
        }

        return parent::fetchColumn($column);
    }
}

final class KayitPdo extends PDO
{
    /** @var array<int,string> */
    public array $sorgular = [];

    public function __construct()
    {
        parent::__construct('sqlite::memory:');
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [SahteSorgu::class]);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->sorgular[] = $query;

        return parent::prepare(str_contains($query, 'information_schema') ? 'SELECT 1 /* information_schema */' : 'SELECT 0 WHERE 0');
    }
}

$beklenenTablolar = [
    'transkript_kaynaklar', 'transkript_metinler', 'transkript_segmentler', 'transkript_analizler',
    'transkript_parcalar', 'transkript_analiz_ogeleri', 'transkript_kisisel_ozetler', 'transkript_sohbetler',
    'transkript_sohbet_mesajlari', 'transkript_ilgi_alanlari', 'ai_kullanim_kaydi',
];

$dosyalar = glob(MODUL_YOLU . '/migrations/*.php') ?: [];
sort($dosyalar);

$olusan = $sorunlar = $geriAlinmayan = [];
$degisiklik = [];   // ALTER migration'ları: dosya => [yukarı SQL, aşağı SQL]
$pdo = new KayitPdo();

/**
 * Kurulumdan önceki / sonraki şema: up() çalışırken modülün kendi
 * eklediği her şey yoktur, down() çalışırken vardır. Tek istisna
 * arama_metni migration'ının kaldırdığı eski FULLTEXT indeksi.
 */
$kaldirilan = ['ft_segment_metin'];
$once  = static fn (array $p): int => in_array($p[':indeks'] ?? '', $kaldirilan, true) ? 1 : 0;
$sonra = static fn (array $p): int => in_array($p[':indeks'] ?? '', $kaldirilan, true) ? 0 : 1;

foreach ($dosyalar as $dosya) {
    $ad = basename($dosya);
    $migration = require $dosya;

    if (!$migration instanceof Migration) {
        $sorunlar[] = "$ad Migration döndürmüyor";
        continue;
    }

    $migration->setConnection($pdo);

    // up(): modülün eklediği şeyler henüz yok; down(): var.
    SahteSorgu::$varMi = $once;
    $pdo->sorgular = [];
    $migration->up();
    $yukari = implode("\n", $pdo->sorgular);

    if (!preg_match('/CREATE TABLE IF NOT EXISTS `([a-z_]+)`/', $yukari, $m)) {
        SahteSorgu::$varMi = $sonra;
        $pdo->sorgular = [];
        $migration->down();
        $degisiklik[$ad] = [$yukari, implode("\n", $pdo->sorgular)];

        if (!str_contains($yukari, 'ALTER TABLE')) {
            $sorunlar[] = "$ad ne tablo kuruyor ne değiştiriyor";
        }

        continue;
    }

    $tablo = $m[1];

    if (!str_contains($yukari, 'ENGINE=InnoDB') || !str_contains($yukari, 'COLLATE=utf8mb4_turkish_ci')) {
        $sorunlar[] = "$tablo InnoDB / utf8mb4_turkish_ci değil";
    }

    preg_match_all('/REFERENCES `([a-z_]+)`/', $yukari, $hedefler);

    foreach ($hedefler[1] as $hedef) {
        if (!in_array($hedef, $olusan, true)) {
            $sorunlar[] = "$tablo → $hedef (hedef tablo daha önce kurulmuyor ya da modülün değil)";
        }
    }

    SahteSorgu::$varMi = $sonra;
    $pdo->sorgular = [];
    $migration->down();

    if (!str_contains(implode("\n", $pdo->sorgular), 'DROP TABLE IF EXISTS `' . $tablo . '`')) {
        $geriAlinmayan[] = $tablo;
    }

    $olusan[] = $tablo;
}

dogrula('11 tablo, bağımlılık sırasıyla kurulur', $olusan === $beklenenTablolar, 'Kurulan: ' . implode(', ', $olusan));
dogrula('Her tablo InnoDB + utf8mb4_turkish_ci; yabancı anahtarlar yalnız önceden kurulan modül tablolarına', $sorunlar === [], implode('; ', $sorunlar));
dogrula('Her migration down() ile geri alınır', $geriAlinmayan === [], 'Geri alınmayan: ' . implode(', ', $geriAlinmayan));

$tumSql = implode("\n", array_map(static fn (string $f): string => (string) file_get_contents($f), $dosyalar));
dogrula('Çekirdek tablolara (kullanicilar…) yabancı anahtar yok', preg_match('/REFERENCES\s+`(?!transkript_)/', $tumSql) !== 1);

$kaynakSql = (string) @file_get_contents(MODUL_YOLU . '/migrations/2026_10_08_230001_transkript_kaynaklar.php');
dogrula('dis_kimlik büyük/küçük harfe duyarlı (ascii_bin): "aBc" ile "AbC" ayrı video',
    preg_match('/`dis_kimlik`\s+VARCHAR\(\d+\)\s+CHARACTER SET ascii COLLATE ascii_bin/', $kaynakSql) === 1);
dogrula('Cache anahtarı UNIQUE(tur, dis_kimlik, sahip_id)', str_contains($kaynakSql, 'UNIQUE KEY `uq_kaynak` (`tur`, `dis_kimlik`, `sahip_id`)'));
[$aramaYukari, $aramaAsagi] = $degisiklik['2026_10_08_230012_transkript_segment_arama_metni.php'] ?? ['', ''];
dogrula('Segmentlere katlanmış arama_metni (NOT NULL) ve üzerinde FULLTEXT eklenir',
    str_contains($aramaYukari, 'ADD COLUMN `arama_metni` TEXT NOT NULL') && str_contains($aramaYukari, 'ADD FULLTEXT KEY `ft_segment_arama` (`arama_metni`)')
    && str_contains($aramaYukari, 'DROP INDEX `ft_segment_metin`'));
dogrula('arama_metni boş bırakılamaz: CHECK kısıtı (strict mod kapalıyken de)',
    str_contains($aramaYukari, "CHECK (`metin` = '' OR `arama_metni` <> '')"));
dogrula('arama_metni migration\'ı geri alınır (önce kısıt, sonra sütun; eski FULLTEXT döner)',
    str_contains($aramaAsagi, 'DROP COLUMN `arama_metni`') && str_contains($aramaAsagi, 'ADD FULLTEXT KEY `ft_segment_metin` (`metin`)')
    && strpos($aramaAsagi, 'DROP CONSTRAINT `chk_segment_arama`') < strpos($aramaAsagi, 'DROP COLUMN `arama_metni`'));

/* Modül sınıfları için modül yeniden açılır (yukarıda kapatıldı). */
Setting::load($bellek);
Modules::forget();
Modules::boot();

/* ---------------------------------------------------------------- */
echo "\nAI sağlayıcıları (ağa gidilmez, yanıtlar sabit örneklerle)\n";

/** Korumalı bir metodu çağırır (normalize, errorCode). */
function korumali(object $nesne, string $metot, mixed ...$argumanlar): mixed
{
    // PHP 8.1+: korumalı üyeye yansıma ile setAccessible() olmadan erişilir.
    return (new ReflectionMethod($nesne, $metot))->invoke($nesne, ...$argumanlar);
}

dogrula('429 / 5xx / bağlantı / zaman aşımı geçici: yeniden denenir',
    (new AiHatasi('', AiHatasi::kodFor(429)))->yenidenDenenebilir() && AiHatasi::kodFor(429) === 'rate_limit'
    && AiHatasi::kodFor(503) === 'sunucu' && AiHatasi::kodFor(529) === 'sunucu'
    && (new AiHatasi('', 'zaman_asimi'))->yenidenDenenebilir() && (new AiHatasi('', 'baglanti'))->yenidenDenenebilir());
dogrula('401 / 403 / 404 / 400 kalıcı: yeniden denenmez',
    AiHatasi::kodFor(401) === 'yetkisiz' && AiHatasi::kodFor(403) === 'yetkisiz' && AiHatasi::kodFor(404) === 'model_bulunamadi'
    && AiHatasi::kodFor(400) === 'istek_hatasi' && !(new AiHatasi('', 'yetkisiz'))->yenidenDenenebilir()
    && !(new AiHatasi('', 'anahtar_yok'))->yenidenDenenebilir());

$gemini = new GeminiProvider('test', 'gemini-2.5-flash');
$sonuc  = korumali($gemini, 'normalize', [
    'candidates'    => [['content' => ['parts' => [['text' => 'iç düşünce', 'thought' => true], ['text' => ' cevap ']]], 'finishReason' => 'STOP']],
    'usageMetadata' => ['promptTokenCount' => 1200, 'candidatesTokenCount' => 300],
]);
dogrula('Gemini: düşünce parçası metne karışmaz, kullanım okunur',
    $sonuc['text'] === 'cevap' && $sonuc['thinking'] === 'iç düşünce' && $sonuc['stop_reason'] === 'end_turn'
    && $sonuc['usage']['input_tokens'] === 1200 && $sonuc['usage']['output_tokens'] === 300);
dogrula('Gemini: maliyet fiyat tablosundan, bilinmeyen modelde 0',
    $sonuc['cost'] > 0 && $sonuc['cost'] === GeminiProvider::estimateCost('gemini-2.5-flash', $sonuc['usage'])
    && GeminiProvider::estimateCost('olmayan-model', $sonuc['usage']) === 0.0);
dogrula('Gemini: MAX_TOKENS → max_tokens (token limiti ayrı hata olur)',
    korumali($gemini, 'normalize', ['candidates' => [['content' => ['parts' => [['text' => 'yarım']]], 'finishReason' => 'MAX_TOKENS']]])['stop_reason'] === 'max_tokens');
$engel = korumali($gemini, 'normalize', ['promptFeedback' => ['blockReason' => 'SAFETY']]);
dogrula('Gemini: engellenen istem hata değil, refusal döner', $engel['refusal'] === 'SAFETY' && $engel['text'] === '');
dogrula('Gemini: geçersiz anahtar 400 dönse de kod "yetkisiz"',
    korumali($gemini, 'errorCode', 400, '{"error":{"message":"API key not valid. Please pass a valid API key."}}') === 'yetkisiz'
    && korumali($gemini, 'errorCode', 400, '{"error":{"message":"Invalid JSON payload"}}') === 'istek_hatasi');

$claude = new ClaudeProvider('test', 'claude-sonnet-5');
$sonuc  = korumali($claude, 'normalize', [
    'content'     => [['type' => 'thinking', 'thinking' => 'özet'], ['type' => 'text', 'text' => 'cevap']],
    'stop_reason' => 'end_turn',
    'usage'       => ['input_tokens' => 10, 'output_tokens' => 5, 'cache_read_input_tokens' => 3],
    'model'       => 'claude-sonnet-5',
]);
dogrula('Claude: içerik bloklar türüne göre okunur, ön bellek sayacı alınır',
    $sonuc['text'] === 'cevap' && $sonuc['thinking'] === 'özet' && $sonuc['usage']['cache_read'] === 3 && $sonuc['refusal'] === null);
dogrula('Claude: refusal kategorisi okunur',
    korumali($claude, 'normalize', ['content' => [], 'stop_reason' => 'refusal', 'stop_details' => ['category' => 'cyber']])['refusal'] === 'cyber');

$groq  = new OpenAiCompatibleProvider('test', 'llama-3.3-70b-versatile');
$sonuc = korumali($groq, 'normalize', ['choices' => [['message' => ['content' => 'z'], 'finish_reason' => 'length']], 'usage' => ['prompt_tokens' => 7, 'completion_tokens' => 2]]);
dogrula('OpenAI uyumlu: length → max_tokens, fiyat tablosu yok (maliyet 0)',
    $sonuc['text'] === 'z' && $sonuc['stop_reason'] === 'max_tokens' && $sonuc['usage']['input_tokens'] === 7 && $sonuc['cost'] === 0.0);

/* Ai::make() — .env değerleri yansıma ile geçici olarak verilir, test sonunda geri alınır. */
$envDegerleri = new ReflectionProperty(Env::class, 'values');
$oncekiEnv = $envDegerleri->getValue();
$bosAnahtarlar = array_fill_keys(['GEMINI_API_KEY', 'GOOGLE_API_KEY', 'GROQ_API_KEY', 'CLAUDE_API_KEY', 'ANTHROPIC_API_KEY', 'AI_API_KEY',
    'XAI_API_KEY', 'OPENROUTER_API_KEY', 'AI_PROVIDER', 'AI_MODEL', 'AI_BASE_URL', 'AI_MAX_TOKENS', 'AI_EFFORT'], '');

/** @return string AiHatasi kodu ya da '' */
function aiHataKodu(callable $fn): string
{
    try {
        $fn();
    } catch (AiHatasi $e) {
        return $e->kod;
    }

    return '';
}

$envDegerleri->setValue(null, $bosAnahtarlar);
dogrula('Anahtar yoksa "anahtar_yok" (istek atılmaz)', aiHataKodu(static fn () => Ai::make('gemini')) === 'anahtar_yok' && !Ai::isConfigured('claude'));
dogrula('Bilinmeyen sağlayıcı "ayar_eksik"', aiHataKodu(static fn () => Ai::make('olmayan')) === 'ayar_eksik');

$envDegerleri->setValue(null, ['GEMINI_API_KEY' => 'g-test', 'CLAUDE_API_KEY' => 'c-test'] + $bosAnahtarlar);
dogrula('make(): sağlayıcı ve model açıkça seçilir (aşama başı model)',
    Ai::make('gemini') instanceof GeminiProvider && Ai::make('gemini')->model() === 'gemini-2.5-flash'
    && Ai::make('claude', 'claude-sonnet-5') instanceof ClaudeProvider && Ai::make('claude', 'claude-sonnet-5')->model() === 'claude-sonnet-5');
dogrula('CLAUDE_API_KEY okunur; varsayılan sağlayıcı Gemini', Ai::isConfigured('claude') && Ai::provider() === 'gemini' && Ai::fromEnv() instanceof GeminiProvider);
$bosAyarli = Ai::make('claude', 'claude-sonnet-5');
dogrula('Boş AI_MAX_TOKENS= / AI_EFFORT= satırı varsayılana düşer (256\'ya ya da boş effort\'a değil)',
    (new ReflectionProperty($bosAyarli, 'maxTokens'))->getValue($bosAyarli) === 4096
    && (new ReflectionProperty($bosAyarli, 'effort'))->getValue($bosAyarli) === 'medium');
dogrula('Hata mesajı anahtarın değerini içermez', !str_contains(aiHataKodu(static fn () => Ai::make('groq')) . (string) json_encode(Ai::status()), 'g-test'));

$envDegerleri->setValue(null, $oncekiEnv);

/* ---------------------------------------------------------------- */
echo "\nSearch (Türkçe katlama, vurgulama, FULLTEXT / LIKE sorgusu)\n";

dogrula('trFold: IŞIK → isik, İstanbul → istanbul', Search::trFold('IŞIK') === 'isik' && Search::trFold('İstanbul') === 'istanbul');
$turkce = 'İIıŞşĞğÜüÖöÇç Aa';
dogrula('trFold uzunluğu korur (vurgulama indisleri kaymaz)', mb_strlen(Search::trFold($turkce)) === mb_strlen($turkce));
dogrula('highlight: "IŞIK" araması "Işık"ı vurgular', Search::highlight('Işık hızı', 'IŞIK') === '<mark>Işık</mark> hızı');
dogrula('highlight: "amp" araması &amp; varlığını bozmaz', Search::highlight('Tom & Jerry', 'amp') === 'Tom &amp; Jerry');
$xss = Search::highlight('<script>alert(1)</script> mimari', 'mimari');
dogrula('highlight: metin kaçışlanır, tek etiket <mark>', str_contains($xss, '&lt;script&gt;') && !str_contains($xss, '<script>') && str_contains($xss, '<mark>mimari</mark>'));
dogrula('highlight: örtüşen eşleşmeler tek <mark> olur', Search::highlight('yapay zekâ', 'yap yapay') === '<mark>yapay</mark> zekâ');
$uzun = str_repeat('a ', 300) . 'hedef sözcük';
dogrula('highlight: pencere ilk eşleşmenin çevresinden açılır', str_starts_with(Search::highlight($uzun, 'hedef', 100), '… ') && str_contains(Search::highlight($uzun, 'hedef', 100), '<mark>hedef</mark>'));
dogrula('highlight: metindeki ayraç karakterleri <mark>\'a dönüşmez', Search::highlight("a\x00b\x01c", 'zz') === 'abc');

dogrula('booleanQuery: operatörler atılır, önek eklenir ("C++ -güvenlik" güvenliği dışlamaz)',
    Search::booleanQuery('C++ -güvenlik "ajan"') === '+güvenlik* +ajan*');
dogrula('Kısa terimde LIKE, en az bir uzun sözcükte FULLTEXT', Search::shouldUseLike('ai') && !Search::shouldUseLike('ai ajan') && Search::queryWords('a b cd') === ['cd']);
dogrula('escapeLike: % _ \\ kaçışlanır', Search::escapeLike('50%_\\') === '50\\%\\_\\\\');

dogrula('Anlamlı sözcük yoksa sorgu kurulmaz', Search::segmentSorgusu(1, ' !! - ', 10) === null);
$like = Search::segmentSorgusu(7, 'AI', 10);
dogrula('LIKE yolu: katlanmış sütunda, metin_id ile sınırlı ("AI" → %ai%)',
    $like !== null && $like['yol'] === 'like' && $like['params'][':like'] === '%ai%' && $like['params'][':metin_id'] === 7
    && str_contains($like['sql'], 'arama_metni LIKE :like'));

$ft = Search::segmentSorgusu(7, 'Çoklu AJAN', 5000, Search::SIRA_ALAKA);
$tutucular = [];
preg_match_all('/:[a-z_]+/', $ft['sql'] ?? '', $tutucular);
dogrula('FULLTEXT yolu: her yer tutucu bir kez geçer ve hepsi bağlanır (emülasyon kapalı)',
    $ft !== null && $ft['yol'] === 'fulltext' && count($tutucular[0]) === count(array_unique($tutucular[0]))
    && array_diff($tutucular[0], array_keys($ft['params'])) === [] && array_diff(array_keys($ft['params']), $tutucular[0]) === []);
dogrula('FULLTEXT yolu: alaka sırası, limit en çok 200', str_contains($ft['sql'], 'ORDER BY skor DESC') && $ft['params'][':lim'] === 200
    && str_contains($ft['sql'], 'MATCH(arama_metni)'));
dogrula('FULLTEXT yolu: sorgu katlanır ("Çoklu AJAN" → +coklu* +ajan*)', $ft['params'][':q_whr'] === '+coklu* +ajan*');

Setting::flush();
Modules::forget();

/* ---------------------------------------------------------------- */
printf("\n%d geçti · %d kaldı\n\n", $gecti, $kaldi);

exit($kaldi > 0 ? 1 : 0);
