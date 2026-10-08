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

$router->get('panel/transkript/{kaynak_id}', KaynakController::class, 'goster',
    ['installed', 'auth', 'can:transkript.view']);

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
