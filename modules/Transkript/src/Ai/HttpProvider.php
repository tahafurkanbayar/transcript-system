<?php
/**
 * =====================================================================
 *  HttpProvider – sağlayıcıların paylaştığı HTTP katmanı
 * ---------------------------------------------------------------------
 *  Üç sağlayıcı da (Gemini, Claude, OpenAI uyumlu) aynı şeyi yapar:
 *  JSON gönder, JSON al, geçici hatalarda yeniden dene. Farklı olan
 *  yalnızca ADRES, BAŞLIKLAR ve GÖVDENİN BİÇİMİDİR.
 *
 *  Bu yüzden yeniden deneme, geri çekilme ve cURL ayarları BİR KEZ
 *  burada yazıldı. Her sağlayıcıya kopyalansaydı, ilk düzeltme
 *  üçünden ikisinde unutulurdu — kopyalanmış kodun klasik sonu.
 *
 *  Alt sınıflar yalnızca dört soruyu cevaplar:
 *      endpoint()  → nereye?
 *      headers()   → hangi kimlik başlığıyla?
 *      payload()   → hangi gövdeyle?
 *      normalize() → gelen yanıt ortak biçime nasıl çevrilir?
 * ---------------------------------------------------------------------
 *  Kaynak: CilginYazilim/ai-integration-system @338353d (MIT),
 *  app/Core/Ai/HttpProvider.php. Transkript modülüne uyarlandı.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Ai;

abstract class HttpProvider implements Provider
{
    /** Geçici hatalarda kaç kez yeniden denensin? */
    protected const MAX_RETRIES = 3;

    public function __construct(
        protected readonly string $apiKey,
        protected readonly string $modelName,
        protected readonly int $maxTokens = 4096,
        protected readonly int $timeout = 120,
    ) {
    }

    public function model(): string
    {
        return $this->modelName;
    }

    /* =================================================================
     *  ALT SINIFLARIN DOLDURDUĞU YERLER
     * ============================================================== */

    /** İsteğin gideceği tam adres. */
    abstract protected function endpoint(): string;

    /**
     * HTTP başlıkları (kimlik doğrulama dahil).
     *
     * @return array<int,string>
     */
    abstract protected function headers(): array;

    /**
     * @param  array<int,array{role:string,content:string}> $messages
     * @return array<string,mixed>
     */
    abstract protected function payload(array $messages, string $system): array;

    /**
     * @param  array<string,mixed> $response
     * @return array<string,mixed>
     */
    abstract protected function normalize(array $response): array;

    /** Sağlayıcıya özgü hata cümlesi. */
    abstract protected function describeError(int $status, string $raw): string;

    /**
     * Makine okunur hata kodu (bkz. AiHatasi). Varsayılan eşleme HTTP
     * durumuna bakar; durumu yanıltıcı olan sağlayıcı bunu ezer.
     */
    protected function errorCode(int $status, string $raw): string
    {
        return AiHatasi::kodFor($status);
    }

    /* =================================================================
     *  ANA ÇAĞRI
     * ============================================================== */

    public function send(array $messages, string $system = ''): array
    {
        return $this->normalize($this->request($this->payload($messages, $system)));
    }

    /**
     * JSON yanıt (modüle eklendi): aynı istek, üzerine sağlayıcının
     * JSON / şema ayarı (jsonPayload). Çok turlu mesaj desteklenir;
     * onarım isteği bozuk yanıtı "assistant" mesajı olarak geri verir.
     */
    public function sendJson(array $messages, string $system, array $sema, array $ek = []): array
    {
        return $this->normalize($this->request($this->jsonPayload($this->payload($messages, $system), $sema, $ek)));
    }

    /**
     * Sağlayıcının JSON modu / şema zorlaması. Varsayılan: hiçbir şey
     * eklenmez (yanıt yine ResultValidator'dan geçer).
     *
     * @param  array<string,mixed> $payload
     * @param  array<string,mixed> $sema
     * @param  array<string,mixed> $ek
     * @return array<string,mixed>
     */
    protected function jsonPayload(array $payload, array $sema, array $ek): array
    {
        return $payload;
    }

    /* =================================================================
     *  HTTP + YENİDEN DENEME
     * =================================================================
     *  HANGİ HATALAR YENİDEN DENENİR?
     *    429 Too Many Requests → hız sınırı (kesinlikle geçici)
     *    529 Overloaded        → servis yoğun (geçici)
     *    500/502/503/504       → sunucu tarafı geçici arıza
     *
     *  HANGİLERİ DENENMEZ?
     *    400 → isteğiniz bozuk; aynısını tekrar atmak aynı hatayı verir
     *    401/403 → anahtar geçersiz veya yetkisiz
     *    404 → model adı yanlış
     *  Bunları yeniden denemek yalnızca zaman kaybettirir.
     *
     *  ÜCRETSİZ KATMANDA BU ÖNEMLİDİR: Gemini'nin ücretsiz katmanı
     *  dakikada birkaç istekle sınırlıdır ve sınıra çarpmak
     *  OLAĞANDIR, arıza değil. Yeniden deneme olmasaydı, örneği
     *  deneyen kişi ilk hızlı iki soruda "bozuk" sanırdı.
     *
     * @param  array<string,mixed> $payload
     * @return array<string,mixed>
     */
    protected function request(array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($body === false) {
            throw new AiHatasi('İstek JSON’a çevrilemedi.', 'istek_hatasi');
        }

        $attempt = 0;

        while (true) {
            $attempt++;

            [$status, $raw, $headers, $curlError, $curlErrno] = $this->curl($body);

            if ($curlError !== '') {
                // Ağ hatası da geçicidir; aynı kurallarla yeniden deniyoruz.
                if ($attempt <= static::MAX_RETRIES) {
                    $this->backoff($attempt, null);

                    continue;
                }

                // 28 = CURLE_OPERATION_TIMEDOUT: zaman aşımı ayrı koddur (9. adım).
                throw new AiHatasi(
                    'Bağlantı hatası: ' . $curlError,
                    $curlErrno === 28 ? 'zaman_asimi' : 'baglanti'
                );
            }

            if ($status >= 200 && $status < 300) {
                $decoded = json_decode($raw, true);

                if (!is_array($decoded)) {
                    throw new AiHatasi('API yanıtı çözümlenemedi.', 'yanit_cozulemedi', $status);
                }

                return $decoded;
            }

            $retryable = $this->retryable($status, $raw);

            if ($retryable && $attempt <= static::MAX_RETRIES) {
                /* SUNUCU "NE KADAR BEKLE" DERSE ONA UYULUR.
                 * Retry-After başlığı varken kendi hesabımızı
                 * dayatmak, hız sınırına tekrar çarpmak demektir. */
                $this->backoff($attempt, $this->retryAfter($headers) ?? $this->retryAfterFromBody($raw));

                continue;
            }

            throw new AiHatasi($this->describeError($status, $raw), $this->errorCode($status, $raw), $status);
        }
    }

    /**
     * Tek bir HTTP isteği.
     *
     * @return array{0:int,1:string,2:array<string,string>,3:string,4:int}
     *         [durum kodu, gövde, başlıklar, curl hatası, curl hata numarası]
     */
    private function curl(string $body): array
    {
        $curl = curl_init($this->endpoint());

        $headers = [];

        curl_setopt_array($curl, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,

            /* Sertifika doğrulaması AÇIK kalmalı. Kapatmak, API
             * anahtarınızı araya giren birine teslim etmek olur.
             * "Çalışmıyor" diye kapatmak yerine CA paketini
             * güncelleyin. */
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,

            CURLOPT_HTTPHEADER => $this->headers(),

            // Yanıt başlıklarını topluyoruz (Retry-After için).
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
                $parts = explode(':', $line, 2);

                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);

        $raw    = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error  = curl_error($curl);
        $errno  = curl_errno($curl);

        curl_close($curl);

        return [$status, is_string($raw) ? $raw : '', $headers, $error, $errno];
    }

    /** 429, 529 ve 5xx yeniden denenir; alt sınıf daraltabilir (ör. günlük kota). */
    protected function retryable(int $status, string $raw): bool
    {
        return $status === 429 || $status === 529 || $status >= 500;
    }

    /**
     * Bekleme süresi başlıkta değil gövdedeyse (Gemini 429:
     * error.details[].retryDelay = "37s") alt sınıf okur.
     */
    protected function retryAfterFromBody(string $raw): ?int
    {
        return null;
    }

    /** @param array<string,string> $headers */
    private function retryAfter(array $headers): ?int
    {
        $value = $headers['retry-after'] ?? '';

        return ctype_digit($value) ? (int) $value : null;
    }

    /**
     * Üstel geri çekilme + rastgele sapma (jitter).
     *
     * NEDEN RASTGELE SAPMA? Aynı anda sınıra çarpan on istemci
     * birebir aynı süreyi beklerse, hepsi aynı anda tekrar dener ve
     * sınıra yine birlikte çarpar ("gök gürültüsü sürüsü" sorunu).
     * Küçük bir rastgelelik bu dalgayı dağıtır.
     */
    protected function backoff(int $attempt, ?int $retryAfter): void
    {
        if ($retryAfter !== null) {
            sleep(min(60, $retryAfter));

            return;
        }

        // 1s, 2s, 4s … + 0-500 ms sapma
        $seconds = min(30, 2 ** ($attempt - 1));

        usleep($seconds * 1000000 + random_int(0, 500000));
    }

    /* =================================================================
     *  ORTAK YARDIMCILAR
     * ============================================================== */

    /**
     * Boş bir kullanım sayacı. Sağlayıcı bazı alanları hiç
     * döndürmez; sıfırla başlayıp üzerine yazmak, "tanımsız dizin"
     * hatalarını baştan keser.
     *
     * @return array<string,int>
     */
    protected static function emptyUsage(): array
    {
        return [
            'input_tokens'  => 0,
            'output_tokens' => 0,
            'cache_write'   => 0,
            'cache_read'    => 0,
        ];
    }

    /**
     * 1 milyon jeton başına fiyattan bu isteğin maliyetini hesaplar.
     *
     * @param array<string,int>                        $usage
     * @param array{input:float,output:float}|null     $rates
     */
    protected static function priceFor(array $usage, ?array $rates): float
    {
        if ($rates === null) {
            return 0.0;
        }

        return ($usage['input_tokens'] / 1000000) * $rates['input']
             + ($usage['output_tokens'] / 1000000) * $rates['output'];
    }
}
