<?php
/**
 * =====================================================================
 *  Transkript MODÜLÜ – Rotaları
 * ---------------------------------------------------------------------
 *  Bu dosya, modül AÇIKKEN çekirdek rotalarından SONRA yüklenir.
 *  $router değişkeni hazır gelir.
 *
 *  Yetkiler ROL düzeyindedir; editör ve üyenin yetkileri module.json →
 *  "yetkiler" bloğundan gelir, yönetici her yetkiye sahiptir. Kayda
 *  bağlı kurallar (yüklenen dosya, sohbet, kişisel özet yalnız
 *  sahibine) TranskriptPolicy'dedir.
 *
 *  Erişim tablosu tests/transkript.php'de; rota eklenir ya da yetkisi
 *  değişirse oradaki matris de güncellenir.
 * =====================================================================
 */

declare(strict_types=1);

use App\Core\Router;
use Modules\Transkript\Controllers\Api\TranskriptApiController;
use Modules\Transkript\Controllers\KaynakController;
use Modules\Transkript\Controllers\SohbetController;

/** @var Router $router */

/* ---------------------------------------------------------------------
 *  PANEL
 * ------------------------------------------------------------------ */
$router->get('panel/transkript', KaynakController::class, 'index',
    ['installed', 'auth', 'can:transkript.view']);

$router->post('panel/transkript/analiz', KaynakController::class, 'analiz',
    ['installed', 'auth', 'csrf', 'can:transkript.analyze']);

$router->get('panel/transkript/ilerleme/{kaynak_id}', KaynakController::class, 'ilerleme',
    ['installed', 'auth', 'can:transkript.view']);

// İşçi çalışmıyorsa sıradaki işleri istek içinde çalıştırır (yalnız geliştirme
// ortamında ya da kuyruk.panelden_isle açıkken; bkz. Services\KuyrukDurumu).
$router->post('panel/transkript/kuyruk/isle', KaynakController::class, 'kuyruguIsle',
    ['installed', 'auth', 'csrf', 'can:transkript.admin']);

// Modülün JS / CSS dosyaları (modules/ klasörü tarayıcıya kapalı; bkz. Varliklar).
$router->get('panel/transkript/varlik/{dosya}', KaynakController::class, 'varlik',
    ['installed', 'auth', 'can:transkript.view']);

$router->get('panel/transkript/{kaynak_id}/transkript', KaynakController::class, 'transkript',
    ['installed', 'auth', 'can:transkript.view']);

// Dışa aktarma: rapor.md, transkript.txt / .srt / .vtt (Services\DisaAktarim)
$router->get('panel/transkript/{kaynak_id}/indir/{bicim}', KaynakController::class, 'indir',
    ['installed', 'auth', 'can:transkript.view']);

$router->get('panel/transkript/{kaynak_id}', KaynakController::class, 'goster',
    ['installed', 'auth', 'can:transkript.view']);

// Kayıt yönetimi (Services\KayitYonetimi). Kayda bağlı kural TranskriptPolicy'de:
// ortak YouTube kaynağını yalnız yönetici siler; süren analizi yönetici ya da başlatan iptal eder.
$router->post('panel/transkript/{kaynak_id}/sil', KaynakController::class, 'sil',
    ['installed', 'auth', 'csrf', 'can:transkript.view']);

$router->post('panel/transkript/{kaynak_id}/iptal', KaynakController::class, 'iptal',
    ['installed', 'auth', 'csrf', 'can:transkript.analyze']);

$router->post('panel/transkript/{kaynak_id}/sohbet', SohbetController::class, 'sor',
    ['installed', 'auth', 'csrf', 'can:transkript.chat']);

/* ---------------------------------------------------------------------
 *  REST API — "Authorization: Bearer cy_…" (docs/TEKNIK_RAPOR.md, 8. bölüm)
 *  "okuma" kapsamlı anahtar POST atamaz (ApiGuard).
 * ------------------------------------------------------------------ */
$router->group('api/v1/transkript', ['installed'], function (Router $r): void {
    $r->post('analiz', TranskriptApiController::class, 'analiz', ['api', 'api.can:transkript.analyze']);
    $r->post('dosya',  TranskriptApiController::class, 'dosya',  ['api', 'api.can:transkript.analyze']);

    $r->get('kaynaklar',                         TranskriptApiController::class, 'kaynaklar',  ['api', 'api.can:transkript.view']);
    $r->get('kaynaklar/{kaynak_id}',             TranskriptApiController::class, 'kaynak',     ['api', 'api.can:transkript.view']);
    $r->get('kaynaklar/{kaynak_id}/ozet',        TranskriptApiController::class, 'ozet',       ['api', 'api.can:transkript.view']);
    $r->get('kaynaklar/{kaynak_id}/transkript',  TranskriptApiController::class, 'transkript', ['api', 'api.can:transkript.view']);
    $r->post('kaynaklar/{kaynak_id}/sohbet',     TranskriptApiController::class, 'sohbet',     ['api', 'api.can:transkript.chat']);

    $r->get('istatistik', TranskriptApiController::class, 'istatistik', ['api', 'api.can:transkript.admin']);
});
