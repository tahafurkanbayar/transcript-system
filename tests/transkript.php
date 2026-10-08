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
 *  UrlParser ve YoutubeMetadata (sahte HTTP istemcisiyle).
 *  Transcript zinciri, YouTube / Gemini sağlayıcıları, Cleaner, Chunker.
 *  İşler (yük, hata sınıflandırması), AnalizBaslatici retleri,
 *  KaynakDurumu, rapor maddeleri, zamanlanmış görev.
 *  Sonraki adımlarda: ResultValidator.
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
use App\Core\Log\Logger;
use App\Core\Modules\Module;
use App\Core\Modules\Modules;
use App\Core\Schedule\Schedule;
use App\Core\Setting;
use App\Core\Throttle;
use App\Models\Role;
use App\Models\User;
use Modules\Transkript\Ai\Ai;
use Modules\Transkript\Ai\AiHatasi;
use Modules\Transkript\Ai\ClaudeProvider;
use Modules\Transkript\Ai\GeminiProvider;
use Modules\Transkript\Ai\OpenAiCompatibleProvider;
use Modules\Transkript\Http\HttpIstemci;
use Modules\Transkript\Http\HttpYanit;
use Modules\Transkript\Ayarlar;
use Modules\Transkript\HataKodlari;
use Modules\Transkript\Jobs\AnalizBirlestir;
use Modules\Transkript\Jobs\KaynakBilgisiAl;
use Modules\Transkript\Jobs\ParcaAnalizEt;
use Modules\Transkript\Jobs\RaporuTamamla;
use Modules\Transkript\Jobs\TranskriptAl;
use Modules\Transkript\Jobs\TranskriptIsi;
use Modules\Transkript\Kaynak\Kaynak;
use Modules\Transkript\Kaynak\KaynakHatasi;
use Modules\Transkript\Kaynak\UrlParser;
use Modules\Transkript\Kaynak\YoutubeMetadata;
use Modules\Transkript\Services\AnalizBaslatici;
use Modules\Transkript\Services\AnalizReddedildi;
use Modules\Transkript\Services\Chunker;
use Modules\Transkript\Services\Cleaner;
use Modules\Transkript\Services\KaynakDurumu;
use Modules\Transkript\Services\Parca;
use Modules\Transkript\Services\Pipeline;
use Modules\Transkript\Services\Search;
use Modules\Transkript\Transcript\AsrProvider;
use Modules\Transkript\Transcript\ExternalTranscriptProvider;
use Modules\Transkript\Transcript\GeminiVideoProvider;
use Modules\Transkript\Transcript\ProviderUnavailable;
use Modules\Transkript\Transcript\RateLimited;
use Modules\Transkript\Transcript\Segment;
use Modules\Transkript\Transcript\TranscriptHatasi;
use Modules\Transkript\Transcript\TranscriptNotFound;
use Modules\Transkript\Transcript\TranscriptProviderInterface;
use Modules\Transkript\Transcript\TranscriptResult;
use Modules\Transkript\Transcript\TranscriptZinciri;
use Modules\Transkript\Transcript\YouTubeTranscriptProvider;
use Modules\Transkript\TranskriptPolicy;
use Modules\Transkript\Zaman;

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
dogrula('FULLTEXT yolu: sorgu katlanır; rapor araması HEPSİ (+coklu* +ajan*), sohbet bağlamı HERHANGİ (coklu* ajan*)',
    $ft['params'][':q_whr'] === 'coklu* ajan*' && Search::segmentSorgusu(7, 'Çoklu AJAN', 10)['params'][':q_whr'] === '+coklu* +ajan*');

/* ---------------------------------------------------------------- */
echo "\nUrlParser (YouTube bağlantısı → 11 karakterlik kimlik)\n";

$kimlik = 'dQw4w9WgXcQ';
$gecerli = [
    'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    'http://youtube.com/watch?v=dQw4w9WgXcQ',
    'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=120s&list=PL123',
    'https://www.youtube.com/watch?feature=share&v=dQw4w9WgXcQ',
    'https://www.youtube.com/watch/?v=dQw4w9WgXcQ',
    'www.youtube.com/watch?v=dQw4w9WgXcQ',
    'youtube.com/watch?v=dQw4w9WgXcQ',
    'https://m.youtube.com/watch?v=dQw4w9WgXcQ&feature=youtu.be',
    'https://music.youtube.com/watch?v=dQw4w9WgXcQ',
    'https://youtu.be/dQw4w9WgXcQ',
    'https://youtu.be/dQw4w9WgXcQ?t=42&si=abc',
    'youtu.be/dQw4w9WgXcQ',
    'https://www.youtube.com/shorts/dQw4w9WgXcQ',
    'https://youtube.com/shorts/dQw4w9WgXcQ?feature=share',
    'https://www.youtube.com/embed/dQw4w9WgXcQ?start=10',
    'https://www.youtube.com/live/dQw4w9WgXcQ?si=x',
    'https://www.youtube.com/v/dQw4w9WgXcQ',
    'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
    'HTTPS://WWW.YOUTUBE.COM/watch?v=dQw4w9WgXcQ',
    'https://www.youtube.com./watch?v=dQw4w9WgXcQ',
    '  https://youtu.be/dQw4w9WgXcQ  ',
    'dQw4w9WgXcQ',
];
$yanlisOkunan = array_filter($gecerli, static fn (string $u): bool => UrlParser::videoKimligi($u) !== $kimlik);
dogrula('Geçerli ' . count($gecerli) . ' biçimin hepsinden kimlik çıkar (watch, youtu.be, shorts, embed, live, mobil, music, nocookie, şemasız)',
    $yanlisOkunan === [], implode(' · ', $yanlisOkunan));

$gecersiz = [
    '',
    'https://www.youtube.com/',
    'https://www.youtube.com/watch',
    'https://www.youtube.com/watch?v=kisa',
    'https://www.youtube.com/watch?v=dQw4w9WgXcQ1',          // 12 karakter
    'https://www.youtube.com/watch?v=dQw4w9WgX$Q',            // geçersiz karakter
    'https://www.youtube.com/watch?v[]=dQw4w9WgXcQ',          // dizi parametre
    'https://www.youtube.com/playlist?list=PLx0sYbCqOb8TBPRdmBHs5Iftvv9TPboYG',
    'https://www.youtube.com/embed/videoseries?list=PL123',   // "videoseries" 11 karakter ama liste
    'https://www.youtube.com/@kanal',
    'https://www.youtube.com/channel/UC_x5XG1OV2P6uZZ5FSM9Ttw',
    'https://youtube.com.evil.com/watch?v=dQw4w9WgXcQ',
    'https://evilyoutube.com/watch?v=dQw4w9WgXcQ',
    'https://youtube.com@evil.com/watch?v=dQw4w9WgXcQ',       // kullanıcı bilgisi hilesi
    'https://evil.com/?u=https://youtube.com/watch?v=dQw4w9WgXcQ',
    'https://evil.com/youtu.be/dQw4w9WgXcQ',
    'javascript:alert(1)//youtube.com/watch?v=dQw4w9WgXcQ',
    'ftp://youtube.com/watch?v=dQw4w9WgXcQ',
    'https://vimeo.com/123456',
    'https://www.yоutube.com/watch?v=dQw4w9WgXcQ',            // Kiril "о"
    str_repeat('a', 3000),
];
$kabulEdilen = array_filter($gecersiz, static fn (string $u): bool => UrlParser::videoKimligi($u) !== null);
dogrula('Geçersiz ' . count($gecersiz) . ' girdinin hepsi reddedilir (başka alan, @ hilesi, javascript:, liste, kanal, videoseries)',
    $kabulEdilen === [], implode(' · ', array_map(static fn (string $u): string => mb_substr($u, 0, 60), $kabulEdilen)));

