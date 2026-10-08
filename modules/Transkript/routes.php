<?php
/**
 * =====================================================================
 *  Transkript MODÜLÜ – Rotaları
 * ---------------------------------------------------------------------
 *  Bu dosya, modül AÇIKKEN çekirdek rotalarından SONRA yüklenir.
 *  $router değişkeni hazır gelir.
 *
 *  Yönetici her yetkiye sahiptir. Modülü editöre ya da üyeye açmak
 *  için yetki adını module.json → "yetkiler" bloğuna yazın, ör.
 *  "editor": ["transkript.view"]. Çekirdeğin Role.php'sine dokunmayın.
 *  Kayıt düzeyi kurallar için bkz. modules/Ornek/src/OrnekPolicy.php.
 * =====================================================================
 */

declare(strict_types=1);

use App\Core\Router;
use Modules\Transkript\Controllers\TranskriptController;

/** @var Router $router */

$router->get('panel/transkript', TranskriptController::class, 'index',
    ['installed', 'auth', 'can:transkript.view']);

$router->post('panel/transkript/kaydet', TranskriptController::class, 'store',
    ['installed', 'auth', 'csrf', 'can:transkript.manage']);

$router->post('panel/transkript/sil/{id}', TranskriptController::class, 'destroy',
    ['installed', 'auth', 'csrf', 'can:transkript.manage']);
