<?php
/**
 * =====================================================================
 *  TRANSKRİPT YETKİ FEATURE TESTİ – rol × eylem × sahiplik, HTTP kodu
 * ---------------------------------------------------------------------
 *      php tests/transkript-yetki.php
 *
 *  tests/transkript.php politikanın KARARLARINI veritabanısız sınar; bu
 *  dosya aynı kuralların uçlarda gerçek HTTP koduna dönüştüğünü
 *  doğrular: her hücre ayrı bir PHP sürecinde, çekirdeğin router'ı ve
 *  ara katmanlarıyla (installed → auth → csrf → can:…) çalışır.
 *
 *  VERİTABANI GEREKİR (yerel geliştirme; CI'da veritabanı yoksa atlanır).
 *  Gerçek kullanıcıya dokunmaz: kullanıcılar bellek içidir (kimlik
 *  99000x), kaynaklar "tkYetki" önekli sahte videolardır. Her hücreden
 *  önce bu kayıtlar sıfırdan kurulur, sonunda silinir; kuyruğa düşen
 *  işleri de hemen silinir. Ağa ve yapay zekâya GİDİLMEZ: bütün
 *  videoların başlığı vardır (oEmbed sorulmaz), kuyruk işletilmez.
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

use App\Core\Auth;
use App\Core\DashboardCards;
use App\Core\Database;
use App\Core\Env;
use App\Core\Exceptions\HttpException;
use App\Core\Log\Logger;
use App\Core\Modules\Modules;
use App\Core\Request;
use App\Core\Setting;
use App\Core\Throttle;
use App\Models\Role;
use App\Models\User;
use Modules\Transkript\Ayarlar;
use Modules\Transkript\Controllers\Api\TranskriptApiController;
use Modules\Transkript\Repositories\KaynakRepository;

const ONEK = 'tkYetki';

/** Bellek içi kullanıcılar: harf => [kimlik, rol, "Üyeler analiz başlatabilir" açık mı] */
const ROLLER = [
    'Y' => [990001, Role::ADMIN, false],
    'A' => [990002, Role::EDITOR, false],
    'B' => [990003, Role::EDITOR, false],
    'U' => [990004, Role::MEMBER, false],
    'P' => [990004, Role::MEMBER, true],
];

function kullanici(string $harf): ?User
{
    if (!isset(ROLLER[$harf])) {
        return null;
    }

    [$id, $rol] = ROLLER[$harf];

    return new User(id: $id, ad: $harf, soyad: 'Test', kullaniciAdi: 'tk_yetki_' . strtolower($harf), eposta: strtolower($harf) . '@ornek.test', rol: $rol);
}

/* =====================================================================
 *  ALT SÜREÇ: tek istek (--istek base64(json)) ya da API listesi (--api)
 * ================================================================== */
