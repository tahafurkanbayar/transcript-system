<?php
/**
 * =====================================================================
 *  Pipeline – AI aşamaları: map (parça), reduce (birleştirme), maddeler
 * ---------------------------------------------------------------------
 *  docs/TEKNIK_RAPOR.md 6. bölüm. İş sınıfları yalnızca bu servisi
 *  çağırır; hangi modelin, hangi istemin kullanıldığını bilmez.
 *
 *  6. ADIM: parcaAnalizEt() ve birlestir() SAHTEDİR — AI'a gitmez, para
 *  harcamaz. Parçanın ilk cümlesinden konu ve zaman damgası üretir; iş
 *  akışı (kuyruk, ilerleme, rapor yazımı) uçtan uca böyle sınanır. 7.
 *  adımda map / reduce istemleri ve ResultValidator ile değişecek.
 *
 *  ogeler() kalıcıdır: reduce çıktısını (rapordaki 8. bölüm biçimi)
 *  transkript_analiz_ogeleri satırlarına çevirir.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Services;

use Modules\Transkript\Transcript\Segment;
use Modules\Transkript\Zaman;

final class Pipeline
{
    public const SAHTE_MODEL = 'sahte (AI bağlı değil)';

    private const ONEM = [
        'critical' => 'kritik', 'kritik' => 'kritik',
        'important' => 'onemli', 'onemli' => 'onemli', 'önemli' => 'onemli',
        'useful' => 'faydali', 'faydali' => 'faydali', 'faydalı' => 'faydali',
        'low' => 'dusuk', 'dusuk' => 'dusuk', 'düşük' => 'dusuk',
    ];

    /**
     * Map: bir parçanın analizi (6. adımda sahte).
     *
     * @param array<string,mixed> $parca   transkript_parcalar satırı
     * @param list<Segment>       $bloklar Cleaner::hazirla() çıktısı
     * @return array{sonuc:array<string,mixed>,kullanim:list<array<string,mixed>>,model:string}
     */
    public function parcaAnalizEt(array $parca, array $bloklar): array
    {
        $ilk = $bloklar[0] ?? null;
        $baslik = $ilk !== null ? self::kisalt($ilk->metin, 90) : '';

        return [
            'sonuc' => [
                'chunk'                 => ['start' => Zaman::yaz((int) $parca['baslangic_ms']), 'end' => Zaman::yaz((int) $parca['bitis_ms'])],
                'topic'                 => $baslik,
                'summary'               => '',
                'important_points'      => [],
                'technical_information' => [],
                'claims'                => [],
                'ideas'                 => [],
                'timestamps'            => $ilk !== null ? [['at' => Zaman::yaz($ilk->baslangicMs), 'label' => $baslik]] : [],
            ],
            'kullanim' => [],
            'model'    => self::SAHTE_MODEL,
        ];
    }

    /**
     * Reduce: parça sonuçlarından tek rapor (6. adımda sahte).
     *
     * @param list<array<string,mixed>> $parcaSonuclari sıra sırasıyla map çıktıları
     * @return array{sonuc:array<string,mixed>,kullanim:list<array<string,mixed>>,model:string}
     */
    public function birlestir(array $parcaSonuclari): array
    {
        $konular = $zamanlar = [];

        foreach ($parcaSonuclari as $p) {
            if (($p['topic'] ?? '') !== '') {
                $konular[] = ['title' => $p['topic'], 'start' => $p['chunk']['start'] ?? null, 'end' => $p['chunk']['end'] ?? null];
            }

            foreach ($p['timestamps'] ?? [] as $z) {
                $zamanlar[] = $z;
            }
        }

        return [
            'sonuc' => [
                'summary'               => 'Yapay zekâ analizi henüz bağlı değil. Aşağıdaki zaman çizelgesi parçaların ilk cümlelerinden üretildi.',
                'detailed_summary'      => '',
                'topics'                => $konular,
                'important_points'      => [],
                'technical_information' => [],
                'claims'                => [],
                'ideas'                 => [],
                'timestamps'            => $zamanlar,
                'user_relevance'        => null,
                'recommendations'       => [],
            ],
            'kullanim' => [],
            'model'    => self::SAHTE_MODEL,
        ];
    }

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

    private static function kisalt(string $metin, int $uzunluk): string
    {
        return mb_strlen($metin) > $uzunluk ? rtrim(mb_substr($metin, 0, $uzunluk - 1)) . '…' : $metin;
    }
}
