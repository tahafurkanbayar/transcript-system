<?php
/**
 * =====================================================================
 *  EVREN ölçüm aracı (docs/evren, FAZ 2)
 * ---------------------------------------------------------------------
 *      php modules/Transkript/bin/evren-olcum.php --fikstur-uret
 *      php modules/Transkript/bin/evren-olcum.php --suite=all --dry-run
 *      php modules/Transkript/bin/evren-olcum.php --suite=smoke
 *      php modules/Transkript/bin/evren-olcum.php --suite=models --models=glm-5.3,deepseek-v4.1-flash --boy=kisa,orta
 *
 *  Seçenekler:
 *    --suite=smoke|models|budget|stream|context|consistency|json|fallback|all (virgülle birden çok)
 *    --models=<liste>        varsayılan: glm-5.3,deepseek-v4.1-flash,mimo-v2.6-pro,qwen3.8-flash-next,gemma-4-31b,auto
 *    --boy=kisa,orta,uzun,cok_uzun  models / json için fixture boyları (varsayılan kisa,orta)
 *    --uzun-models=<liste>   uzun ve çok uzun fixture'ı yalnız bu modeller çalıştırır
 *    --uzun-boy=uzun,cok_uzun bu modellerin çalıştıracağı uzun boylar
 *    --repeat=3 --concurrency=2 --max-tokens-budget=1500000
 *    --akis=1|0 --dusunme=low|off|default --sema=1|0  (akış, düşünme, json_schema; varsayılan .env)
 *                            "önce" ölçümü: --akis=0 --dusunme=default --sema=0 (11.10.2026 sabahki üretim)
 *    --etiket=<ad>           çalışma klasörünün adına eklenir (ör. once, sonra)
 *    --dry-run               çağrı yapmadan plan ve tahmini token
 *
 *  ÜRETİM KODUNU ÖLÇER: EvrenProvider (Ai::make), Pipeline (v3 istemleri,
 *  onarım, doğrulayıcı, kademeli birleştirme), Chunker, Cleaner. Araya
 *  yalnız çağrıları kaydeden ince bir sarmalayıcı girer (Kaydedici). Ölçüm
 *  çağrıları ai_kullanim_kaydi'na YAZILMAZ (panel istatistiği bozulmasın).
 *
 *  GÜVENLİK: anahtar hiçbir dosyaya yazılmaz (hata metinleri maskeli);
 *  günlük kalan kota 5.000.000'un altındaysa başlamaz; toplam harcama
 *  --max-tokens-budget'ı aşacaksa durur; 503'te 2/4/8 sn bekleyip yeniden,
 *  429'da durur. "fallback" ağa gitmez (sahte HTTP, ayrı dinlenme dosyası).
 *
 *  Çıktı: storage/files/evren-bench/runs/<tarih-saat>[-etiket]/
 *         results.jsonl (çağrı başına), summary.json, rapor.md
 *  Fixture: storage/files/evren-bench/fixtures/*.json (herkese açık
 *  YouTube videolarının transcript'i; kullanıcı bilgisi yok).
 * =====================================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('CY_BASE', dirname(__DIR__, 3));
define('CY_START', microtime(true));

require CY_BASE . '/app/bootstrap.php';

use App\Core\Database;
use App\Core\Modules\Modules;
use App\Core\Setting;
use Modules\Transkript\Ai\Ai;
use Modules\Transkript\Ai\AiHatasi;
use Modules\Transkript\Ai\DinlenmeDeposu;
use Modules\Transkript\Ai\EvrenHesap;
use Modules\Transkript\Ai\EvrenProvider;
use Modules\Transkript\Ai\GirisSiniri;
use Modules\Transkript\Ai\OpenAiCompatibleProvider;
use Modules\Transkript\Ai\Provider;
use Modules\Transkript\Ai\YedekliSaglayici;
use Modules\Transkript\Repositories\AiKullanimRepository;
use Modules\Transkript\Services\Chunker;
use Modules\Transkript\Services\Cleaner;
use Modules\Transkript\Services\Pipeline;
use Modules\Transkript\Services\ResultValidator;
use Modules\Transkript\Services\Search;
use Modules\Transkript\Transcript\Segment;
use Modules\Transkript\Zaman;

Setting::load(Database::connection());
Modules::boot();

const OLCUM_KOK = CY_BASE . '/storage/files/evren-bench';
const VARSAYILAN_MODELLER = ['glm-5.3', 'deepseek-v4.1-flash', 'mimo-v2.6-pro', 'qwen3.8-flash-next', 'gemma-4-31b', 'auto'];
const DUSUNEN_MODELLER = ['glm-5.3', 'deepseek-v4.1-flash', 'mimo-v2.6-pro', 'qwen3.8-flash-next'];
const EN_AZ_KOTA = 5_000_000;

/** Fixture'lar: kaynak kimliği => [ad, boy]. Herkese açık YouTube videoları. */
const FIKSTURLER = [
    4382 => ['evrim-agaci-2dk', 'kisa'],
    4221 => ['yapay-zeka-nedir-2dk', 'kisa'],
    4381 => ['185-milyon-nesil-4dk-en', 'orta'],
    4380 => ['cikolata-tarihi-4dk', 'orta'],
    4222 => ['internet-agi-5dk', 'orta'],
    4379 => ['satranc-tarihi-5dk', 'orta'],
    4383 => ['jev-a-b-beyni-39dk', 'uzun'],
    3910 => ['evren-platformu-77dk', 'cok_uzun'],
];

/* =====================================================================
 *  YARDIMCI SINIFLAR
 * ================================================================== */

/** Ölçüm çağrıları üretim günlüğüne yazılmasın: her sorgu boş geçer. */
final class OlcumSorgu extends PDOStatement
{
    public function execute(?array $params = null): bool
    {
        return true;
    }
}

final class OlcumPdo extends PDO
{
    public function __construct()
    {
        parent::__construct('sqlite::memory:');
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [OlcumSorgu::class]);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare('SELECT 0 WHERE 0');
    }
}

/**
 * Üretim sağlayıcısını sarar; her sendJson / send çağrısını bir ölçüm
 * satırı olarak kaydeder (başarı ya da hata). 503'te 2/4/8 sn bekleyip
 * yeniden dener; 429'da Durdur fırlatır.
 */
final class Kaydedici implements Provider
{
    public string $asama = 'bolum';
    public int $parca = 0;

    /** @var list<array<string,mixed>> */
    public array $kayitlar = [];

    /** @param Closure(array<string,mixed>): void $yaz */
    public function __construct(private readonly EvrenProvider $ic, private readonly array $ortak, private readonly Closure $yaz, private readonly Closure $butceVar)
    {
    }

    public function send(array $messages, string $system = ''): array
    {
        return $this->cagir(fn (): array => $this->ic->send($messages, $system));
    }

    public function sendJson(array $messages, string $system, array $sema, array $ek = []): array
    {
        return $this->cagir(fn (): array => $this->ic->sendJson($messages, $system, $sema, $ek));
    }

    public function model(): string
    {
        return $this->ic->model();
    }