dogrula('cozumle(): geçersizde KaynakHatasi(gecersiz_url) ve Türkçe mesaj', (static function (): bool {
    try {
        UrlParser::cozumle('https://vimeo.com/1');
    } catch (KaynakHatasi $e) {
        return $e->kod === 'gecersiz_url' && !$e->yenidenDenenebilir() && str_contains($e->kullaniciMesaji(), 'YouTube');
    }

    return false;
})());
dogrula('İstek adresi kullanıcının yazdığından değil kimlikten kurulur', UrlParser::izlemeAdresi($kimlik) === 'https://www.youtube.com/watch?v=dQw4w9WgXcQ');

/* ---------------------------------------------------------------- */
echo "\nYoutubeMetadata (sahte HTTP; ağa gidilmez)\n";

/** Adres önekine göre hazır yanıt veren, istekleri kaydeden istemci. */
final class SahteHttp implements HttpIstemci
{
    /** @var array<int,array{adres:string,basliklar:array<int,string>}> */
    public array $istekler = [];

    /** @param array<string,HttpYanit|list<HttpYanit>> $yanitlar adres öneki => yanıt (liste: sırayla, sonuncusu tekrarlanır) */
    public function __construct(private array $yanitlar)
    {
    }

    public function get(string $adres, array $basliklar = []): HttpYanit
    {
        $this->istekler[] = ['adres' => $adres, 'basliklar' => $basliklar];

        foreach ($this->yanitlar as $onek => $yanit) {
            if (str_starts_with($adres, $onek)) {
                if (is_array($yanit)) {
                    return count($yanit) > 1 ? array_shift($this->yanitlar[$onek]) : $yanit[0];
                }

                return $yanit;
            }
        }

        return new HttpYanit(0, '', [], 'sahte: tanımsız adres', 7);
    }

    public function post(string $adres, string $govde, array $basliklar = []): HttpYanit
    {
        return $this->get($adres, $basliklar);
    }
}

// Uyarılar (Data API yedeğe düştü) gerçek log klasörünü kirletmesin.
Logger::useDirectory(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'transkript-test-log');

const DATA_API = 'https://www.googleapis.com/youtube/v3/videos';
const OEMBED   = 'https://www.youtube.com/oembed';

/** @return string KaynakHatasi kodu ya da '' */
function kaynakHataKodu(callable $fn): string
{
    try {
        $fn();
    } catch (KaynakHatasi $e) {
        return $e->kod;
    }

    return '';
}

$dataApiOge = ['items' => [[
    'snippet' => [
        'title' => 'Çoklu ajan mimarisi', 'channelTitle' => 'Çılgın Kanal', 'channelId' => 'UC_x5XG1OV2P6uZZ5FSM9Ttw',
        'publishedAt' => '2026-01-15T10:00:00Z', 'defaultAudioLanguage' => 'tr', 'liveBroadcastContent' => 'none',
        'thumbnails' => ['high' => ['url' => 'https://i.ytimg.com/vi/x/hq.jpg'], 'maxres' => ['url' => 'https://i.ytimg.com/vi/x/max.jpg']],
    ],
    'contentDetails' => ['duration' => 'PT2H3M4S'],
    'status' => ['privacyStatus' => 'public', 'uploadStatus' => 'processed'],
]]];

$http = new SahteHttp([DATA_API => new HttpYanit(200, (string) json_encode($dataApiOge))]);
$b    = (new YoutubeMetadata($http, 'gizli-anahtar'))->getir($kimlik);
dogrula('Data API: başlık, kanal, süre (PT2H3M4S → 7384 sn), dil, en büyük görsel',
    $b->baslik === 'Çoklu ajan mimarisi' && $b->kanalAdi === 'Çılgın Kanal' && $b->kanalId === 'UC_x5XG1OV2P6uZZ5FSM9Ttw'
    && $b->sureSn === 7384 && $b->dil === 'tr' && $b->kucukResim === 'https://i.ytimg.com/vi/x/max.jpg'
    && $b->gizlilik === 'public' && !$b->canliYayin && $b->kaynak === 'data_api' && $b->yayinTarihi !== null);
dogrula('API anahtarı URL\'de değil başlıkta gider (log\'a düşmez)',
    count($http->istekler) === 1 && !str_contains($http->istekler[0]['adres'], 'gizli-anahtar')
    && in_array('X-Goog-Api-Key: gizli-anahtar', $http->istekler[0]['basliklar'], true));

$bos = new HttpYanit(200, '{"items":[]}');
dogrula('Data API boş + oEmbed 401 → gizli_video (kalıcı)', kaynakHataKodu(static fn () => (new YoutubeMetadata(new SahteHttp([DATA_API => $bos, OEMBED => new HttpYanit(401, 'Unauthorized')]), 'k'))->getir($kimlik)) === 'gizli_video');
dogrula('Data API boş + oEmbed 404 → video_bulunamadi', kaynakHataKodu(static fn () => (new YoutubeMetadata(new SahteHttp([DATA_API => $bos, OEMBED => new HttpYanit(404, 'Not Found')]), 'k'))->getir($kimlik)) === 'video_bulunamadi');

$silinmis = $dataApiOge;
$silinmis['items'][0]['status']['uploadStatus'] = 'rejected';
dogrula('uploadStatus rejected → silinmis_video', kaynakHataKodu(static fn () => (new YoutubeMetadata(new SahteHttp([DATA_API => new HttpYanit(200, (string) json_encode($silinmis))]), 'k'))->getir($kimlik)) === 'silinmis_video');

$oembed = new HttpYanit(200, '{"title":"Me at the zoo","author_name":"jawed","thumbnail_url":"https://i.ytimg.com/vi/x/hqdefault.jpg"}');
$http   = new SahteHttp([DATA_API => new HttpYanit(403, '{"error":{"errors":[{"reason":"quotaExceeded"}]}}'), OEMBED => $oembed]);
$b      = (new YoutubeMetadata($http, 'k'))->getir($kimlik);
dogrula('Data API kota hatası → oEmbed yedeği (analiz durmaz)', $b->kaynak === 'oembed' && $b->baslik === 'Me at the zoo' && $b->sureSn === null && count($http->istekler) === 2);

