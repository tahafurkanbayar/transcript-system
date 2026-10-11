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

use Closure;

abstract class HttpProvider implements Provider
{
    /** Geçici hatalarda kaç kez yeniden denensin? */
    protected const MAX_RETRIES = 3;

    /** Bağlantı kurma süresi sınırı (sn); 0: cURL varsayılanı. Toplam süre ayrıca $timeout. */
    protected const BAGLANTI_ZAMAN_ASIMI = 0;

    /** null: MAX_RETRIES. Yedek zincirinde son halka değilse 0 (bkz. yenidenDenemeSiniri). */
    private ?int $denemeSiniri = null;

    /**
     * null: gerçek cURL. Testler sahte yanıt verir, ağa gidilmez (tasiyici()).
     *
     * @var ?Closure(string): array{0:int,1:string,2:array<string,string>,3:string,4:int}
     */
    private ?Closure $tasiyici = null;

    /**
     * Uzun istek sürerken çağrılır (kuyruk işinin nabzı, Jobs\IsNabzi):
     * cURL'ün ilerleme geri çağrısından, saniyede birkaç kez. Kanca kendisi
     * seyreltir. null: kanca yok.
     */
    private static ?Closure $nabiz = null;

    /**
     * Son isteğin ölçümleri: akis, http, toplam_ms, ilk_token_ms (akışta ilk
     * veri), keepalive (akışta yorum satırı), bosta_kesildi.
     *
     * @var array<string,mixed>
     */
    protected array $olcum = [];

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

    /**
     * Geçici hatada (429, 5xx, ağ) aynı istek içinde en çok kaç kez
     * yeniden denensin. YedekliSaglayici, zincirde arkasında başka
     * sağlayıcı olan halkayı 0'a çeker: kotası dolan servisi dakikalarca
     * beklemek yerine hemen sıradakine geçilir. Son halka varsayılanla
     * (MAX_RETRIES, Retry-After uyumu) çalışır.
     */
    public function yenidenDenemeSiniri(int $kez): static
    {
        $this->denemeSiniri = max(0, $kez);

        return $this;
    }

    private function denemeSiniri(): int
    {
        return $this->denemeSiniri ?? static::MAX_RETRIES;
    }

    /**
     * Bu HTTP durumunda en çok kaç yeniden deneme. Varsayılan: hepsi aynı
     * (denemeSiniri). EVREN 503'ü (anlık doluluk) zincirde de 3 kez dener.
     */
    protected function durumDenemeSiniri(int $status): int
    {
        return $this->denemeSiniri();
    }

    /**
     * HTTP isteğini cURL yerine verilen işlev yapar: gövdeyi alır,
     * [durum, yanıt gövdesi, başlıklar (küçük harf), cURL hatası, hata no]
     * döndürür. Birim testleri gerçek API'ye gitmeden hata kodlarını ve
     * yedeğe geçişi böyle dener (CLAUDE.md, kural 10).
     */
    public function tasiyici(?Closure $tasiyici): static
    {
        $this->tasiyici = $tasiyici;

        return $this;
    }

    public static function nabizKancasi(?Closure $kanca): void
    {
        self::$nabiz = $kanca;
    }

    /** @var ?Closure(float): void testler gerçekten beklemesin: bekleme süresi (sn) buraya gider */
    private static ?Closure $uyuyucu = null;

    public static function uyuyucu(?Closure $islev): void
    {
        self::$uyuyucu = $islev;
    }

    protected static function uyu(float $saniye): void
    {
        if (self::$uyuyucu !== null) {
            (self::$uyuyucu)($saniye);

            return;
        }

        usleep((int) ($saniye * 1_000_000));
    }

    /** @return array<string,mixed> son isteğin ölçümleri (bkz. $olcum) */
    public function sonOlcum(): array
    {
        return $this->olcum;
    }

    /* =================================================================
     *  AKIŞ (SSE) — İSTEĞE BAĞLI, VARSAYILAN KAPALI
     * =================================================================
     *  Akışı açan sağlayıcı (EVREN) dört kancayı doldurur. Kapalıyken
     *  istek eskisi gibidir: tek CURLOPT_TIMEOUT, gövde sonunda okunur.
     *  Açıkken gövde parça parça gelir; BOŞTA KALMA sınırı ayrı ölçülür:
     *  bostaSiniri() saniye boyunca hiç veri satırı ("data: …") gelmezse
     *  istek kesilir (":" ile başlayan keep-alive satırları veri sayılmaz).
     * ============================================================== */

    protected function akis(): bool
    {
        return false;
    }

    /** Akışta veri gelmeden geçebilecek en uzun süre (sn); 0 = sınır yok. */
    protected function bostaSiniri(): int
    {
        return 0;
    }

    /**
     * İLK veri satırına kadar beklenebilecek süre (sn): model kuyruktayken
     * sunucu keep-alive yollar, token yoktur. 0: bostaSiniri. Bu evrede hiç
     * bayt (keep-alive dahil) OLU_BAGLANTI_SN gelmezse bağlantı ölüdür.
     */
    protected function ilkTokenSiniri(): int
    {
        return 0;
    }

