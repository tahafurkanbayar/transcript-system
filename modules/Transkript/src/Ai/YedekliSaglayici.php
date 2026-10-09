<?php
/**
 * =====================================================================
 *  YedekliSaglayici – sıralı birden çok yapay zekâyı tek sağlayıcı gibi sunar
 * ---------------------------------------------------------------------
 *  Ücretsiz katmanların günlük kotası çabuk doluyor: uzun bir video
 *  10–20 AI çağrısı açar ve Gemini'nin ücretsiz kotası bitince analiz
 *  "ai_kota_doldu" ile duruyordu. .env'de birden çok sağlayıcı
 *  tanımlanınca (AI_PROVIDERS=gemini,openrouter,groq) bu sınıf onları
 *  sırayla dener; biri hata verirse sıradakine geçer. Pipeline yine tek
 *  bir Provider görür.
 *
 *  DİNLENME: Kotası dolan ya da anahtarı reddedilen sağlayıcı bir süre
 *  "dinlenir" (Cache, işçiler arasında ortak). Sonraki çağrılar önce
 *  dinlenmeyenleri dener; her parçada bitmiş servise gidip vakit ve
 *  istek harcanmaz. Hepsi dinleniyorsa yine sırayla denenir (kota
 *  açılmış olabilir). Süreler config: ai.yedek.dinlenme_sn.
 *
 *  BEKLEMEDEN GEÇİŞ: Arkasında başka sağlayıcı olan halka 429 / 5xx'te
 *  aynı istek içinde beklemez (HttpProvider::yenidenDenemeSiniri(0));
 *  yalnız son halka Retry-After'a uyup yeniden dener.
 *
 *  HEPSİ DÜŞERSE: Denemelerden biri geçiciyse (rate_limit, sunucu,
 *  baglanti, zaman_asimi) o kodla fırlatılır, iş kuyrukta sonra yeniden
 *  dener. Hepsi günlük kotaysa "kota_doldu" (analiz durur, yarın). Aksi
 *  hâlde son hatanın kodu. Mesaj her sağlayıcının sebebini taşır; yalnız
 *  log'a yazılır.
 *
 *  Fikir: kullanıcının ci4 projesindeki FallbackLlmClient (aynı sahip).
 *  Fark: anahtarlar veritabanında değil, yalnız .env'de (CLAUDE.md, 5).
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Ai;

use App\Core\Cache\Cache;
use Closure;
use Modules\Transkript\Ayarlar;

final class YedekliSaglayici implements Provider
{
    private const DINLENME_ONEKI = 'transkript.ai_dinlenme.';

    /** Hata kodu → dinlenme (sn). Listede olmayan kod dinletmez (isteğe özgü: 400, 413). */
    public const DINLENME_SN = [
        'kota_doldu'       => 3600,
        'rate_limit'       => 60,
        'yetkisiz'         => 1800,
        'model_bulunamadi' => 1800,
        'sunucu'           => 120,
        'baglanti'         => 120,
        'zaman_asimi'      => 120,
    ];

    private const GECICI = ['rate_limit', 'sunucu', 'baglanti', 'zaman_asimi'];

    /** Son başarılı halka (sonAd / model). */
    private ?int $son = null;

    /** @var list<array{ad:string, model:string, kod:string, mesaj:string, sure_ms:int}> */
    private array $denemeler = [];

    /**
     * @param list<array{ad:string, saglayici:Provider, ek?:array<string,mixed>}> $halkalar öncelik sırasında
     * @param ?Closure(): int $saat test için zaman kaynağı
     */
    public function __construct(private readonly array $halkalar, private readonly ?Closure $saat = null)
    {
        if ($halkalar === []) {
            throw new AiHatasi('Yedek zincirinde hiç sağlayıcı yok.', 'ayar_eksik');
        }

        foreach ($halkalar as $i => $halka) {
            if ($i < count($halkalar) - 1 && $halka['saglayici'] instanceof HttpProvider) {
                $halka['saglayici']->yenidenDenemeSiniri(0);
            }
        }
    }

    public function send(array $messages, string $system = ''): array
    {
        return $this->dene(static fn (Provider $p, array $ek): array => $p->send($messages, $system));
    }

    public function sendJson(array $messages, string $system, array $sema, array $ek = []): array
    {
        // $ek yok sayılır: her halkanın kendi eki vardır (Gemini thinkingConfig yalnız Gemini'ye).
        return $this->dene(static fn (Provider $p, array $halkaEki): array => $p->sendJson($messages, $system, $sema, $halkaEki));
    }

    /** Son yanıtı veren sağlayıcının modeli; henüz yoksa ilk halkanınki. */
    public function model(): string
    {
        return $this->halkalar[$this->son ?? 0]['saglayici']->model();
    }

    /** Son yanıtı veren sağlayıcının adı ("gemini", "groq" …); kullanım kaydı bunu yazar. */
    public function sonAd(): string
    {
        return $this->halkalar[$this->son ?? 0]['ad'];
    }

    /** @return list<string> zincirin sırası */
    public function adlar(): array
    {
        return array_map(static fn (array $h): string => $h['ad'], $this->halkalar);
    }

    /**
     * Son çağrıda düşen sağlayıcılar (başarılı olan hariç). Pipeline
     * bunları kullanım kaydına "hata" satırı olarak yazar.
     *
     * @return list<array{ad:string, model:string, kod:string, mesaj:string, sure_ms:int}>
     */
    public function denemeler(): array
    {
        return $this->denemeler;
    }

    /* =================================================================
     *  DİNLENME (Cache; bütün işçiler aynı bilgiyi görür)
     * ============================================================== */

    /** Sağlayıcı dinleniyorsa kalan saniye; dinlenmiyorsa 0. */
    public static function dinlenmeKalan(string $ad, ?int $simdi = null): int
    {
        $bitis = Cache::get(self::DINLENME_ONEKI . $ad);

        return is_int($bitis) ? max(0, $bitis - ($simdi ?? time())) : 0;
    }

    public static function dinlendir(string $ad, string $kod, ?int $simdi = null): int
    {
        $sure = self::dinlenmeSuresi($kod);

        if ($sure > 0) {
            Cache::put(self::DINLENME_ONEKI . $ad, ($simdi ?? time()) + $sure, $sure);
        }

        return $sure;
    }

    public static function dinlenmeyiBitir(string $ad): void
    {
        Cache::forget(self::DINLENME_ONEKI . $ad);
    }

    public static function dinlenmeSuresi(string $kod): int
    {
        $ayar = (array) Ayarlar::get('ai.yedek.dinlenme_sn', []);

        return max(0, (int) ($ayar[$kod] ?? self::DINLENME_SN[$kod] ?? 0));
    }

    /* =================================================================
     *  ZİNCİR
     * ============================================================== */

    /**
     * @param Closure(Provider, array<string,mixed>): array<string,mixed> $cagri
     * @return array<string,mixed>
     */
    private function dene(Closure $cagri): array
    {
        $this->denemeler = [];
        $simdi = $this->saat !== null ? ($this->saat)() : time();

        // Önce dinlenmeyenler (sırası bozulmadan), sonra dinlenenler.
        $dinc = $dinlenen = [];

        foreach (array_keys($this->halkalar) as $i) {
            self::dinlenmeKalan($this->halkalar[$i]['ad'], $simdi) > 0 ? $dinlenen[] = $i : $dinc[] = $i;
        }

        $son = null;

        foreach ([...$dinc, ...$dinlenen] as $i) {
            $halka = $this->halkalar[$i];
            $bas   = hrtime(true);

            try {
                $yanit = $cagri($halka['saglayici'], (array) ($halka['ek'] ?? []));

                $this->son = $i;

                if (in_array($i, $dinlenen, true)) {
                    self::dinlenmeyiBitir($halka['ad']);
                }

                return $yanit;
            } catch (AiHatasi $e) {
                $son = $e;
                $this->denemeler[] = [
                    'ad'      => $halka['ad'],
                    'model'   => $halka['saglayici']->model(),
                    'kod'     => $e->kod,
                    'mesaj'   => $e->getMessage(),
                    'sure_ms' => (int) ((hrtime(true) - $bas) / 1_000_000),
                ];

                self::dinlendir($halka['ad'], $e->kod, $simdi);
            }
        }

        throw self::hepsiDustu($this->denemeler, $son);
    }

    /** @param list<array{ad:string, model:string, kod:string, mesaj:string, sure_ms:int}> $denemeler */
    public static function hepsiDustu(array $denemeler, ?AiHatasi $son): AiHatasi
    {
        $kodlar = array_column($denemeler, 'kod');
        $gecici = array_values(array_intersect($kodlar, self::GECICI));

        $kod = match (true) {
            $gecici !== []                                              => $gecici[0],
            $kodlar !== [] && array_unique($kodlar) === ['kota_doldu'] => 'kota_doldu',
            default                                                     => $son?->kod ?? 'ayar_eksik',
        };

        $ozet = implode(' | ', array_map(
            static fn (array $d): string => sprintf('%s (%s): %s', $d['ad'], $d['kod'], mb_strimwidth($d['mesaj'], 0, 160, '…')),
            $denemeler
        ));

        return new AiHatasi('Yedek zincirindeki bütün sağlayıcılar başarısız: ' . $ozet, $kod, $son?->httpDurumu ?? 0, $son);
    }
}