$logDosyasi = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'transkript-test-log' . DIRECTORY_SEPARATOR . 'transkript-' . date('Y-m-d') . '.log';
$referer    = '{"error":{"code":403,"errors":[{"reason":"forbidden"}],"details":[{"@type":"type.googleapis.com/google.rpc.ErrorInfo","reason":"API_KEY_HTTP_REFERRER_BLOCKED"}]}}';
$b          = (new YoutubeMetadata(new SahteHttp([DATA_API => new HttpYanit(403, $referer), OEMBED => $oembed]), 'k'))->getir($kimlik);
dogrula('Web sitesine kısıtlı anahtar: log\'a belirgin neden yazılır, oEmbed\'e düşülür',
    $b->kaynak === 'oembed' && str_contains((string) @file_get_contents($logDosyasi), '"neden":"API_KEY_HTTP_REFERRER_BLOCKED"'));

$http = new SahteHttp([OEMBED => $oembed]);
$b    = (new YoutubeMetadata($http, ''))->getir($kimlik);
dogrula('Anahtarsız: Data API\'ye hiç gidilmez, oEmbed kullanılır', $b->kanalAdi === 'jawed' && count($http->istekler) === 1 && str_starts_with($http->istekler[0]['adres'], OEMBED)
    && str_contains($http->istekler[0]['adres'], rawurlencode(UrlParser::izlemeAdresi($kimlik))));

$hata = static fn (HttpYanit $y): string => kaynakHataKodu(static fn () => (new YoutubeMetadata(new SahteHttp([OEMBED => $y]), ''))->getir($kimlik));
dogrula('oEmbed 503 / 429 / ağ hatası → metadata_hatasi (geçici, yeniden denenir)',
    $hata(new HttpYanit(503, '')) === 'metadata_hatasi' && $hata(new HttpYanit(429, '')) === 'metadata_hatasi'
    && $hata(new HttpYanit(0, '', [], 'Could not resolve host', 6)) === 'metadata_hatasi'
    && (new KaynakHatasi('metadata_hatasi'))->yenidenDenenebilir() && !(new KaynakHatasi('gizli_video'))->yenidenDenenebilir());
dogrula('Geçersiz kimlikle istek atılmaz', kaynakHataKodu(static fn () => (new YoutubeMetadata(new SahteHttp([]), ''))->getir('kisa')) === 'gecersiz_url');

dogrula('ISO 8601 süre: PT2H3M4S, PT45S, P1DT2H, P0D; bozukta null',
    YoutubeMetadata::iso8601Sure('PT2H3M4S') === 7384 && YoutubeMetadata::iso8601Sure('PT45S') === 45
    && YoutubeMetadata::iso8601Sure('P1DT2H') === 93600 && YoutubeMetadata::iso8601Sure('P0D') === 0
    && YoutubeMetadata::iso8601Sure('') === null && YoutubeMetadata::iso8601Sure('PT') === null && YoutubeMetadata::iso8601Sure('2 saat') === null);

/* ---------------------------------------------------------------- */
echo "\nTranscript zinciri (sahte sağlayıcılar)\n";

/** Sahte sağlayıcı: verilen hatayı fırlatır ya da sonucu döner; çağrıları sayar. */
final class SahteSaglayici implements TranscriptProviderInterface
{
    public int $cagri = 0;

    public function __construct(
        private readonly string $ad,
        private readonly ?Throwable $hata = null,
        private readonly bool $destekler = true,
    ) {
    }

    public function name(): string
    {
        return $this->ad;
    }

    public function supports(Kaynak $kaynak): bool
    {
        return $this->destekler;
    }

    public function fetch(Kaynak $kaynak, array $preferredLangs): TranscriptResult
    {
        $this->cagri++;

        if ($this->hata !== null) {
            throw $this->hata;
        }

        return new TranscriptResult([new Segment(0, 1000, 'merhaba')], 'tr', $this->ad, false);
    }
}

$ytKaynak = new Kaynak(1, 'youtube', 'dQw4w9WgXcQ', 0, 125, null);

/** @return TranscriptResult|Throwable */
function zincirSonucu(array $saglayicilar, Kaynak $kaynak): TranscriptResult|Throwable
{
    try {
        return (new TranscriptZinciri($saglayicilar))->getir($kaynak, ['tr']);
    } catch (Throwable $e) {
        return $e;
    }
}

$bulunamadi = new SahteSaglayici('youtube', new TranscriptNotFound('altyazı yok'));
$basarili   = new SahteSaglayici('gemini');
$sonuc      = zincirSonucu([$bulunamadi, $basarili], $ytKaynak);
dogrula('İlk sağlayıcı bulamazsa sıradaki denenir, ilk başarılı döner', $sonuc instanceof TranscriptResult && $sonuc->saglayici === 'gemini' && $bulunamadi->cagri === 1);

$ikinci = new SahteSaglayici('gemini');
dogrula('Video gizliyse (KaynakHatasi) zincir durur, sıradaki çağrılmaz',
    ($e = zincirSonucu([new SahteSaglayici('youtube', new KaynakHatasi('gizli_video')), $ikinci], $ytKaynak)) instanceof KaynakHatasi && $e->kod === 'gizli_video' && $ikinci->cagri === 0);
dogrula('Hepsi "yok" → TranscriptNotFound (kalıcı)',
    ($e = zincirSonucu([new SahteSaglayici('a', new TranscriptNotFound()), new SahteSaglayici('b', new TranscriptNotFound())], $ytKaynak)) instanceof TranscriptNotFound && !$e->yenidenDenenebilir());
dogrula('Hız sınırı varsa → RateLimited (iş sonra yeniden dener)',
    ($e = zincirSonucu([new SahteSaglayici('a', new RateLimited()), new SahteSaglayici('b', new TranscriptNotFound())], $ytKaynak)) instanceof RateLimited && $e->yenidenDenenebilir());
dogrula('IP engeli + Gemini anahtarsız → geçici transkript_api_hatasi',
    ($e = zincirSonucu([new SahteSaglayici('a', new ProviderUnavailable('ip_engeli', '', true)), new SahteSaglayici('b', new ProviderUnavailable('anahtar_yok'))], $ytKaynak)) instanceof ProviderUnavailable
    && $e->kod === 'transkript_api_hatasi' && $e->yenidenDenenebilir() && str_contains($e->getMessage(), 'a: ip_engeli'));
dogrula('Altyazı yok + Gemini anahtarsız → TranscriptNotFound',
    zincirSonucu([new SahteSaglayici('a', new TranscriptNotFound()), new SahteSaglayici('b', new ProviderUnavailable('anahtar_yok'))], $ytKaynak) instanceof TranscriptNotFound);
$desteklemeyen = new SahteSaglayici('asr', null, false);
dogrula('Kaynağı desteklemeyen sağlayıcı atlanır; hiçbiri desteklemezse saglayici_yok',
    zincirSonucu([$desteklemeyen, $basarili], $ytKaynak) instanceof TranscriptResult && $desteklemeyen->cagri === 0
    && ($e = zincirSonucu([$desteklemeyen], $ytKaynak)) instanceof ProviderUnavailable && $e->kod === 'saglayici_yok');