if (($argv[1] ?? '') === '--istek' || ($argv[1] ?? '') === '--api') {
    $istek = json_decode((string) base64_decode((string) ($argv[2] ?? '')), true) ?: [];

    $_SERVER['REQUEST_METHOD'] = $istek['yontem'] ?? 'GET';
    $_SERVER['REQUEST_URI']    = '/' . $istek['yol'];
    $_SERVER['HTTP_ACCEPT']    = 'application/json';
    $_SERVER['HTTP_X_REQUESTED_WITH'] = ($istek['ajax'] ?? true) ? 'XMLHttpRequest' : '';
    $_GET  = ['r' => $istek['yol']] + ($istek['sorgu'] ?? []);
    $_POST = ($istek['veri'] ?? []) + ['csrf_token' => 'tk-yetki-csrf'];
    $_SESSION = ['_csrf_token' => 'tk-yetki-csrf'];

    Setting::load(Database::connection());
    Modules::boot();
    // Hız sayaçları ve günlükler geçici klasöre: gerçek kullanıcının sınırına ve log'una dokunulmaz.
    Throttle::useDirectory((string) $istek['hiz_klasoru']);
    Logger::useDirectory((string) $istek['hiz_klasoru']);

    if (isset(ROLLER[$istek['rol']])) {
        Auth::actingAs(kullanici($istek['rol']));
        Ayarlar::set('analiz.uye_baslatabilir', ROLLER[$istek['rol']][2]);
    }

    ob_start();

    register_shutdown_function(static function (): void {
        $govde = '';

        while (ob_get_level() > 0) {
            $govde = (string) ob_get_clean() . $govde;
        }

        // Hücrenin kuyruğa bıraktığı işler hemen silinir (işçi görmeden).
        Database::connection()->exec(
            "DELETE i FROM isler i JOIN transkript_analizler a ON a.id = JSON_EXTRACT(i.veri, '$.analiz_id')
               JOIN transkript_kaynaklar k ON k.id = a.kaynak_id WHERE k.dis_kimlik LIKE '" . ONEK . "%'"
        );

        echo "\n@@SONUC@@" . json_encode(['kod' => http_response_code() ?: 200, 'govde' => mb_substr($govde, 0, 4000)], JSON_UNESCAPED_UNICODE);
    });

    /* Komut satırında ErrorHandler istisnayı konsola yazar, HTTP yanıtına
     * çevirmez; burada tarayıcının alacağı durum koduna çevrilir. */
    try {
        if ($argv[1] === '--api') {
            (new TranskriptApiController())->kaynaklar(new Request());
        }

        $router = require CY_BASE . '/routes/web.php';
        Modules::loadRoutes($router);
        $router->dispatch(new Request());
    } catch (HttpException $e) {
        http_response_code($e->wireStatus());
        echo json_encode(['success' => false, 'description' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }

    exit;
}

/* =====================================================================
 *  ANA SÜREÇ
 * ================================================================== */
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

echo "\nTranskript yetki feature testi (rol × eylem × sahiplik → HTTP)\n";

if (!Env::exists(CY_BASE . '/.env')) {
    echo "\n  (.env yok; veritabanı gerektiren bu test atlandı)\n\n";
    exit(0);
}

try {
    $db = Database::connection();
    $db->query('SELECT 1 FROM transkript_kutuphane LIMIT 1');
} catch (Throwable $e) {
    echo "\n  (veritabanı ya da transkript_kutuphane yok; php cy migrate — test atlandı)\n\n";
    exit(0);
}

Setting::load($db);
Modules::boot();

$hizKlasoru = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tk-yetki-' . bin2hex(random_bytes(4));
@mkdir($hizKlasoru, 0777, true);

/** Test kayıtlarını siler (kaynak silinince analiz, parça ve kütüphane satırı CASCADE ile gider). */
function temizle(PDO $db): void
{
    $db->exec(
        "DELETE i FROM isler i JOIN transkript_analizler a ON a.id = JSON_EXTRACT(i.veri, '$.analiz_id')
           JOIN transkript_kaynaklar k ON k.id = a.kaynak_id WHERE k.dis_kimlik LIKE '" . ONEK . "%'"
    );
    $db->exec("DELETE FROM transkript_kaynaklar WHERE dis_kimlik LIKE '" . ONEK . "%'");
}

/**
 * Sabit durum. K1 A'nın raporu · K2 B'nin raporu · K3 yöneticinin,
 * paylaşılan · K4 A'nın SÜREN analizi, paylaşılan (B görür ama iptal
 * edemez) · K5 A'nın kota yüzünden DURAN analizi (raporsuz).
 *
 * @return array<string,int> ad => kaynak_id
 */
function kur(PDO $db): array
{
    temizle($db);

    $tanim = [
        'K1' => ['A', 'tamamlandi', 0, null],
        'K2' => ['B', 'tamamlandi', 0, null],
        'K3' => ['Y', 'tamamlandi', 1, null],
        'K4' => ['A', 'analiz_ediliyor', 1, null],
        'K5' => ['A', 'hata', 0, 'ai_kota_doldu'],
    ];
    $kimlik = [];
    $sira   = 1;

    foreach ($tanim as $ad => [$sahip, $durum, $paylasildi, $hata]) {
        $video = ONEK . str_pad((string) $sira++, 4, '0', STR_PAD_LEFT);
        $db->prepare("INSERT INTO transkript_kaynaklar (tur, dis_kimlik, sahip_id, baslik, kanal_adi, sure_sn, paylasildi, olusturan_id)
                      VALUES ('youtube', :v, 0, :b, 'Test kanalı', 600, :p, :o)")
            ->execute([':v' => $video, ':b' => 'Yetki testi ' . $ad, ':p' => $paylasildi, ':o' => ROLLER[$sahip][0]]);
        $kaynakId = (int) $db->lastInsertId();
        $bitti    = $durum === 'tamamlandi';

        $db->prepare("INSERT INTO transkript_analizler (kaynak_id, kullanici_id, durum, asama, ilerleme, guncel_mi, sonuc_json, prompt_surumu, model, bitti_at, hata_kodu)
                      VALUES (:k, :u, :d, :a, :i, :g, :j, 'v3', 'test-modeli', :bt, :h)")
            ->execute([
                ':k' => $kaynakId, ':u' => ROLLER[$sahip][0], ':d' => $durum,
                ':a' => $bitti ? 'bitti' : 'parca', ':i' => $bitti ? 100 : 40, ':g' => $bitti ? 1 : 0,
                ':j' => $bitti ? json_encode(['summary' => 'Test özeti.', 'detailed_summary' => 'Ayrıntı.'], JSON_UNESCAPED_UNICODE) : null,
                ':bt' => $bitti ? date('Y-m-d H:i:s') : null, ':h' => $hata,
            ]);

        $db->prepare("INSERT INTO transkript_kutuphane (kullanici_id, kaynak_id, ekleme_turu) VALUES (:u, :k, 'baslatti')")
            ->execute([':u' => ROLLER[$sahip][0], ':k' => $kaynakId]);

        $kimlik[$ad] = $kaynakId;
    }

    return $kimlik;
}

/** @return array{kod:int, govde:string, json:array<string,mixed>|null} */
function istek(string $rol, string $yontem, string $yol, array $veri = [], array $sorgu = [], string $kip = '--istek', bool $ajax = true): array
{
    global $hizKlasoru;

    // ajax: panel JS'inin istekleri (POST, ilerleme JSON'u); false → tarayıcıda açılan sayfa.
    $yuk = base64_encode((string) json_encode([
        'rol' => $rol, 'yontem' => $yontem, 'yol' => $yol, 'veri' => $veri, 'sorgu' => $sorgu, 'hiz_klasoru' => $hizKlasoru,
        'ajax' => $ajax,
    ]));
    $cikti = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . $kip . ' ' . $yuk . ' 2>&1');
    $konum = strrpos($cikti, '@@SONUC@@');
    $sonuc = $konum !== false ? json_decode(substr($cikti, $konum + 9), true) : null;

    if (!is_array($sonuc)) {
        return ['kod' => 0, 'govde' => mb_substr($cikti, 0, 500), 'json' => null];
    }

    // ApiResponse çıktı tamponlarını boşaltıp doğrudan yazar; gövde o zaman işaretten öncedir.
    $govde = (string) $sonuc['govde'] !== '' ? (string) $sonuc['govde'] : trim(substr($cikti, 0, $konum));
    $json  = json_decode($govde, true);

    return ['kod' => (int) $sonuc['kod'], 'govde' => $govde, 'json' => is_array($json) ? $json : null];
}

function satir(PDO $db, string $sql, array $p = []): mixed
{
    $s = $db->prepare($sql);
    $s->execute($p);

    return $s->fetchColumn();
}

$url = static fn (string $video): string => 'https://www.youtube.com/watch?v=' . $video;

try {
    /* ------------------------------------------------------------ */
    echo "\nGörünürlük (GET; görmeyen 404 alır, varlık sızmaz)\n";

    $k = kur($db);
    $gorme = [
        // [rol, kaynak, beklenen]
        ['Y', 'K1', 200], ['A', 'K1', 200], ['B', 'K1', 404], ['U', 'K1', 404], ['P', 'K1', 404],
        ['B', 'K2', 200], ['U', 'K2', 404],
        ['A', 'K3', 200], ['B', 'K3', 200], ['U', 'K3', 200],
        ['A', 'K5', 200], ['B', 'K5', 404],
    ];
    $fark = [];

    foreach ($gorme as [$rol, $ad, $beklenen]) {
        $r = istek($rol, 'GET', 'panel/transkript/' . $k[$ad], ajax: false);
        $r2 = istek($rol, 'GET', 'panel/transkript/ilerleme/' . $k[$ad]);

        if ($r['kod'] !== $beklenen || $r2['kod'] !== $beklenen) {
            $fark[] = "$rol $ad: rapor {$r['kod']}, ilerleme {$r2['kod']} (beklenen $beklenen)";
        }
    }

    dogrula('Rapor sayfası ve ilerleme JSON\'u: kütüphanesindeki ve paylaşılan 200, başkasının 404 (' . count($gorme) . ' hücre)', $fark === [], implode('; ', $fark));
    $indir = istek('A', 'GET', 'panel/transkript/' . $k['K1'] . '/indir/rapor.md');
    $indirB = istek('B', 'GET', 'panel/transkript/' . $k['K1'] . '/indir/rapor.md');
    dogrula('İndirme ucu da aynı kural: sahibi 200, başkası 404', $indir['kod'] === 200 && $indirB['kod'] === 404, "A {$indir['kod']}, B {$indirB['kod']}");
    $misafir = istek('M', 'GET', 'panel/transkript/' . $k['K3'], ajax: false);
    dogrula('Misafir panel raporunu açamaz (girişe yönlenir)', in_array($misafir['kod'], [302, 401], true), (string) $misafir['kod']);

    /* ------------------------------------------------------------ */
    echo "\nAnaliz başlatma (POST panel/transkript/analiz)\n";

    $k = kur($db);
    $bOnce   = (int) satir($db, 'SELECT COUNT(*) FROM transkript_analizler WHERE kullanici_id = :u AND created_at >= CURDATE()', [':u' => ROLLER['B'][0]]);
    Throttle::useDirectory($hizKlasoru);
    $hizOnce = Throttle::remaining('transkript-analiz:' . ROLLER['B'][0], 20, 3600);
    $onbellek = istek('B', 'POST', 'panel/transkript/analiz', ['url' => $url(ONEK . '0001'), 'yeniden' => 0]);
    $bSonra  = (int) satir($db, 'SELECT COUNT(*) FROM transkript_analizler WHERE kullanici_id = :u AND created_at >= CURDATE()', [':u' => ROLLER['B'][0]]);
    $hizSonra = Throttle::remaining('transkript-analiz:' . ROLLER['B'][0], 20, 3600);
    $bKutup  = satir($db, 'SELECT ekleme_turu FROM transkript_kutuphane WHERE kullanici_id = :u AND kaynak_id = :k', [':u' => ROLLER['B'][0], ':k' => $k['K1']]);
    Throttle::useDirectory(null);
    dogrula('Önbellekteki video: 200 "onbellekten", B\'nin kütüphanesine "onbellek" olarak eklenir, günlük ve saatlik haktan düşmez',
        $onbellek['kod'] === 200 && ($onbellek['json']['sonuc'] ?? '') === 'onbellekten' && $bKutup === 'onbellek'
        && $bOnce === $bSonra && $hizOnce === $hizSonra, json_encode([$onbellek['kod'], $onbellek['json']['sonuc'] ?? null, $bKutup, $bOnce, $bSonra, $hizOnce, $hizSonra]));
    dogrula('Eklenen rapor artık B\'nin listesinde ve sayfası açılıyor',
        istek('B', 'GET', 'panel/transkript/' . $k['K1'], ajax: false)['kod'] === 200);
    dogrula('Önbellekten dönen başkasının raporunu B yeniden analiz edemez (yanıt bunu söyler)', ($onbellek['json']['yeniden_analiz'] ?? null) === false);

    $k = kur($db);
    $baslatma = [
        // [rol, url, yeniden, beklenen HTTP, beklenen sonuc]
        ['U', $url(ONEK . '0001'), 0, 403, null],
        ['U', 'https://ornek.com/video', 0, 403, null],
        ['A', 'https://ornek.com/video', 0, 422, null],
        ['M', $url(ONEK . '0001'), 0, 401, null],
        ['B', $url(ONEK . '0001'), 1, 403, null],
        ['P', $url(ONEK . '0003'), 0, 200, 'onbellekten'],
    ];
    $fark = [];

    foreach ($baslatma as [$rol, $adres, $yeniden, $beklenen, $sonuc]) {
        $r = istek($rol, 'POST', 'panel/transkript/analiz', ['url' => $adres, 'yeniden' => $yeniden]);

        if ($r['kod'] !== $beklenen || ($sonuc !== null && ($r['json']['sonuc'] ?? null) !== $sonuc)) {
            $fark[] = "$rol $adres yeniden=$yeniden → {$r['kod']} " . ($r['json']['sonuc'] ?? '') . " (beklenen $beklenen $sonuc)";
        }
    }

    dogrula('Üye 403 (bozuk bağlantıda da; yetki önce), analist bozuk bağlantıda 422, misafir 401, başkasının raporunu yeniden analiz 403; ayar açık üye önbellekten ekler',
        $fark === [], implode('; ', $fark));

    $k = kur($db);
    $kendi = istek('A', 'POST', 'panel/transkript/analiz', ['url' => $url(ONEK . '0001'), 'yeniden' => 1]);
    $aYeni = (int) satir($db, "SELECT COUNT(*) FROM transkript_analizler WHERE kaynak_id = :k AND kullanici_id = :u AND durum = 'bekliyor'", [':k' => $k['K1'], ':u' => ROLLER['A'][0]]);
    dogrula('Analist KENDİ raporunu yeniden analiz eder: 200 "baslatildi", yeni analiz açılır (kotadan düşer)',
        $kendi['kod'] === 200 && ($kendi['json']['sonuc'] ?? '') === 'baslatildi' && $aYeni === 1, json_encode([$kendi['kod'], $kendi['json']['sonuc'] ?? null, $aYeni]));
    $isKaldi = (int) satir($db, "SELECT COUNT(*) FROM isler i JOIN transkript_analizler a ON a.id = JSON_EXTRACT(i.veri, '$.analiz_id') WHERE a.kaynak_id = :k", [':k' => $k['K1']]);
    dogrula('Testin kuyruğa bıraktığı iş hemen silinir (işçi çalıştırmaz, yapay zekâya gidilmez)', $isKaldi === 0, (string) $isKaldi);

    $k = kur($db);
    $baskaDevam = istek('B', 'POST', 'panel/transkript/analiz', ['url' => $url(ONEK . '0005'), 'yeniden' => 0]);
    $aDurum = satir($db, 'SELECT durum FROM transkript_analizler WHERE kaynak_id = :k AND kullanici_id = :u ORDER BY id LIMIT 1', [':k' => $k['K5'], ':u' => ROLLER['A'][0]]);
    $bAnaliz = (int) satir($db, 'SELECT COUNT(*) FROM transkript_analizler WHERE kaynak_id = :k AND kullanici_id = :u', [':k' => $k['K5'], ':u' => ROLLER['B'][0]]);
    dogrula('Başkasının duran analizine dokunulmaz: B aynı videoyu gönderince KENDİ analizi açılır, A\'nınki "hata"da kalır',
        $baskaDevam['kod'] === 200 && ($baskaDevam['json']['sonuc'] ?? '') === 'baslatildi' && $aDurum === 'hata' && $bAnaliz === 1,
        json_encode([$baskaDevam['kod'], $baskaDevam['json']['sonuc'] ?? null, $aDurum, $bAnaliz]));

    $k = kur($db);
    $kendiDevam = istek('A', 'POST', 'panel/transkript/analiz', ['url' => $url(ONEK . '0005'), 'yeniden' => 0]);
    dogrula('Başlatan kendi duran analizini kaldığı yerden sürdürür: 200 "devam_etti"',
        $kendiDevam['kod'] === 200 && ($kendiDevam['json']['sonuc'] ?? '') === 'devam_etti', json_encode([$kendiDevam['kod'], $kendiDevam['json']['sonuc'] ?? null]));

    /* ------------------------------------------------------------ */
    echo "\nİptal, kütüphaneden çıkarma, silme, paylaşım (önce 404 / 403, en son 409)\n";

    $hucreler = [
        // [rol, yöntem yolu, kaynak, veri, beklenen]
        ['B', 'iptal', 'K4', [], 403],                 // görüyor (paylaşılan) ama başlatan değil
        ['U', 'iptal', 'K4', [], 403],
        ['A', 'iptal', 'K3', [], 403],                 // süren analiz yok AMA önce sahiplik: 409 değil 403
        ['A', 'iptal', 'K1', [], 409],                 // kendi kaydı, süren analiz yok
        ['B', 'iptal', 'K1', [], 404],                 // göremiyor
        ['M', 'iptal', 'K4', [], 401],
        ['A', 'iptal', 'K4', [], 200],
        ['Y', 'iptal', 'K4', [], 200],
        ['A', 'kutuphaneden-cikar', 'K3', [], 409],    // paylaşılan, kütüphanesinde değil
        ['B', 'kutuphaneden-cikar', 'K1', [], 404],
        ['A', 'kutuphaneden-cikar', 'K1', [], 200],
        ['A', 'sil', 'K1', [], 403],                   // ortak videoyu yalnız yönetici siler
        ['B', 'sil', 'K1', [], 404],
        ['Y', 'sil', 'K1', [], 200],
        ['A', 'paylas', 'K1', ['paylas' => 1], 403],
        ['U', 'paylas', 'K3', ['paylas' => 0], 403],
        ['Y', 'paylas', 'K1', ['paylas' => 1], 200],
        ['U', 'kuyruk/tik', null, [], 403],            // ayar kapalı üye kuyruğu işletemez
    ];
    $fark = [];

    foreach ($hucreler as [$rol, $eylem, $ad, $veri, $beklenen]) {
        $k   = kur($db);
        $yol = $ad === null ? 'panel/transkript/' . $eylem : 'panel/transkript/' . $k[$ad] . '/' . $eylem;
        $r   = istek($rol, 'POST', $yol, $veri);

        if ($r['kod'] !== $beklenen) {
            $fark[] = "$rol $eylem $ad → {$r['kod']} (beklenen $beklenen) " . mb_substr((string) ($r['json']['description'] ?? $r['govde']), 0, 80);
        }
    }

    dogrula('Her hücre beklenen HTTP kodunu verir (' . count($hucreler) . ' hücre)', $fark === [], implode("\n        ", $fark));

    $k = kur($db);
    istek('A', 'POST', 'panel/transkript/' . $k['K1'] . '/kutuphaneden-cikar');
    dogrula('Kütüphaneden çıkarma ortak kaydı silmez; A artık göremez, yönetici görür',
        (int) satir($db, 'SELECT COUNT(*) FROM transkript_kaynaklar WHERE id = :k', [':k' => $k['K1']]) === 1
        && istek('A', 'GET', 'panel/transkript/' . $k['K1'], ajax: false)['kod'] === 404
        && istek('Y', 'GET', 'panel/transkript/' . $k['K1'], ajax: false)['kod'] === 200);

    $k = kur($db);
    istek('Y', 'POST', 'panel/transkript/' . $k['K2'] . '/paylas', ['paylas' => 1]);
    dogrula('Yönetici paylaşınca üye ve A, B\'nin raporunu görür',
        istek('U', 'GET', 'panel/transkript/' . $k['K2'], ajax: false)['kod'] === 200
        && istek('A', 'GET', 'panel/transkript/' . $k['K2'], ajax: false)['kod'] === 200);

    /* ------------------------------------------------------------ */
    echo "\nSayılar: Kontrol Paneli = liste = API (aynı kapsam)\n";

    $k = kur($db);
    $repo = new KaynakRepository($db);
    $fark = [];

    foreach (['Y', 'A', 'B', 'U'] as $rol) {
        $kim    = kullanici($rol);
        $admin  = $kim->can('transkript.admin');
        Ayarlar::set('analiz.uye_baslatabilir', false);
        $liste  = $repo->liste(['durum' => 'hazir'], 1, 100, $kim->id, $admin)['toplam'];
        $sayac  = $admin ? $repo->istatistik(null)['kaynak'] : $repo->gorunenIstatistik($kim->id)['kaynak'];
        $kart   = null;

        foreach (DashboardCards::collect($kim) as $c) {
            if (str_starts_with($c['etiket'], 'Transkript rapor')) {
                $kart = (int) $c['deger'];
            }
        }

        $api    = istek($rol, 'GET', 'api/v1/transkript/kaynaklar', [], ['durum' => 'hazir', 'sayfa_boyut' => 100], '--api');
        $apiSay = $api['json']['meta']['total'] ?? $api['json']['meta']['toplam'] ?? null;

        if (!($liste === $sayac && $sayac === $kart && $kart === (int) $apiSay)) {
            $fark[] = "$rol: liste $liste, sayaç $sayac, kart " . var_export($kart, true) . ', API ' . var_export($apiSay, true);
        }
    }

    Ayarlar::forget();
    dogrula('Her rolde liste "Raporu hazır" toplamı = sayaç = Kontrol Paneli kartı = API meta', $fark === [], implode('; ', $fark));

    $bListe = array_column($repo->liste([], 1, 100, ROLLER['B'][0], false)['satirlar'], 'id');
    $uListe = array_column($repo->liste([], 1, 100, ROLLER['U'][0], false)['satirlar'], 'id');
    sort($bListe);
    sort($uListe);
    $beklenenB = [$k['K2'], $k['K3'], $k['K4']];
    $beklenenU = [$k['K3'], $k['K4']];
    sort($beklenenB);
    sort($beklenenU);
    dogrula('Liste kapsamı: B kendi raporu + paylaşılanlar; üye yalnız paylaşılanlar (test kayıtları içinde)',
        array_values(array_intersect($bListe, $k)) === $beklenenB && array_values(array_intersect($uListe, $k)) === $beklenenU,
        json_encode([$bListe, $uListe, $k]));
} finally {
    temizle($db);
    Throttle::useDirectory(null);

    $ogeler = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($hizKlasoru, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

    foreach ($ogeler as $oge) {
        $oge->isDir() ? @rmdir($oge->getPathname()) : @unlink($oge->getPathname());
    }

    @rmdir($hizKlasoru);
}

$artik = (int) satir($db, "SELECT COUNT(*) FROM transkript_kaynaklar WHERE dis_kimlik LIKE '" . ONEK . "%'");
dogrula('Test kayıtları silindi', $artik === 0, (string) $artik);

printf("\n%d geçti · %d kaldı\n\n", $gecti, $kaldi);

exit($kaldi > 0 ? 1 : 0);
