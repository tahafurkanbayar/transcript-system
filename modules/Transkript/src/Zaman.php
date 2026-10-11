<?php
/**
 * =====================================================================
 *  Zaman – "hh:mm:ss" ile milisaniye arasında çeviri
 * ---------------------------------------------------------------------
 *  AI'a giden satırlar "[01:24:32] metin" biçimindedir, AI'ın döndürdüğü
 *  zaman damgaları da aynı biçimdedir; veritabanında her şey
 *  milisaniye tam sayıdır.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript;

final class Zaman
{
    /** "01:02:03", "02:03", "1:02:03.5", "75" (saniye) → milisaniye; okunamazsa null. */
    public static function oku(string $zaman): ?int
    {
        // Model transcript satırındaki biçimi aynen kopyalayabiliyor: "[00:01:25]", "(01:25)".
        $zaman = trim($zaman, " \t\n\r[]()");

        if (preg_match('/^(?:(\d{1,2}):)?(\d{1,2}):(\d{2})(?:[.,](\d{1,3}))?$/', $zaman, $m)) {
            if ((int) $m[3] >= 60 || ($m[1] !== '' && (int) $m[2] >= 60)) {
                return null;
            }

            $kesir = isset($m[4]) ? (int) str_pad($m[4], 3, '0') : 0;

            return (((int) ($m[1] ?: 0) * 3600) + ((int) $m[2] * 60) + (int) $m[3]) * 1000 + $kesir;
        }

        if (preg_match('/^\d+(?:\.\d+)?$/', $zaman)) {
            return (int) round((float) $zaman * 1000);
        }

        return null;
    }

    /** milisaniye → "hh:mm:ss" */
    public static function yaz(int $ms): string
    {
        $sn = intdiv(max(0, $ms), 1000);

        return sprintf('%02d:%02d:%02d', intdiv($sn, 3600), intdiv($sn % 3600, 60), $sn % 60);
    }
}