Ayarlar::set('transcript.saglayicilar', ['youtube', 'olmayan', 'gemini', 'asr']);
dogrula('Zincir sırası config / .env\'den; bilinmeyen ad atlanır', TranscriptZinciri::fromAyarlar()->adlar() === ['youtube', 'gemini', 'asr']);
// Yerel .env'deki TRANSCRIPT_PROVIDERS testi etkilemesin: değer geçici olarak boşaltılır.
$envDegerleri->setValue(null, ['TRANSCRIPT_PROVIDERS' => ''] + $oncekiEnv);
Ayarlar::forget();
dogrula('Varsayılan sıra: youtube, gemini', TranscriptZinciri::fromAyarlar()->adlar() === ['youtube', 'gemini']);
$envDegerleri->setValue(null, $oncekiEnv);
Ayarlar::forget();
dogrula('AsrProvider yalnız dosyayı, YouTube sağlayıcıları yalnız youtube\'u destekler',
    (new AsrProvider())->supports(new Kaynak(2, 'ses_dosyasi', str_repeat('a', 64))) && !(new AsrProvider())->supports($ytKaynak)
    && !(new ExternalTranscriptProvider())->supports(new Kaynak(2, 'video_dosyasi', str_repeat('a', 64))));

/* ---------------------------------------------------------------- */
echo "\nYouTubeTranscriptProvider (sahte HTTP; ağa gidilmez)\n";

const IZLEME = 'https://www.youtube.com/watch';
const PLAYER = 'https://www.youtube.com/youtubei/v1/player';
const ALTYAZI = 'https://www.youtube.com/api/timedtext';

$sayfa = new HttpYanit(200, '<html><script>ytcfg.set({"INNERTUBE_API_KEY":"AIzaTEST_key-1"});</script></html>');
$player = static fn (array $izler, array $ek = []): HttpYanit => new HttpYanit(200, (string) json_encode([
    'playabilityStatus' => ['status' => 'OK'],
    'captions' => ['playerCaptionsTracklistRenderer' => ['captionTracks' => $izler] + $ek],
]));
$iz = static fn (string $dil, bool $otomatik, string $ek = ''): array => [
    'baseUrl' => ALTYAZI . '?v=dQw4w9WgXcQ&lang=' . $dil . '&fmt=srv3' . $ek,
    'languageCode' => $dil,
    'name' => ['runs' => [['text' => $dil]]],
] + ($otomatik ? ['kind' => 'asr'] : []);
$xml = new HttpYanit(200, '<?xml version="1.0" encoding="utf-8" ?><transcript>'
    . '<text start="0.5" dur="2.16">it&amp;#39;s a &lt;font color=&quot;#fff&quot;&gt;test&lt;/font&gt;</text>'
    . '<text start="2.66" dur="1.5">ikinci   satır</text><text start="4" dur="1">   </text></transcript>');

$http   = new SahteHttp([IZLEME => $sayfa, PLAYER => $player([$iz('en', true), $iz('tr', false)]), ALTYAZI => $xml]);
$sonuc  = (new YouTubeTranscriptProvider($http))->fetch($ytKaynak, ['tr']);
dogrula('Elle girilmiş Türkçe iz seçilir; saglayici youtube_elle',
    $sonuc->dil === 'tr' && $sonuc->saglayici === 'youtube_elle' && !$sonuc->otomatikMi);
dogrula('XML: çift kaçış açılır, etiket atılır, saniye → ms, boş satır atılır',
    count($sonuc->segmentler) === 2 && $sonuc->segmentler[0]->metin === "it's a test"
    && $sonuc->segmentler[0]->baslangicMs === 500 && $sonuc->segmentler[0]->bitisMs === 2660 && $sonuc->segmentler[1]->metin === 'ikinci satır');
$sonIstek = end($http->istekler);
dogrula('Altyazı XML biçiminde istenir (fmt=srv3 atılır); player ANDROID istemcisiyle',
    is_array($sonIstek) && str_starts_with($sonIstek['adres'], ALTYAZI) && !str_contains($sonIstek['adres'], 'fmt=')
    && str_contains($http->istekler[1]['adres'], 'key=AIzaTEST_key-1'));

$izler = [$iz('en', true), $iz('fr-FR', true), $iz('de', false)];
dogrula('Dil sırası: tercih (elle) → orijinal (elle, otomatik) → en → herhangi',
    YouTubeTranscriptProvider::izSec($izler, ['tr'], 'en-US')['languageCode'] === 'en'
    && YouTubeTranscriptProvider::izSec($izler, ['de'], 'en-US')['languageCode'] === 'de'
    && YouTubeTranscriptProvider::izSec($izler, ['tr'], 'fr')['languageCode'] === 'fr-FR'
    && YouTubeTranscriptProvider::izSec([$iz('tr', true)], ['tr'], null)['languageCode'] === 'tr'
    && YouTubeTranscriptProvider::izSec([$iz('tr', true), $iz('en', false)], ['tr'], 'tr')['languageCode'] === 'tr'
    && YouTubeTranscriptProvider::izSec([], ['tr'], null) === null);
dogrula('Orijinal dil: audioTracks[defaultAudioTrackIndex] ("en-US.4"); tek ses izinde null',
    YouTubeTranscriptProvider::orijinalDil(['audioTracks' => [['audioTrackId' => 'fr-FR.10'], ['audioTrackId' => 'en-US.4']], 'defaultAudioTrackIndex' => 1]) === 'en-US'
    && YouTubeTranscriptProvider::orijinalDil(['captionTracks' => []]) === null);

$durumHatasi = static function (string $durum, string $neden = '', bool $altyazisiz = false): string {
    try {
        YouTubeTranscriptProvider::izListesi(['playabilityStatus' => ['status' => $durum, 'reason' => $neden]] + ($altyazisiz ? [] : ['captions' => []]), 'x');
    } catch (KaynakHatasi | TranscriptHatasi $e) {
        return get_class($e) === KaynakHatasi::class ? 'kaynak:' . $e->kod : $e->kod . ($e->yenidenDenenebilir() ? '+' : '');
    }

    return 'yok';
};
dogrula('Bot engeli → ip_engeli (geçici, zincir Gemini\'ye geçer)', $durumHatasi('LOGIN_REQUIRED', 'Sign in to confirm you’re not a bot') === 'ip_engeli+');
dogrula('Gizli → KaynakHatasi(gizli_video); kaldırılmış → silinmis_video; yok → video_bulunamadi',
    $durumHatasi('LOGIN_REQUIRED', 'This video is private') === 'kaynak:gizli_video'
    && $durumHatasi('ERROR', 'This video has been removed by the uploader') === 'kaynak:silinmis_video'
    && $durumHatasi('ERROR', 'Video unavailable') === 'kaynak:video_bulunamadi');
dogrula('Yaş sınırı / oynatılamaz → ProviderUnavailable; "page" sözcüğü yaş sınırı sayılmaz',
    $durumHatasi('LOGIN_REQUIRED', 'Sign in to confirm your age') === 'yas_siniri' && $durumHatasi('UNPLAYABLE', 'Not available on this page') === 'oynatilamaz');
dogrula('Altyazı kapalı → TranscriptNotFound(altyazi_kapali)', $durumHatasi('OK', '', true) === 'altyazi_kapali');

$hataKodu = static function (SahteHttp $h) use ($ytKaynak): string {
    try {
        (new YouTubeTranscriptProvider($h))->fetch($ytKaynak, ['tr']);
    } catch (TranscriptHatasi $e) {
        return $e->kod;
    }

    return 'yok';
};
dogrula('reCAPTCHA sayfası → rate_limit; player 429 → rate_limit',
    $hataKodu(new SahteHttp([IZLEME => new HttpYanit(200, '<div class="g-recaptcha"></div>')])) === 'rate_limit'
    && $hataKodu(new SahteHttp([IZLEME => $sayfa, PLAYER => new HttpYanit(429, '')])) === 'rate_limit');
