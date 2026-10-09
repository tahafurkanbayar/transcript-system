<?php
/**
 * =====================================================================
 *  Varliklar – modülün JS / CSS dosyaları ve sayfa CSP izinleri
 * ---------------------------------------------------------------------
 *  NEDEN ROTA? Panel düzeninin $scripts listesi yalnız çekirdeğin
 *  assets/js klasörüne bakar; modules/ klasörü ise nginx örneğinde ve
 *  geliştirme sunucusunda tarayıcıya kapalıdır. Dosyalar
 *  modules/Transkript/assets/ altında durur ve beyaz listeyle
 *  panel/transkript/varlik/{dosya} rotasından sunulur (sürüm damgalı,
 *  uzun önbellek). Çekirdeğe dosya eklenmez.
 *
 *  CSP: çekirdeğin politikası yalnız kendi alan adına izin verir.
 *  Transkript sayfaları YouTube küçük resmi (i.ytimg.com) ve gömülü
 *  oynatıcı (youtube-nocookie.com) ister; youtubeIzinleri() bu iki
 *  kaynağı YALNIZ bu sayfalarda ekleyip başlığı yeniden gönderir.
 *  script-src açılmaz: oynatıcı dış betik yüklemeden postMessage ile
 *  sürülür (bkz. assets/js/transkript.js).
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript;

use App\Core\Config;
use App\Core\Response;

final class Varliklar
{
    /** Sunulabilecek dosyalar: ad → [göreli yol, içerik türü] */
    private const DOSYALAR = [
        'transkript.js'  => ['js/transkript.js', 'application/javascript; charset=utf-8'],
        'transkript.css' => ['css/transkript.css', 'text/css; charset=utf-8'],
    ];

    private const KLASOR = __DIR__ . '/../assets/';

    /** Ek CSP kaynakları (yalnız Transkript sayfalarında). */
    private const CSP = [
        'img-src'   => ['https://i.ytimg.com'],
        'frame-src' => ['https://www.youtube-nocookie.com', 'https://www.youtube.com'],
    ];

    /** Sürüm damgalı adres: dosya değişince tarayıcı yenisini alır. */
    public static function adres(string $dosya): string
    {
        $yol = self::yol($dosya);

        // url() parametresiyle: temiz adres kapalıyken adres zaten "?r=…" içerir.
        return url('panel/transkript/varlik/' . $dosya, $yol !== null ? ['v' => (string) filemtime($yol)] : []);
    }

    /** Beyaz listedeki dosyanın tam yolu; değilse null. */
    public static function yol(string $dosya): ?string
    {
        if (!isset(self::DOSYALAR[$dosya])) {
            return null;
        }

        $yol = self::KLASOR . self::DOSYALAR[$dosya][0];

        return is_file($yol) ? $yol : null;
    }

    public static function tur(string $dosya): string
    {
        return self::DOSYALAR[$dosya][1] ?? 'application/octet-stream';
    }

    /**
     * Bu isteğin CSP'sine YouTube kaynaklarını ekler ve güvenlik
     * başlıklarını yeniden gönderir (aynı adlı başlık eskisinin yerine
     * geçer). Görünüm basılmadan ÖNCE çağrılmalı.
     */
    public static function youtubeIzinleri(): void
    {
        $ek = (array) Config::get('security.csp_extra', []);

        foreach (self::CSP as $yonerge => $kaynaklar) {
            $ek[$yonerge] = array_values(array_unique(array_merge((array) ($ek[$yonerge] ?? []), $kaynaklar)));
        }

        Config::set('security.csp_extra', $ek);
        Response::securityHeaders();
    }

    /** @return array<string,list<string>> Testler için */
    public static function cspKaynaklari(): array
    {
        return self::CSP;
    }
}
