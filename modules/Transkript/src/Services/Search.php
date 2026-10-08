<?php
/**
 * =====================================================================
 *  Search – transcript içinde arama, Türkçe harf katlama, vurgulama
 * ---------------------------------------------------------------------
 *  Kaynak: CilginYazilim/search-autocomplete @62e519c (MIT),
 *  system/function.php (tr_fold, boolean_query, query_words,
 *  should_use_like, escape_like, highlight) ve system/ajax.php
 *  (FULLTEXT + LIKE yedeği, benzersiz yer tutucular). Fonksiyonlar
 *  sınıfa toplandı ve transkript_segmentler tablosuna uyarlandı.
 *
 *  Kullanım yerleri: rapor sayfasında transcript içi arama, sohbette
 *  soruyla ilgili segmentleri bulma (MVP sonrası 4. aşama).
 *
 *  İKİ YOL
 *    FULLTEXT boolean  "+mimari* +ajan*" — önek eşleşmesi; Türkçe
 *                      eklemeli olduğu için "indeks" "indeksleri" de bulur.
 *    LIKE (yedek)      En uzun sözcük 3 harften kısaysa: InnoDB
 *                      FULLTEXT 3 harften kısa sözcüğü HİÇ indekslemez,
 *                      "ai" araması sessizce boş dönerdi.
 *
 *  KATLANMIŞ SÜTUNDA ARANIR: transkript_segmentler.arama_metni =
 *  trFold(metin). utf8mb4_turkish_ci "AI" ile "ai"yi, unicode_ci "IŞIK"
 *  ile "ışık"ı eşleştirmez; sorgu da katlanınca ikisi de bulunur ve
 *  arama ile vurgulama aynı şeyi eşleşme sayar. Segment yazan kod
 *  arama_metni'ni Search::trFold($metin) ile doldurmak ZORUNDADIR
 *  (sütun NOT NULL ve varsayılansız).
 *
 *  AYNI ADLI YER TUTUCU BİR SORGUDA İKİ KEZ KULLANILAMAZ: starter'da
 *  PDO::ATTR_EMULATE_PREPARES kapalı; MATCH hem SELECT'te (skor) hem
 *  WHERE'de geçtiği için :q_sel ve :q_whr ayrı adlardır.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Services;

use PDO;

final class Search
{
    /** Sonuç sırası: rapor sayfasında zamana, sohbette alakaya göre. */
    public const SIRA_ZAMAN = 'zaman';
    public const SIRA_ALAKA = 'alaka';

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Bir transcript sürümünün segmentlerinde arar.
     *
     * @return array<int,array{id:int,sira:int,baslangic_ms:int,bitis_ms:int,metin:string,skor:float}>
     */
    public function segmentler(int $metinId, string $q, int $limit = 50, string $sira = self::SIRA_ZAMAN): array
    {
        $sorgu = self::segmentSorgusu($metinId, $q, $limit, $sira);

        if ($sorgu === null) {
            return [];
        }

        $statement = $this->db->prepare($sorgu['sql']);

        foreach ($sorgu['params'] as $ad => $deger) {
            $statement->bindValue($ad, $deger, is_int($deger) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }

        $statement->execute();

        return array_map(static fn (array $r): array => [
            'id'           => (int) $r['id'],
            'sira'         => (int) $r['sira'],
            'baslangic_ms' => (int) $r['baslangic_ms'],
            'bitis_ms'     => (int) $r['bitis_ms'],
            'metin'        => (string) $r['metin'],
            'skor'         => (float) $r['skor'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Arama sorgusunu kurar (veritabanına gitmez; test edilebilir).
     * Aranacak anlamlı sözcük yoksa null.
     *
     * @return array{sql:string,params:array<string,int|string>,yol:string}|null
     */
    public static function segmentSorgusu(int $metinId, string $q, int $limit, string $sira = self::SIRA_ZAMAN): ?array
    {
        if (self::queryWords($q) === []) {
            return null;
        }

        $limit  = max(1, min($limit, 200));
        $katli  = self::trFold($q);
        $params = [':metin_id' => $metinId, ':lim' => $limit];

        if (self::shouldUseLike($katli)) {
            $params[':like'] = '%' . self::escapeLike(trim($katli)) . '%';

            return [
                'sql' => 'SELECT id, sira, baslangic_ms, bitis_ms, metin, 1 AS skor
                            FROM transkript_segmentler
                           WHERE metin_id = :metin_id AND arama_metni LIKE :like
                           ORDER BY baslangic_ms ASC
                           LIMIT :lim',
                'params' => $params,
                'yol'    => 'like',
            ];
        }

        $bool = self::booleanQuery($katli);
        $params[':q_sel'] = $bool;
        $params[':q_whr'] = $bool;

        $siralama = $sira === self::SIRA_ALAKA ? 'skor DESC, baslangic_ms ASC' : 'baslangic_ms ASC';

        return [
            'sql' => "SELECT id, sira, baslangic_ms, bitis_ms, metin,
                             MATCH(arama_metni) AGAINST(:q_sel IN BOOLEAN MODE) AS skor
                        FROM transkript_segmentler
                       WHERE metin_id = :metin_id AND MATCH(arama_metni) AGAINST(:q_whr IN BOOLEAN MODE)
                       ORDER BY $siralama
                       LIMIT :lim",
            'params' => $params,
            'yol'    => 'fulltext',
        ];
    }

    /* =================================================================
     *  TÜRKÇE HARF KATLAMA
     * -----------------------------------------------------------------
     *  mb_strtolower('I') → 'i' verir ama Türkçede 'I'nın küçüğü 'ı'dır;
     *  mb_strtolower('İ') ise İKİ kod noktası döndürür (i + birleştirici
     *  nokta) ve katlanmış metnin uzunluğu değişir. Vurgulama konumları
     *  karakter indisine dayandığı için bütün işaretler kayardı.
     *
     *  Bu tablo BİREBİRDİR: her karakter tam bir karaktere dönüşür,
     *  uzunluk korunur, katlanmış dizedeki indis orijinaldeki indisle
     *  aynı yeri gösterir.
     * ================================================================= */

    /** Karşılaştırma için metni sadeleştirir. Uzunluğu DEĞİŞTİRMEZ. */
    public static function trFold(string $s): string
    {
        $map = [
            'İ' => 'i', 'I' => 'i', 'ı' => 'i',
            'Ş' => 's', 'ş' => 's',
            'Ğ' => 'g', 'ğ' => 'g',
            'Ü' => 'u', 'ü' => 'u',
            'Ö' => 'o', 'ö' => 'o',
            'Ç' => 'c', 'ç' => 'c',
        ];

        // Önce Türkçeye özel çiftler, sonra geri kalan için genel küçültme.
        return mb_strtolower(strtr($s, $map), 'UTF-8');
    }

    /* =================================================================
     *  SORGU TEMİZLEME
     * ================================================================= */

    /**
     * Ham aramayı boolean-mod ifadesine çevirir (önek eşleşmeli).
     *
     * Ham girdi AGAINST() içine konmaz: + - * " ( ) ~ < > @ operatördür;
     * "C++" sözdizimi hatası verir, "-güvenlik" yazan kullanıcı farkında
     * olmadan o sözcüğü DIŞLAR. Harf ve rakam dışındaki her şey boşluğa
     * çevrilir; operatörleri biz ekleriz.
     */
    public static function booleanQuery(string $raw): string
    {
        return implode(' ', array_map(static fn (string $w): string => '+' . $w . '*', self::queryWords($raw)));
    }

    /**
     * Sorgudaki anlamlı sözcükler (en az 2 harf).
     *
     * @return array<int,string>
     */
    public static function queryWords(string $raw): array
    {
        $clean = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $raw) ?? '';
        $words = preg_split('/\s+/u', trim($clean), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter($words, static fn (string $w): bool => mb_strlen($w) >= 2));
    }

    /** En uzun sözcük 3 harften kısaysa LIKE'a düşülür (bkz. dosya başı). */
    public static function shouldUseLike(string $raw): bool
    {
        foreach (self::queryWords($raw) as $w) {
            if (mb_strlen($w) >= 3) {
                return false;
            }
        }

        return true;
    }

    public static function escapeLike(string $v): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $v);
    }

    /* =================================================================
     *  VURGULAMA
     * -----------------------------------------------------------------
     *  KAÇIŞLAMA SIRASI EN ÖNEMLİ PARÇADIR. "Önce kaçışla, sonra <mark>
     *  ekle" HTML varlıklarını bozar: metinde & varsa kaçışlanmış hâli
     *  &amp; olur, "amp" aranınca &<mark>amp</mark>; çıkar.
     *
     *  Doğru sıra:
     *    1) HAM metinde eşleşmeleri bul, \x00 … \x01 ayraçlarıyla işaretle
     *    2) Metnin tamamını e() ile kaçışla
     *    3) Ayraçları <mark> ve </mark> ile değiştir
     *
     *  Karşılaştırma trFold() ile katlanmış kopyada, kesme ORİJİNAL
     *  metinde yapılır; trFold birebir olduğu için indisler aynıdır.
     *  Dönen değer GÜVENLİ HTML'dir: transcript metni yalnızca kaçışlı
     *  olarak basılır, tek etiket <mark>'tır.
     * ================================================================= */

    /**
     * Metinde arama sözcüklerini <mark> ile sarar ve ilk eşleşmenin
     * çevresinden bir özet penceresi alır. $maxLen null ise metnin
     * tamamı işaretlenir.
     */
    public static function highlight(string $text, string $rawQuery, ?int $maxLen = 220): string
    {
        // Transcript dışarıdan gelir: ayraç karakterleri metinde varsa önce atılır.
        $text       = str_replace(["\x00", "\x01"], '', $text);
        $words      = self::queryWords($rawQuery);
        $foldedText = self::trFold($text);

        if ($maxLen === null) {
            $startAt = 0;
            $maxLen  = mb_strlen($text);
        } else {
            // Pencere ilk eşleşmenin biraz öncesinden başlar.
            $firstHit = null;

            foreach ($words as $w) {
                $p = mb_strpos($foldedText, self::trFold($w));

                if ($p !== false && ($firstHit === null || $p < $firstHit)) {
                    $firstHit = $p;
                }
            }

            $startAt = $firstHit === null ? 0 : max(0, $firstHit - 60);
        }

        $snippet = mb_substr($text, $startAt, $maxLen);
        $prefix  = $startAt > 0 ? '… ' : '';
        $suffix  = mb_strlen($text) > $startAt + $maxLen ? ' …' : '';

        // 1) Ham metinde işaretle — [başlangıç, uzunluk]
        $foldedSnippet = self::trFold($snippet);
        $marks = [];

        foreach ($words as $w) {
            $fw  = self::trFold($w);
            $len = mb_strlen($fw);
            $off = 0;

            while (($p = mb_strpos($foldedSnippet, $fw, $off)) !== false) {
                $marks[] = [$p, $len];
                $off = $p + $len;
            }
        }

        if ($marks === []) {
            return e($prefix . $snippet . $suffix);
        }

        // Örtüşen işaretleri birleştir: iç içe <mark> üretilmesin.
        usort($marks, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $merged = [];

        foreach ($marks as [$start, $len]) {
            $end  = $start + $len;
            $last = count($merged) - 1;

            if ($last >= 0 && $start <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $end);
                continue;
            }

            $merged[] = [$start, $end];
        }

        // Sondan başa yerleştir: önceki eklemeler indisleri kaydırmasın.
        $out = $snippet;

        foreach (array_reverse($merged) as [$start, $end]) {
            $out = mb_substr($out, 0, $start)
                 . "\x00" . mb_substr($out, $start, $end - $start) . "\x01"
                 . mb_substr($out, $end);
        }

        // 2) Kaçışla, 3) ayraçları etikete çevir.
        return str_replace(["\x00", "\x01"], ['<mark>', '</mark>'], e($prefix . $out . $suffix));
    }
}