dogrula('İz PoToken isterse (exp=xpe) potoken_gerekli; altyazı isteği atılmaz',
    $hataKodu($h = new SahteHttp([IZLEME => $sayfa, PLAYER => $player([$iz('en', true, '&exp=xpe')])])) === 'potoken_gerekli' && count($h->istekler) === 2);
dogrula('Ağ hatası → geçici baglanti',
    $hataKodu(new SahteHttp([IZLEME => new HttpYanit(0, '', [], 'Could not resolve host', 6)])) === 'baglanti');

$onay = new HttpYanit(200, '<form action="https://consent.youtube.com/s"><input name="v" value="cb.2021-17-p0.tr+FX+123"></form>');
$http = new SahteHttp([IZLEME => [$onay, $sayfa], PLAYER => $player([$iz('tr', false)]), ALTYAZI => $xml]);
(new YouTubeTranscriptProvider($http))->fetch($ytKaynak, ['tr']);
dogrula('AB çerez onayı: ikinci istek "Cookie: CONSENT=YES+…" başlığıyla (kaynakta Set-Cookie idi)',
    in_array('Cookie: CONSENT=YES+cb.2021-17-p0.tr+FX+123', $http->istekler[1]['basliklar'], true));

dogrula('xmlAdresi: fmt parametresi nerede olursa olsun atılır',
    YouTubeTranscriptProvider::xmlAdresi(ALTYAZI . '?v=x&fmt=srv3&lang=en') === ALTYAZI . '?v=x&lang=en'
    && YouTubeTranscriptProvider::xmlAdresi(ALTYAZI . '?fmt=json3') === ALTYAZI);
$xxe = YouTubeTranscriptProvider::xmlCozumle('<?xml version="1.0"?><!DOCTYPE t [<!ENTITY e SYSTEM "file:///' . str_replace('\\', '/', __FILE__) . '">]><transcript><text start="1" dur="1">a&e;b</text></transcript>');
dogrula('XXE: dış varlık dosyası okunmaz', !str_contains(implode(' ', array_map(static fn (Segment $s): string => $s->metin, $xxe)), 'dogrula'));
dogrula('Bozuk / boş XML boş liste döner', YouTubeTranscriptProvider::xmlCozumle('<transcript><text') === [] && YouTubeTranscriptProvider::xmlCozumle('') === []);

/* ---------------------------------------------------------------- */
echo "\nGeminiVideoProvider (sahte çağrı; ağa ve AI'a gidilmez)\n";

dogrula('Pencereler: süre biliniyorsa 20 dk dilimler, değilse / kısaysa tek çağrı',
    GeminiVideoProvider::pencereler(2500, 1200) === [[0, 1200], [1200, 2400], [2400, 2500]]
    && GeminiVideoProvider::pencereler(null, 1200) === [[null, null]] && GeminiVideoProvider::pencereler(900, 1200) === [[null, null]]);
dogrula('Zaman okuma: hh:mm:ss, mm:ss, kesirli, saniye; bozukta null',
    GeminiVideoProvider::zamaniOku('01:02:03') === 3723000 && GeminiVideoProvider::zamaniOku('02:03') === 123000
    && GeminiVideoProvider::zamaniOku('1:02:03.5') === 3723500 && GeminiVideoProvider::zamaniOku('75') === 75000
    && GeminiVideoProvider::zamaniOku('00:61:00') === null && GeminiVideoProvider::zamaniOku('yarım saat') === null);

$cagrilar = [];
$yanitlar = [
    '{"dil":"en","satirlar":[{"bas":"00:00:05","metin":"hello"},{"bas":"00:00:12","metin":"world"},{"bas":"00:25:00","metin":"pencere dışı"}]}',
    // İkinci pencere zamanları GÖRELİ veriyor (00:00:05 = 20:05)
    '{"dil":"en","satirlar":[{"bas":"00:00:05","metin":"second"},{"bas":"bozuk","metin":"atılır"},{"bas":"00:00:09","metin":"  "}]}',
    '{"dil":"en","satirlar":[{"bas":"00:40:10","metin":"last"}]}',
];
$gemini = new GeminiVideoProvider(
    static function (array $parcalar, string $sistem, array $ek) use (&$cagrilar, &$yanitlar): array {
        $cagrilar[] = [$parcalar, $ek];

        return ['text' => array_shift($yanitlar), 'refusal' => null, 'stop_reason' => 'end_turn', 'model' => 'gemini-2.5-flash',
            'usage' => ['input_tokens' => 1000, 'output_tokens' => 200], 'cost' => 0.0008];
    },
    1200,
    ['mediaResolution' => 'MEDIA_RESOLUTION_LOW'],
);
$sonuc = $gemini->fetch(new Kaynak(1, 'youtube', 'dQw4w9WgXcQ', 0, 2500), ['tr']);
$baslangiclar = array_map(static fn (Segment $s): int => $s->baslangicMs, $sonuc->segmentler);
dogrula('3 pencere = 3 çağrı; fileData + videoMetadata + JSON şeması + düşük çözünürlük',
    count($cagrilar) === 3 && ($cagrilar[1][0][0]['videoMetadata'] ?? null) === ['startOffset' => '1200s', 'endOffset' => '2400s']
    && str_contains($cagrilar[0][0][0]['fileData']['fileUri'], 'dQw4w9WgXcQ')
    && ($cagrilar[0][1]['responseSchema'] ?? null) === GeminiVideoProvider::SEMA && ($cagrilar[0][1]['mediaResolution'] ?? '') === 'MEDIA_RESOLUTION_LOW');
dogrula('Göreli zaman kaydırılır, pencere dışı ve bozuk satır atılır',
    $baslangiclar === [5000, 12000, 1205000, 2410000], implode(',', $baslangiclar));
dogrula('Bitiş = sonraki başlangıç (en çok 30 sn); saglayici gemini, otomatik',
    $sonuc->segmentler[0]->bitisMs === 12000 && $sonuc->segmentler[1]->bitisMs === 42000 && $sonuc->saglayici === 'gemini' && $sonuc->otomatikMi && $sonuc->dil === 'en');
dogrula('Her çağrının token ve maliyeti döner (ai_kullanim_kaydi için)',
    count($sonuc->aiKullanimi) === 3 && $sonuc->aiKullanimi[0]['giris_token'] === 1000 && $sonuc->aiKullanimi[0]['maliyet_usd'] === 0.0008);

[$dil, $kurtarilan] = GeminiVideoProvider::cozumle(['text' => '```json' . "\n" . '{"dil":"tr","satirlar":[{"bas":"00:00:01","metin":"Bir"},{"bas":"00:00:04","metin":"İki \"tırnak\""},{"bas":"00:00:0', 'refusal' => null, 'stop_reason' => 'max_tokens']);
dogrula('max token\'da kesilen JSON: tamamlanmış satırlar kurtarılır', $dil === 'tr' && count($kurtarilan) === 2 && $kurtarilan[1]['metin'] === 'İki "tırnak"');

