<?php
/**
 * =====================================================================
 *  EVREN canlı entegrasyon testi (docs/evren) — varsayılan KAPALI
 * ---------------------------------------------------------------------
 *  Gerçek EVREN'e gider; yalnız EVREN_LIVE_TESTS=1 iken çalışır:
 *
 *      PowerShell:  $env:EVREN_LIVE_TESTS=1; php tests/evren-canli.php; Remove-Item Env:EVREN_LIVE_TESTS
 *      bash:        EVREN_LIVE_TESTS=1 php tests/evren-canli.php
 *
 *  Sırayla: şart durumu, kota, model listesi, akışlı (SSE) kısa sohbet
 *  (ilk token, keep-alive, kalan kota), akışsız kısa sohbet, json_schema
 *  (strict) JSON yanıtı. Şartları ONAYLAMAZ. Anahtar hiçbir satıra
 *  yazılmaz. 1 Kasım 2026'ya kadar maliyet 0 (birkaç yüz token).
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

use App\Core\Env;
use Modules\Transkript\Ai\Ai;
use Modules\Transkript\Ai\AiHatasi;
use Modules\Transkript\Ai\EvrenHesap;
use Modules\Transkript\Ai\EvrenProvider;
use Modules\Transkript\Services\ResultValidator;

if (getenv('EVREN_LIVE_TESTS') !== '1' && Env::get('EVREN_LIVE_TESTS') !== '1') {
    echo "EVREN canlı testi atlandı (EVREN_LIVE_TESTS=1 değil).\n";
    exit(0);
}

try {
    App\Core\Setting::load(App\Core\Database::connection());
} catch (Throwable) {
    // Veritabanı olmadan da çalışır.
}

App\Core\Modules\Modules::boot();

$gecti = 0;
$kaldi = 0;
$dogrula = static function (string $ad, bool $sonuc, string $ayrinti = '') use (&$gecti, &$kaldi): void {
    $sonuc ? $gecti++ : $kaldi++;
    echo ($sonuc ? '  ✔ ' : '  ✖ ') . $ad . ($ayrinti !== '' ? ' — ' . Ai::maskele($ayrinti) : '') . "\n";
};

$hesap = EvrenHesap::ortamdan();

if ($hesap === null) {
    echo "  ✖ .env içinde EVREN_API_KEY yok.\n";
    exit(1);
}

echo "EVREN canlı testi\n";

$sart = EvrenHesap::sartBilgisi($hesap->sartDurumu()->json());
$dogrula('Şart durumu okunur ve onaylı', $sart['onayli'] === true, 'sürüm ' . ($sart['guncel_surum'] ?? '?'));

$kota = $hesap->kota()->json() ?? [];
$dogrula('Kota okunur (günlük kalan)', isset($kota['daily_remaining_tokens']), 'kalan ' . number_format((int) ($kota['daily_remaining_tokens'] ?? 0), 0, ',', '.'));

$adet = Modules\Transkript\Ai\EvrenModelleri::yenile($hesap);
$dogrula('Model listesi okunur ve önbelleğe yazılır', ($adet ?? 0) > 0, ($adet ?? 0) . ' model');

$model = $argv[1] ?? 'gemma-4-31b';

foreach ([true, false] as $akis) {
    try {
        $p = Ai::make('evren', $model, 2048, 120);
        $y = $p instanceof EvrenProvider ? $p->olcumAyari(['akis' => $akis])->send([['role' => 'user', 'content' => 'Tek kelimeyle cevap ver: Türkiye\'nin başkenti neresi?']]) : [];
        $o = $y['olcum'] ?? [];
        $dogrula(($akis ? 'Akışlı (SSE)' : 'Akışsız') . ' sohbet yanıt verir; düşünme metni yanıta girmez',
            str_contains(mb_strtolower((string) ($y['text'] ?? '')), 'ankara') && ($y['thinking'] ?? '') === '',
            sprintf('%s · %.1f sn · ilk token %s · keep-alive %d · bitiş %s · kalan kota %s', $y['model'] ?? '?', ($o['toplam_ms'] ?? 0) / 1000,
                isset($o['ilk_token_ms']) ? number_format($o['ilk_token_ms'] / 1000, 1) . ' sn' : '—', (int) ($o['keepalive'] ?? 0), $o['bitis_nedeni'] ?? '?',
                isset($o['kalan_kota']) ? number_format((int) $o['kalan_kota'], 0, ',', '.') : '—'));
    } catch (AiHatasi $e) {
        $dogrula(($akis ? 'Akışlı' : 'Akışsız') . ' sohbet', false, $e->kod . ': ' . $e->getMessage());
    }
}

try {
    $p = Ai::make('evren', $model, 2048, 120);
    $sema = ['type' => 'object', 'properties' => ['sehir' => ['type' => 'string'], 'nufus_milyon' => ['type' => 'number']], 'required' => ['sehir', 'nufus_milyon']];
    $y = $p->sendJson([['role' => 'user', 'content' => 'Türkiye\'nin en kalabalık şehri ve yaklaşık nüfusu (milyon) JSON olarak.']], 'Yalnız JSON döndür.', $sema);
    $j = ResultValidator::ayikla((string) ($y['text'] ?? ''));
    $dogrula('json_schema (strict): şemadaki alanlar, fazlası yok', is_array($j) && array_keys($j) === ['sehir', 'nufus_milyon'], (string) json_encode($j, JSON_UNESCAPED_UNICODE));
} catch (AiHatasi $e) {
    $dogrula('json_schema', false, $e->kod . ': ' . $e->getMessage());
}

echo "\n$gecti geçti · $kaldi kaldı\n";
exit($kaldi > 0 ? 1 : 0);
