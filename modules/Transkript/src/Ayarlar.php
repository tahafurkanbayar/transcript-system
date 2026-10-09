<?php
/**
 * =====================================================================
 *  Ayarlar – modülün config.php dosyasını okur
 * ---------------------------------------------------------------------
 *      Ayarlar::get('parca.hedef_token', 4000)
 *
 *  Çekirdeğin Config sınıfı yalnızca config/ klasörünü yükler; modül
 *  ayarları çekirdeğe dokunmadan burada tutulur. Dosya istek başına
 *  bir kez yüklenir. Testler set() ile değer verip forget() ile geri alır.
 *
 *  Yöneticinin panelden girdiği değerler (panel/transkript/ayarlar,
 *  PanelAyarlari) config.php'nin üstüne yazılır; varsayilan() panelsiz
 *  config değerini verir.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript;

final class Ayarlar
{
    /** @var array<string,mixed>|null */
    private static ?array $degerler = null;

    /** varsayilan() çağrısı sürerken panel değeri uygulanmaz. */
    private static bool $panelsiz = false;

    /**
     * config.php değeri; yönetici panelden (panel/transkript/ayarlar) bir
     * değer girdiyse o üstüne yazılır (PanelAyarlari::ustune).
     */
    public static function get(string $anahtar, mixed $varsayilan = null): mixed
    {
        $deger = self::yukle();

        foreach (explode('.', $anahtar) as $parca) {
            if (!is_array($deger) || !array_key_exists($parca, $deger)) {
                $deger = $varsayilan;
                break;
            }

            $deger = $deger[$parca];
        }

        return self::$panelsiz ? $deger : PanelAyarlari::ustune($anahtar, $deger);
    }

    /** Ekran varsayılanı göstermek için: panel değeri olmadan config.php. */
    public static function varsayilan(string $anahtar, mixed $varsayilan = null): mixed
    {
        self::$panelsiz = true;

        try {
            return self::get($anahtar, $varsayilan);
        } finally {
            self::$panelsiz = false;
        }
    }

    public static function set(string $anahtar, mixed $deger): void
    {
        self::yukle();

        $hedef = &self::$degerler;

        foreach (explode('.', $anahtar) as $parca) {
            if (!isset($hedef[$parca]) || !is_array($hedef[$parca])) {
                $hedef[$parca] = [];
            }

            $hedef = &$hedef[$parca];
        }

        $hedef = $deger;
    }

    public static function forget(): void
    {
        self::$degerler = null;
    }

    /** @return array<string,mixed> */
    private static function yukle(): array
    {
        if (self::$degerler === null) {
            $dosya = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config.php';
            $veri  = is_file($dosya) ? require $dosya : [];

            self::$degerler = is_array($veri) ? $veri : [];
        }

        return self::$degerler;
    }
}
