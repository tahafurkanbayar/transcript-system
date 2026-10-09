<?php
/**
 * =====================================================================
 *  Pipeline – AI aşamaları: map (parça), reduce (birleştirme), maddeler
 * ---------------------------------------------------------------------
 *  docs/TEKNIK_RAPOR.md 6. bölüm. İş sınıfları yalnızca bu servisi
 *  çağırır; hangi modelin, hangi istemin kullanıldığını bilmez.
 *
 *      map     parça blokları "[hh:mm:ss] metin" satırları olarak
 *              prompts/parca_analizi.<sürüm>.txt ile gider
 *      reduce  parça JSON'ları (transcript değil) tek çağrıda
 *              prompts/birlestirme.<sürüm>.txt ile birleşir
 *
 *  HER ÇAĞRI (onarım dahil) hemen ai_kullanim_kaydi'na yazılır:
 *  model, token, maliyet, süre ve durum (basarili / gecersiz / hata).
 *  İş sonradan çökse de harcanan token kayıtta kalır.
 *
 *  JSON GÜVENLİĞİ: sağlayıcının şema zorlaması açılır (sendJson), yanıt
 *  ResultValidator'dan geçer. Geçmezse modele BİR KEZ onarım isteği
 *  gider (bozuk yanıt + sorun listesi); o da geçmezse AiHatasi
 *  "yanit_cozulemedi" (kalıcı): parça düşer, rapor onsuz tamamlanır.
 *  Model yanıtlamayı reddederse "reddedildi" (kalıcı).
 *
 *  ogeler(): reduce çıktısını transkript_analiz_ogeleri satırlarına çevirir.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Services;

use App\Core\Env;
use Closure;
use Modules\Transkript\Ai\Ai;
use Modules\Transkript\Ai\AiHatasi;
use Modules\Transkript\Ai\Provider;
use Modules\Transkript\Ayarlar;
use Modules\Transkript\Repositories\AiKullanimRepository;
use Modules\Transkript\Transcript\Segment;
use Modules\Transkript\Zaman;

final class Pipeline
{
    private const ONEM = [
        'critical' => 'kritik', 'kritik' => 'kritik',
        'important' => 'onemli', 'onemli' => 'onemli', 'önemli' => 'onemli',
        'useful' => 'faydali', 'faydali' => 'faydali', 'faydalı' => 'faydali',
        'low' => 'dusuk', 'dusuk' => 'dusuk', 'düşük' => 'dusuk',
    ];

    /* =================================================================
     *  ŞEMALAR (JSON Schema alt kümesi; sağlayıcıya göre çevrilir)
     * ============================================================== */

    private const METIN = ['type' => 'string'];
    private const ZAMAN = ['type' => 'string', 'description' => 'hh:mm:ss, transcript satırlarındaki zaman damgalarından biri'];

    /** Map ve reduce'un ortak madde listeleri. */
    private const MADDELER = [
        'important_points' => ['type' => 'array', 'items' => [
            'type'       => 'object',
            'properties' => ['text' => self::METIN, 'at' => self::ZAMAN, 'importance' => ['type' => 'string', 'enum' => ResultValidator::ONEM]],
            'required'   => ['text', 'at', 'importance'],
        ]],
        'technical_information' => ['type' => 'array', 'items' => [
            'type'       => 'object',
            'properties' => ['name' => self::METIN, 'category' => self::METIN, 'description' => self::METIN, 'at' => self::ZAMAN],
            'required'   => ['name', 'category', 'description', 'at'],
        ]],
        'claims' => ['type' => 'array', 'items' => [
            'type'       => 'object',
            'properties' => ['text' => self::METIN, 'at' => self::ZAMAN, 'speaker_confidence' => ['type' => 'string', 'enum' => ResultValidator::GUVEN]],
            'required'   => ['text', 'at', 'speaker_confidence'],
        ]],
        'ideas' => ['type' => 'array', 'items' => [
            'type'       => 'object',
            'properties' => ['text' => self::METIN, 'type' => self::METIN, 'at' => self::ZAMAN],
            'required'   => ['text', 'type', 'at'],
        ]],
        'timestamps' => ['type' => 'array', 'items' => [
            'type'       => 'object',
            'properties' => ['at' => self::ZAMAN, 'label' => self::METIN],
            'required'   => ['at', 'label'],
        ]],
    ];

    public const PARCA_SEMASI = [
        'type'       => 'object',
        'properties' => ['topic' => self::METIN, 'summary' => self::METIN] + self::MADDELER,
        'required'   => ['topic', 'summary', 'important_points', 'technical_information', 'claims', 'ideas', 'timestamps'],
    ];

    public const BIRLESTIRME_SEMASI = [
        'type'       => 'object',
        'properties' => [
            'summary'          => self::METIN,
            'detailed_summary' => self::METIN,
            'topics'           => ['type' => 'array', 'items' => [
                'type'       => 'object',
                'properties' => ['title' => self::METIN, 'start' => self::ZAMAN, 'end' => self::ZAMAN, 'summary' => self::METIN],
                'required'   => ['title', 'start', 'end', 'summary'],
            ]],
        ] + self::MADDELER + [
            'recommendations' => ['type' => 'array', 'items' => [
                'type'       => 'object',
                'properties' => ['text' => self::METIN],
                'required'   => ['text'],
            ]],
        ],
        'required' => ['summary', 'detailed_summary', 'topics', 'important_points', 'technical_information',
                       'claims', 'ideas', 'timestamps', 'recommendations'],
    ];

    /**
     * @param ?Closure(string): array{0: Provider, 1: string} $saglayici
     *        Aşama → [sağlayıcı, sağlayıcı adı]. null → config + .env
     *        (Ai::make). Testler sahte sağlayıcı verir.
     */
    public function __construct(
        private readonly AiKullanimRepository $kullanim,
        private readonly int $analizId,
        private readonly ResultValidator $dogrulayici = new ResultValidator(),
        private readonly ?Closure $saglayici = null,
    ) {
    }

    /* =================================================================
     *  MAP
     * ============================================================== */

    /**
     * @param array<string,mixed> $parca        transkript_parcalar satırı
     * @param list<Segment>       $bloklar      Cleaner::hazirla() çıktısı (istemin satırları)
     * @param list<int>           $baslangiclar parçadaki gerçek segment başlangıçları (zaman yaslama)
     * @param array<string,mixed> $baglam       baslik, kanal, dil, toplam (parça sayısı)
     * @return array{sonuc: array<string,mixed>, model: string, uyarilar: list<string>}
     */
    public function parcaAnalizEt(array $parca, array $bloklar, array $baslangiclar, array $baglam): array
    {
        $basMs = (int) $parca['baslangic_ms'];
        $bitMs = (int) $parca['bitis_ms'];
        $istem = Istem::yukle('parca_analizi');

        $satirlar = array_map(
            static fn (Segment $b): string => '[' . Zaman::yaz($b->baslangicMs) . '] '
                . ($b->konusmaci !== null && $b->konusmaci !== '' ? '(' . $b->konusmaci . ') ' : '') . $b->metin,
            $bloklar
        );

        $kullanici = $istem->kullanici([
            'baslik'     => Istem::veri(self::bilgi($baglam['baslik'] ?? null)),
            'kanal'      => Istem::veri(self::bilgi($baglam['kanal'] ?? null)),
            'dil'        => Istem::veri(self::bilgi($baglam['dil'] ?? null)),
            'sira'       => (int) $parca['sira'],
            'toplam'     => max((int) $parca['sira'], (int) ($baglam['toplam'] ?? 0)),
            'baslangic'  => Zaman::yaz($basMs),
            'bitis'      => Zaman::yaz($bitMs),
            'transcript' => Istem::veri(implode("\n", $satirlar)),
        ]);

        return $this->jsonIste('map', $istem, $kullanici, self::PARCA_SEMASI,
            fn (array $veri): array => $this->dogrulayici->parca($veri, $basMs, $bitMs, $baslangiclar));
    }

    /* =================================================================
     *  REDUCE
     * ============================================================== */

    /**
     * @param list<array<string,mixed>> $parcaSonuclari doğrulanmış map sonuçları, zaman sırasıyla
     * @param list<int>                 $baslangiclar   transcript'in bütün segment başlangıçları
     * @param array<string,mixed>       $baglam         baslik, kanal, eksik (list<string> aralık)
     * @return array{sonuc: array<string,mixed>, model: string, uyarilar: list<string>}
     */
    public function birlestir(array $parcaSonuclari, int $bitMs, array $baslangiclar, array $baglam): array
    {
        $istem = Istem::yukle('birlestirme');

        // Bölüm başına bir JSON satırı. JSON_HEX_TAG "<" ">" işaretlerini
        // <'ye çevirir: AI çıktısı veri bloğunu kapatamaz.
        $bolumler = array_map(
            static fn (array $s): string => (string) json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_INVALID_UTF8_SUBSTITUTE),
            $parcaSonuclari
        );

        $eksik = array_values(array_filter((array) ($baglam['eksik'] ?? []), 'is_string'));

        $kullanici = $istem->kullanici([
            'baslik'       => Istem::veri(self::bilgi($baglam['baslik'] ?? null)),
            'kanal'        => Istem::veri(self::bilgi($baglam['kanal'] ?? null)),
            'sure'         => Zaman::yaz($bitMs),
            'bolum_sayisi' => count($parcaSonuclari),
            'eksik'        => $eksik === [] ? 'yok' : implode(', ', $eksik),
            'bolumler'     => implode("\n", $bolumler),
        ]);

        return $this->jsonIste('reduce', $istem, $kullanici, self::BIRLESTIRME_SEMASI,
            fn (array $veri): array => $this->dogrulayici->birlestirme($veri, $bitMs, $baslangiclar));
    }

    /* =================================================================
     *  İSTEK + DOĞRULAMA + TEK ONARIM
     * ============================================================== */

    /**
     * @param  array<string,mixed>                                          $sema
     * @param  Closure(array<string,mixed>): array{sonuc: array<string,mixed>, uyarilar: list<string>} $dogrula
     * @return array{sonuc: array<string,mixed>, model: string, uyarilar: list<string>}
     *
     * @throws AiHatasi
     */
    private function jsonIste(string $asama, Istem $istem, string $kullanici, array $sema, Closure $dogrula): array
    {
        [$saglayici, $ad] = $this->saglayici !== null ? ($this->saglayici)($asama) : self::saglayiciUret($asama);

        $ek       = $ad === 'gemini' ? (array) Ayarlar::get('ai.' . $asama . '.gemini_ek', []) : [];
        $mesajlar = [['role' => 'user', 'content' => $kullanici]];
        $enCok    = max(500, (int) Ayarlar::get('ai.onarim_en_cok_karakter', 6000));

        for ($deneme = 1; ; $deneme++) {
            $baslangic = hrtime(true);

            try {
                $yanit = $saglayici->sendJson($mesajlar, $istem->sistem, $sema, $ek);
            } catch (AiHatasi $e) {
                $this->kaydet($ad, $saglayici->model(), null, $baslangic, 'hata');

                throw $e;
            }

            $durum = 'gecersiz';

            try {
                if ($yanit['refusal'] !== null) {
                    $durum = 'reddedildi';

                    throw new AiHatasi(sprintf('Model yanıtlamadı (%s, %s).', $asama, $yanit['refusal']), 'reddedildi');
                }

                $veri = ResultValidator::ayikla($yanit['text']) ?? throw new GecersizSonuc(['Yanıt geçerli bir JSON nesnesi değil.']);
                $sonuc = $dogrula($veri);
                $durum = 'basarili';

                return $sonuc + ['model' => $yanit['model']];
            } catch (GecersizSonuc $e) {
                if ($deneme >= 2) {
                    throw new AiHatasi(sprintf('%s yanıtı onarımdan sonra da geçersiz: %s', $asama, $e->getMessage()), 'yanit_cozulemedi');
                }

                $mesajlar[] = ['role' => 'assistant', 'content' => $yanit['text'] !== '' ? mb_substr($yanit['text'], 0, $enCok) : '(boş yanıt)'];
                $mesajlar[] = ['role' => 'user', 'content' => self::onarimIstegi($e->sorunlar, $yanit['stop_reason'] === 'max_tokens')];
            } finally {
                $this->kaydet($ad, (string) $yanit['model'], $yanit, $baslangic, $durum);
            }
        }
    }

    /** @param list<string> $sorunlar */
    private static function onarimIstegi(array $sorunlar, bool $kesildi): string
    {
        return 'Önceki yanıtın kullanılamadı: ' . implode(' ', $sorunlar)
            . ($kesildi ? ' Yanıt uzunluk sınırında kesildi; maddeleri kısaltarak yaz.' : '')
            . ' Aynı analizi yalnızca şemaya uygun, geçerli TEK bir JSON nesnesi olarak yeniden döndür;'
            . ' açıklama ya da kod bloğu ekleme.';
    }

    /** @param array<string,mixed>|null $yanit */
    private function kaydet(string $saglayici, string $model, ?array $yanit, int|float $baslangic, string $durum): void
    {
        $this->kullanim->kaydet([
            'saglayici'   => $saglayici,
            'model'       => $model,
            'giris_token' => (int) ($yanit['usage']['input_tokens'] ?? 0),
            'cikis_token' => (int) ($yanit['usage']['output_tokens'] ?? 0),
            'maliyet_usd' => (float) ($yanit['cost'] ?? 0.0),
            'sure_ms'     => (int) ((hrtime(true) - $baslangic) / 1_000_000),
        ], 'analiz', $this->analizId, $durum);
    }

    /**
     * config (ai.<aşama>) + .env'den sağlayıcı.
     *
     * @return array{0: Provider, 1: string}
     */
    private static function saglayiciUret(string $asama): array
    {
        $ayar = (array) Ayarlar::get('ai.' . $asama, []);
        $ad   = strtolower(trim((string) ($ayar['saglayici'] ?? '')));
        $ad   = $ad !== '' ? $ad : Ai::provider();

        $model = trim((string) ($ayar['model'] ?? ''));
        // AI_MODEL yalnız .env'deki sağlayıcıya aittir; aşama başka sağlayıcıdaysa onun varsayılanı.
        $model = $model !== '' ? $model : ($ad === Ai::provider() ? trim(Env::get('AI_MODEL')) : '');

        return [
            Ai::make($ad, $model, (int) ($ayar['max_token'] ?? 8192), (int) ($ayar['zaman_asimi'] ?? 120)),
            $ad,
        ];
    }

    private static function bilgi(mixed $deger): string
    {
        $metin = ResultValidator::metin($deger, 300);

        return $metin !== '' ? $metin : 'bilinmiyor';
    }

    /* =================================================================
     *  RAPOR MADDELERİ
     * ============================================================== */

    /**
     * Reduce çıktısını transkript_analiz_ogeleri satırlarına çevirir.
     * Bozuk madde atlanır; zaman okunamazsa null kalır (konu ve nokta
     * zamansız da gösterilebilir).
     *
     * @param  array<string,mixed> $rapor
     * @return list<array<string,mixed>>
     */
    public static function ogeler(array $rapor): array
    {
        $ogeler = [];

        $ekle = static function (string $tur, mixed $liste, callable $donustur) use (&$ogeler): void {
            if (!is_array($liste)) {
                return;
            }

            foreach ($liste as $madde) {
                if (!is_array($madde)) {
                    continue;
                }

                $oge = $donustur($madde);

                if ($oge !== null && trim((string) $oge['baslik']) !== '') {
                    $ogeler[] = ['tur' => $tur] + $oge;
                }
            }
        };

        $ekle('konu', $rapor['topics'] ?? null, static fn (array $m): array => [
            'baslik'       => (string) ($m['title'] ?? $m['topic'] ?? ''),
            'aciklama'     => self::metinVeyaNull($m['summary'] ?? null),
            'baslangic_ms' => self::ms($m['start'] ?? $m['at'] ?? null),
            'bitis_ms'     => self::ms($m['end'] ?? null),
        ]);

        $ekle('nokta', $rapor['important_points'] ?? null, static fn (array $m): array => [
            'baslik'       => (string) ($m['text'] ?? ''),
            'baslangic_ms' => self::ms($m['at'] ?? null),
            'onem'         => self::ONEM[strtolower((string) ($m['importance'] ?? ''))] ?? null,
        ]);

        $ekle('teknik', $rapor['technical_information'] ?? null, static fn (array $m): array => [
            'baslik'       => (string) ($m['name'] ?? ''),
            'aciklama'     => self::metinVeyaNull($m['description'] ?? null),
            'kategori'     => self::metinVeyaNull($m['category'] ?? null),
            'baslangic_ms' => self::ms($m['at'] ?? null),
        ]);

        $ekle('iddia', $rapor['claims'] ?? null, static fn (array $m): array => [
            'baslik'       => (string) ($m['text'] ?? ''),
            'baslangic_ms' => self::ms($m['at'] ?? null),
            'dogrulama'    => self::metinVeyaNull($m['verification'] ?? $m['speaker_confidence'] ?? null),
        ]);

        $ekle('fikir', $rapor['ideas'] ?? null, static fn (array $m): array => [
            'baslik'       => (string) ($m['text'] ?? ''),
            'kategori'     => self::metinVeyaNull($m['type'] ?? null),
            'baslangic_ms' => self::ms($m['at'] ?? null),
        ]);

        $ekle('zaman', $rapor['timestamps'] ?? null, static fn (array $m): ?array => self::ms($m['at'] ?? null) === null ? null : [
            'baslik'       => (string) ($m['label'] ?? ''),
            'baslangic_ms' => self::ms($m['at'] ?? null),
        ]);

        return $ogeler;
    }

    private static function ms(mixed $zaman): ?int
    {
        return is_string($zaman) || is_int($zaman) ? Zaman::oku((string) $zaman) : null;
    }

    private static function metinVeyaNull(mixed $deger): ?string
    {
        return is_string($deger) && trim($deger) !== '' ? trim($deger) : null;
    }
}
