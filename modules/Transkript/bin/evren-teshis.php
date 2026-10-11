<?php
/**
 * =====================================================================
 *  EVREN teşhisi (komut satırı)
 * ---------------------------------------------------------------------
 *      php modules/Transkript/bin/evren-teshis.php
 *
 *  Sırayla: anahtar (yalnız önek ve uzunluk), giden kimlik başlığı
 *  (maskeli), GET /v1/terms/status, GET /v1/quota, GET /v1/models,
 *  POST /v1/chat/completions ("Merhaba", varsayılan model, max_tokens 2048).
 *  Şartları ONAYLAMAZ (onay: Transkript ayarları → Durum → "Şartları oku
 *  ve onayla"). Anahtarın değeri hiçbir satıra yazılmaz.
 *
 *  Çekirdeğin komut listesi (php cy) sabittir; modül komutu eklemek
 *  çekirdek değişikliği gerektirdiği için betik modülün içindedir.
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

use App\Core\Modules\Modules;
use Modules\Transkript\Ai\EvrenHesap;
use Modules\Transkript\Http\HttpYanit;

try {
    App\Core\Setting::load(App\Core\Database::connection());
} catch (Throwable) {
    // Veritabanı olmadan da çalışır (yalnız .env gerekir).
}

Modules::boot();

$hesap = EvrenHesap::ortamdan();

echo "EVREN teşhisi (" . date('Y-m-d H:i:s') . ")\n\n";

if ($hesap === null) {
    echo "1. EVREN_API_KEY: TANIMSIZ (.env)\n";
    exit(1);
}

$bilgi = $hesap->anahtarBilgisi();
printf("1. EVREN_API_KEY: tanımlı · önek %s · uzunluk %d%s\n", $bilgi['onek'], $bilgi['uzunluk'],
    $bilgi['onek_dogru'] ? '' : '  ← LLM anahtarı "evren_llm_" ile başlamalı');
printf("2. Giden başlık: %s · taban %s\n", $hesap->baslikOrnegi(), $hesap->taban());

$yaz = static function (string $baslik, HttpYanit $y, int $sinir = 600) use ($hesap): void {
    printf("%s → HTTP %d%s\n", $baslik, $y->durum, $y->agHatasi() ? ' (ağ hatası: ' . $y->hata . ')' : '');

    if ($y->govde !== '') {
        echo '   ', $hesap->maskele(mb_strimwidth(preg_replace('/\s+/', ' ', $y->govde) ?? '', 0, $sinir, '…')), "\n";
    }
};

$sart = $hesap->sartDurumu();
$yaz('3. GET /terms/status', $sart);
$sb = EvrenHesap::sartBilgisi($sart->json());
printf("   onaylı: %s · güncel sürüm: %s\n", $sb['onayli'] === null ? 'bilinmiyor' : ($sb['onayli'] ? 'EVET' : 'HAYIR'), $sb['guncel_surum'] ?? 'bilinmiyor');

$yaz('4. GET /quota', $hesap->kota());

$modeller = $hesap->modeller();
printf("5. GET /models → HTTP %d", $modeller->durum);
$onbellek = Modules\Transkript\Ai\EvrenModelleri::yenile($hesap);
$liste = $modeller->json()['data'] ?? null;
echo is_array($liste) ? ' · ' . count($liste) . ' model: ' . implode(', ', array_map(static fn (array $m): string => (string) ($m['id'] ?? '?'), array_filter($liste, 'is_array'))) . "\n" : "\n";
if (!is_array($liste)) {
    echo '   ', $hesap->maskele(mb_strimwidth($modeller->govde, 0, 300, '…')), "\n";
}

$sohbet = $hesap->sohbetDene();
$yaz('6. POST /chat/completions (' . Modules\Transkript\Ai\EvrenProvider::VARSAYILAN_MODEL . ', "Merhaba")', $sohbet, 300);
$cevap = $sohbet->json()['choices'][0]['message']['content'] ?? null;
if (is_string($cevap)) {
    printf("   yanıt: %s · gerçek model: %s\n", $hesap->maskele(trim($cevap)), (string) ($sohbet->json()['model'] ?? '?'));
}

foreach (['x-evren-daily-remaining-tokens', 'x-evren-daily-limit-tokens', 'x-evren-daily-reset', 'x-evren-routed-model'] as $b) {
    if (isset($sohbet->basliklar[$b])) {
        printf("   %s: %s\n", $b, $sohbet->basliklar[$b]);
    }
}
