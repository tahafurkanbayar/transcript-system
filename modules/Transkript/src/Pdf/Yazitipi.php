<?php
/**
 * =====================================================================
 *  Yazitipi – PDF'in yazı tipleri: Helvetica (normal / kalın / eğik)
 * ---------------------------------------------------------------------
 *  Bağımlılık yok (CLAUDE.md kural 4): yazı tipi gömülmez, PDF'in her
 *  görüntüleyicide bulunan 14 standart yazı tipinden Helvetica kullanılır.
 *  Türkçe harfler için kodlama CP1254'tür (Windows Türkçe): WinAnsi'nin
 *  altı kodu /Differences ile Ğ İ Ş ğ ı ş glif adlarına çevrilir
 *  (PdfBelgesi). Glif adları Adobe Glyph List'te olduğu için metin
 *  PDF'ten kopyalanınca da Türkçe kalır.
 *
 *  GENİŞLİKLER: 1000 birimde, CP1254'ün 256 kodu için. Helvetica AFM ile
 *  aynıdır (Arial, Helvetica ile metrik uyumludur); tablo Arial
 *  dosyasından üretildi (boşluk 278, a 556, W 944, ı 278). Satır kırma bu
 *  tabloyla ölçülür; görüntüleyici aynı genişlikte çizer. Eğik yazı
 *  normalle aynı genişliktedir.
 *
 *  CP1254 DIŞI KARAKTER (emoji, Kiril, Çince …): bilinen bir karşılığı
 *  varsa o (₺ → TL, ‐ → -), Latin harfse aksansız hali, yoksa atlanır.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Pdf;

final class Yazitipi
{
    public const NORMAL = 'normal';
    public const KALIN  = 'kalin';
    public const EGIK   = 'egik';

    /** Yazı tipi → PDF temel yazı tipi adı. */
    public const TEMEL = [
        self::NORMAL => 'Helvetica',
        self::KALIN  => 'Helvetica-Bold',
        self::EGIK   => 'Helvetica-Oblique',
    ];

    /** WinAnsi'den farkları (CP1254): kod → glif adı. */
    public const FARKLAR = [0xD0 => 'Gbreve', 0xDD => 'Idotaccent', 0xDE => 'Scedilla', 0xF0 => 'gbreve', 0xFD => 'dotlessi', 0xFE => 'scedilla'];

    /** Helvetica genişlikleri (1000 birim), CP1254 kodu sırasıyla. */
    private const GENISLIK_NORMAL = [
           0,    0,    0,    0,    0,    0,    0,    0,    0,    0,    0,    0,    0,    0,    0,    0, // 0x00
           0,    0,    0,    0,    0,    0,    0,    0,    0,    0,    0,    0,    0,    0,    0,    0, // 0x10
         278,  278,  355,  556,  556,  889,  667,  191,  333,  333,  389,  584,  278,  333,  278,  278, // 0x20
         556,  556,  556,  556,  556,  556,  556,  556,  556,  556,  278,  278,  584,  584,  584,  556, // 0x30
        1015,  667,  667,  722,  722,  667,  611,  778,  722,  278,  500,  667,  556,  833,  722,  778, // 0x40
         667,  778,  722,  667,  611,  722,  667,  944,  667,  667,  611,  278,  278,  278,  469,  556, // 0x50
         333,  556,  556,  500,  556,  556,  278,  556,  556,  222,  222,  500,  222,  833,  556,  556, // 0x60
         556,  556,  333,  500,  278,  556,  500,  722,  500,  500,  500,  334,  260,  334,  584,  750, // 0x70
         556,    0,  222,  556,  333, 1000,  556,  556,  333, 1000,  667,  333, 1000,    0,    0,    0, // 0x80
           0,  222,  222,  333,  333,  350,  556, 1000,  333, 1000,  500,  333,  944,    0,    0,  667, // 0x90
         278,  333,  556,  556,  556,  556,  260,  556,  333,  737,  370,  556,  584,  333,  737,  552, // 0xA0
         400,  549,  333,  333,  333,  576,  537,  333,  333,  333,  365,  556,  834,  834,  834,  611, // 0xB0
         667,  667,  667,  667,  667,  667, 1000,  722,  667,  667,  667,  667,  278,  278,  278,  278, // 0xC0
         778,  722,  778,  778,  778,  778,  778,  584,  778,  722,  722,  722,  722,  278,  667,  611, // 0xD0
         556,  556,  556,  556,  556,  556,  889,  500,  556,  556,  556,  556,  278,  278,  278,  278, // 0xE0
         556,  556,  556,  556,  556,  556,  556,  549,  611,  556,  556,  556,  556,  278,  500,  500, // 0xF0
    ];

    /** Helvetica-Bold genişlikleri. */
    private const GENISLIK_KALIN = [
           0,    0,    0,    0,    0,    0,    0,    0,    0,    0,    0,    0,    0,    0,    0,    0, // 0x00
           0,    0,    0,    0,    0,    0,    0,    0,    0,    0,    0,    0,    0,    0,    0,    0, // 0x10
         278,  333,  474,  556,  556,  889,  722,  238,  333,  333,  389,  584,  278,  333,  278,  278, // 0x20
         556,  556,  556,  556,  556,  556,  556,  556,  556,  556,  333,  333,  584,  584,  584,  611, // 0x30
         975,  722,  722,  722,  722,  667,  611,  778,  722,  278,  556,  722,  611,  833,  722,  778, // 0x40
         667,  778,  722,  667,  611,  722,  667,  944,  667,  667,  611,  333,  278,  333,  584,  556, // 0x50
         333,  556,  611,  556,  611,  556,  333,  611,  611,  278,  278,  556,  278,  889,  611,  611, // 0x60
         611,  611,  389,  556,  333,  611,  556,  778,  556,  556,  500,  389,  280,  389,  584,  750, // 0x70
         556,    0,  278,  556,  500, 1000,  556,  556,  333, 1000,  667,  333, 1000,    0,    0,    0, // 0x80
           0,  278,  278,  500,  500,  350,  556, 1000,  333, 1000,  556,  333,  944,    0,    0,  667, // 0x90
         278,  333,  556,  556,  556,  556,  280,  556,  333,  737,  370,  556,  584,  333,  737,  552, // 0xA0
         400,  549,  333,  333,  333,  576,  556,  333,  333,  333,  365,  556,  834,  834,  834,  611, // 0xB0
         722,  722,  722,  722,  722,  722, 1000,  722,  667,  667,  667,  667,  278,  278,  278,  278, // 0xC0
         778,  722,  778,  778,  778,  778,  778,  584,  778,  722,  722,  722,  722,  278,  667,  611, // 0xD0
         556,  556,  556,  556,  556,  556,  889,  556,  556,  556,  556,  556,  278,  278,  278,  278, // 0xE0
         611,  611,  611,  611,  611,  611,  611,  549,  611,  611,  611,  611,  611,  278,  556,  556, // 0xF0
    ];

    /** Unicode → CP1254 (0x80 üstü). */
    private const UST = [
        0x20AC => 0x80, 0x201A => 0x82, 0x0192 => 0x83, 0x201E => 0x84, 0x2026 => 0x85, 0x2020 => 0x86,
        0x2021 => 0x87, 0x02C6 => 0x88, 0x2030 => 0x89, 0x0160 => 0x8A, 0x2039 => 0x8B, 0x0152 => 0x8C,
        0x2018 => 0x91, 0x2019 => 0x92, 0x201C => 0x93, 0x201D => 0x94, 0x2022 => 0x95, 0x2013 => 0x96,
        0x2014 => 0x97, 0x02DC => 0x98, 0x2122 => 0x99, 0x0161 => 0x9A, 0x203A => 0x9B, 0x0153 => 0x9C,
        0x0178 => 0x9F, 0x00A0 => 0xA0, 0x00A1 => 0xA1, 0x00A2 => 0xA2, 0x00A3 => 0xA3, 0x00A4 => 0xA4,
        0x00A5 => 0xA5, 0x00A6 => 0xA6, 0x00A7 => 0xA7, 0x00A8 => 0xA8, 0x00A9 => 0xA9, 0x00AA => 0xAA,
        0x00AB => 0xAB, 0x00AC => 0xAC, 0x00AD => 0xAD, 0x00AE => 0xAE, 0x00AF => 0xAF, 0x00B0 => 0xB0,
        0x00B1 => 0xB1, 0x00B2 => 0xB2, 0x00B3 => 0xB3, 0x00B4 => 0xB4, 0x00B5 => 0xB5, 0x00B6 => 0xB6,
        0x00B7 => 0xB7, 0x00B8 => 0xB8, 0x00B9 => 0xB9, 0x00BA => 0xBA, 0x00BB => 0xBB, 0x00BC => 0xBC,
        0x00BD => 0xBD, 0x00BE => 0xBE, 0x00BF => 0xBF, 0x00C0 => 0xC0, 0x00C1 => 0xC1, 0x00C2 => 0xC2,
        0x00C3 => 0xC3, 0x00C4 => 0xC4, 0x00C5 => 0xC5, 0x00C6 => 0xC6, 0x00C7 => 0xC7, 0x00C8 => 0xC8,
        0x00C9 => 0xC9, 0x00CA => 0xCA, 0x00CB => 0xCB, 0x00CC => 0xCC, 0x00CD => 0xCD, 0x00CE => 0xCE,
        0x00CF => 0xCF, 0x011E => 0xD0, 0x00D1 => 0xD1, 0x00D2 => 0xD2, 0x00D3 => 0xD3, 0x00D4 => 0xD4,
        0x00D5 => 0xD5, 0x00D6 => 0xD6, 0x00D7 => 0xD7, 0x00D8 => 0xD8, 0x00D9 => 0xD9, 0x00DA => 0xDA,
        0x00DB => 0xDB, 0x00DC => 0xDC, 0x0130 => 0xDD, 0x015E => 0xDE, 0x00DF => 0xDF, 0x00E0 => 0xE0,
        0x00E1 => 0xE1, 0x00E2 => 0xE2, 0x00E3 => 0xE3, 0x00E4 => 0xE4, 0x00E5 => 0xE5, 0x00E6 => 0xE6,
        0x00E7 => 0xE7, 0x00E8 => 0xE8, 0x00E9 => 0xE9, 0x00EA => 0xEA, 0x00EB => 0xEB, 0x00EC => 0xEC,
        0x00ED => 0xED, 0x00EE => 0xEE, 0x00EF => 0xEF, 0x011F => 0xF0, 0x00F1 => 0xF1, 0x00F2 => 0xF2,
        0x00F3 => 0xF3, 0x00F4 => 0xF4, 0x00F5 => 0xF5, 0x00F6 => 0xF6, 0x00F7 => 0xF7, 0x00F8 => 0xF8,
        0x00F9 => 0xF9, 0x00FA => 0xFA, 0x00FB => 0xFB, 0x00FC => 0xFC, 0x0131 => 0xFD, 0x015F => 0xFE,
        0x00FF => 0xFF,
    ];

    /** CP1254'te olmayan ama karşılığı bilinen karakterler. */
    private const KARSILIK = [
        0x20BA => 'TL', 0x2010 => '-', 0x2011 => '-', 0x2012 => '-', 0x2015 => '—', 0x2212 => '-',
        0x2032 => "'", 0x2033 => '"', 0x2192 => '->', 0x2190 => '<-', 0x2194 => '<->', 0x21D2 => '=>',
        0x2264 => '<=', 0x2265 => '>=', 0x2260 => '!=', 0x2248 => '~', 0x2713 => 'v', 0x2714 => 'v',
        0x25CF => '•', 0x25AA => '•', 0x2023 => '•', 0x2043 => '-', 0x2219 => '•',
        0x200B => '', 0x200C => '', 0x200D => '', 0xFEFF => '', 0x00AD => '', 0xFE0F => '',
        0x2002 => ' ', 0x2003 => ' ', 0x2009 => ' ', 0x202F => ' ', 0x2007 => ' ', 0x3000 => ' ',
    ];

    /**
     * UTF-8 metni CP1254 baytlarına çevirir. Satır sonu ve sekme boşluk
     * olur, art arda boşluk teke iner (atlanan emoji çift boşluk
     * bırakmasın); öteki kontrol karakterleri atılır.
     */
    public static function kodla(string $metin): string
    {
        return (string) preg_replace('/ {2,}/', ' ', self::cevir($metin));
    }

    private static function cevir(string $metin): string
    {
        if ($metin === '') {
            return '';
        }

        if (!mb_check_encoding($metin, 'UTF-8')) {
            $metin = mb_convert_encoding($metin, 'UTF-8', 'UTF-8');
        }

        $cikti = '';

        foreach (mb_str_split($metin) as $harf) {
            $kod = mb_ord($harf, 'UTF-8');

            if ($kod === false) {
                continue;
            }

            if ($kod < 0x80) {
                $cikti .= $kod >= 0x20 && $kod !== 0x7F ? $harf : ($kod === 0x09 || $kod === 0x0A || $kod === 0x0D ? ' ' : '');

                continue;
            }

            if (isset(self::UST[$kod])) {
                $cikti .= chr(self::UST[$kod]);

                continue;
            }

            $cikti .= self::cevir(self::karsilik($harf, $kod));
        }

        return $cikti;
    }

    /** Metnin genişliği (punto cinsinden), kodlanmış (CP1254) metin için. */
    public static function genislik(string $kodlu, string $yazitipi, float $punto): float
    {
        $tablo = $yazitipi === self::KALIN ? self::GENISLIK_KALIN : self::GENISLIK_NORMAL;
        $toplam = 0;

        foreach (count_chars($kodlu, 1) as $bayt => $adet) {
            $toplam += $tablo[$bayt] * $adet;
        }

        return $toplam * $punto / 1000;
    }

    /** CP1254 dışı karakterin karşılığı ('' = atla). */
    private static function karsilik(string $harf, int $kod): string
    {
        if (isset(self::KARSILIK[$kod])) {
            return self::KARSILIK[$kod];
        }

        // Aksanlı Latin harf (ā, ő, ș …): aksansız hali CP1254'te varsa o.
        if (class_exists(\Normalizer::class)) {
            $ayrik = (string) \Normalizer::normalize($harf, \Normalizer::FORM_D);
            $taban = (string) preg_replace('/\p{Mn}+/u', '', $ayrik);

            if ($taban !== '' && $taban !== $harf && mb_strlen($taban) === 1) {
                $tabanKod = mb_ord($taban, 'UTF-8');

                if ($tabanKod !== false && ($tabanKod < 0x80 || isset(self::UST[$tabanKod]))) {
                    return $taban;
                }
            }
        }

        return '';
    }
}
