<?php
/**
 * =====================================================================
 *  TranscriptZinciri – sağlayıcıları sırayla dener, ilk başarılıyı döner
 * ---------------------------------------------------------------------
 *  Sıra config.php → transcript.saglayicilar (.env: TRANSCRIPT_PROVIDERS),
 *  kodda sabit değil. Kaynağın türünü desteklemeyen sağlayıcı atlanır.
 *
 *  HER BAŞARISIZLIKTA SIRADAKİNE GEÇİLİR (TranscriptNotFound,
 *  ProviderUnavailable, RateLimited). KaynakHatasi (gizli / silinmiş
 *  video) yakalanmaz: hiçbir sağlayıcı onu okuyamaz, zincir durur.
 *
 *  HEPSİ BAŞARISIZSA tek bir sonuç hatası, şu öncelikle:
 *      hız sınırı varsa           → RateLimited (iş bekleyip yeniden dener)
 *      geçici bir hata varsa       → ProviderUnavailable(transkript_api_hatasi, geçici)
 *      en az biri "transcript yok" → TranscriptNotFound (kalıcı)
 *      kalanı (kapalı, anahtarsız) → ProviderUnavailable(transkript_api_hatasi, kalıcı)
 *  Her sağlayıcının kendi hata kodu log'a yazılır ("transkript" kanalı).
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Transcript;

use App\Core\Log\Logger;
use Modules\Transkript\Ayarlar;
use Modules\Transkript\Kaynak\Kaynak;

final class TranscriptZinciri
{
    /** @param list<TranscriptProviderInterface> $saglayicilar */
    public function __construct(private readonly array $saglayicilar)
    {
    }

    public static function fromAyarlar(): self
    {
        $liste = [];

        foreach ((array) Ayarlar::get('transcript.saglayicilar', ['youtube', 'gemini']) as $ad) {
            $saglayici = self::uret((string) $ad);

            if ($saglayici === null) {
                Logger::warning('Bilinmeyen transcript sağlayıcısı atlandı', ['ad' => $ad], 'transkript');
                continue;
            }

            $liste[] = $saglayici;
        }

        return new self($liste);
    }

    public static function uret(string $ad): ?TranscriptProviderInterface
    {
        return match (strtolower(trim($ad))) {
            'youtube' => YouTubeTranscriptProvider::fromAyarlar(),
            'gemini'  => GeminiVideoProvider::fromAyarlar(),
            'harici'  => new ExternalTranscriptProvider(),
            'asr'     => new AsrProvider(),
            default   => null,
        };
    }

    /** @return list<string> */
    public function adlar(): array
    {
        return array_map(static fn (TranscriptProviderInterface $s): string => $s->name(), $this->saglayicilar);
    }

    /**
     * @param list<string> $tercihDiller
     *
     * @throws TranscriptHatasi hepsi başarısızsa (bkz. dosya başı)
     * @throws \Modules\Transkript\Kaynak\KaynakHatasi video gizli / silinmiş / bulunamadı
     */
    public function getir(Kaynak $kaynak, array $tercihDiller): TranscriptResult
    {
        $hatalar = [];

        foreach ($this->saglayicilar as $saglayici) {
            if (!$saglayici->supports($kaynak)) {
                continue;
            }

            try {
                $sonuc = $saglayici->fetch($kaynak, $tercihDiller);

                Logger::info('Transcript alındı', [
                    'kaynak'      => $kaynak->id,
                    'saglayici'   => $sonuc->saglayici,
                    'dil'         => $sonuc->dil,
                    'segment'     => count($sonuc->segmentler),
                    'once_denenen' => array_map(static fn (TranscriptHatasi $e): string => $e->kod, $hatalar),
                ], 'transkript');

                return $sonuc;
            } catch (TranscriptHatasi $e) {
                $hatalar[$saglayici->name()] = $e;

                Logger::warning('Transcript sağlayıcısı başarısız; sıradakine geçiliyor', [
                    'kaynak'    => $kaynak->id,
                    'saglayici' => $saglayici->name(),
                    'kod'       => $e->kod,
                    'ayrinti'   => $e->getMessage(),
                ], 'transkript');
            }
        }

        throw self::sonHata($hatalar, $kaynak);
    }

    /** @param array<string,TranscriptHatasi> $hatalar sağlayıcı adı => hata */
    public static function sonHata(array $hatalar, Kaynak $kaynak): TranscriptHatasi
    {
        if ($hatalar === []) {
            return new ProviderUnavailable('saglayici_yok', 'Bu kaynak türünü destekleyen açık sağlayıcı yok: ' . $kaynak->tur);
        }

        $ozet = implode('; ', array_map(
            static fn (string $ad, TranscriptHatasi $e): string => $ad . ': ' . $e->kod,
            array_keys($hatalar),
            $hatalar
        ));

        foreach ($hatalar as $e) {
            if ($e instanceof RateLimited) {
                return new RateLimited($ozet);
            }
        }

        foreach ($hatalar as $e) {
            if ($e->yenidenDenenebilir()) {
                return new ProviderUnavailable('transkript_api_hatasi', $ozet, true);
            }
        }

        foreach ($hatalar as $e) {
            if ($e instanceof TranscriptNotFound) {
                return new TranscriptNotFound($ozet);
            }
        }

        return new ProviderUnavailable('transkript_api_hatasi', $ozet, false);
    }
}
