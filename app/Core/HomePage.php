<?php
/**
 * =====================================================================
 *  HomePage – Ana sayfayı bir modülün çizmesi
 * ---------------------------------------------------------------------
 *  Ana sayfa normalde Panel → Ayarlar → Ana Sayfa'dan beslenen genel
 *  vitrindir (Site\HomeController). Ürünü bir modül olan sitede vitrin
 *  o modülün tanıtım sayfası olmalıdır; ama modül rotası "" adresini
 *  alamaz (Router: ilk kayıt kazanır, çekirdek rotaları önce gelir).
 *
 *  Modül events.php'de bir çizici kaydeder:
 *
 *      HomePage::set('Stok', static function (Request $request): bool {
 *          View::render('Stok::vitrin', [...], 'layouts/site');
 *          return true;
 *      });
 *
 *  HomeController önce çiziciye sorar. Çizici false dönerse ya da hata
 *  fırlatırsa çıktısı atılır ve genel vitrin çizilir: bir modülün
 *  hatası ana sayfayı düşürmez (Modules::loadRoutes'taki yalıtım gibi).
 *  Tek çizici vardır; ilk kayıt kazanır.
 * =====================================================================
 */

declare(strict_types=1);

namespace App\Core;

use App\Core\Log\Logger;
use Closure;
use Throwable;

final class HomePage
{
    private static ?string $owner = null;

    private static ?Closure $renderer = null;

    /** @param Closure(Request): bool $renderer true: sayfa çizildi */
    public static function set(string $owner, Closure $renderer): void
    {
        if (self::$renderer !== null) {
            return;
        }

        self::$owner    = $owner;
        self::$renderer = $renderer;
    }

    /** Ana sayfayı üstlenen modül; yoksa null. */
    public static function owner(): ?string
    {
        return self::$owner;
    }

    /** Çizici sayfayı çizdiyse true; yoksa, false döndüyse ya da hata verdiyse false. */
    public static function render(Request $request): bool
    {
        if (self::$renderer === null) {
            return false;
        }

        $seviye = ob_get_level();
        ob_start();

        try {
            $cizildi = (self::$renderer)($request) === true;
        } catch (Throwable $e) {
            Logger::error('Ana sayfa çizicisi hata verdi; genel vitrin gösterildi', [
                'modul' => self::$owner,
                'hata'  => $e->getMessage(),
                'yer'   => $e->getFile() . ':' . $e->getLine(),
            ]);
            $cizildi = false;
        }

        while (ob_get_level() > $seviye) {
            $cizildi ? ob_end_flush() : ob_end_clean();
        }

        return $cizildi;
    }

    /** Testler için: kaydı siler. */
    public static function reset(): void
    {
        self::$owner    = null;
        self::$renderer = null;
    }
}