$geminiHata = static function (AiHatasi|array $davranis): string {
    $saglayici = new GeminiVideoProvider(static function () use ($davranis): array {
        if ($davranis instanceof AiHatasi) {
            throw $davranis;
        }

        return $davranis;
    });

    try {
        $saglayici->fetch(new Kaynak(1, 'youtube', 'dQw4w9WgXcQ'), ['tr']);
    } catch (TranscriptHatasi $e) {
        return $e->kod . ($e->yenidenDenenebilir() ? '+' : '');
    }

    return 'yok';
};
dogrula('AI hata eşlemesi: rate_limit, anahtar_yok (kalıcı), sunucu (geçici)',
    $geminiHata(new AiHatasi('', 'rate_limit', 429)) === 'rate_limit+' && $geminiHata(new AiHatasi('', 'anahtar_yok')) === 'anahtar_yok'
    && $geminiHata(new AiHatasi('', 'sunucu', 503)) === 'sunucu+');
dogrula('Reddetme → gemini_reddetti; boş liste → transkript_bulunamadi; JSON değil → yanit_cozulemedi',
    $geminiHata(['text' => '', 'refusal' => 'SAFETY']) === 'gemini_reddetti'
    && $geminiHata(['text' => '{"dil":"tr","satirlar":[]}', 'refusal' => null]) === 'transkript_bulunamadi'
    && $geminiHata(['text' => 'Üzgünüm, yapamam.', 'refusal' => null]) === 'yanit_cozulemedi');

/* ---------------------------------------------------------------- */
echo "\nCleaner (temizleme ve 15–30 sn bloklar)\n";

dogrula('Varlık, etiket, [Music] / [Müzik] / ♪ ve fazla boşluk atılır',
    Cleaner::metniTemizle('it&amp;#39;s [Music] ♪ <font color="#fff">fine</font>   [Müzik] ok') === "it's fine ok"
    && Cleaner::metniTemizle('[Alkış]') === '');

$temiz = (new Cleaner())->temizle([
    new Segment(4920, 9840, 'üçüncü'),
    new Segment(199, 4920, 'birinci'),
    new Segment(2520, 7560, 'ikinci'),
    new Segment(7000, 7500, '[Music]'),
    new Segment(9840, 11000, 'üçüncü'),
]);
dogrula('Sıralanır, boş atılır, örtüşen bitiş sonraki başlangıca çekilir',
    count($temiz) === 3 && $temiz[0]->metin === 'birinci' && $temiz[0]->bitisMs === 2520 && $temiz[1]->bitisMs === 4920);
dogrula('Ardışık aynı satır tek segment olur, bitişi uzar', $temiz[2]->metin === 'üçüncü' && $temiz[2]->bitisMs === 11000);

$kisa = [];
for ($i = 0; $i < 30; $i++) {
    $kisa[] = new Segment($i * 2000, $i * 2000 + 2000, $i === 8 ? 'cümle biter.' : 'kelime');
}
$bloklar = (new Cleaner())->bloklara($kisa);
dogrula('Blok cümle sonunda kapanır (15 sn\'den sonra ilk nokta)', $bloklar[0]->bitisMs === 18000 && str_ends_with($bloklar[0]->metin, 'biter.'));
dogrula('Noktalama yoksa 30 sn\'yi aşmadan kapanır', $bloklar[1]->sureMs() <= 30000 && count($bloklar) === 3);
$uzun = (new Cleaner())->bloklara([new Segment(0, 40000, 'tek uzun konuşma'), new Segment(40000, 42000, 'sonra')]);
dogrula('30 sn\'yi tek başına aşan segment bölünmez', count($uzun) === 2 && $uzun[0]->sureMs() === 40000);
$konusmaci = (new Cleaner())->bloklara([new Segment(0, 2000, 'a', 'K1'), new Segment(2000, 4000, 'b', 'K2')]);
dogrula('Konuşmacı değişince blok kapanır', count($konusmaci) === 2 && $konusmaci[1]->konusmaci === 'K2');

/* ---------------------------------------------------------------- */
echo "\nChunker (~8–10 dk / ~4.000 token, 30 sn örtüşme)\n";

// 3 saat, 5 sn'lik otomatik altyazı satırları; her 4 satırda bir cümle sonu.
$ucSaat = [];
for ($t = 0, $i = 0; $t < 3 * 3600 * 1000; $t += 5000, $i++) {
    $ucSaat[] = new Segment($t, $t + 5000, 'bu satırda konuşmacı bir şeyler anlatıyor ve devam ediyor' . ($i % 4 === 3 ? '.' : ''));
}
$parcalar = (new Chunker())->parcala((new Cleaner())->hazirla($ucSaat));
$sureler  = array_map(static fn (Parca $p): int => intdiv($p->sureMs(), 1000), $parcalar);
dogrula('3 saatlik video ~20 parça (' . count($parcalar) . ')', count($parcalar) >= 18 && count($parcalar) <= 23);
dogrula('Son parça hariç her parça 8–10 dk', min(array_slice($sureler, 0, -1)) >= 480 && max($sureler) <= 600 + 120, implode(',', $sureler));
dogrula('İlk parça 0\'da başlar, son parça videonun sonunda biter, sıra 1..n',
    $parcalar[0]->baslangicMs === 0 && end($parcalar)->bitisMs === 3 * 3600 * 1000 && array_column(array_map(static fn (Parca $p): array => ['s' => $p->sira], $parcalar), 's') === range(1, count($parcalar)));
$ortusmeler = [];
for ($i = 1; $i < count($parcalar); $i++) {
    $ortusmeler[] = $parcalar[$i - 1]->bitisMs - $parcalar[$i]->baslangicMs;
}
dogrula('Ardışık parçalar arasında boşluk yok, örtüşme ≤ 1 dk', min($ortusmeler) > 0 && max($ortusmeler) <= 60000, implode(',', $ortusmeler));
$cumleIle = array_filter(array_slice($parcalar, 0, -1), static fn (Parca $p): bool => Cleaner::cumleSonu($p->segmentler[count($p->segmentler) - 1]->metin));
dogrula('Parçalar cümle sonunda kesilir', count($cumleIle) === count($parcalar) - 1);

// Noktalama yok: 9. dakikadaki 3 sn'lik sessizlikten kesilmeli.
$noktasiz = [];
for ($t = 0; $t < 20 * 60 * 1000; $t += 20000) {
    $ek = $t === 540000 ? 3000 : 0;
    $noktasiz[] = new Segment($t, $t + 20000 - 500 - $ek, 'noktalamasız otomatik altyazı metni');
}
$kesim = (new Chunker())->parcala($noktasiz);
dogrula('Noktalama yoksa en uzun sessizlikten kesilir', $kesim[0]->bitisMs === 540000 + 20000 - 500 - 3000, (string) $kesim[0]->bitisMs);
$bitisik = array_map(static fn (int $t): Segment => new Segment($t, $t + 20000, 'boşluksuz otomatik altyazı'), range(0, 20 * 60 * 1000, 20000));
$bitisikKesim = (new Chunker())->parcala($bitisik)[0]->bitisMs;
dogrula('Ne noktalama ne sessizlik varsa 9 dk\'ya en yakın blokta kesilir', abs($bitisikKesim - 540000) <= 10000, (string) $bitisikKesim);