    /** İlk token beklenirken hiç bayt gelmeyen en uzun süre (sn): sonra bağlantı ölü sayılır. */
    protected const OLU_BAGLANTI_SN = 30;

    /** Bu isteğin toplam süre sınırı (sn); alt sınıf girdi boyutuna göre ölçekleyebilir. */
    protected function toplamSure(string $govde): int
    {
        return $this->timeout;
    }

    /**
     * Kesilen (zaman aşımı / boşta) istekte harcanan token TAHMİNİ:
     * ['giris_token', 'cikis_token', 'tahmini' => true]; bilinmiyorsa [].
     * Varsayılan: tahmin yok.
     *
     * @return array<string,mixed>
     */
    protected function kismiKullanim(string $istek, string $kismiYanit): array
    {
        return [];
    }

    /**
     * 2xx akış gövdesini tek yanıt dizisine çevirir; null → gövde düz JSON
     * sayılır (sunucu akışı yok saydıysa).
     *
     * @return array<string,mixed>|null
     */
    protected function akisiCoz(string $raw): ?array
    {
        return null;
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

    /**
     * Her HTTP yanıtının başlıkları (küçük harf), başarılı ya da hatalı.
     * Durumu başlıkta bildiren sağlayıcı (EVREN: kalan günlük token)
     * errorCode() / retryable()'dan önce burada okur.
     *
     * @param array<string,string> $headers
     */
    protected function basliklariIsle(int $status, array $headers): void
    {
    }

    /**
     * Hatayla birlikte yedek zincirine / işe giden bekleme önerisi (sn).
     * Günlük kotada null: bekleme gövdesindeki kısa süre (Gemini retryDelay)
     * kotanın açılışı değildir; zincir sıfırlama saatini kendisi hesaplar.
     * Sıfırlama saatini bilen sağlayıcı (EVREN) bunu ezer.
     */
    protected function hataBeklemesi(string $kod, ?int $bekle): ?int
    {
        return $kod === 'kota_doldu' ? null : $bekle;
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

            if ($this->tasiyici !== null) {
                $bas = hrtime(true);
                [$status, $raw, $headers, $curlError, $curlErrno] = ($this->tasiyici)($body);
                $this->olcum = ['akis' => $this->akis(), 'http' => $status, 'toplam_ms' => (int) ((hrtime(true) - $bas) / 1_000_000),
                                'ilk_token_ms' => null, 'keepalive' => 0, 'bosta_kesildi' => $curlErrno === 28 && str_starts_with($curlError, 'Boşta')];
            } else {
                [$status, $raw, $headers, $curlError, $curlErrno] = $this->curl($body);
            }

            if ($curlError === '') {
                $this->basliklariIsle($status, $headers);
            }

            if ($curlError !== '') {
                // Ağ hatası da geçicidir; aynı kurallarla yeniden deniyoruz.
                if ($attempt <= $this->denemeSiniri()) {
                    $this->backoff($attempt, null);

                    continue;
                }

                // 28 = CURLE_OPERATION_TIMEDOUT: zaman aşımı ayrı koddur (9. adım).
                // Ölçüm + sağlayıcının tahmin edebildiği kısmi kullanım (kesilen akış).
                throw new AiHatasi(
                    'Bağlantı hatası: ' . $curlError,
                    $curlErrno === 28 ? 'zaman_asimi' : 'baglanti',
                    0, null, null, null,
                    $this->olcum + $this->kismiKullanim($body, $raw)
                );
            }

            if ($status >= 200 && $status < 300) {
                $decoded = ($this->akis() ? $this->akisiCoz($raw) : null) ?? json_decode($raw, true);

                if (!is_array($decoded)) {
                    throw new AiHatasi('API yanıtı çözümlenemedi.', 'yanit_cozulemedi', $status);
                }

                return $decoded;
            }

            $retryable = $this->retryable($status, $raw);
            $bekle     = $this->retryAfter($headers) ?? $this->retryAfterFromBody($raw);

            // İki dakikadan uzun bekleme (ör. günlük sınır): istek içinde beklenmez, zincir / kuyruk erteler.
            if ($retryable && $attempt <= $this->durumDenemeSiniri($status) && ($bekle === null || $bekle <= 120)) {
                /* SUNUCU "NE KADAR BEKLE" DERSE ONA UYULUR.
                 * Retry-After başlığı varken kendi hesabımızı
                 * dayatmak, hız sınırına tekrar çarpmak demektir. */
                $this->backoff($attempt, $bekle);

                continue;
            }

            // Bekleme önerisi yedek zincirine gider: sağlayıcı o kadar dinlenir.
            $kod = $this->errorCode($status, $raw);

            throw new AiHatasi($this->describeError($status, $raw), $kod, $status, null, $this->hataBeklemesi($kod, $bekle));
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
        $curl    = curl_init($this->endpoint());
        $headers = [];
        $akis    = $this->akis();
        $bosta   = $akis ? max(0, $this->bostaSiniri()) : 0;
        $ilkSinir = $akis && $this->ilkTokenSiniri() > 0 ? $this->ilkTokenSiniri() : $bosta;
        $olu     = $akis && $this->ilkTokenSiniri() > 0 ? static::OLU_BAGLANTI_SN : 0;
        $bas     = microtime(true);
        $sonVeri = $bas;
        $sonBayt = $bas;
        $ilk     = null;
        $keep    = 0;
        $govde   = '';
        $tampon  = '';
        $kesildi = false;

        curl_setopt_array($curl, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => max(1, $this->toplamSure($body)),
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
            /* İlerleme: kuyruk nabzı (uzun istekte iş "ölü" sayılmasın) ve
             * akışta boşta kalma sınırı. 0 dışı dönüş isteği keser. */
            CURLOPT_NOPROGRESS       => false,
            CURLOPT_XFERINFOFUNCTION => static function () use (&$sonVeri, &$sonBayt, &$ilk, &$kesildi, $bosta, $ilkSinir, $olu, $bas): int {
                if (self::$nabiz !== null) {
                    (self::$nabiz)();
                }

                $simdi = microtime(true);

                // İlk token öncesi: kuyrukta bekleyiş (keep-alive geliyorsa) daha uzun; hiç bayt yoksa bağlantı ölü.
                $asim = $ilk === null
                    ? ($ilkSinir > 0 && $simdi - $bas > $ilkSinir) || ($olu > 0 && $simdi - $sonBayt > $olu)
                    : $bosta > 0 && $simdi - $sonVeri > $bosta;

                if ($asim) {
                    $kesildi = true;

                    return 1;
                }

                return 0;
            },
        ]);

        if ($akis) {
            // Gövde parça parça: veri satırı ("data: {…}") boşta sayacını sıfırlar; ":" keep-alive saymaz.
            curl_setopt($curl, CURLOPT_WRITEFUNCTION, static function ($handle, string $parca) use (&$govde, &$tampon, &$sonVeri, &$sonBayt, &$ilk, &$keep, $bas): int {
                $govde  .= $parca;
                $tampon .= $parca;
                $sonBayt = microtime(true);

                while (($i = strpos($tampon, "\n")) !== false) {
                    $satir  = rtrim(substr($tampon, 0, $i), "\r");
                    $tampon = (string) substr($tampon, $i + 1);

                    if ($satir !== '' && $satir[0] === ':') {
                        $keep++;
                    } elseif (SseAkisi::veriSatiriMi($satir)) {
                        $sonVeri = microtime(true);
                        $ilk   ??= (int) (($sonVeri - $bas) * 1000);
                    }
                }

                return strlen($parca);
            });
        }

        if (static::BAGLANTI_ZAMAN_ASIMI > 0) {
            curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, static::BAGLANTI_ZAMAN_ASIMI);
        }

