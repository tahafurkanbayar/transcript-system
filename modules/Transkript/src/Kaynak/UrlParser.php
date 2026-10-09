<?php
/**
 * =====================================================================
 *  UrlParser – YouTube bağlantısından 11 karakterlik video kimliği
 * ---------------------------------------------------------------------
 *  Kabul edilen biçimler (http/https, www'li/www'siz, şemasız):
 *
 *      youtube.com/watch?v=ID   (&t=, &list=, &si= … yok sayılır)
 *      m.youtube.com/watch?v=ID · music.youtube.com/watch?v=ID
 *      youtu.be/ID
 *      youtube.com/shorts/ID · /embed/ID · /live/ID · /v/ID · /e/ID
 *      youtube-nocookie.com/embed/ID
 *      ID   (yalnızca 11 karakterlik kimlik)
 *
 *  GÜVENLİK: Kullanıcının verdiği adrese HİÇ istek atılmaz; yalnızca
 *  kimlik çıkarılır ve sonraki her istek bu kimlikten yeniden kurulur
 *  (izlemeAdresi). Alan adı beyaz listededir; "youtube.com.evil.com",
 *  "youtube.com@evil.com" (kullanıcı bilgisi hilesi), javascript: gibi
 *  şemalar reddedilir. Kimlik ^[A-Za-z0-9_-]{11}$ ile doğrulanır.
 *
 *  TUZAK: /embed/videoseries?list=… bir oynatma listesidir ama
 *  "videoseries" tam 11 karakterdir ve kimlik desenine uyar.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Kaynak;

final class UrlParser
{
    /** "www." öneki atıldıktan sonra kabul edilen alan adları. */
    public const ALAN_ADLARI = ['youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtu.be', 'youtube-nocookie.com'];

    private const KIMLIK = '/^[A-Za-z0-9_-]{11}$/';

    /** /onek/KIMLIK biçimindeki yollar. */
    private const YOL_ONEKLERI = ['shorts', 'embed', 'live', 'v', 'e'];

    /** Desene uyan ama video olmayan sözcükler. */
    private const KIMLIK_OLMAYAN = ['videoseries'];

    /** Bağlantıdan video kimliği; YouTube video bağlantısı değilse null. */
    public static function videoKimligi(string $girdi): ?string
    {
        $girdi = trim($girdi);

        if ($girdi === '' || strlen($girdi) > 2048) {
            return null;
        }

        if (self::gecerliKimlik($girdi)) {
            return $girdi;
        }

        // Şemasız yazılmış adres ("youtu.be/…", "www.youtube.com/…").
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $girdi)) {
            $girdi = 'https://' . $girdi;
        }

        $parca = parse_url($girdi);

        if (!is_array($parca) || !isset($parca['host'])) {
            return null;
        }

        if (!in_array(strtolower($parca['scheme'] ?? ''), ['http', 'https'], true)
            || isset($parca['user']) || isset($parca['pass'])) {
            return null;
        }

        $alan = rtrim(strtolower($parca['host']), '.');

        if (str_starts_with($alan, 'www.')) {
            $alan = substr($alan, 4);
        }

        if (!in_array($alan, self::ALAN_ADLARI, true)) {
            return null;
        }

        $yol = array_values(array_filter(explode('/', $parca['path'] ?? ''), static fn (string $s): bool => $s !== ''));
        parse_str($parca['query'] ?? '', $sorgu);

        $aday = match (true) {
            $alan === 'youtu.be'                                => $yol[0] ?? null,
            ($yol[0] ?? '') === 'watch'                         => $sorgu['v'] ?? null,
            in_array($yol[0] ?? '', self::YOL_ONEKLERI, true)   => $yol[1] ?? null,
            default                                             => null,
        };

        return is_string($aday) && self::gecerliKimlik($aday) ? $aday : null;
    }

    /**
     * Kimliği döndürür ya da kullanıcıya gösterilecek hatayı fırlatır.
     *
     * @throws KaynakHatasi gecersiz_url
     */
    public static function cozumle(string $girdi): string
    {
        return self::videoKimligi($girdi)
            ?? throw new KaynakHatasi(self::neden($girdi), 'YouTube video kimliği çıkarılamadı.');
    }

    /**
     * Kimlik çıkmadıysa kullanıcıya hangi mesaj? YouTube alan adındaki
     * oynatma listesi (/playlist?list=, /embed/videoseries) ve kanal
     * (/@ad, /channel/, /c/, /user/) bağlantısı ayrı söylenir; gerisi
     * gecersiz_url. Yalnız mesaj seçer, hiçbir şeyi kabul etmez.
     */
    public static function neden(string $girdi): string
    {
        $girdi = trim($girdi);

        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $girdi)) {
            $girdi = 'https://' . $girdi;
        }

        $parca = parse_url($girdi);
        $alan  = is_array($parca) ? rtrim(strtolower((string) ($parca['host'] ?? '')), '.') : '';
        $alan  = str_starts_with($alan, 'www.') ? substr($alan, 4) : $alan;

        if (!in_array($alan, self::ALAN_ADLARI, true) || !in_array(strtolower((string) ($parca['scheme'] ?? '')), ['http', 'https'], true)) {
            return 'gecersiz_url';
        }

        $yol = array_values(array_filter(explode('/', (string) ($parca['path'] ?? '')), static fn (string $s): bool => $s !== ''));
        parse_str((string) ($parca['query'] ?? ''), $sorgu);
        $ilk = strtolower($yol[0] ?? '');

        return match (true) {
            $ilk === 'playlist', ($yol[1] ?? '') === 'videoseries', isset($sorgu['list']) => 'oynatma_listesi',
            str_starts_with($ilk, '@'), in_array($ilk, ['channel', 'c', 'user'], true)    => 'kanal_baglantisi',
            default                                                                     => 'gecersiz_url',
        };
    }

    public static function gecerliKimlik(string $kimlik): bool
    {
        return preg_match(self::KIMLIK, $kimlik) === 1 && !in_array($kimlik, self::KIMLIK_OLMAYAN, true);
    }

    /** İstekler hep bu adresten kurulur, kullanıcının yazdığından değil. */
    public static function izlemeAdresi(string $kimlik): string
    {
        return 'https://www.youtube.com/watch?v=' . rawurlencode($kimlik);
    }
}