    private function cagir(Closure $istek): array
    {
        if (!($this->butceVar)()) {
            throw new Durdur('Token bütçesi doldu.');
        }

        for ($deneme = 0; ; $deneme++) {
            $bas = microtime(true);

            try {
                $y = $istek();
                $this->kaydet($y, null, $bas);

                return $y;
            } catch (AiHatasi $e) {
                $this->kaydet(null, $e, $bas);

                if ($e->kod === 'rate_limit' || $e->kod === 'kota_doldu') {
                    throw new Durdur('EVREN 429 (' . $e->kod . '): ölçüm durduruldu.');
                }

                if ($e->kod === 'sunucu' && $deneme < 3) {
                    sleep(2 ** ($deneme + 1));

                    continue;
                }

                throw $e;
            }
        }
    }

    /** @param array<string,mixed>|null $y */
    private function kaydet(?array $y, ?AiHatasi $e, float $bas): void
    {
        $o     = $y['olcum'] ?? ($e !== null && $e->olcum !== [] ? $e->olcum : $this->ic->sonOlcum());
        $metin = (string) ($y['text'] ?? '');
        $sure  = (int) round((microtime(true) - $bas) * 1000);
        $cikis = (int) ($y['usage']['output_tokens'] ?? $o['cikis_token'] ?? 0);

        $kayit = $this->ortak + [
            'asama'          => $this->asama,
            'parca'          => $this->parca,
            'istenen_model'  => $this->ic->model(),
            'cozulen_model'  => (string) ($y['model'] ?? ''),
            'akis'           => (bool) ($o['akis'] ?? false),
            'max_tokens'     => $o['max_token'] ?? null,
            'http_status'    => $e?->httpDurumu ?: ($o['http'] ?? null),
            'hata_kodu'      => $e?->kod,
            'hata_ozet'      => $e !== null ? mb_substr(Ai::maskele($e->getMessage()), 0, 240) : null,
            'finish_reason'  => $o['bitis_nedeni'] ?? null,
            'prompt_tokens'  => (int) ($y['usage']['input_tokens'] ?? $o['giris_token'] ?? 0),
            'completion_tokens' => $cikis,
            'reasoning_tokens'  => $o['dusunme_token'] ?? null,
            'cached_tokens'  => $o['onbellek_token'] ?? null,
            'ttft_ms'        => $o['ilk_token_ms'] ?? null,
            'toplam_ms'      => $sure,
            'tokens_per_sec' => $sure > 0 && $cikis > 0 ? round($cikis / ($sure / 1000), 1) : null,
            'keepalive'      => (int) ($o['keepalive'] ?? 0),
            'bosta_kesildi'  => (bool) ($o['bosta_kesildi'] ?? false),
            'kalan_kota_sonra' => $o['kalan_kota'] ?? null,
            'cikti_karakter' => mb_strlen($metin),
            'json_gecerli'   => $y !== null ? ResultValidator::ayikla($metin) !== null : false,
            'json_citli'     => $y !== null && str_starts_with(ltrim($metin), '```'),
            'turkce_mi'      => $y !== null ? turkceMi($metin) : null,
            'zaman'          => date('c', (int) $bas),
        ];

        $this->kayitlar[] = $kayit;
        ($this->yaz)($kayit);
    }
}

final class Durdur extends RuntimeException
{
}

/* =====================================================================
 *  KOMUT SATIRI
 * ================================================================== */

$secenek = static function (string $ad, ?string $varsayilan = null) use ($argv): ?string {
    foreach (array_slice($argv, 1) as $arguman) {
        if ($arguman === '--' . $ad) {
            return '1';
        }

        if (str_starts_with($arguman, '--' . $ad . '=')) {
            return substr($arguman, strlen($ad) + 3);
        }
    }

    return $varsayilan;
};
$liste = static fn (?string $metin): array => array_values(array_filter(array_map('trim', explode(',', (string) $metin)), static fn (string $s): bool => $s !== ''));

if ($secenek('fikstur-uret') !== null) {
    fiksturUret();
    exit(0);
}

// Alt süreç: görev dosyasının kendi payını çalıştırır.
if (($cocuk = $secenek('cocuk')) !== null) {
    exit(cocukCalistir($cocuk, (string) $secenek('parca', '1/1')));
}

$suitler = $liste($secenek('suite', 'smoke'));
$suitler = in_array('all', $suitler, true) ? ['smoke', 'models', 'budget', 'stream', 'context', 'consistency', 'json', 'fallback'] : $suitler;
$ayar = [
    'modeller'     => $liste($secenek('models', implode(',', VARSAYILAN_MODELLER))),
    'uzun_modeller' => $liste($secenek('uzun-models', 'glm-5.3,deepseek-v4.1-flash')),
    'uzun_boylar'  => $liste($secenek('uzun-boy', 'uzun,cok_uzun')),
    'boylar'       => $liste($secenek('boy', 'kisa,orta')),
    'tekrar'       => max(1, (int) $secenek('repeat', '3')),
    'eszamanli'    => max(1, min(4, (int) $secenek('concurrency', '2'))),
    'butce'        => max(10_000, (int) $secenek('max-tokens-budget', '1500000')),
    'akis'         => $secenek('akis') !== null ? $secenek('akis') === '1' : null,
    'dusunme'      => $secenek('dusunme'),
    'sema'         => $secenek('sema') !== null ? $secenek('sema') === '1' : null,
    'kuru'         => $secenek('dry-run') !== null,
    'etiket'       => preg_replace('/[^a-z0-9_-]/i', '', (string) $secenek('etiket', '')),
];

$fiksturler = fiksturleriOku();

if ($fiksturler === [] && array_diff($suitler, ['smoke', 'fallback']) !== []) {
    fwrite(STDERR, "Fixture yok. Önce: php modules/Transkript/bin/evren-olcum.php --fikstur-uret\n");
    exit(1);
}

$gorevler = gorevleriPlanla($suitler, $ayar, $fiksturler);
$tahmin   = array_sum(array_column($gorevler, 'tahmin'));

echo sprintf("Plan: %d görev · tahmini %s token · bütçe %s · eşzamanlı %d\n", count($gorevler), sayi($tahmin), sayi($ayar['butce']), $ayar['eszamanli']);

foreach ($gorevler as $g) {
    echo sprintf("  %-11s %-20s %-26s %-6s %-8s %s\n", $g['suite'], $g['model'], $g['fikstur'] ?? '-', $g['tur'],
        ($g['akis'] === null ? 'env' : ($g['akis'] ? 'akış' : 'akışsız')) . '/' . ($g['dusunme'] ?? 'env') . '/' . (($g['sema'] ?? null) === false ? 'object' : 'şema'),
        ($g['max_token'] ?? '') . ($g['hedef_token'] ? ' hedef ' . $g['hedef_token'] : '') . ' ~' . sayi($g['tahmin']));
}

if ($tahmin > $ayar['butce']) {
    echo "UYARI: tahmin bütçeyi aşıyor; bütçe dolunca kalan görevler atlanır.\n";
}

if ($ayar['kuru']) {
    echo "\n--dry-run: çağrı yapılmadı.\n";
    exit(0);
}

$hesap = EvrenHesap::ortamdan() ?? throw new RuntimeException('.env içinde EVREN_API_KEY yok.');
$kotaOnce = $hesap->kota()->json() ?? [];

