<?php
/**
 * =====================================================================
 *  Video kategorisini geri doldurma (komut satırı)
 * ---------------------------------------------------------------------
 *      php modules/Transkript/bin/kategori-doldur.php --dry-run
 *      php modules/Transkript/bin/kategori-doldur.php            # kuyruğa
 *      php modules/Transkript/bin/kategori-doldur.php --hemen    # şimdi, sırayla
 *
 *  Raporu hazır olup kategorisi olmayan videolar için KategoriBelirle
 *  işi açar. Her video bir küçük AI çağrısıdır (girdi raporun özeti,
 *  transcript değil). Rapora dokunmaz; yalnız transkript_kategoriler'e
 *  yazar.
 *
 *    --dry-run     yalnız listeler, AI'a gitmez
 *    --hemen       kuyruğa koymaz, işleri bu süreçte sırayla çalıştırır
 *    --yenile      AI'ın belirlediklerini de yeniden belirler (elle
 *                  düzeltilenlere dokunmaz)
 *    --limit=N     en çok N video (varsayılan 1000)
 *
 *  Çekirdeğin komut listesi (php cy) sabittir; modül komutu çekirdek
 *  değişikliği gerektirdiği için betik modülün içindedir.
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
use App\Core\Queue\Queue;
use App\Core\Setting;
use Modules\Transkript\Jobs\KategoriBelirle;
use Modules\Transkript\Kategori\Kategoriler;
use Modules\Transkript\Repositories\KategoriRepository;

$secenekler = getopt('', ['dry-run', 'hemen', 'yenile', 'limit:']);
$db         = Database::connection();
Setting::load($db);
Modules::boot();

$repo   = new KategoriRepository($db);
$liste  = $repo->eksikler(isset($secenekler['yenile']), max(1, (int) ($secenekler['limit'] ?? 1000)));
$kuru   = isset($secenekler['dry-run']);
$hemen  = isset($secenekler['hemen']);

printf("%d video%s\n", count($liste), $kuru ? ' (kuru deneme: AI çağrısı yok)' : ($hemen ? ', sırayla şimdi' : ', kuyruğa alınıyor'));

$basari = 0;

foreach ($liste as $i => $satir) {
    $etiket = sprintf('%3d. kaynak %-5d analiz %-5d %s', $i + 1, $satir['kaynak_id'], $satir['analiz_id'], mb_strimwidth($satir['baslik'], 0, 60, '…'));

    if ($kuru) {
        echo $etiket, "\n";

        continue;
    }

    if (!$hemen) {
        Queue::push(new KategoriBelirle($satir['analiz_id']));
        echo $etiket, " → kuyrukta\n";

        continue;
    }

    $bas = microtime(true);

    try {
        (new KategoriBelirle($satir['analiz_id']))->handle();
    } catch (Throwable $e) {
        printf("%s → HATA %s\n", $etiket, mb_strimwidth($e->getMessage(), 0, 120, '…'));

        continue;
    }

    $k = $repo->bul($satir['kaynak_id']);

    if ($k === null) {
        printf("%s → belirlenemedi (günlüğe bakın)\n", $etiket);

        continue;
    }

    $basari++;
    printf("%s\n      %s · %s%s · %s · %s · %.1f sn\n", $etiket, Kategoriler::turAdi($k['icerik_turu']), Kategoriler::alanAdi($k['konu_alani']),
        $k['ikincil_alan'] !== null ? ' + ' . Kategoriler::alanAdi($k['ikincil_alan']) : '', Kategoriler::seviyeAdi($k['seviye']),
        implode(', ', $k['etiketler']), microtime(true) - $bas);
}

if ($hemen) {
    printf("\n%d / %d video kategorilendi.\n", $basari, count($liste));
}
