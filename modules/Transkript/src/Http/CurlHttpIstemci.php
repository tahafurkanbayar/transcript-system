<?php
/**
 * =====================================================================
 *  CurlHttpIstemci – HttpIstemci'nin PHP curl ile gerçek uygulaması
 * ---------------------------------------------------------------------
 *  Sertifika doğrulaması AÇIK kalır: kapatmak, istekteki anahtarı
 *  araya giren birine teslim etmek olur. Windows / XAMPP'ta curl
 *  "SSL certificate problem" derse php.ini'deki curl.cainfo'yu
 *  düzeltin, doğrulamayı kapatmayın.
 *
 *  Yönlendirmeler izlenir ama en fazla 3 kez ve yalnızca http(s).
 *  Anahtarı taşıyan başlıklar yönlendirmede başka bir alan adına
 *  taşınmasın diye anahtar URL'ye değil başlığa konur (bkz.
 *  YoutubeMetadata) — curl başka bir hosta yönlendirmede özel
 *  başlıkları da gönderir, bu yüzden anahtarlı isteklerde
 *  yönlendirme kapatılabilir ($yonlendirmeIzle).
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Http;

final class CurlHttpIstemci implements HttpIstemci
{
    public function __construct(
        private readonly int $zamanAsimi = 20,
        private readonly bool $yonlendirmeIzle = true,
        private readonly string $kullaniciAjani = 'Mozilla/5.0 (compatible; transcript-system/0.1)',
    ) {
    }

    public function get(string $adres, array $basliklar = []): HttpYanit
    {
        return $this->istek($adres, null, $basliklar);
    }

    public function post(string $adres, string $govde, array $basliklar = []): HttpYanit
    {
        return $this->istek($adres, $govde, $basliklar);
    }

    /** @param array<int,string> $basliklar */
    private function istek(string $adres, ?string $govde, array $basliklar): HttpYanit
    {
        $curl = curl_init($adres);
        $gelen = [];

        $secenekler = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->zamanAsimi,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->zamanAsimi),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => $this->yonlendirmeIzle,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT      => $this->kullaniciAjani,
            CURLOPT_ENCODING       => '',   // gzip / deflate kabul et, curl açar
            CURLOPT_HTTPHEADER     => $basliklar,
            CURLOPT_HEADERFUNCTION => static function ($tutamac, string $satir) use (&$gelen): int {
                $parca = explode(':', $satir, 2);

                if (count($parca) === 2) {
                    $gelen[strtolower(trim($parca[0]))] = trim($parca[1]);
                }

                return strlen($satir);
            },
        ];

        if (defined('CURLOPT_REDIR_PROTOCOLS')) {
            $secenekler[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }

        if ($govde !== null) {
            $secenekler[CURLOPT_POST]       = true;
            $secenekler[CURLOPT_POSTFIELDS] = $govde;
        }

        curl_setopt_array($curl, $secenekler);

        $ham   = curl_exec($curl);
        $durum = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $hata  = curl_error($curl);
        $no    = curl_errno($curl);

        curl_close($curl);

        return new HttpYanit($durum, is_string($ham) ? $ham : '', $gelen, $hata, $no);
    }
}