$yogun = [];
for ($t = 0; $t < 30 * 60 * 1000; $t += 15000) {
    $yogun[] = new Segment($t, $t + 15000, str_repeat('yoğun teknik anlatım ', 100) . '.');
}
$yogunParca = (new Chunker())->parcala($yogun);
dogrula('Yoğun metinde token sınırı süreden önce dolar (≤ 5.000 token)', $yogunParca[0]->sureMs() < 480000 && max(array_map(static fn (Parca $p): int => $p->tahminiToken, $yogunParca)) <= 5000);
$onBucuk = array_map(static fn (int $t): Segment => new Segment($t, $t + 15000, 'kısa video.'), range(0, 10 * 60 * 1000 + 15000, 15000));
dogrula('Kısa kuyruk ayrı parça olmaz (10,5 dk → 1 parça); boş girdi → []',
    count((new Chunker())->parcala($onBucuk)) === 1 && (new Chunker())->parcala([]) === []);
dogrula('Token tahmini: karakter / 3,5', (new Chunker())->tahminiToken(str_repeat('a', 35)) === 10);

/* ---------------------------------------------------------------- */
echo "\nİşler (yük yalnız kimlik; hata sınıflandırması)\n";

$isler  = [KaynakBilgisiAl::class, TranskriptAl::class, AnalizBirlestir::class, RaporuTamamla::class];
$yukler = array_map(static fn (string $sinif): string => json_encode($sinif::fromPayload(['analiz_id' => '12'])->toPayload()), $isler);
dogrula('Zincirdeki işlerin yükü yalnız analiz_id (metinden tam sayıya)', array_values(array_unique($yukler)) === ['{"analiz_id":12}'], implode(' ', $yukler));
$parcaIsi = ParcaAnalizEt::fromPayload(['analiz_id' => 12, 'parca' => 7]);
dogrula('ParcaAnalizEt yükü: analiz_id + parca', $parcaIsi->toPayload() === ['analiz_id' => 12, 'parca' => 7]);
$kuyruklar = array_map(static fn (string $sinif): string => $sinif::fromPayload(['analiz_id' => 1])->queue(), $isler);
dogrula('Hepsi "transkript" kuyruğunda', $parcaIsi->queue() === 'transkript' && array_values(array_unique($kuyruklar)) === ['transkript']);
dogrula('Birleştirme 2, diğerleri 3 deneme', (new AnalizBirlestir(1))->tries() === 2 && (new TranskriptAl(1))->tries() === 3 && $parcaIsi->tries() === 3);

$kalici = [
    'gizli video → gizli_video'          => [new KaynakHatasi('gizli_video'), 'gizli_video'],
    'meta veri servisi geçici → null'    => [new KaynakHatasi('metadata_hatasi'), null],
    'altyazı yok → transkript_bulunamadi' => [new TranscriptNotFound('yok'), 'transkript_bulunamadi'],
    'canlı yayın → canli_yayin'          => [new TranscriptNotFound('canlı', 'canli_yayin'), 'canli_yayin'],
    'YouTube hız sınırı → null'          => [new RateLimited('429'), null],
    'sağlayıcı geçici → null'            => [new ProviderUnavailable('transkript_api_hatasi', '503', true), null],
    'sağlayıcı kalıcı → kendi kodu'      => [new ProviderUnavailable('altyazi_kapali', 'kapalı'), 'altyazi_kapali'],
    'AI anahtarı yok → ai_ayar_eksik'    => [new AiHatasi('x', 'anahtar_yok'), 'ai_ayar_eksik'],
    'AI model yok → ai_ayar_eksik'       => [new AiHatasi('x', 'model_bulunamadi'), 'ai_ayar_eksik'],
    'AI bozuk yanıt → ai_api_hatasi'     => [new AiHatasi('x', 'yanit_cozulemedi'), 'ai_api_hatasi'],
    'AI hız sınırı → null'               => [new AiHatasi('x', 'rate_limit'), null],
    'AI 5xx → null'                      => [new AiHatasi('x', 'sunucu'), null],
    'bilinmeyen istisna (ör. PDO) → null' => [new RuntimeException('bağlantı koptu'), null],
];
$yanlis = [];
foreach ($kalici as $ad => [$hata, $beklenenKod]) {
    if (TranskriptIsi::kaliciKod($hata) !== $beklenenKod) {
        $yanlis[] = $ad . ' (gerçek: ' . var_export(TranskriptIsi::kaliciKod($hata), true) . ')';
    }
}
dogrula('Kalıcı / geçici ayrımı (' . count($kalici) . ' durum)', $yanlis === [], implode('; ', $yanlis));

$tukenen = [
    'rate_limit'       => new RateLimited('429'),
    'ai_api_hatasi'    => new AiHatasi('x', 'sunucu'),
    'metadata_hatasi'  => new KaynakHatasi('metadata_hatasi'),
    'beklenmeyen_hata' => new RuntimeException('?'),
];
$yanlis = [];
foreach ($tukenen as $kod => $hata) {
    if (TranskriptIsi::hataKodu($hata) !== $kod) {
        $yanlis[] = $kod . ' ≠ ' . TranskriptIsi::hataKodu($hata);
    }
}
dogrula('Denemeler tükenince yazılan kod', $yanlis === [], implode('; ', $yanlis));
dogrula('AI hız sınırı tükenince "rate_limit"', TranskriptIsi::hataKodu(new AiHatasi('x', 'rate_limit')) === 'rate_limit');

$genel  = HataKodlari::mesaj('beklenmeyen_hata');
$kodlar = array_merge(array_filter(array_map(static fn (array $d): ?string => $d[1], $kalici)), array_keys($tukenen));
$mesajsiz = array_values(array_filter(
    array_unique($kodlar),
    static fn (string $k): bool => $k !== 'beklenmeyen_hata' && HataKodlari::mesaj($k) === $genel
));
dogrula('İşlerin yazabildiği her kodun kendi Türkçe mesajı var', $mesajsiz === [], implode(', ', $mesajsiz));
dogrula('Bilinmeyen kod genel mesaja, boş kod boş metne düşer',
    HataKodlari::mesaj('uydurma_kod') === $genel && HataKodlari::mesaj(null) === ''
    && HataKodlari::mesaj('gecersiz_url') === KaynakHatasi::MESAJLAR['gecersiz_url']);

dogrula('Zaman::oku hh:mm:ss / mm:ss / kesir / saniye',
    Zaman::oku('01:02:03') === 3723000 && Zaman::oku('02:03') === 123000 && Zaman::oku('0:00:01.5') === 1500 && Zaman::oku('75') === 75000);
dogrula('Zaman::oku geçersizi reddeder; Zaman::yaz hh:mm:ss',
    Zaman::oku('1:75') === null && Zaman::oku('abc') === null && Zaman::yaz(3723999) === '01:02:03' && Zaman::yaz(-5) === '00:00:00');

/* ---------------------------------------------------------------- */
echo "\nRapor maddeleri (Pipeline::ogeler; sahte map / reduce)\n";