        $raw    = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error  = curl_error($curl);
        $errno  = curl_errno($curl);

        curl_close($curl);

        $this->olcum = [
            'akis'          => $akis,
            'http'          => $status,
            'toplam_ms'     => (int) ((microtime(true) - $bas) * 1000),
            'ilk_token_ms'  => $ilk,
            'keepalive'     => $keep,
            'bosta_kesildi' => $kesildi,
        ];

        if ($kesildi) {
            // Boşta kalma zaman aşımı: 28 (CURLE_OPERATION_TIMEDOUT) gibi "zaman_asimi" sayılır.
            return [$status, $govde, $headers, $ilk === null
                ? sprintf('Boşta kalma: ilk token %d sn içinde gelmedi (ya da bağlantı %d sn sessiz kaldı)', $ilkSinir, $olu)
                : sprintf('Boşta kalma: %d sn boyunca yanıt verisi gelmedi', $bosta), 28];
        }

        return [$status, $akis ? $govde : (is_string($raw) ? $raw : ''), $headers, $error, $errno];
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

    /**
     * Retry-After (saniye). Yoksa X-RateLimit-Reset: OpenRouter'ın
     * ücretsiz günlük sınırı bunu milisaniye cinsinden zaman damgası
     * olarak verir (modüle eklendi).
     *
     * @param array<string,string> $headers
     */
    private function retryAfter(array $headers): ?int
    {
        $value = $headers['retry-after'] ?? '';

        if (ctype_digit($value)) {
            return (int) $value;
        }

        $reset = $headers['x-ratelimit-reset'] ?? '';

        if (ctype_digit($reset) && strlen($reset) >= 12) {
            $sn = intdiv((int) $reset, 1000) - time();

            return $sn > 0 ? $sn + 1 : null;
        }

        return null;
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
            self::uyu(min(60, $retryAfter));

            return;
        }

        // 1s, 2s, 4s … + 0-500 ms sapma
        $seconds = min(30, 2 ** ($attempt - 1));

        self::uyu($seconds + random_int(0, 500000) / 1_000_000);
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
