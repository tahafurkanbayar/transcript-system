<?php
/**
 * =====================================================================
 *  DinlenmeDeposu – yapay zekâ sağlayıcılarının dinlenme kayıtları
 * ---------------------------------------------------------------------
 *  Kota ya da hata veren sağlayıcı bir süre denenmez (YedekliSaglayici).
 *  Kayıt eskiden önbellekteydi (Cache); önbellek temizlenince günlük
 *  kotası dolan Gemini "Hazır" görünüyor, her analiz onu boşuna deneyip
 *  günlüğe hata yazıyordu (cowork 6. tur K2). Artık kalıcı bir JSON
 *  dosyasında: storage/files/transkript-dinlenme.json (web'e kapalı,
 *  depoya girmez). Her okuma diskten: bütün işçiler aynı bilgiyi görür.
 *  Yazma kilitli (oku–değiştir–yaz); süresi geçen kayıtlar yazarken atılır.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Ai;

final class DinlenmeDeposu
{
    private static ?string $yol = null;

    /** Dosya yolu. Testler geçici bir dosya verir (kullan()). */
    public static function yol(): string
    {
        return self::$yol ?? (defined('CY_BASE') ? CY_BASE : dirname(__DIR__, 4)) . '/storage/files/transkript-dinlenme.json';
    }

    /** Testler için başka bir dosya; null → varsayılan. */
    public static function kullan(?string $yol): void
    {
        self::$yol = $yol;
    }

    /** @return array<string,mixed>|null */
    public static function al(string $ad): ?array
    {
        $kayit = self::oku()[$ad] ?? null;

        return is_array($kayit) ? $kayit : null;
    }

    /** @param array<string,mixed> $kayit */
    public static function yaz(string $ad, array $kayit): void
    {
        self::degistir(static function (array $hepsi) use ($ad, $kayit): array {
            $hepsi[$ad] = $kayit;

            return $hepsi;
        });
    }

    public static function sil(string $ad): void
    {
        self::degistir(static function (array $hepsi) use ($ad): array {
            unset($hepsi[$ad]);

            return $hepsi;
        });
    }

    /** @return array<string,mixed> */
    private static function oku(): array
    {
        $yol = self::yol();

        if (!is_file($yol)) {
            return [];
        }

        $tutamak = @fopen($yol, 'rb');

        if ($tutamak === false) {
            return [];
        }

        try {
            flock($tutamak, LOCK_SH);
            $veri = json_decode((string) stream_get_contents($tutamak), true);
        } finally {
            flock($tutamak, LOCK_UN);
            fclose($tutamak);
        }

        return is_array($veri) ? $veri : [];
    }

    /** @param callable(array<string,mixed>): array<string,mixed> $islem */
    private static function degistir(callable $islem): void
    {
        $yol = self::yol();

        if (!is_dir(dirname($yol))) {
            @mkdir(dirname($yol), 0775, true);
        }

        $tutamak = @fopen($yol, 'c+b');

        if ($tutamak === false) {
            return;
        }

        try {
            flock($tutamak, LOCK_EX);
            $hepsi = json_decode((string) stream_get_contents($tutamak), true);
            $hepsi = $islem(is_array($hepsi) ? $hepsi : []);

            // Süresi geçen kayıtlar atılır; dosya küçük kalır. "sakla" gerçek
            // saatle yazılır (bitiş sahte saatle hesaplanmış olabilir: testler).
            $simdi = time();
            $hepsi = array_filter($hepsi, static fn (mixed $k): bool => is_array($k) && (int) ($k['sakla'] ?? $k['bitis'] ?? 0) > $simdi);

            ftruncate($tutamak, 0);
            rewind($tutamak);
            fwrite($tutamak, (string) json_encode($hepsi, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            fflush($tutamak);
        } finally {
            flock($tutamak, LOCK_UN);
            fclose($tutamak);
        }
    }
}