$rapor = [
    'topics'                => [['title' => 'Giriş', 'start' => '00:00:00', 'end' => '00:05:10', 'summary' => ' özet ']],
    'important_points'      => [['text' => 'Önemli', 'at' => '00:01:00', 'importance' => 'Critical'], ['text' => '  ', 'at' => '00:02:00'], 'bozuk'],
    'technical_information' => [['name' => 'PHP 8.3', 'category' => 'dil', 'at' => '00:03:00']],
    'claims'                => [['text' => 'İddia', 'at' => '00:04:00', 'verification' => 'dogrulanmali']],
    'ideas'                 => [['text' => 'Fikir', 'type' => 'urun']],
    'timestamps'            => [['at' => '00:00:30', 'label' => 'Başlık'], ['at' => 'saçma', 'label' => 'Zamansız']],
];
$ogeler = Pipeline::ogeler($rapor);
dogrula('Her türden madde çıkar; boş ve bozuk madde atlanır',
    array_column($ogeler, 'tur') === ['konu', 'nokta', 'teknik', 'iddia', 'fikir', 'zaman'], implode(',', array_column($ogeler, 'tur')));
dogrula('Zamanlar milisaniyeye çevrilir; önem eşlenir; metin kırpılır',
    $ogeler[0]['baslangic_ms'] === 0 && $ogeler[0]['bitis_ms'] === 310000 && $ogeler[0]['aciklama'] === 'özet'
    && $ogeler[1]['onem'] === 'kritik' && $ogeler[5]['baslangic_ms'] === 30000);
dogrula('Zamanı okunamayan zaman çizelgesi maddesi atılır', count(array_filter($ogeler, static fn (array $o): bool => $o['tur'] === 'zaman')) === 1);
dogrula('Bölüm eksik ya da liste değilse madde yok', Pipeline::ogeler([]) === [] && Pipeline::ogeler(['topics' => 'metin']) === []);

$sahte  = new Pipeline();
$map    = $sahte->parcaAnalizEt(['baslangic_ms' => 540000, 'bitis_ms' => 1080000], [new Segment(541000, 560000, 'İkinci bölümün ilk cümlesi.')]);
$reduce = $sahte->birlestir([$map['sonuc']]);
dogrula('Sahte map parça aralığını taşır, AI kullanımı yok',
    $map['sonuc']['chunk'] === ['start' => '00:09:00', 'end' => '00:18:00'] && $map['kullanim'] === [] && $map['model'] === Pipeline::SAHTE_MODEL);
$reduceTurleri = array_column(Pipeline::ogeler($reduce['sonuc']), 'tur');
dogrula('Sahte reduce konu ve zaman maddesi üretir', in_array('konu', $reduceTurleri, true) && in_array('zaman', $reduceTurleri, true), implode(',', $reduceTurleri));

/* ---------------------------------------------------------------- */
echo "\nAnalizBaslatici (retler; veritabanı sahte)\n";

/** @return array{0:string,1:int,2:int} [kod, http, sorgu sayısı]; ret yoksa kod '' */
function baslatmaReddi(?User $kullanici, string $url, bool $yeniden = false): array
{
    $pdo = new KayitPdo();

    try {
        (new AnalizBaslatici($pdo, $kullanici))->youtube($url, $yeniden);
    } catch (AnalizReddedildi $e) {
        return [$e->kod, $e->http, count($pdo->sorgular)];
    } catch (Throwable) {
        // Sahte veritabanında kaynak açılamaz; burada yalnız retler ölçülür.
    }

    return ['', 0, count($pdo->sorgular)];
}

$gecerliUrl = 'https://youtu.be/dQw4w9WgXcQ';
dogrula('Geçersiz URL → gecersiz_url 422, veritabanına gidilmez', baslatmaReddi($editor, 'https://ornek.com/video') === ['gecersiz_url', 422, 0]);
dogrula('Analiz yetkisi olmayan üye → 403, veritabanına gidilmez', baslatmaReddi($uye, $gecerliUrl) === ['yetkisiz', 403, 0]);
dogrula('Editör yeniden analiz isteyemez → 403', baslatmaReddi($editor, $gecerliUrl, true) === ['yetkisiz', 403, 0]);
dogrula('Oturumsuz istek → 403', baslatmaReddi(null, $gecerliUrl) === ['yetkisiz', 403, 0]);

$hizKlasoru = sys_get_temp_dir() . '/transkript-hiz-' . bin2hex(random_bytes(4));
Throttle::useDirectory($hizKlasoru);
Ayarlar::set('analiz.saatlik_sinir', 1);
$ilk    = baslatmaReddi($yonetici, $gecerliUrl);
$ikinci = baslatmaReddi($yonetici, $gecerliUrl);
dogrula('Yeni iş saatlik sınıra tabi: ikinci istek → rate_limit 429',
    $ilk[0] === '' && $ikinci[0] === 'rate_limit' && $ikinci[1] === 429, json_encode([$ilk, $ikinci]));
Ayarlar::forget();
Throttle::useDirectory(null);
array_map('unlink', glob($hizKlasoru . '/*') ?: []);
@rmdir($hizKlasoru);

/* ---------------------------------------------------------------- */
echo "\nKaynakDurumu (ilerleme etiketi) ve zamanlanmış görev\n";

$parcaDurumu = ['durum' => 'analiz_ediliyor', 'asama' => 'parca', 'calisiyor' => false, 'parca' => ['toplam' => 14, 'biten' => 3, 'hatali' => 0], 'hata_kodu' => null];
dogrula('Etiket: analiz yok / sırada / çalışıyor / parça sayacı / bitti',
    KaynakDurumu::etiket(null) === 'Henüz analiz edilmedi'
    && KaynakDurumu::etiket(['durum' => 'bekliyor', 'asama' => 'kaynak', 'calisiyor' => false]) === 'Sırada bekliyor'
    && KaynakDurumu::etiket(['durum' => 'bekliyor', 'asama' => 'kaynak', 'calisiyor' => true]) === 'Video bilgisi alınıyor'
    && KaynakDurumu::etiket($parcaDurumu) === 'Bölümler analiz ediliyor (3 / 14)'
    && KaynakDurumu::etiket(['durum' => 'tamamlandi', 'asama' => 'bitti']) === 'Analiz tamamlandı');
dogrula('Etiket: hatada Türkçe mesaj (teknik ayrıntı yok)',
    KaynakDurumu::etiket(['durum' => 'hata', 'asama' => 'transkript', 'hata_kodu' => 'transkript_bulunamadi']) === HataKodlari::mesaj('transkript_bulunamadi'));

$gorevler = array_values(array_filter(Schedule::tasks(), static fn ($g): bool => $g->name() === 'transkript-kuyrugu'));
dogrula('Modül "transkript-kuyrugu" görevini bir kez, her dakika için kaydeder',
    count($gorevler) === 1 && $gorevler[0]->isDue(time(), time() - 60) && !$gorevler[0]->isDue(time(), time() - 10));


Logger::useDirectory(null);
Setting::flush();
Modules::forget();

/* ---------------------------------------------------------------- */
printf("\n%d geçti · %d kaldı\n\n", $gecti, $kaldi);

exit($kaldi > 0 ? 1 : 0);
