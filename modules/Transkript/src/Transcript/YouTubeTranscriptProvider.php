<?php
/**
 * =====================================================================
 *  YouTubeTranscriptProvider – YouTube altyazısı (elle ve otomatik)
 * ---------------------------------------------------------------------
 *  Kaynak: MrMySQL/youtube-transcript @26b8337 (WTFPL),
 *  src/TranscriptListFetcher.php, TranscriptList.php, Transcript.php,
 *  TranscriptParser.php. PSR-18 / Composer bağımlılığı olmadan
 *  HttpIstemci'ye taşındı.
 *
 *  AKIŞ
 *    1) İzleme sayfası → INNERTUBE_API_KEY (AB'de çerez onay sayfası
 *       gelirse CONSENT çereziyle bir kez daha)
 *    2) InnerTube "player" ucu, ANDROID istemcisiyle → altyazı izleri.
 *       Web istemcisinin izleri PoToken ister; ANDROID istemezdi.
 *    3) Seçilen izin baseUrl'i → XML (<transcript><text start dur>)
 *
 *  KAYNAKTAN FARKLI OLANLAR
 *    · Kaynakta PSR-7 isteği değişmez olduğu halde withHeader() sonucu
 *      atanmıyordu; Accept-Language ve onay çerezi hiç gitmiyordu. Onay
 *      çerezi de "Set-Cookie" istek başlığıyla yollanıyordu; doğrusu
 *      "Cookie".
 *    · ORİJİNAL DİL: otomatik dublajlı videolarda her ses izi için bir
 *      otomatik altyazı gelir (fr-FR, de-DE …). Özgün ses izi
 *      audioTracks[defaultAudioTrackIndex]'tir (ÖLÇÜLEN: "en-US.4",
 *      dublajlar ".10"). Dil sırası: tercih edilen dil (yalnız elle),
 *      orijinal dil (elle, sonra otomatik), İngilizce, kalan herhangi iz.
 *    · playabilityStatus ayrıştırılır: gizli / silinmiş / bulunamadı
 *      KaynakHatasi'dır (zincir durur); bot engeli, yaş sınırı,
 *      PoToken ProviderUnavailable'dır (zincir Gemini'ye geçer).
 *
 *  Resmî olmayan uçtur: YouTube değiştirirse önce config.php'deki
 *  istemci sürümünü güncellemek yeterli olabilir.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Transcript;

use DOMDocument;
use DOMXPath;
use Modules\Transkript\Ayarlar;
use Modules\Transkript\Http\CurlHttpIstemci;
use Modules\Transkript\Http\HttpIstemci;
use Modules\Transkript\Http\HttpYanit;
use Modules\Transkript\Kaynak\Kaynak;
use Modules\Transkript\Kaynak\KaynakHatasi;
use Modules\Transkript\Kaynak\UrlParser;

final class YouTubeTranscriptProvider implements TranscriptProviderInterface
{
    private const PLAYER = 'https://www.youtube.com/youtubei/v1/player?prettyPrint=false&key=';

    /** Sunucu tarafında masaüstü tarayıcı gibi görünmezsek izleme sayfası farklı gelir. */
    public const TARAYICI = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

    /** @param array{clientName:string,clientVersion:string} $istemci */
    public function __construct(
        private readonly HttpIstemci $http,
        private readonly array $istemci = ['clientName' => 'ANDROID', 'clientVersion' => '20.10.38'],
    ) {
    }

    public static function fromAyarlar(): self
    {
        return new self(
            new CurlHttpIstemci((int) Ayarlar::get('transcript.youtube.zaman_asimi', 20), true, self::TARAYICI),
            (array) Ayarlar::get('transcript.youtube.istemci', ['clientName' => 'ANDROID', 'clientVersion' => '20.10.38']),
        );
    }

    public function name(): string
    {
        return 'youtube';
    }

    public function supports(Kaynak $kaynak): bool
    {
        return $kaynak->youtubeMu();
    }

    public function fetch(Kaynak $kaynak, array $preferredLangs): TranscriptResult
    {
        $id = $kaynak->disKimlik;

        if (!UrlParser::gecerliKimlik($id)) {
            throw new KaynakHatasi('gecersiz_url', 'Geçersiz video kimliği: ' . $id);
        }

        $anahtar  = self::innertubeAnahtari($this->izlemeSayfasi($id));
        $izListesi = self::izListesi($this->player($id, $anahtar), $id);
        $orijinal = self::orijinalDil($izListesi) ?? $kaynak->dil;
        $iz       = self::izSec($izListesi['captionTracks'], $preferredLangs, $orijinal);

        if ($iz === null) {
            throw new TranscriptNotFound('Uygun dilde altyazı izi yok: ' . $id);
        }

        $adres = (string) ($iz['baseUrl'] ?? '');

        if (!str_starts_with($adres, 'https://www.youtube.com/')) {
            throw new ProviderUnavailable('yanit_beklenmedik', 'Altyazı adresi beklenmeyen bir yerde: ' . $id);
        }

        // Kaynaktaki PoTokenRequiredException: bu izin adresi jeton olmadan boş döner.
        if (str_contains($adres, '&exp=xpe')) {
            throw new ProviderUnavailable('potoken_gerekli', 'Altyazı izi PoToken istiyor: ' . $id);
        }

        $yanit = $this->http->get(self::xmlAdresi($adres), ['Accept-Language: en-US']);
        self::durumuDenetle($yanit, 'altyazı dosyası');

        $segmentler = self::xmlCozumle($yanit->govde);

        if ($segmentler === []) {
            throw new TranscriptNotFound('Altyazı dosyası boş geldi: ' . $id);
        }

        $otomatik = ($iz['kind'] ?? '') === 'asr';

        return new TranscriptResult(
            $segmentler,
            (string) ($iz['languageCode'] ?? ''),
            $otomatik ? 'youtube_otomatik' : 'youtube_elle',
            $otomatik,
        );
    }

    /* =================================================================
     *  1) İZLEME SAYFASI
     * ============================================================== */

    private function izlemeSayfasi(string $id): string
    {
        $basliklar = ['Accept-Language: en-US'];
        $yanit = $this->http->get(UrlParser::izlemeAdresi($id), $basliklar);
        self::durumuDenetle($yanit, 'izleme sayfası');

        $html = $yanit->govde;

        if (str_contains($html, 'action="https://consent.youtube.com/s"')) {
            if (!preg_match('/name="v" value="(.*?)"/', $html, $m)) {
                throw new ProviderUnavailable('onay_cerezi', 'Çerez onay sayfası aşılamadı: ' . $id);
            }

            $basliklar[] = 'Cookie: CONSENT=YES+' . $m[1];
            $yanit = $this->http->get(UrlParser::izlemeAdresi($id), $basliklar);
            self::durumuDenetle($yanit, 'izleme sayfası (onaylı)');
            $html = $yanit->govde;

            if (str_contains($html, 'action="https://consent.youtube.com/s"')) {
                throw new ProviderUnavailable('onay_cerezi', 'Çerez onay sayfası ikinci kez geldi: ' . $id);
            }
        }

        return $html;
    }

    public static function innertubeAnahtari(string $html): string
    {
        if (preg_match('/"INNERTUBE_API_KEY"\s*:\s*"([a-zA-Z0-9_-]+)"/', $html, $m)) {
            return $m[1];
        }

        if (str_contains($html, 'class="g-recaptcha"')) {
            throw new RateLimited('YouTube reCAPTCHA sayfası döndü (IP geçici olarak sınırlandı).');
        }

        throw new ProviderUnavailable('yanit_beklenmedik', 'INNERTUBE_API_KEY bulunamadı.', true);
    }

    /* =================================================================
     *  2) INNERTUBE PLAYER
     * ============================================================== */

    /** @return array<string,mixed> */
    private function player(string $id, string $anahtar): array
    {
        $govde = (string) json_encode(['context' => ['client' => $this->istemci], 'videoId' => $id]);
        $yanit = $this->http->post(self::PLAYER . rawurlencode($anahtar), $govde, ['Content-Type: application/json', 'Accept-Language: en-US']);
        self::durumuDenetle($yanit, 'player');

        return $yanit->json() ?? throw new ProviderUnavailable('yanit_beklenmedik', 'Player yanıtı JSON değil.', true);
    }

    /**
     * playabilityStatus'u yorumlar, altyazı listesini döndürür.
     *
     * @param  array<string,mixed> $player
     * @return array<string,mixed> playerCaptionsTracklistRenderer
     */
    public static function izListesi(array $player, string $id): array
    {
        $durum = $player['playabilityStatus']['status'] ?? null;
        $neden = (string) ($player['playabilityStatus']['reason'] ?? '');
        $ayrinti = sprintf('%s: %s %s', $id, (string) $durum, $neden);

        if ($durum === null) {
            throw new ProviderUnavailable('yanit_beklenmedik', 'playabilityStatus yok: ' . $id, true);
        }

        if ($durum !== 'OK') {
            $kucuk = strtolower($neden);

            match (true) {
                // Kaynaktaki RequestBlockedException: bot şüphesi, altyazılar silinmiş gelir.
                $durum === 'LOGIN_REQUIRED' && str_contains($kucuk, 'bot')          => throw new ProviderUnavailable('ip_engeli', $ayrinti, true),
                str_contains($kucuk, 'private')                                      => throw new KaynakHatasi('gizli_video', $ayrinti),
                str_contains($kucuk, 'removed') || str_contains($kucuk, 'terminated') => throw new KaynakHatasi('silinmis_video', $ayrinti),
                $durum === 'ERROR'                                                   => throw new KaynakHatasi('video_bulunamadi', $ayrinti),
                preg_match('/\bage\b/', $kucuk) === 1                                => throw new ProviderUnavailable('yas_siniri', $ayrinti),
                str_starts_with((string) $durum, 'LIVE_STREAM')                      => throw new TranscriptNotFound($ayrinti, 'canli_yayin'),
                default                                                              => throw new ProviderUnavailable('oynatilamaz', $ayrinti),
            };
        }

        $liste = $player['captions']['playerCaptionsTracklistRenderer'] ?? null;

        if (!is_array($liste)) {
            throw new TranscriptNotFound('Videoda altyazı kapalı: ' . $id, 'altyazi_kapali');
        }

        if (!is_array($liste['captionTracks'] ?? null) || $liste['captionTracks'] === []) {
            throw new TranscriptNotFound('Videoda altyazı izi yok: ' . $id);
        }

        return $liste;
    }

    /**
     * Özgün ses izinin dili: audioTracks[defaultAudioTrackIndex].audioTrackId
     * ("en-US.4" → "en-US"). Tek ses izli videoda bu alan yoktur → null.
     *
     * @param array<string,mixed> $izListesi
     */
    public static function orijinalDil(array $izListesi): ?string
    {
        $sira = $izListesi['defaultAudioTrackIndex'] ?? null;
        $kimlik = is_int($sira) ? ($izListesi['audioTracks'][$sira]['audioTrackId'] ?? null) : null;

        if (!is_string($kimlik) || $kimlik === '') {
            return null;
        }

        $dil = explode('.', $kimlik, 2)[0];

        return preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})?$/', $dil) ? $dil : null;
    }

    /**
     * Dil sırası: tercih edilen diller (yalnız elle), orijinal dil (elle,
     * sonra otomatik), İngilizce (elle, sonra otomatik), kalan herhangi
     * elle iz, kalan herhangi otomatik iz.
     *
     * @param list<array<string,mixed>> $izler
     * @param list<string>              $tercihDiller
     * @return array<string,mixed>|null
     */
    public static function izSec(array $izler, array $tercihDiller, ?string $orijinal): ?array
    {
        $elle = $otomatik = [];

        foreach ($izler as $iz) {
            if (!is_array($iz) || !is_string($iz['languageCode'] ?? null)) {
                continue;
            }

            if (($iz['kind'] ?? '') === 'asr') {
                $otomatik[] = $iz;
            } else {
                $elle[] = $iz;
            }
        }

        $bul = static function (array $havuz, string $dil): ?array {
            $kok = self::dilKoku($dil);

            foreach ($havuz as $iz) {
                if (self::dilKoku((string) $iz['languageCode']) === $kok) {
                    return $iz;
                }
            }

            return null;
        };

        $sira = [];

        foreach ($tercihDiller as $dil) {
            $sira[] = [$elle, $dil];
        }

        if ($orijinal !== null && $orijinal !== '') {
            $sira[] = [$elle, $orijinal];
            $sira[] = [$otomatik, $orijinal];
        }

        $sira[] = [$elle, 'en'];
        $sira[] = [$otomatik, 'en'];

        foreach ($sira as [$havuz, $dil]) {
            $iz = $bul($havuz, $dil);

            if ($iz !== null) {
                return $iz;
            }
        }

        return $elle[0] ?? $otomatik[0] ?? null;
    }

    /** "en-US" → "en", "pt-BR" → "pt" */
    private static function dilKoku(string $dil): string
    {
        return strtolower(explode('-', str_replace('_', '-', $dil), 2)[0]);
    }

    /* =================================================================
     *  3) ALTYAZI DOSYASI
     * ============================================================== */

    /** fmt parametresi atılır: varsayılan biçim XML'dir (<text start dur>). */
    public static function xmlAdresi(string $baseUrl): string
    {
        $adres = (string) preg_replace('/([?&])fmt=[^&]*(&|$)/', '$1', $baseUrl);

        return rtrim($adres, '?&');
    }

    /**
     * <transcript><text start="1.2" dur="2.16">…</text></transcript>
     * Metin kaynakta çift kaçışlıdır ("&amp;#39;"): XML bir kez, ikinci
     * html_entity_decode bir kez daha açar; etiketler (<font>) atılır.
     *
     * @return list<Segment>
     */
    public static function xmlCozumle(string $xml): array
    {
        if (trim($xml) === '') {
            return [];
        }

        $dom = new DOMDocument();
        $onceki = libxml_use_internal_errors(true);
        // LIBXML_NONET: dış kaynak yüklenmez; LIBXML_NOENT verilmez (XXE yok).
        $tamam = $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($onceki);

        if (!$tamam) {
            return [];
        }

        $segmentler = [];

        foreach ((new DOMXPath($dom))->query('/transcript/text') ?: [] as $dugum) {
            if (!$dugum instanceof \DOMElement) {
                continue;
            }

            $metin = html_entity_decode($dugum->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $metin = trim((string) preg_replace('/\s+/u', ' ', strip_tags($metin)));

            if ($metin === '') {
                continue;
            }

            $bas = (int) round((float) $dugum->getAttribute('start') * 1000);
            $sure = (int) round((float) $dugum->getAttribute('dur') * 1000);

            $segmentler[] = new Segment($bas, $bas + max(0, $sure), $metin);
        }

        return $segmentler;
    }

    /* =================================================================
     *  ORTAK
     * ============================================================== */

    private static function durumuDenetle(HttpYanit $yanit, string $ne): void
    {
        if ($yanit->agHatasi()) {
            throw new ProviderUnavailable($yanit->zamanAsimi() ? 'zaman_asimi' : 'baglanti', $ne . ': ' . $yanit->hata, true);
        }

        if ($yanit->durum === 429) {
            throw new RateLimited($ne . ': HTTP 429');
        }

        if (!$yanit->basarili()) {
            throw new ProviderUnavailable('youtube_hatasi', $ne . ': HTTP ' . $yanit->durum, $yanit->durum >= 500);
        }
    }
}