if (array_diff($suitler, ['fallback']) !== [] && (int) ($kotaOnce['daily_remaining_tokens'] ?? 0) < EN_AZ_KOTA) {
    fwrite(STDERR, 'Günlük kalan kota ' . sayi((int) ($kotaOnce['daily_remaining_tokens'] ?? 0)) . " < 5.000.000: üretime pay bırakmak için ölçüm başlamadı.\n");
    exit(2);
}

$klasor = OLCUM_KOK . '/runs/' . date('Ymd-His') . ($ayar['etiket'] !== '' ? '-' . $ayar['etiket'] : '');
@mkdir($klasor, 0775, true);
file_put_contents($klasor . '/plan.json', json_encode(['ayar' => $ayar, 'suitler' => $suitler, 'gorevler' => $gorevler], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

$bas = microtime(true);

// fallback ağa gitmez: ana süreçte çalışır.
$cevrimdisi = array_values(array_filter($gorevler, static fn (array $g): bool => $g['suite'] === 'fallback'));
$agli       = array_values(array_filter($gorevler, static fn (array $g): bool => $g['suite'] !== 'fallback'));

if ($cevrimdisi !== []) {
    yedekSenaryolari($klasor . '/results-fallback.jsonl');
}

if ($agli !== []) {
    file_put_contents($klasor . '/gorevler.json', json_encode($agli, JSON_UNESCAPED_UNICODE));
    $n = min($ayar['eszamanli'], count($agli));
    $surecler = [];

    for ($i = 1; $i <= $n; $i++) {
        $surecler[] = proc_open([PHP_BINARY, __FILE__, '--cocuk=' . $klasor, '--parca=' . $i . '/' . $n, '--max-tokens-budget=' . intdiv($ayar['butce'], $n)],
            [1 => ['file', $klasor . '/cikti-' . $i . '.log', 'a'], 2 => ['file', $klasor . '/cikti-' . $i . '.log', 'a']], $borular);
    }

    foreach ($surecler as $surec) {
        proc_close($surec);
    }
}

$kayitlar = [];

foreach (glob($klasor . '/results-*.jsonl') ?: [] as $dosya) {
    foreach (file($dosya, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $satir) {
        $kayit = json_decode($satir, true);

        if (is_array($kayit)) {
            $kayitlar[] = $kayit;
        }
    }
}

file_put_contents($klasor . '/results.jsonl', implode("\n", array_map(static fn (array $k): string => (string) json_encode($k, JSON_UNESCAPED_UNICODE), $kayitlar)) . "\n");

$kotaSonra = $hesap->kota()->json() ?? [];
$ozet = ozetle($kayitlar, [
    'baslangic'  => date('c', (int) $bas),
    'sure_sn'    => (int) (microtime(true) - $bas),
    'suitler'    => $suitler,
    'ayar'       => $ayar,
    'kota_once'  => array_intersect_key($kotaOnce, array_flip(['daily_used_tokens', 'daily_remaining_tokens', 'daily_reset_at'])),
    'kota_sonra' => array_intersect_key($kotaSonra, array_flip(['daily_used_tokens', 'daily_remaining_tokens', 'daily_reset_at'])),
    'dusunme_env' => EvrenProvider::dusunmeAyari(),
]);
file_put_contents($klasor . '/summary.json', json_encode($ozet, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
file_put_contents($klasor . '/rapor.md', raporYaz($ozet));

echo "\n" . raporYaz($ozet) . "\nSonuçlar: " . str_replace(CY_BASE . '/', '', $klasor) . "\n";

/* =====================================================================
 *  FIXTURE
 * ================================================================== */

function fiksturUret(): void
{
    $db = Database::connection();
    @mkdir(OLCUM_KOK . '/fixtures', 0775, true);

    foreach (FIKSTURLER as $kaynakId => [$ad, $boy]) {
        $kaynak = $db->query('SELECT id, baslik, kanal_adi, sure_sn FROM transkript_kaynaklar WHERE id = ' . (int) $kaynakId)->fetch(PDO::FETCH_ASSOC);
        $metin  = $db->query('SELECT id, dil, saglayici FROM transkript_metinler WHERE kaynak_id = ' . (int) $kaynakId . ' ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);

        if (!is_array($kaynak) || !is_array($metin)) {
            echo "  atlandı: kaynak $kaynakId (transcript yok)\n";

            continue;
        }

        $segmentler = $db->query('SELECT baslangic_ms, bitis_ms, metin FROM transkript_segmentler WHERE metin_id = ' . (int) $metin['id'] . ' ORDER BY baslangic_ms')->fetchAll(PDO::FETCH_NUM);
        $nesneler   = array_map(static fn (array $s): Segment => new Segment((int) $s[0], (int) $s[1], (string) $s[2]), $segmentler);
        $karakter   = array_sum(array_map(static fn (array $s): int => mb_strlen((string) $s[2]), $segmentler));
        $parcalar   = Chunker::fromAyarlar()->parcala(Cleaner::fromAyarlar()->hazirla($nesneler));

        $fikstur = [
            'ad'            => $ad,
            'boy'           => $boy,
            'kaynak_id'     => (int) $kaynakId,
            'baslik'        => (string) $kaynak['baslik'],
            'kanal'         => (string) ($kaynak['kanal_adi'] ?? ''),
            'dil'           => (string) $metin['dil'],
            'altyazi'       => (string) $metin['saglayici'],
            'sure_sn'       => (int) $kaynak['sure_sn'],
            'karakter'      => $karakter,
            'tahmini_token' => (int) ceil($karakter / 3.5),
            'bolum_sayisi'  => count($parcalar),
            'segmentler'    => array_map(static fn (array $s): array => [(int) $s[0], (int) $s[1], (string) $s[2]], $segmentler),
        ];

        file_put_contents(OLCUM_KOK . '/fixtures/' . $ad . '.json', json_encode($fikstur, JSON_UNESCAPED_UNICODE));
        echo sprintf("  %-26s %-8s %s %5d sn %6d karakter ~%5d token %d bölüm\n", $ad, $boy, $fikstur['dil'], $fikstur['sure_sn'], $karakter, $fikstur['tahmini_token'], count($parcalar));
    }
}

/** @return array<string, array<string,mixed>> ad => fixture */
function fiksturleriOku(): array
{
    $sonuc = [];

    foreach (glob(OLCUM_KOK . '/fixtures/*.json') ?: [] as $dosya) {
        $f = json_decode((string) file_get_contents($dosya), true);

        if (is_array($f) && isset($f['ad'], $f['segmentler'])) {
            $sonuc[$f['ad']] = $f;
        }
    }

    uasort($sonuc, static fn (array $a, array $b): int => $a['sure_sn'] <=> $b['sure_sn']);

    return $sonuc;
}

/* =====================================================================
 *  PLAN
 * ================================================================== */

/**
 * @param  list<string>                       $suitler
 * @param  array<string,mixed>                $ayar
 * @param  array<string, array<string,mixed>> $fiksturler
 * @return list<array<string,mixed>>
 */
function gorevleriPlanla(array $suitler, array $ayar, array $fiksturler): array
{
    $g = [];
    $boyla = static fn (array $boylar): array => array_values(array_filter($fiksturler, static fn (array $f): bool => in_array($f['boy'], $boylar, true)));
    $kisa  = $boyla(['kisa']);
    $orta  = $boyla(['orta']);
    $gorev = static fn (string $suite, string $tur, string $model, ?array $f, array $ek = []): array => $ek + [
        'suite' => $suite, 'tur' => $tur, 'model' => $model, 'fikstur' => $f['ad'] ?? null, 'akis' => $ayar['akis'], 'dusunme' => $ayar['dusunme'], 'sema' => $ayar['sema'],
        'max_token' => null, 'hedef_token' => null, 'tekrar' => 1, 'tahmin' => tahmin($tur, $f),
    ];

    foreach ($suitler as $suite) {
        switch ($suite) {
            case 'smoke':
                foreach ($ayar['modeller'] as $m) {
                    $g[] = $gorev('smoke', 'merhaba', $m, null);
                }
                break;

            case 'models':
            case 'json':
                if ($suite === 'json' && in_array('models', $suitler, true)) {
                    break; // models'in çağrıları JSON için de değerlendirilir
                }
                foreach ($ayar['modeller'] as $m) {
                    foreach ($boyla($ayar['boylar']) as $f) {
                        $g[] = $gorev($suite, 'pipeline', $m, $f);
                    }
                    if (in_array($m, $ayar['uzun_modeller'], true) && $suite === 'models') {
                        foreach ($boyla(array_diff($ayar['uzun_boylar'], $ayar['boylar'])) as $f) {
                            $g[] = $gorev($suite, 'pipeline', $m, $f);
                        }
                    }
                }
                break;

            case 'budget':
                foreach (array_intersect(DUSUNEN_MODELLER, $ayar['modeller']) as $m) {
                    foreach (['default', 'low'] as $d) {
                        foreach ([2048, 4096, 8192, 16384] as $mt) {
                            $g[] = $gorev('budget', 'map1', $m, $orta[0] ?? null, ['dusunme' => $d, 'max_token' => $mt]);
                        }
                    }
                }
                break;

            case 'stream':
                foreach (array_intersect(['glm-5.3', 'deepseek-v4.1-flash'], $ayar['modeller']) as $m) {
                    foreach ([true, false] as $a) {
                        for ($i = 0; $i < 2; $i++) {
                            $g[] = $gorev('stream', 'map1', $m, $orta[1] ?? $orta[0] ?? null, ['akis' => $a]);
                        }
                    }
                }
                break;

            case 'context':
                $uzun = $boyla(['uzun'])[0] ?? null;
                $model = in_array('deepseek-v4.1-flash', $ayar['modeller'], true) ? 'deepseek-v4.1-flash' : ($ayar['modeller'][0] ?? 'glm-5.3');
                foreach ([2000, 4000, 8000, 16000] as $h) {
                    $g[] = $gorev('context', 'pipeline', $model, $uzun, ['hedef_token' => $h]);
                }
                break;

            case 'consistency':
                foreach (array_unique(array_merge(['auto'], array_slice(array_diff($ayar['modeller'], ['auto']), 0, 1))) as $m) {
                    foreach (array_slice($kisa, 0, 2) as $f) {
                        for ($i = 1; $i <= $ayar['tekrar']; $i++) {
                            $g[] = $gorev('consistency', 'map1', $m, $f, ['tekrar' => $i]);
                        }
                    }
                }
                break;

            case 'fallback':
                $g[] = $gorev('fallback', 'senaryo', 'sahte', null, ['tahmin' => 0]);
                break;
        }
    }

    return $g;
}

/** Görevin yaklaşık token harcaması (giriş + çıkış). */
function tahmin(string $tur, ?array $f): int
{
    $istem = 2200;   // sistem + kurallar
    $cikti = 2500;   // düşünme + JSON

    return match ($tur) {
        'merhaba'  => 300,
        'map1'     => $istem + min((int) ($f['tahmini_token'] ?? 1500), 3000) + $cikti,
        'pipeline' => (int) ($f['bolum_sayisi'] ?? 1) * ($istem + $cikti) + (int) ($f['tahmini_token'] ?? 1500)
                      + 2500 + 1200 * (int) ($f['bolum_sayisi'] ?? 1) + 4000,
        default    => 0,
    };
}

/* =====================================================================
 *  ÇALIŞTIRMA (alt süreç)
 * ================================================================== */

function cocukCalistir(string $klasor, string $parca): int
{
    [$sira, $toplam] = array_map('intval', explode('/', $parca)) + [1, 1];
    $gorevler = json_decode((string) file_get_contents($klasor . '/gorevler.json'), true) ?: [];
    $benim    = array_values(array_filter($gorevler, static fn ($g, int $i): bool => $i % max(1, $toplam) === $sira - 1, ARRAY_FILTER_USE_BOTH));
    $dosya    = $klasor . '/results-' . $sira . '.jsonl';
    $butce    = (int) ($GLOBALS['argv'] ? (preg_match('/--max-tokens-budget=(\d+)/', implode(' ', $GLOBALS['argv']), $m) ? $m[1] : 750000) : 750000);
    $harcanan = 0;
    $fiksturler = fiksturleriOku();

    $yaz = static function (array $kayit) use ($dosya, &$harcanan): void {
        $harcanan += (int) $kayit['prompt_tokens'] + (int) $kayit['completion_tokens'];
        file_put_contents($dosya, json_encode($kayit, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    };

    foreach ($benim as $g) {
        if ($harcanan >= $butce) {
            echo date('H:i:s') . " bütçe doldu ($harcanan ≥ $butce), kalan görevler atlandı\n";
            break;
        }

        $f = $g['fikstur'] !== null ? ($fiksturler[$g['fikstur']] ?? null) : null;
        echo date('H:i:s') . " {$g['suite']} {$g['model']} " . ($g['fikstur'] ?? '-') . " {$g['tur']}\n";

        try {
            gorevCalistir($g, $f, $yaz, static fn (): bool => $harcanan < $butce);
        } catch (Durdur $e) {
            echo date('H:i:s') . ' DURDU: ' . $e->getMessage() . "\n";
            break;
        } catch (Throwable $e) {
            echo date('H:i:s') . ' hata: ' . Ai::maskele($e->getMessage()) . "\n";
        }
    }

    return 0;
}

/**
 * @param array<string,mixed>       $g
 * @param array<string,mixed>|null  $f
 * @param Closure(array<string,mixed>): void $yaz
 */
function gorevCalistir(array $g, ?array $f, Closure $yaz, Closure $butceVar): void
{
    $sinirMap    = (int) ($g['max_token'] ?? 8192);
    $sinirReduce = (int) ($g['max_token'] ?? 24000);
    $olcumAyari  = array_filter(['akis' => $g['akis'], 'dusunme' => $g['dusunme'], 'sema' => $g['sema'] ?? null], static fn ($v): bool => $v !== null);
    $ortak       = ['suite' => $g['suite'], 'fikstur' => $g['fikstur'], 'tur' => $g['tur'], 'tekrar' => $g['tekrar'], 'hedef_token' => $g['hedef_token'],
                    'dusunme' => $g['dusunme'] ?? EvrenProvider::dusunmeAyari(), 'sema' => $g['sema'] ?? true];
    $saglayici   = static function (int $sinir, int $sure) use ($g, $olcumAyari, $ortak, $yaz, $butceVar): Kaydedici {
        $p = Ai::make('evren', $g['model'], $sinir, $sure);

        if (!$p instanceof EvrenProvider) {
            throw new RuntimeException('EVREN sağlayıcısı kurulamadı.');
        }

        // Üretimde EVREN zincirin ilk halkasıdır: zaman aşımında aynı istek yeniden denenmez (YedekliSaglayici).
        return new Kaydedici($p->olcumAyari($olcumAyari)->yenidenDenemeSiniri(0), $ortak, $yaz, $butceVar);
    };

    if ($g['tur'] === 'merhaba') {
        $k = $saglayici(2048, 120);
        $k->asama = 'smoke';
        $k->send([['role' => 'user', 'content' => 'Merhaba! Tek cümleyle, Türkçe olarak kendini tanıt.']]);

        return;
    }

    if ($f === null) {
        throw new RuntimeException('Görevin fixture\'ı yok.');
    }

    $map    = $saglayici($sinirMap, 120);
    $reduce = $saglayici($sinirReduce, 240);
    $aktif  = $map;
    $pipeline = new Pipeline(new AiKullanimRepository(new OlcumPdo()), 0, new ResultValidator(),
        static function (string $asama) use (&$aktif): array { return [$aktif, 'evren']; });

    $segmentler = array_map(static fn (array $s): Segment => new Segment((int) $s[0], (int) $s[1], (string) $s[2]), $f['segmentler']);
    $baslangiclar = array_map(static fn (Segment $s): int => $s->baslangicMs, $segmentler);
    $metin      = implode(' ', array_map(static fn (Segment $s): string => $s->metin, $segmentler));
    $chunker    = $g['hedef_token'] ? hedefliChunker((int) $g['hedef_token'], $f) : Chunker::fromAyarlar();
    $parcalar   = $chunker->parcala(Cleaner::fromAyarlar()->hazirla($segmentler));
    $baglam     = ['baslik' => $f['baslik'], 'kanal' => $f['kanal'], 'dil' => $f['dil'], 'toplam' => count($parcalar)];
    $bas        = microtime(true);
    $sonuclar   = [];

    foreach ($parcalar as $p) {
        $map->parca = $p->sira;
        $map->asama = 'bolum';
        $dilim = array_values(array_filter($segmentler, static fn (Segment $s): bool => $s->bitisMs >= $p->baslangicMs && $s->baslangicMs <= $p->bitisMs));

        try {
            $sonuc = $pipeline->parcaAnalizEt(['sira' => $p->sira, 'baslangic_ms' => $p->baslangicMs, 'bitis_ms' => $p->bitisMs],
                Cleaner::fromAyarlar()->hazirla($dilim), array_map(static fn (Segment $s): int => $s->baslangicMs, $dilim), $baglam);
            $sonuclar[] = $sonuc['sonuc'];
            ($yaz)(['suite' => $g['suite'], 'tur' => 'kalite', 'asama' => 'bolum', 'model' => $g['model'], 'fikstur' => $f['ad'], 'parca' => $p->sira,
                    'hedef_token' => $g['hedef_token'], 'tekrar' => $g['tekrar'], 'prompt_tokens' => 0, 'completion_tokens' => 0,
                    'kalite' => kalite($sonuc['sonuc'], $metin, 'bolum'), 'uyarilar' => $sonuc['uyarilar'], 'cozulen_model' => $sonuc['model'],
                    'maddeler' => maddeKokleri($sonuc['sonuc'])]);
        } catch (Durdur $e) {
            throw $e;
        } catch (Throwable $e) {
            ($yaz)(['suite' => $g['suite'], 'tur' => 'kalite', 'asama' => 'bolum', 'model' => $g['model'], 'fikstur' => $f['ad'], 'parca' => $p->sira,
                    'hedef_token' => $g['hedef_token'], 'tekrar' => $g['tekrar'], 'prompt_tokens' => 0, 'completion_tokens' => 0,
                    'kalite' => ['puan' => 0.0, 'hata' => $e instanceof AiHatasi ? $e->kod : 'hata'], 'cozulen_model' => '']);
        }

        if ($g['tur'] === 'map1') {
            return;
        }
    }

    if ($sonuclar === []) {
        return;
    }

    $aktif = $reduce;
    $reduce->asama = 'rapor';
    $bitMs = max(array_map(static fn ($p): int => $p->bitisMs, $parcalar));

    try {
        $r = $pipeline->birlestirKademeli($sonuclar, $bitMs, $baslangiclar, $baglam + ['eksik' => [], 'kelime' => Pipeline::kelimeSayisi($metin)],
            static fn (): int => GirisSiniri::sinir('evren', $g['model']) ?: PHP_INT_MAX);
        ($yaz)(['suite' => $g['suite'], 'tur' => 'kalite', 'asama' => 'rapor', 'model' => $g['model'], 'fikstur' => $f['ad'], 'parca' => 0,
                'hedef_token' => $g['hedef_token'], 'tekrar' => $g['tekrar'], 'prompt_tokens' => 0, 'completion_tokens' => 0,
                'kalite' => kalite($r['sonuc'], $metin, 'rapor'), 'uyarilar' => $r['uyarilar'], 'cozulen_model' => $r['model'], 'kademeli' => $r['kademeli'],
                'uctan_uca_ms' => (int) round((microtime(true) - $bas) * 1000), 'bolum' => count($parcalar)]);
    } catch (Durdur $e) {
        throw $e;
    } catch (Throwable $e) {
        ($yaz)(['suite' => $g['suite'], 'tur' => 'kalite', 'asama' => 'rapor', 'model' => $g['model'], 'fikstur' => $f['ad'], 'parca' => 0,
                'hedef_token' => $g['hedef_token'], 'tekrar' => $g['tekrar'], 'prompt_tokens' => 0, 'completion_tokens' => 0,
                'kalite' => ['puan' => 0.0, 'hata' => $e instanceof AiHatasi ? $e->kod : 'hata'], 'cozulen_model' => '',
                'uctan_uca_ms' => (int) round((microtime(true) - $bas) * 1000), 'bolum' => count($parcalar)]);
    }
}

/** Bölüm boyutu deneyi: hedef token'a göre süre penceresi (fixture'ın konuşma hızıyla). */
function hedefliChunker(int $hedef, array $f): Chunker
{
    $tokenDk = max(50.0, ((int) $f['tahmini_token']) / max(1, (int) $f['sure_sn'] / 60));
    $dk      = $hedef / $tokenDk;

    return new Chunker((int) ($dk * 0.8 * 60000), (int) ($dk * 60000), $hedef, 30000, 3.5);
}

/* =====================================================================
 *  KALİTE (kural tabanlı, 0–10)
 * ================================================================== */

/**
 * @param  array<string,mixed> $s doğrulanmış bölüm ya da rapor sonucu
 * @return array<string,mixed>
 */
function kalite(array $s, string $transcript, string $tur): array
{
    $maddeler = [];

    foreach (['important_points' => 'text', 'technical_information' => 'name', 'claims' => 'text', 'ideas' => 'text'] as $alan => $metinAlani) {
        foreach ((array) ($s[$alan] ?? []) as $m) {
            if (is_array($m)) {
                $maddeler[] = ['metin' => (string) ($m[$metinAlani] ?? '') . ' ' . (string) ($m['description'] ?? ''), 'at' => $m['at'] ?? null];
            }
        }
    }

    $metin = trim((string) ($s['summary'] ?? '') . ' ' . (string) ($s['detailed_summary'] ?? '') . ' ' . implode(' ', array_column($maddeler, 'metin')));
    $puan  = ['sema' => 3.0];

    // Türkçe (2)
    $tr = turkceOrani($metin);
    $puan['turkce'] = round(2 * min(1.0, max(0.0, ($tr - 0.5) * 2)), 2);

    // Alanlar (1)
    $alanlar = (int) (trim((string) ($s['summary'] ?? '')) !== '') + (int) (count((array) ($s['important_points'] ?? [])) > 0)
        + ($tur === 'rapor' ? (int) (count((array) ($s['topics'] ?? [])) > 0) : (int) (trim((string) ($s['topic'] ?? '')) !== ''));
    $puan['alanlar'] = round($alanlar / 3, 2);

    // Zaman damgası (1,5)
    $zamanli = count(array_filter($maddeler, static fn (array $m): bool => is_string($m['at']) && $m['at'] !== ''));
    $zamanOrani = $maddeler === [] ? 0.0 : $zamanli / count($maddeler);
    $puan['zaman'] = round(1.5 * $zamanOrani, 2);

    // Uzunluk (0,5): özet 60–2500 karakter
    $ozetUzunluk = mb_strlen((string) ($s['summary'] ?? ''));
    $puan['uzunluk'] = $ozetUzunluk >= 60 && $ozetUzunluk <= 2500 ? 0.5 : 0.0;

    // Sızıntı (0,5): düşünme metni / İngilizce akıl yürütme
    $sizinti = (bool) preg_match('/<\/?think>|reasoning|let me|okay,? (so|let)|first,? i (need|will)/i', $metin);
    $puan['sizinti'] = $sizinti ? 0.0 : 0.5;

    // Tekrar (0,5)
    $normal = array_map(static fn (array $m): string => Search::trFold(trim($m['metin'])), $maddeler);
    $tekrar = count($normal) > 0 ? 1 - count(array_unique($normal)) / count($normal) : 0.0;
    $puan['tekrar'] = round(0.5 * (1 - $tekrar), 2);

    // Uydurma (1): rapordaki sayılar ve özel adlar transcript'te geçiyor mu
    $kaynak = Search::trFold($transcript);
    preg_match_all('/\b\d+(?:[.,]\d+)?\b/u', $metin, $sayilar);
    preg_match_all('/(?<=[a-zçğıöşü,;:] )\p{Lu}[\p{L}]{3,}/u', $metin, $adlar);
    $adaylar = array_unique(array_merge($sayilar[0], $adlar[0]));
    $gecen   = count(array_filter($adaylar, static fn (string $a): bool => str_contains($kaynak, Search::trFold($a))));
    $uydurmaOrani = $adaylar === [] ? 1.0 : $gecen / count($adaylar);
    $puan['uydurma'] = round(1.0 * $uydurmaOrani, 2);

    return [
        'puan'         => round(array_sum($puan), 2),
        'parcalar'     => $puan,
        'turkce_orani' => round($tr, 2),
        'zaman_orani'  => round($zamanOrani, 2),
        'madde'        => count($maddeler),
        'kaynakta_gecen' => round($uydurmaOrani, 2),
    ];
}

/** Türkçe oranı (0–1): Türkçe dolgu sözcükleri / (Türkçe + İngilizce). */
function turkceOrani(string $metin): float
{
    $sozcukler = preg_split('/[^\p{L}]+/u', mb_strtolower($metin)) ?: [];
    $tr = count(array_intersect($sozcukler, ['ve', 'bir', 'bu', 'için', 'ile', 'da', 'de', 'olarak', 'olan', 'çok', 'daha', 'gibi', 'en', 'ise', 'veya']));
    $en = count(array_intersect($sozcukler, ['the', 'and', 'of', 'to', 'is', 'in', 'that', 'for', 'with', 'are', 'this', 'it', 'as', 'be']));

    return $tr + $en === 0 ? 0.0 : $tr / ($tr + $en);
}

function turkceMi(string $metin): ?bool
{
    $metin = trim($metin);

    return $metin === '' ? null : turkceOrani($metin) >= 0.7;
}

/** Tutarlılık için: önemli noktaların kök kümesi. @return list<string> */
function maddeKokleri(array $s): array
{
    $kokler = [];

    foreach ((array) ($s['important_points'] ?? []) as $m) {
        $kokler = array_merge($kokler, array_keys(\Modules\Transkript\Services\ZamanYedegi::kokler((string) ($m['text'] ?? ''))));
    }

    return array_values(array_unique($kokler));
}

/* =====================================================================
 *  FALLBACK: sahte HTTP ile zincir senaryoları (ağ yok)
 * ================================================================== */

function yedekSenaryolari(string $dosya): void
{
    $depo = sys_get_temp_dir() . '/evren-olcum-dinlenme-' . getmypid() . '.json';
    DinlenmeDeposu::kullan($depo);
    $yanit = static fn (string $icerik, string $bitis = 'stop', string $dusunce = ''): string => (string) json_encode([
        'model' => 'zai/glm-5.3-fp8', 'choices' => [['message' => ['content' => $icerik, 'reasoning_content' => $dusunce], 'finish_reason' => $bitis]],
        'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50],
    ]);
    $senaryolar = [
        '403 terms_not_accepted' => [[403, '{"error":{"code":"terms_not_accepted","message":"x","evren":{"required_terms_version":2}}}']],
        '429 günlük kota'        => [[429, '{"error":{"message":"kota"}}', ['x-evren-daily-remaining-tokens' => '0']]],
        '429 dakikalık'          => [[429, '{"error":{"message":"tpm"}}', ['x-evren-daily-remaining-tokens' => '9000000', 'retry-after' => '20']]],
        '503 doluluk'            => [[503, '{"error":{"message":"dolu"}}']],
        '400 endpoint_mismatch'  => [[400, '{"error":{"code":"endpoint_mismatch","message":"model bu uçta yok"}}'], [400, '{"error":{"code":"endpoint_mismatch"}}'], [400, '{"error":{"code":"endpoint_mismatch"}}']],
        'zaman aşımı (boşta)'    => 'bosta',
        'boş içerik + düşünme (length)' => [[200, $yanit('', 'length', 'uzun düşünce')], [200, $yanit('', 'length', 'yine')]],
        'length, ×2 bütçeyle düzelir'  => [[200, $yanit('', 'length', 'uzun düşünce')], [200, $yanit('{"tamam":1}')]],
    ];

    foreach ($senaryolar as $ad => $yanitlar) {
        $evren = (new EvrenProvider('evren_llm_sahte0000', 'glm-5.3'))->olcumAyari(['akis' => false]);
        $evren->tasiyici($yanitlar === 'bosta'
            ? static fn (string $g): array => [0, '', [], 'Boşta kalma: 60 sn boyunca yanıt verisi gelmedi', 28]
            : static function (string $g) use (&$yanitlar): array {
                [$d, $y, $b] = (array_shift($yanitlar) ?? [500, '{}']) + [2 => []];

                return [$d, $y, $b, '', 0];
            });
        $groq = (new OpenAiCompatibleProvider('k', 'openai/gpt-oss-120b'))->tasiyici(static fn (string $g): array => [200, $yanit('{"yedek":1}'), [], '', 0]);
        $zincir = new YedekliSaglayici([['ad' => 'olcum-evren', 'saglayici' => $evren], ['ad' => 'olcum-groq', 'saglayici' => $groq]]);
        $bas = microtime(true);

        try {
            $y = $zincir->sendJson([['role' => 'user', 'content' => 'x']], 'JSON', ['type' => 'object']);
            $sonuc = ['yanitlayan' => $zincir->sonAd(), 'metin' => $y['text']];
        } catch (AiHatasi $e) {
            $sonuc = ['hata' => $e->kod];
        }

        $kayit = ['suite' => 'fallback', 'tur' => 'senaryo', 'senaryo' => $ad, 'prompt_tokens' => 0, 'completion_tokens' => 0,
            'denemeler' => array_map(static fn (array $d): string => $d['ad'] . ':' . $d['kod'], $zincir->denemeler()),
            'evren_dinlenme_sn' => YedekliSaglayici::dinlenmeKalan('olcum-evren'), 'toplam_ms' => (int) round((microtime(true) - $bas) * 1000)] + $sonuc;
        file_put_contents($dosya, json_encode($kayit, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);

        foreach (['olcum-evren', 'olcum-groq'] as $a) {
            YedekliSaglayici::dinlenmeyiBitir($a);
            GirisSiniri::unut($a);
        }

        EvrenProvider::dusunmeReddiniUnut();
    }

    DinlenmeDeposu::kullan(null);
    @unlink($depo);
}

/* =====================================================================
 *  ÖZET VE RAPOR
 * ================================================================== */

/**
 * @param  list<array<string,mixed>> $k
 * @param  array<string,mixed>       $kunye
 * @return array<string,mixed>
 */
function ozetle(array $k, array $kunye): array
{
    $cagri  = array_values(array_filter($k, static fn (array $r): bool => !in_array($r['tur'] ?? '', ['kalite', 'senaryo'], true)));
    $kalite = array_values(array_filter($k, static fn (array $r): bool => ($r['tur'] ?? '') === 'kalite'));
    $grupla = static function (array $satirlar, Closure $anahtar): array {
        $g = [];
        foreach ($satirlar as $s) {
            $g[$anahtar($s)][] = $s;
        }
        ksort($g);

        return $g;
    };
    $istatistik = static function (array $s): array {
        $sureler = array_map(static fn (array $r): int => (int) $r['toplam_ms'], $s);
        sort($sureler);
        $basarili = array_filter($s, static fn (array $r): bool => ($r['hata_kodu'] ?? null) === null);
        $ttft = array_values(array_filter(array_map(static fn (array $r) => $r['ttft_ms'] ?? null, $s), 'is_int'));
        $hiz  = array_values(array_filter(array_map(static fn (array $r) => $r['tokens_per_sec'] ?? null, $s), 'is_numeric'));
        $giris = array_sum(array_column($s, 'prompt_tokens'));
        $onbellek = array_sum(array_map(static fn (array $r): int => (int) ($r['cached_tokens'] ?? 0), $s));

        return [
            'cagri'      => count($s),
            'basari'     => count($s) ? round(count($basarili) / count($s), 3) : 0,
            'ort_ms'     => count($s) ? (int) (array_sum($sureler) / count($s)) : 0,
            'p95_ms'     => $sureler ? $sureler[(int) floor(0.95 * (count($sureler) - 1))] : 0,
            'ttft_ms'    => $ttft ? (int) (array_sum($ttft) / count($ttft)) : null,
            'token_sn'   => $hiz ? round(array_sum($hiz) / count($hiz), 1) : null,
            'ort_token'  => count($s) ? (int) ((array_sum(array_column($s, 'prompt_tokens')) + array_sum(array_column($s, 'completion_tokens'))) / count($s)) : 0,
            'json'       => count($basarili) ? round(count(array_filter($basarili, static fn (array $r): bool => (bool) $r['json_gecerli'])) / count($basarili), 3) : null,
            'citli'      => count(array_filter($s, static fn (array $r): bool => (bool) ($r['json_citli'] ?? false))),
            'zaman_asimi' => count(array_filter($s, static fn (array $r): bool => ($r['hata_kodu'] ?? null) === 'zaman_asimi')),
            'butce'      => count(array_filter($s, static fn (array $r): bool => ($r['hata_kodu'] ?? null) === 'dusunme_butcesi')),
            'hatalar'    => array_count_values(array_filter(array_map(static fn (array $r) => $r['hata_kodu'] ?? null, $s), 'is_string')),
            'cozulen'    => array_count_values(array_filter(array_map(static fn (array $r): string => (string) ($r['cozulen_model'] ?? ''), $s))),
            'bitis'      => array_count_values(array_filter(array_map(static fn (array $r) => $r['finish_reason'] ?? null, $s), 'is_string')),
            'dusunme_orani' => (static function () use ($s): ?float {
                $d = array_sum(array_map(static fn (array $r): int => (int) ($r['reasoning_tokens'] ?? 0), $s));
                $c = array_sum(array_column($s, 'completion_tokens'));

                return $c > 0 ? round($d / $c, 2) : null;
            })(),
            'onbellek_orani' => $giris > 0 ? round($onbellek / $giris, 3) : null,
            'keepalive'  => count($s) ? round(array_sum(array_column($s, 'keepalive')) / count($s), 1) : 0,
            'toplam_token' => array_sum(array_column($s, 'prompt_tokens')) + array_sum(array_column($s, 'completion_tokens')),
        ];
    };
    $kaliteOrt = static function (array $s): ?float {
        $p = array_map(static fn (array $r): float => (float) ($r['kalite']['puan'] ?? 0), $s);

        return $p ? round(array_sum($p) / count($p), 2) : null;
    };

    $ozet = $kunye + ['cagri_sayisi' => count($cagri), 'toplam_token' => array_sum(array_column($cagri, 'prompt_tokens')) + array_sum(array_column($cagri, 'completion_tokens'))];

    foreach ($grupla($cagri, static fn (array $r): string => $r['suite']) as $suite => $satirlar) {
        $ozet['suitler_ozet'][$suite]['genel'] = $istatistik($satirlar);
        $anahtar = match ($suite) {
            'budget'      => static fn (array $r): string => $r['istenen_model'] . ' · ' . $r['dusunme'] . ' · ' . ($r['max_tokens'] ?? '?'),
            'stream'      => static fn (array $r): string => $r['istenen_model'] . ' · ' . ($r['akis'] ? 'akış' : 'akışsız'),
            'context'     => static fn (array $r): string => $r['istenen_model'] . ' · hedef ' . $r['hedef_token'] . ' · ' . $r['asama'],
            'consistency' => static fn (array $r): string => $r['istenen_model'] . ' · ' . $r['fikstur'],
            default       => static fn (array $r): string => $r['istenen_model'] . ' · ' . $r['asama'],
        };

        foreach ($grupla($satirlar, $anahtar) as $ad => $s) {
            $ozet['suitler_ozet'][$suite]['gruplar'][$ad] = $istatistik($s);
        }
    }

    foreach ($grupla($kalite, static fn (array $r): string => $r['suite']) as $suite => $satirlar) {
        foreach ($grupla($satirlar, static fn (array $r): string => $r['model'] . ' · ' . $r['asama'] . ($r['hedef_token'] ? ' · hedef ' . $r['hedef_token'] : '')) as $ad => $s) {
            $ozet['kalite'][$suite][$ad] = [
                'n'         => count($s),
                'puan'      => $kaliteOrt($s),
                'zaman_orani' => round(array_sum(array_map(static fn (array $r): float => (float) ($r['kalite']['zaman_orani'] ?? 0), $s)) / count($s), 2),
                'turkce_orani' => round(array_sum(array_map(static fn (array $r): float => (float) ($r['kalite']['turkce_orani'] ?? 0), $s)) / count($s), 2),
                'hata'      => count(array_filter($s, static fn (array $r): bool => isset($r['kalite']['hata']))),
                'uctan_uca_ms' => ($u = array_filter(array_map(static fn (array $r) => $r['uctan_uca_ms'] ?? null, $s), 'is_int')) ? (int) (array_sum($u) / count($u)) : null,
                'kademeli'  => count(array_filter($s, static fn (array $r): bool => (bool) ($r['kademeli'] ?? false))),
            ];
        }

        // Fixture başına uçtan uca (models): model × fixture
        foreach ($satirlar as $r) {
            if (($r['asama'] ?? '') === 'rapor') {
                $ozet['uctan_uca'][$suite][$r['model']][$r['fikstur'] . ($r['hedef_token'] ? ' @' . $r['hedef_token'] : '')] = [
                    'ms' => $r['uctan_uca_ms'] ?? null, 'puan' => $r['kalite']['puan'] ?? null, 'bolum' => $r['bolum'] ?? null, 'hata' => $r['kalite']['hata'] ?? null,
                ];
            }
        }
    }

    // Tutarlılık: tekrarlar arası önemli nokta kök benzerliği (Jaccard ortalaması)
    $tutarlilik = array_filter($kalite, static fn (array $r): bool => $r['suite'] === 'consistency' && isset($r['maddeler']));

    foreach ($grupla($tutarlilik, static fn (array $r): string => $r['model'] . ' · ' . $r['fikstur']) as $ad => $s) {
        $benzerlik = [];

        for ($i = 0; $i < count($s); $i++) {
            for ($j = $i + 1; $j < count($s); $j++) {
                $a = $s[$i]['maddeler'];
                $b = $s[$j]['maddeler'];
                $birlesim = count(array_unique(array_merge($a, $b)));
                $benzerlik[] = $birlesim > 0 ? count(array_intersect($a, $b)) / $birlesim : 0;
            }
        }

        $ozet['tutarlilik'][$ad] = [
            'tekrar'   => count($s),
            'jaccard'  => $benzerlik ? round(array_sum($benzerlik) / count($benzerlik), 2) : null,
            'modeller' => array_count_values(array_map(static fn (array $r): string => (string) $r['cozulen_model'], $s)),
        ];
    }

    $ozet['fallback'] = array_values(array_filter($k, static fn (array $r): bool => ($r['tur'] ?? '') === 'senaryo'));

    return $ozet;
}

/** @param array<string,mixed> $o */
function raporYaz(array $o): string
{
    $s = '# EVREN ölçümü — ' . ($o['baslangic'] ?? '') . "\n\n"
        . sprintf("Süre %d sn · %d çağrı · %s token · düşünme (.env) %s\n", $o['sure_sn'] ?? 0, $o['cagri_sayisi'] ?? 0, sayi((int) ($o['toplam_token'] ?? 0)), $o['dusunme_env'] ?? '?')
        . sprintf("Kota: önce %s kalan, sonra %s kalan\n\n",
            sayi((int) ($o['kota_once']['daily_remaining_tokens'] ?? 0)), sayi((int) ($o['kota_sonra']['daily_remaining_tokens'] ?? 0)));

    foreach ((array) ($o['suitler_ozet'] ?? []) as $suite => $v) {
        $s .= "## $suite\n\n| grup | çağrı | başarı | ort. sn | p95 sn | TTFT sn | tok/sn | ort. token | JSON | zaman aşımı | bütçe | düşünme oranı | önbellek | çözülen |\n|---|---|---|---|---|---|---|---|---|---|---|---|---|---|\n";

        foreach ((array) ($v['gruplar'] ?? []) as $ad => $g) {
            $s .= sprintf("| %s | %d | %s | %.1f | %.1f | %s | %s | %s | %s | %d | %d | %s | %s | %s |\n", $ad, $g['cagri'], yuzde($g['basari']),
                $g['ort_ms'] / 1000, $g['p95_ms'] / 1000, $g['ttft_ms'] !== null ? number_format($g['ttft_ms'] / 1000, 1) : '—',
                $g['token_sn'] ?? '—', sayi($g['ort_token']), $g['json'] !== null ? yuzde($g['json']) : '—', $g['zaman_asimi'], $g['butce'],
                $g['dusunme_orani'] ?? '—', $g['onbellek_orani'] !== null ? yuzde($g['onbellek_orani']) : '—',
                implode(', ', array_map(static fn ($m, $n): string => $m . '×' . $n, array_keys($g['cozulen']), $g['cozulen'])));
        }

        $s .= "\n";
    }

    foreach ((array) ($o['kalite'] ?? []) as $suite => $gruplar) {
        $s .= "### Kalite: $suite\n\n| model · aşama | n | puan (0–10) | zaman damgası | Türkçe | hata | uçtan uca sn | kademeli |\n|---|---|---|---|---|---|---|---|\n";

        foreach ($gruplar as $ad => $g) {
            $s .= sprintf("| %s | %d | %s | %s | %s | %d | %s | %d |\n", $ad, $g['n'], $g['puan'] ?? '—', yuzde($g['zaman_orani']), yuzde($g['turkce_orani']),
                $g['hata'], $g['uctan_uca_ms'] !== null ? number_format($g['uctan_uca_ms'] / 1000, 1) : '—', $g['kademeli']);
        }

        $s .= "\n";
    }

    if (!empty($o['tutarlilik'])) {
        $s .= "### Tutarlılık\n\n| model · fixture | tekrar | Jaccard | çözülen modeller |\n|---|---|---|---|\n";

        foreach ($o['tutarlilik'] as $ad => $t) {
            $s .= sprintf("| %s | %d | %s | %s |\n", $ad, $t['tekrar'], $t['jaccard'] ?? '—',
                implode(', ', array_map(static fn ($m, $n): string => ($m !== '' ? $m : 'hata') . '×' . $n, array_keys($t['modeller']), $t['modeller'])));
        }

        $s .= "\n";
    }

    if (!empty($o['fallback'])) {
        $s .= "### Yedek senaryoları (sahte HTTP)\n\n| senaryo | denemeler | sonuç | EVREN dinlenme sn |\n|---|---|---|---|\n";

        foreach ($o['fallback'] as $f) {
            $s .= sprintf("| %s | %s | %s | %d |\n", $f['senaryo'], implode(' → ', $f['denemeler']), isset($f['hata']) ? 'hata: ' . $f['hata'] : 'yanıt: ' . $f['yanitlayan'], $f['evren_dinlenme_sn']);
        }
    }

    return $s;
}

function sayi(int $n): string
{
    return number_format($n, 0, ',', '.');
}

function yuzde(float|int|null $x): string
{
    return $x === null ? '—' : '%' . number_format(100 * (float) $x, 0);
}
