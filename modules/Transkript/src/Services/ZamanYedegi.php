<?php
/**
 * =====================================================================
 *  ZamanYedegi – birleştirmede kaybolan zaman damgasını kaynaktan bulur
 * ---------------------------------------------------------------------
 *  Birleştirme (ara ya da son) maddeleri bölüm analizlerinden derler;
 *  model kimi maddede "at" alanını boş bırakıyor ya da aralık dışı bir
 *  zaman yazıyor. Analiz 3910'da (1:17:08, kademeli) 11 önemli noktanın
 *  5'i, 25 teknik bilginin 10'u zamansız kaldı (cowork 2. test turu).
 *
 *  ResultValidator zamanı tutmayan maddede bu sınıfa sorar:
 *    1) Girdideki maddelerden metni en çok örtüşen (sözcük kökleri, Türkçe
 *       harf katlanmış) ve zamanı olan maddenin zamanı.
 *    2) Yoksa: metni en çok örtüşen BÖLÜMÜN başlangıcı (chunk.start).
 *    3) O da yoksa null: zaman uydurulmaz, madde zamansız kalır.
 *
 *  Girdinin zamanları doğrulayıcıdan geçmiştir (gerçek segmentlere
 *  çekilmiş); bulunan zaman yine yaslan() ile aralığa karşı denetlenir.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Services;

final class ZamanYedegi
{
    /** Kökler bu kadar harfe kısaltılır: "platformu" / "platformun" → "platf". */
    private const KOK_UZUNLUGU = 5;

    /** Eşleşme için sorgu köklerinin en az bu oranı kaynakta geçmeli. */
    private const ESIK = 0.34;

    /** Anlam taşımayan sık sözcükler (katlanmış, kök uzunluğunda). */
    private const DOLGU = [
        've', 'ile', 'bir', 'bu', 'su', 'o', 'da', 'de', 'icin', 'olan', 'olara', 'daha', 'gibi', 'cok', 'en',
        'kadar', 'ancak', 'ama', 'veya', 'ya', 'hem', 'ise', 'her', 'tum', 'butun', 'sonra', 'once', 'uzeri',
        'olmas', 'oldug', 'etmek', 'yapma', 'edild', 'olduk', 'ayric', 'nasil', 'neden', 'kendi',
        'the', 'and', 'for', 'with', 'that', 'this', 'from',
    ];

    /** @var list<array{kokler: array<string,true>, at: string, bolum: int}> */
    private array $maddeler = [];

    /** @var list<array{kokler: array<string,true>, at: string}> */
    private array $bolumler = [];

    private int $doldurulan = 0;

    /** Hiçbir eşleşme yoksa döndürülen zaman (bölüm analizinde bölümün başı); null = yok. */
    private ?string $varsayilan = null;

    /**
     * Bölüm analizi (map) için: kaynak transcript satırlarıdır. Madde
     * metni önce tek tek satırlarla, tutmazsa ardışık 3 satırlık
     * pencerelerle (madde birkaç satırı özetler) eşleştirilir; bulunamazsa
     * bölümün başlangıcı (cowork 3. tur: "boş gelirse bölüm başlangıcı
     * yedek olarak kullanılsın").
     *
     * @param list<\Modules\Transkript\Transcript\Segment> $bloklar Cleaner::hazirla() çıktısı
     */
    public static function bloklardan(array $bloklar, ?string $varsayilan = null): self
    {
        $yedek = new self([]);
        $bloklar = array_values($bloklar);

        foreach ($bloklar as $i => $blok) {
            $at     = \Modules\Transkript\Zaman::yaz($blok->baslangicMs);
            $satir  = self::kokler($blok->metin);
            $pencere = $satir;

            foreach (array_slice($bloklar, $i + 1, 2) as $komsu) {
                $pencere += self::kokler($komsu->metin);
            }

            if ($satir !== []) {
                $yedek->maddeler[] = ['kokler' => $satir, 'at' => $at, 'bolum' => 0];
            }

            if ($pencere !== []) {
                $yedek->bolumler[] = ['kokler' => $pencere, 'at' => $at];
            }
        }

        $yedek->varsayilan = $varsayilan;

        return $yedek;
    }

    /** @param list<array<string,mixed>> $kaynaklar girdi bölüm (ya da ara özet) sonuçları */
    public function __construct(array $kaynaklar)
    {
        foreach (array_values($kaynaklar) as $i => $kaynak) {
            if (!is_array($kaynak)) {
                continue;
            }

            $bolumKokleri = self::kokler(((string) ($kaynak['topic'] ?? '')) . ' ' . ((string) ($kaynak['summary'] ?? '')));

            foreach (self::metinliMaddeler($kaynak) as [$metin, $at]) {
                $kokler       = self::kokler($metin);
                $bolumKokleri += $kokler;

                if ($at !== null && $kokler !== []) {
                    $this->maddeler[] = ['kokler' => $kokler, 'at' => $at, 'bolum' => $i];
                }
            }

            $baslangic = $kaynak['chunk']['start'] ?? null;

            if (is_string($baslangic) && $baslangic !== '' && $bolumKokleri !== []) {
                $this->bolumler[] = ['kokler' => $bolumKokleri, 'at' => $baslangic];
            }
        }
    }

    /** Maddenin metni için zaman; bulunamazsa null. */
    public function bul(string $metin): ?string
    {
        $sorgu = self::kokler($metin);

        if ($sorgu === []) {
            return $this->varsayilan;
        }

        $at = self::enIyi($sorgu, $this->maddeler) ?? self::enIyi($sorgu, $this->bolumler) ?? $this->varsayilan;

        if ($at !== null) {
            $this->doldurulan++;
        }

        return $at;
    }

    /** Kaç maddenin zamanı bu sınıftan geldi (uyarı satırı için). */
    public function doldurulan(): int
    {
        return $this->doldurulan;
    }

    /**
     * Örtüşmesi eşiği geçen en iyi adayın zamanı. Eşitlikte daha özgül
     * olan (kendi kökleri daha az) ve önce gelen kazanır.
     *
     * @param array<string,true>                                   $sorgu
     * @param list<array{kokler: array<string,true>, at: string}> $adaylar
     */
    private static function enIyi(array $sorgu, array $adaylar): ?string
    {
        $enIyi  = null;
        $puan   = 0.0;
        $ozgul  = 0.0;
        $gerekli = min(2, count($sorgu));

        foreach ($adaylar as $aday) {
            $ortak = count(array_intersect_key($sorgu, $aday['kokler']));

            if ($ortak < $gerekli) {
                continue;
            }

            $p = $ortak / count($sorgu);
            $o = $ortak / count($aday['kokler']);

            if ($p >= self::ESIK && ($p > $puan || ($p === $puan && $o > $ozgul))) {
                $enIyi = $aday['at'];
                $puan  = $p;
                $ozgul = $o;
            }
        }

        return $enIyi;
    }

    /**
     * Kaynağın zamanlı maddeleri: [metin, at].
     *
     * @param  array<string,mixed> $kaynak
     * @return list<array{0: string, 1: ?string}>
     */
    private static function metinliMaddeler(array $kaynak): array
    {
        $sonuc = [];

        foreach (['important_points' => ['text'], 'technical_information' => ['name', 'description'], 'claims' => ['text'],
                  'ideas' => ['text'], 'timestamps' => ['label']] as $alan => $metinAlanlari) {
            foreach ((array) ($kaynak[$alan] ?? []) as $madde) {
                if (!is_array($madde)) {
                    continue;
                }

                $metin = implode(' ', array_map(static fn (string $a): string => is_string($madde[$a] ?? null) ? $madde[$a] : '', $metinAlanlari));
                $at    = is_string($madde['at'] ?? null) && $madde['at'] !== '' ? $madde['at'] : null;
                $sonuc[] = [$metin, $at];
            }
        }

        return $sonuc;
    }

    /**
     * Sözcük kökleri: Türkçe harf katlanmış, 5 harfe kısaltılmış; 3 harften
     * kısa sözcük (rakam içermiyorsa) ve dolgu sözcükleri atılır. "GLM 5.3"
     * gibi sürüm adları tek parça kalır.
     *
     * @return array<string,true>
     */
    public static function kokler(string $metin): array
    {
        preg_match_all('/[\p{L}\p{N}]+(?:[.,]\p{N}+)*/u', Search::trFold($metin), $m);
        $kokler = [];

        foreach ($m[0] as $sozcuk) {
            $rakamli = (bool) preg_match('/\p{N}/u', $sozcuk);

            if (!$rakamli && mb_strlen($sozcuk) < 3) {
                continue;
            }

            $kok = $rakamli ? $sozcuk : mb_substr($sozcuk, 0, self::KOK_UZUNLUGU);

            if (!in_array($kok, self::DOLGU, true)) {
                $kokler[$kok] = true;
            }
        }

        return $kokler;
    }
}
