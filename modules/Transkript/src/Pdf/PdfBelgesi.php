<?php
/**
 * =====================================================================
 *  PdfBelgesi – bağımlılıksız, küçük PDF 1.4 yazıcı
 * ---------------------------------------------------------------------
 *  Composer / npm paketi yasak (CLAUDE.md kural 4); rapor PDF'i için
 *  gereken kadarı: A4 sayfa, metin (Helvetica, CP1254 Türkçe), dolu ve
 *  köşesi yuvarlak dikdörtgen, çizgi, tıklanabilir bağlantı (URI),
 *  yer imleri (görüntüleyicinin kenar çubuğundaki içindekiler) ve belge
 *  bilgisi. İçerik akışları Flate ile sıkıştırılır (zlib varsa).
 *
 *  KOORDİNAT: çağıran sol ÜST köşeden ölçer (y aşağı doğru artar);
 *  PDF'in sol alt düzenine burada çevrilir. Metnin y'si taban çizgisidir.
 *
 *  METİN: metin() Yazitipi::kodla() çıktısını (CP1254 baytları) alır;
 *  AI metni burada yalnız bir dize olarak yazılır, PDF komutu olarak
 *  yorumlanmaz ( \ ( ) kaçışlanır).
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Pdf;

use LogicException;

final class PdfBelgesi
{
    /** A4, punto. */
    public const GENISLIK  = 595.28;
    public const YUKSEKLIK = 841.89;

    /** Yazı tipi → PDF kaynak adı. */
    private const KAYNAK_ADI = [Yazitipi::NORMAL => 'F1', Yazitipi::KALIN => 'F2', Yazitipi::EGIK => 'F3'];

    /** @var list<array{icerik: string, baglantilar: list<array{0: float, 1: float, 2: float, 3: float, 4: string}>}> */
    private array $sayfalar = [];

    private int $etkin = -1;

    /** @var list<array{baslik: string, sayfa: int, y: float, seviye: int}> */
    private array $yerImleri = [];

    public function __construct(
        private readonly string $baslik = '',
        private readonly string $konu = '',
        private readonly string $ureten = 'Transkript',
        private readonly ?int $tarih = null,
    ) {
    }

    /** Yeni sayfa açar ve etkin yapar; sayfa numarası (0'dan). */
    public function sayfaEkle(): int
    {
        $this->sayfalar[] = ['icerik' => '', 'baglantilar' => []];

        return $this->etkin = count($this->sayfalar) - 1;
    }

    public function sayfaSayisi(): int
    {
        return count($this->sayfalar);
    }

    /** Sonradan yazmak için (ör. "Sayfa 2 / 5" alt bilgisi) bir sayfayı etkin yapar. */
    public function sayfaSec(int $sayfa): void
    {
        if (!isset($this->sayfalar[$sayfa])) {
            throw new LogicException('Böyle bir sayfa yok: ' . $sayfa);
        }

        $this->etkin = $sayfa;
    }

    public function etkinSayfa(): int
    {
        return $this->etkin;
    }

    /**
     * @param string                $kodlu Yazitipi::kodla() çıktısı
     * @param array{int,int,int}    $renk  0–255
     */
    public function metin(float $x, float $y, string $kodlu, string $yazitipi, float $punto, array $renk): void
    {
        if ($kodlu === '') {
            return;
        }

        $this->yaz(sprintf("BT /%s %s Tf %s rg %s %s Td %s Tj ET\n",
            self::KAYNAK_ADI[$yazitipi] ?? 'F1', self::sayi($punto), self::renk($renk),
            self::sayi($x), self::sayi(self::YUKSEKLIK - $y), self::dize($kodlu)));
    }

    /** Dolu dikdörtgen; $yaricap > 0 ise köşeler yuvarlak. @param array{int,int,int} $renk */
    public function dikdortgen(float $x, float $y, float $genislik, float $yukseklik, array $renk, float $yaricap = 0.0): void
    {
        if ($genislik <= 0 || $yukseklik <= 0) {
            return;
        }

        $alt = self::YUKSEKLIK - $y - $yukseklik;
        $r   = min($yaricap, $genislik / 2, $yukseklik / 2);

        if ($r <= 0) {
            $this->yaz(sprintf("%s rg %s %s %s %s re f\n", self::renk($renk), self::sayi($x), self::sayi($alt), self::sayi($genislik), self::sayi($yukseklik)));

            return;
        }

        // Dört köşe Bézier eğrisi (k = 0,5523: çeyrek daire yaklaşımı).
        $k  = 0.5523 * $r;
        $x2 = $x + $genislik;
        $y2 = $alt + $yukseklik;
        $s  = self::sayi(...);

        $this->yaz(self::renk($renk) . " rg\n"
            . $s($x + $r) . ' ' . $s($alt) . " m\n"
            . $s($x2 - $r) . ' ' . $s($alt) . " l\n"
            . $s($x2 - $r + $k) . ' ' . $s($alt) . ' ' . $s($x2) . ' ' . $s($alt + $r - $k) . ' ' . $s($x2) . ' ' . $s($alt + $r) . " c\n"
            . $s($x2) . ' ' . $s($y2 - $r) . " l\n"
            . $s($x2) . ' ' . $s($y2 - $r + $k) . ' ' . $s($x2 - $r + $k) . ' ' . $s($y2) . ' ' . $s($x2 - $r) . ' ' . $s($y2) . " c\n"
            . $s($x + $r) . ' ' . $s($y2) . " l\n"
            . $s($x + $r - $k) . ' ' . $s($y2) . ' ' . $s($x) . ' ' . $s($y2 - $r + $k) . ' ' . $s($x) . ' ' . $s($y2 - $r) . " c\n"
            . $s($x) . ' ' . $s($alt + $r) . " l\n"
            . $s($x) . ' ' . $s($alt + $r - $k) . ' ' . $s($x + $r - $k) . ' ' . $s($alt) . ' ' . $s($x + $r) . ' ' . $s($alt) . " c\nh f\n");
    }

    /** @param array{int,int,int} $renk */
    public function cizgi(float $x1, float $y1, float $x2, float $y2, array $renk, float $kalinlik = 0.5): void
    {
        $this->yaz(sprintf("%s RG %s w %s %s m %s %s l S\n", self::renk($renk), self::sayi($kalinlik),
            self::sayi($x1), self::sayi(self::YUKSEKLIK - $y1), self::sayi($x2), self::sayi(self::YUKSEKLIK - $y2)));
    }

    /**
     * Tıklanabilir alan (etkin sayfada). Yalnız http(s) adresi; öteki
     * şemalar (javascript:, file:) yok sayılır.
     */
    public function baglanti(float $x, float $y, float $genislik, float $yukseklik, string $url): void
    {
        if (!preg_match('#^https?://[\x21-\x7E]+$#', $url) || $this->etkin < 0) {
            return;
        }

        $this->sayfalar[$this->etkin]['baglantilar'][] = [$x, self::YUKSEKLIK - $y - $yukseklik, $x + $genislik, self::YUKSEKLIK - $y, $url];
    }

    /** Kenar çubuğundaki içindekiler: etkin sayfada $y hizasına gider. Seviye 0 ya da 1. */
    public function yerImi(string $baslik, float $y, int $seviye = 0): void
    {
        if ($this->etkin < 0) {
            return;
        }

        $this->yerImleri[] = ['baslik' => $baslik, 'sayfa' => $this->etkin, 'y' => $y, 'seviye' => $seviye > 0 ? 1 : 0];
    }

    /** Bitmiş PDF dosyasının baytları. */
    public function cikti(): string
    {
        if ($this->sayfalar === []) {
            $this->sayfaEkle();
        }

        /** @var array<int,string> $nesne numara → gövde */
        $nesne = [];
        $no    = 0;
        $ayir  = static function () use (&$no): int {
            return ++$no;
        };

        $katalog  = $ayir();
        $sayfalar = $ayir();
        $kodlama  = $ayir();
        $fontlar  = [];

        foreach (Yazitipi::TEMEL as $tip => $temel) {
            $fontlar[$tip] = $ayir();
        }

        $bilgi = $ayir();

        $farklar = '';

        foreach (Yazitipi::FARKLAR as $kod => $glif) {
            $farklar .= $kod . ' /' . $glif . ' ';
        }

        $nesne[$kodlama] = '<< /Type /Encoding /BaseEncoding /WinAnsiEncoding /Differences [' . trim($farklar) . '] >>';

        foreach (Yazitipi::TEMEL as $tip => $temel) {
            $nesne[$fontlar[$tip]] = '<< /Type /Font /Subtype /Type1 /BaseFont /' . $temel . ' /Encoding ' . $kodlama . ' 0 R >>';
        }

        $kaynaklar = '<< /Font << ' . implode(' ', array_map(
            static fn (string $tip): string => '/' . self::KAYNAK_ADI[$tip] . ' ' . $fontlar[$tip] . ' 0 R',
            array_keys(Yazitipi::TEMEL),
        )) . ' >> >>';

        $sayfaNo = [];

        foreach ($this->sayfalar as $i => $sayfa) {
            $sayfaNo[$i] = $ayir();
            $icerikNo    = $ayir();
            $akis        = $sayfa['icerik'];
            $suzgec      = '';

            if (function_exists('gzcompress')) {
                $akis   = (string) gzcompress($akis, 6);
                $suzgec = ' /Filter /FlateDecode';
            }

            $nesne[$icerikNo] = '<< /Length ' . strlen($akis) . $suzgec . " >>\nstream\n" . $akis . "\nendstream";

            $annotlar = [];

            foreach ($sayfa['baglantilar'] as [$x1, $y1, $x2, $y2, $url]) {
                $a = $ayir();
                $nesne[$a] = '<< /Type /Annot /Subtype /Link /Rect [' . self::sayi($x1) . ' ' . self::sayi($y1) . ' ' . self::sayi($x2) . ' ' . self::sayi($y2) . ']'
                    . ' /Border [0 0 0] /A << /S /URI /URI ' . self::dize($url) . ' >> >>';
                $annotlar[] = $a . ' 0 R';
            }

            $nesne[$sayfaNo[$i]] = '<< /Type /Page /Parent ' . $sayfalar . ' 0 R /MediaBox [0 0 ' . self::sayi(self::GENISLIK) . ' ' . self::sayi(self::YUKSEKLIK) . ']'
                . ' /Resources ' . $kaynaklar . ' /Contents ' . $icerikNo . ' 0 R'
                . ($annotlar !== [] ? ' /Annots [' . implode(' ', $annotlar) . ']' : '') . ' >>';
        }

        $nesne[$sayfalar] = '<< /Type /Pages /Kids [' . implode(' ', array_map(static fn (int $n): string => $n . ' 0 R', $sayfaNo)) . '] /Count ' . count($sayfaNo) . ' >>';

        $anahat = $this->yerImleriniYaz($nesne, $ayir, $sayfaNo);

        $nesne[$katalog] = '<< /Type /Catalog /Pages ' . $sayfalar . ' 0 R /Lang (tr-TR) /ViewerPreferences << /DisplayDocTitle true >>'
            . ($anahat !== null ? ' /Outlines ' . $anahat . ' 0 R /PageMode /UseOutlines' : '') . ' >>';

        $tarih = date('YmdHis', $this->tarih ?? time()) . str_replace(':', "'", date('P', $this->tarih ?? time())) . "'";
        $nesne[$bilgi] = '<< /Title ' . self::metinDizesi($this->baslik) . ' /Subject ' . self::metinDizesi($this->konu)
            . ' /Producer ' . self::metinDizesi($this->ureten) . ' /Creator ' . self::metinDizesi($this->ureten)
            . ' /CreationDate (D:' . $tarih . ') >>';

        ksort($nesne);

        $pdf     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $konumlar = [];

        foreach ($nesne as $n => $govde) {
            $konumlar[$n] = strlen($pdf);
            $pdf .= $n . " 0 obj\n" . $govde . "\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . ($no + 1) . "\n0000000000 65535 f \n";

        for ($n = 1; $n <= $no; $n++) {
            $pdf .= sprintf("%010d 00000 n \n", $konumlar[$n]);
        }

        return $pdf . 'trailer << /Size ' . ($no + 1) . ' /Root ' . $katalog . ' 0 R /Info ' . $bilgi . " 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
    }

    /**
     * @param array<int,string> $nesne
     * @param list<int>|array<int,int> $sayfaNo
     * @return int|null anahat kökünün numarası
     */
    private function yerImleriniYaz(array &$nesne, callable $ayir, array $sayfaNo): ?int
    {
        if ($this->yerImleri === []) {
            return null;
        }

        $kok = $ayir();

        // Ağaç: seviye 0 → kök altında, seviye 1 → son seviye 0'ın altında.
        $ust   = [];
        $son0  = null;

        foreach ($this->yerImleri as $i => $y) {
            $this->yerImleri[$i]['no'] = $ayir();

            if ($y['seviye'] === 0 || $son0 === null) {
                $ust[$i] = null;
                $son0    = $i;
            } else {
                $ust[$i] = $son0;
            }
        }

        $cocuklar = [];

        foreach ($ust as $i => $u) {
            $cocuklar[$u ?? -1][] = $i;
        }

        foreach ($this->yerImleri as $i => $y) {
            $kardes = $cocuklar[$ust[$i] ?? -1];
            $sira   = array_search($i, $kardes, true);
            $ebeveyn = $ust[$i] === null ? $kok : $this->yerImleri[$ust[$i]]['no'];

            $govde = '<< /Title ' . self::metinDizesi($y['baslik']) . ' /Parent ' . $ebeveyn . ' 0 R'
                . ' /Dest [' . $sayfaNo[$y['sayfa']] . ' 0 R /XYZ 0 ' . self::sayi(self::YUKSEKLIK - $y['y'] + 12) . ' 0]';

            if ($sira > 0) {
                $govde .= ' /Prev ' . $this->yerImleri[$kardes[$sira - 1]]['no'] . ' 0 R';
            }

            if ($sira < count($kardes) - 1) {
                $govde .= ' /Next ' . $this->yerImleri[$kardes[$sira + 1]]['no'] . ' 0 R';
            }

            if (isset($cocuklar[$i])) {
                $govde .= ' /First ' . $this->yerImleri[$cocuklar[$i][0]]['no'] . ' 0 R /Last ' . $this->yerImleri[end($cocuklar[$i])]['no'] . ' 0 R'
                    . ' /Count -' . count($cocuklar[$i]);
            }

            $nesne[$y['no']] = $govde . ' >>';
        }

        $ilk = $cocuklar[-1];
        $nesne[$kok] = '<< /Type /Outlines /First ' . $this->yerImleri[$ilk[0]]['no'] . ' 0 R /Last ' . $this->yerImleri[end($ilk)]['no'] . ' 0 R'
            . ' /Count ' . count($ilk) . ' >>';

        return $kok;
    }

    private function yaz(string $komut): void
    {
        if ($this->etkin < 0) {
            $this->sayfaEkle();
        }

        $this->sayfalar[$this->etkin]['icerik'] .= $komut;
    }

    /** PDF dize sabiti: \ ( ) ve satır sonu kaçışlanır. */
    public static function dize(string $baytlar): string
    {
        return '(' . strtr($baytlar, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '\\r', "\n" => '\\n']) . ')';
    }

    /** Belge bilgisi ve yer imi başlığı: UTF-16BE (BOM'lu) onaltılık dize. */
    public static function metinDizesi(string $utf8): string
    {
        return '<FEFF' . strtoupper(bin2hex((string) mb_convert_encoding($utf8, 'UTF-16BE', 'UTF-8'))) . '>';
    }

    private static function sayi(float $sayi): string
    {
        $metin = rtrim(rtrim(sprintf('%.3F', $sayi), '0'), '.');

        return $metin === '-0' || $metin === '' ? '0' : $metin;
    }

    /** @param array{int,int,int} $renk */
    private static function renk(array $renk): string
    {
        return implode(' ', array_map(static fn (int $c): string => self::sayi(max(0, min(255, $c)) / 255), array_values($renk)));
    }
}
