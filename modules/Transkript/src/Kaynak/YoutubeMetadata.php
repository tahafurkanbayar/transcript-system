<?php
/**
 * =====================================================================
 *  YoutubeMetadata – video kartı: başlık, kanal, tarih, süre, görsel
 * ---------------------------------------------------------------------
 *  İKİ YOL
 *    1) YouTube Data API v3 (YOUTUBE_API_KEY varsa)
 *       videos.list?part=snippet,contentDetails,status — her alan dolu,
 *       gizlilik ve yüklenme durumu da gelir. Kota: günde 10.000 birim,
 *       bu çağrı 1 birim.
 *    2) oEmbed yedeği (anahtarsız): başlık, kanal adı ve küçük resim.
 *       Süre, tarih ve dil yoktur.
 *
 *  GİZLİ Mİ, SİLİNMİŞ Mİ?
 *    Data API anahtarla bile başkasının GİZLİ videosunu döndürmez:
 *    gizli, silinmiş ve hiç olmayan video aynı boş "items" dizisidir.
 *    Bu yüzden boş sonuçta oEmbed'e sorulur:
 *        401 / 403 → gizli (ya da başka sitelerde oynatılmaya kapalı)
 *        404 / 400 → bulunamadı (silinmiş ya da hiç olmamış)
 *    Data API'nin döndürdüğü videoda uploadStatus deleted / rejected /
 *    failed ise "silinmiş" sayılır.
 *    Anahtarsız kurulumda gömmeye kapalı herkese açık bir video da
 *    "gizli" görünür; YOUTUBE_API_KEY tanımlamak bunu çözer.
 *
 *  ANAHTAR URL'DE DEĞİL BAŞLIKTA (X-Goog-Api-Key): adres satırındaki
 *  anahtar sunucu ve vekil günlüklerine düşer. Yönlendirme izlenmez,
 *  başlık başka bir alana taşınmasın.
 *
 *  Data API hata verirse (kota, geçersiz anahtar, 5xx) oEmbed'e düşülür
 *  ve uyarı log'a yazılır; analiz durmaz.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Kaynak;

use App\Core\Env;
use App\Core\Log\Logger;
use DateTimeImmutable;
use DateTimeZone;
use Modules\Transkript\Http\CurlHttpIstemci;
use Modules\Transkript\Http\HttpIstemci;
use Modules\Transkript\Http\HttpYanit;
use Throwable;

final class YoutubeMetadata
{
    private const DATA_API = 'https://www.googleapis.com/youtube/v3/videos';
    private const OEMBED   = 'https://www.youtube.com/oembed';

    public function __construct(
        private readonly HttpIstemci $http,
        private readonly string $apiAnahtari = '',
    ) {
    }

    public static function fromEnv(): self
    {
        return new self(new CurlHttpIstemci(15, false), trim(Env::get('YOUTUBE_API_KEY')));
    }

    /**
     * @throws KaynakHatasi gecersiz_url · video_bulunamadi · gizli_video ·
     *                      silinmis_video · metadata_hatasi (geçici)
     */
    public function getir(string $videoId): VideoBilgisi
    {
        if (!UrlParser::gecerliKimlik($videoId)) {
            throw new KaynakHatasi('gecersiz_url', 'Geçersiz video kimliği.');
        }

        if ($this->apiAnahtari !== '') {
            $bilgi = $this->dataApi($videoId);

            if ($bilgi !== null) {
                return $bilgi;
            }
        }

        return $this->oembed($videoId);
    }

    /* =================================================================
     *  DATA API v3
     * ============================================================== */

    /** Video görünmüyorsa ya da API hata verdiyse null (oEmbed karar verir). */
    private function dataApi(string $id): ?VideoBilgisi
    {
        $yanit = $this->http->get(
            self::DATA_API . '?' . http_build_query(['part' => 'snippet,contentDetails,status', 'id' => $id]),
            ['X-Goog-Api-Key: ' . $this->apiAnahtari, 'Accept: application/json']
        );

        if (!$yanit->basarili()) {
            Logger::warning('YouTube Data API yanıt vermedi; oEmbed yedeğine geçiliyor', [
                'video'  => $id,
                'durum'  => $yanit->durum,
                'neden'  => self::dataApiHataNedeni($yanit),
                'ag'     => $yanit->hata,
            ], 'transkript');

            return null;
        }

        $oge = $yanit->json()['items'][0] ?? null;

        if (!is_array($oge)) {
            return null;
        }

        $yukleme = (string) ($oge['status']['uploadStatus'] ?? '');

        if (in_array($yukleme, ['deleted', 'rejected', 'failed'], true)) {
            throw new KaynakHatasi('silinmis_video', 'Data API uploadStatus=' . $yukleme);
        }

        $gizlilik = (string) ($oge['status']['privacyStatus'] ?? '');

        if ($gizlilik === 'private') {
            throw new KaynakHatasi('gizli_video', 'Data API privacyStatus=private');
        }

        $snippet = is_array($oge['snippet'] ?? null) ? $oge['snippet'] : [];
        $dil     = (string) ($snippet['defaultAudioLanguage'] ?? $snippet['defaultLanguage'] ?? '');

        return new VideoBilgisi(
            videoId:     $id,
            baslik:      (string) ($snippet['title'] ?? ''),
            kanalAdi:    self::boslukNull($snippet['channelTitle'] ?? null),
            kanalId:     self::boslukNull($snippet['channelId'] ?? null),
            yayinTarihi: self::tarih((string) ($snippet['publishedAt'] ?? '')),
            sureSn:      self::iso8601Sure((string) ($oge['contentDetails']['duration'] ?? '')),
            kucukResim:  self::enIyiKucukResim(is_array($snippet['thumbnails'] ?? null) ? $snippet['thumbnails'] : [], $id),
            dil:         $dil !== '' ? substr($dil, 0, 10) : null,
            gizlilik:    in_array($gizlilik, ['public', 'unlisted'], true) ? $gizlilik : 'bilinmiyor',
            canliYayin:  in_array($snippet['liveBroadcastContent'] ?? 'none', ['live', 'upcoming'], true),
            kaynak:      'data_api',
        );
    }

    /** Google hata gövdesinden kısa neden (quotaExceeded, keyInvalid …); anahtar içermez. */
    private static function dataApiHataNedeni(HttpYanit $yanit): string
    {
        $json = $yanit->json();

        /* details[].reason daha belirgindir: "forbidden" yerine
         * API_KEY_HTTP_REFERRER_BLOCKED (anahtar web sitesine kısıtlı;
         * sunucu isteğinde referer yok), API_KEY_SERVICE_BLOCKED,
         * SERVICE_DISABLED gibi. */
        foreach ((array) ($json['error']['details'] ?? []) as $ayrinti) {
            if (is_array($ayrinti) && is_string($ayrinti['reason'] ?? null) && $ayrinti['reason'] !== '') {
                return $ayrinti['reason'];
            }
        }

        return (string) ($json['error']['errors'][0]['reason'] ?? $json['error']['status'] ?? '');
    }

    /* =================================================================
     *  oEMBED
     * ============================================================== */

    private function oembed(string $id): VideoBilgisi
    {
        $yanit = $this->http->get(
            self::OEMBED . '?' . http_build_query(['url' => UrlParser::izlemeAdresi($id), 'format' => 'json']),
            ['Accept: application/json']
        );

        if ($yanit->agHatasi() || $yanit->durum === 429 || $yanit->durum >= 500) {
            throw new KaynakHatasi('metadata_hatasi', sprintf('oEmbed HTTP %d %s', $yanit->durum, $yanit->hata));
        }

        if ($yanit->durum === 401 || $yanit->durum === 403) {
            throw new KaynakHatasi('gizli_video', 'oEmbed HTTP ' . $yanit->durum);
        }

        if ($yanit->durum === 404 || $yanit->durum === 400) {
            throw new KaynakHatasi('video_bulunamadi', 'oEmbed HTTP ' . $yanit->durum);
        }

        $json = $yanit->json();

        if (!$yanit->basarili() || $json === null) {
            throw new KaynakHatasi('metadata_hatasi', 'oEmbed beklenmeyen yanıt, HTTP ' . $yanit->durum);
        }

        return new VideoBilgisi(
            videoId:     $id,
            baslik:      (string) ($json['title'] ?? ''),
            kanalAdi:    self::boslukNull($json['author_name'] ?? null),
            kanalId:     null,
            yayinTarihi: null,
            sureSn:      null,
            kucukResim:  self::boslukNull($json['thumbnail_url'] ?? null) ?? self::varsayilanKucukResim($id),
            dil:         null,
            gizlilik:    'bilinmiyor',
            canliYayin:  false,
            kaynak:      'oembed',
        );
    }

    /* =================================================================
     *  YARDIMCILAR
     * ============================================================== */

    /**
     * ISO 8601 süre → saniye: "PT1H2M3S" → 3723, "P1DT2H" → 93600.
     * Tanınmayan biçimde null (canlı yayında "P0D" → 0).
     */
    public static function iso8601Sure(string $sure): ?int
    {
        if (!preg_match('/^P(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/', $sure, $m) || $sure === 'P' || $sure === 'PT') {
            return null;
        }

        [$hafta, $gun, $saat, $dakika, $saniye] = array_map('intval', array_pad(array_slice($m, 1), 5, '0'));

        return $hafta * 604800 + $gun * 86400 + $saat * 3600 + $dakika * 60 + $saniye;
    }

    /** "2005-04-24T03:31:52Z" → uygulamanın saat diliminde "Y-m-d H:i:s". */
    private static function tarih(string $iso): ?string
    {
        if ($iso === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($iso))
                ->setTimezone(new DateTimeZone(date_default_timezone_get()))
                ->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $kucukResimler */
    private static function enIyiKucukResim(array $kucukResimler, string $id): string
    {
        foreach (['maxres', 'standard', 'high', 'medium', 'default'] as $boy) {
            $adres = $kucukResimler[$boy]['url'] ?? null;

            if (is_string($adres) && str_starts_with($adres, 'https://')) {
                return $adres;
            }
        }

        return self::varsayilanKucukResim($id);
    }

    private static function varsayilanKucukResim(string $id): string
    {
        return 'https://i.ytimg.com/vi/' . rawurlencode($id) . '/hqdefault.jpg';
    }

    private static function boslukNull(mixed $deger): ?string
    {
        return is_string($deger) && trim($deger) !== '' ? trim($deger) : null;
    }
}
