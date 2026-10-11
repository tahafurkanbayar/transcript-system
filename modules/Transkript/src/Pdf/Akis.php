<?php
/**
 * =====================================================================
 *  Akis – PdfBelgesi üzerinde yukarıdan aşağı metin akışı
 * ---------------------------------------------------------------------
 *  Paragraf "parça"lardan oluşur; her parçanın kendi yazı tipi, puntosu,
 *  rengi ve isteğe bağlı bağlantısı / rozeti vardır:
 *
 *      ['metin' => '00:04:47', 'yazitipi' => Yazitipi::KALIN, 'punto' => 8.5,
 *       'renk' => [29, 78, 216], 'url' => 'https://youtu.be/…?t=287']
 *      ['metin' => 'Kritik', 'rozet' => [254, 226, 226], …]   bölünmez, zeminli
 *      ['metin' => '#7. sınıf fen', 'butun' => true, …]         bölünmez (etiket)
 *      ['sekme' => 62]                                         x'i girinti + 62'ye taşır
 *
 *  Satır kırma kelime kelimedir (Yazitipi genişlikleriyle); satıra
 *  sığmayan tek kelime harf harf bölünür. Satır sayfaya sığmazsa yeni
 *  sayfa açılır ve sayfa başı geri çağrısı (üst bilgi) çalışır.
 *  "asilma": ilk satırdan sonraki satırların girintisi (madde işareti,
 *  rozet ve zaman sütunu hizası).
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Pdf;

use Closure;

final class Akis
{
    /** İmlecin y'si (sayfanın üstünden, punto). */
    public float $y;

    /** Rozetin yatay / dikey iç boşluğu. */
    private const ROZET_YATAY = 4.0;
    private const ROZET_DIKEY = 2.0;

    /**
     * @param ?Closure(Akis): void $sayfaBasi yeni sayfa açılınca (üst bilgi; y'yi ayarlayabilir)
     */
    public function __construct(
        public readonly PdfBelgesi $pdf,
        public readonly float $sol = 50.0,
        public readonly float $sag = 50.0,
        public readonly float $ust = 56.0,
        public readonly float $alt = 60.0,
        private readonly ?Closure $sayfaBasi = null,
    ) {
        $this->y = $ust;
    }

    public function genislik(): float
    {
        return PdfBelgesi::GENISLIK - $this->sol - $this->sag;
    }

    public function yeniSayfa(): void
    {
        $this->pdf->sayfaEkle();
        $this->y = $this->ust;

        if ($this->sayfaBasi !== null) {
            ($this->sayfaBasi)($this);
        }
    }

    /** $yukseklik sayfanın kalanına sığmıyorsa yeni sayfa. */
    public function yerAc(float $yukseklik): void
    {
        if ($this->pdf->etkinSayfa() < 0 || $this->y + $yukseklik > PdfBelgesi::YUKSEKLIK - $this->alt) {
            $this->yeniSayfa();
        }
    }

    public function kalan(): float
    {
        return PdfBelgesi::YUKSEKLIK - $this->alt - $this->y;
    }

    public function bosluk(float $yukseklik): void
    {
        $this->y += $yukseklik;
    }

    /**
     * Paragrafın yüksekliği (çizmeden). Kısa maddeleri sayfada bölmemek
     * için: yerAc(yukseklik(...)).
     *
     * @param list<array<string,mixed>> $parcalar
     * @param array<string,mixed>       $secenek paragraf() ile aynı
     */
    public function yukseklik(array $parcalar, array $secenek = []): float
    {
        $ic       = isset($secenek['zemin']) ? (float) ($secenek['ic'] ?? 10.0) : 0.0;
        $girinti  = (float) ($secenek['girinti'] ?? 0.0) + $ic + (isset($secenek['serit']) ? 3.0 : 0.0);
        $genislik = (float) ($secenek['genislik'] ?? $this->genislik()) - $girinti - $ic;
        $satirlar = $this->satirla($parcalar, $genislik, (float) ($secenek['asilma'] ?? 0.0), (float) ($secenek['satir'] ?? 1.4));

        return array_sum(array_column($satirlar, 'yukseklik')) + ($satirlar === [] ? 0.0 : 2 * $ic);
    }

    /**
     * Birkaç paragrafı (ör. konu başlığı + özeti) aynı sayfada tutar:
     * toplamı sayfaya sığıyorsa ve şu an sığmıyorsa yeni sayfa.
     *
     * @param list<array{0: list<array<string,mixed>>, 1?: array<string,mixed>}> $paragraflar
     */
    public function birlikteTut(array $paragraflar, float $ek = 0.0): void
    {
        $toplam = $ek;

        foreach ($paragraflar as $p) {
            $toplam += $this->yukseklik($p[0], $p[1] ?? []);
        }

        $sayfa = PdfBelgesi::YUKSEKLIK - $this->ust - $this->alt;

        if ($toplam <= $sayfa * 0.6) {
            $this->yerAc($toplam);
        }
    }

    /**
     * Paragrafı yazar.
     *
     * @param list<array<string,mixed>> $parcalar
     * @param array{girinti?: float, asilma?: float, satir?: float, genislik?: float, zemin?: array{int,int,int}, serit?: array{int,int,int}, ic?: float} $secenek
     *        satir: satır yüksekliği / punto (1.4); zemin: paragrafın arkasında renkli kutu;
     *        serit: kutunun solunda 3 pt'lik şerit; ic: kutunun iç boşluğu
     */
    public function paragraf(array $parcalar, array $secenek = []): void
    {
        $ic      = isset($secenek['zemin']) ? (float) ($secenek['ic'] ?? 10.0) : 0.0;
        $girinti = (float) ($secenek['girinti'] ?? 0.0) + $ic + (isset($secenek['serit']) ? 3.0 : 0.0);
        $genislik = (float) ($secenek['genislik'] ?? $this->genislik()) - $girinti - $ic;
        $satirlar = $this->satirla($parcalar, $genislik, (float) ($secenek['asilma'] ?? 0.0), (float) ($secenek['satir'] ?? 1.4));

        if ($satirlar === []) {
            return;
        }

        $x0 = $this->sol + (float) ($secenek['girinti'] ?? 0.0);
        $kutuGenisligi = (float) ($secenek['genislik'] ?? $this->genislik()) - (float) ($secenek['girinti'] ?? 0.0);

        // Satırlar sayfa sayfa: zemin her sayfadaki parça için önce çizilir (metin üstte kalsın).
        $i = 0;
        $n = count($satirlar);

        while ($i < $n) {
            $this->yerAc($satirlar[$i]['yukseklik'] + 2 * $ic);

            $parca  = [];
            $toplam = 2 * $ic;

            while ($i < $n && ($parca === [] || $toplam + $satirlar[$i]['yukseklik'] <= $this->kalan())) {
                $parca[] = $satirlar[$i];
                $toplam += $satirlar[$i]['yukseklik'];
                $i++;
            }

            if (isset($secenek['zemin'])) {
                $this->pdf->dikdortgen($x0, $this->y, $kutuGenisligi, $toplam, $secenek['zemin'], 4.0);
            }

            if (isset($secenek['serit'])) {
                $this->pdf->dikdortgen($x0, $this->y, 3.0, $toplam, $secenek['serit'], 1.5);
            }

            $this->y += $ic;

            foreach ($parca as $satir) {
                $this->satiriCiz($satir, $this->sol + $girinti);
                $this->y += $satir['yukseklik'];
            }

            $this->y += $ic;
        }
    }

    /**
     * Tek satırlık, kırılmayan metin (üst / alt bilgi): genişliği aşarsa
     * sonu "…" ile kısaltılır. y taban çizgisidir.
     *
     * @param array{int,int,int} $renk
     */
    public function tekSatir(string $utf8, float $x, float $y, string $yazitipi, float $punto, array $renk, float $enCok, string $hiza = 'sol'): float
    {
        $kodlu = self::kisalt(Yazitipi::kodla($utf8), $yazitipi, $punto, $enCok);
        $g     = Yazitipi::genislik($kodlu, $yazitipi, $punto);

        $this->pdf->metin($hiza === 'sag' ? $x - $g : $x, $y, $kodlu, $yazitipi, $punto, $renk);

        return $g;
    }

    /** Kodlu metni genişliğe sığacak kadar kısaltır ("…" = 0x85). */
    public static function kisalt(string $kodlu, string $yazitipi, float $punto, float $enCok): string
    {
        if (Yazitipi::genislik($kodlu, $yazitipi, $punto) <= $enCok) {
            return $kodlu;
        }

        $nokta = "\x85";

        while ($kodlu !== '' && Yazitipi::genislik(rtrim($kodlu) . $nokta, $yazitipi, $punto) > $enCok) {
            $kodlu = substr($kodlu, 0, -1);
        }

        return rtrim($kodlu) . $nokta;
    }

    /* =================================================================
     *  SATIR KIRMA
     * ============================================================== */

    /**
     * @param  list<array<string,mixed>> $parcalar
     * @return list<array{ogeler: list<array<string,mixed>>, yukseklik: float, taban: float}>
     */
    public function satirla(array $parcalar, float $genislik, float $asilma = 0.0, float $satirKatsayi = 1.4): array
    {
        $satirlar = [];
        $ogeler   = [];
        $x        = 0.0;
        $baslangic = 0.0;

        $bitir = static function () use (&$satirlar, &$ogeler, &$x, &$baslangic, $asilma, $satirKatsayi): void {
            // Sondaki boşluk atılır.
            while ($ogeler !== [] && ($son = end($ogeler)) !== false && ($son['bosluk'] ?? false)) {
                array_pop($ogeler);
            }

            if ($ogeler !== []) {
                $punto = max(array_map(static fn (array $o): float => $o['punto'], $ogeler));
                $satirlar[] = ['ogeler' => $ogeler, 'yukseklik' => $punto * $satirKatsayi, 'taban' => $punto * ($satirKatsayi - 1) / 2 + $punto * 0.78];
            }

            $ogeler    = [];
            $x         = $asilma;
            $baslangic = $asilma;
        };

        foreach ($parcalar as $parca) {
            if (isset($parca['sekme'])) {
                $x = max($x, (float) $parca['sekme']);

                continue;
            }

            $yazitipi = (string) ($parca['yazitipi'] ?? Yazitipi::NORMAL);
            $punto    = (float) ($parca['punto'] ?? 10.0);
            $stil     = ['yazitipi' => $yazitipi, 'punto' => $punto, 'renk' => $parca['renk'] ?? [17, 24, 39], 'url' => $parca['url'] ?? null];
            $kodlu    = Yazitipi::kodla((string) ($parca['metin'] ?? ''));

            if ($kodlu === '') {
                continue;
            }

            // Rozet ve "butun" parça bölünmez (satıra sığmazsa alt satıra iner).
            if (isset($parca['rozet']) || !empty($parca['butun'])) {
                $g = Yazitipi::genislik($kodlu, $yazitipi, $punto) + (isset($parca['rozet']) ? 2 * self::ROZET_YATAY : 0);

                if ($x + $g > $genislik && $x > $baslangic) {
                    $bitir();
                }

                $ogeler[] = $stil + ['x' => $x, 'metin' => $kodlu, 'genislik' => $g] + (isset($parca['rozet']) ? ['rozet' => $parca['rozet']] : []);
                $x += $g;

                continue;
            }

            foreach (preg_split('/( +)/', $kodlu, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $parcacik) {
                $bosluk = $parcacik[0] === ' ';
                $g      = Yazitipi::genislik($parcacik, $yazitipi, $punto);

                if ($bosluk) {
                    if ($x > $baslangic) {
                        $ogeler[] = $stil + ['x' => $x, 'metin' => $parcacik, 'genislik' => $g, 'bosluk' => true];
                        $x += $g;
                    }

                    continue;
                }

                if ($x + $g > $genislik && $x > $baslangic) {
                    $bitir();
                }

                // Satırdan uzun tek kelime (adres, uzun sayı): harf harf.
                while ($g > $genislik - $x && strlen($parcacik) > 1) {
                    $sigan = '';

                    foreach (str_split($parcacik) as $harf) {
                        if (Yazitipi::genislik($sigan . $harf, $yazitipi, $punto) > $genislik - $x && $sigan !== '') {
                            break;
                        }

                        $sigan .= $harf;
                    }

                    $ogeler[] = $stil + ['x' => $x, 'metin' => $sigan, 'genislik' => Yazitipi::genislik($sigan, $yazitipi, $punto)];
                    $bitir();
                    $parcacik = (string) substr($parcacik, strlen($sigan));
                    $g        = Yazitipi::genislik($parcacik, $yazitipi, $punto);
                }

                $ogeler[] = $stil + ['x' => $x, 'metin' => $parcacik, 'genislik' => $g];
                $x += $g;
            }
        }

        $bitir();

        return $satirlar;
    }

    /** @param array{ogeler: list<array<string,mixed>>, yukseklik: float, taban: float} $satir */
    private function satiriCiz(array $satir, float $x0): void
    {
        $taban = $this->y + $satir['taban'];

        // Aynı biçimdeki ardışık parçalar tek Tj; bağlantı alanı da birleşir.
        $grup = null;

        $yazGrup = function (?array $g) use ($taban, $x0, $satir): void {
            if ($g === null) {
                return;
            }

            if (isset($g['rozet'])) {
                $p = $g['punto'];
                $this->pdf->dikdortgen($x0 + $g['x'], $taban - $p * 0.78 - self::ROZET_DIKEY, $g['genislik'], $p + 2 * self::ROZET_DIKEY, $g['rozet'], ($p + 2 * self::ROZET_DIKEY) / 2);
                $this->pdf->metin($x0 + $g['x'] + self::ROZET_YATAY, $taban, $g['metin'], $g['yazitipi'], $p, $g['renk']);

                return;
            }

            $this->pdf->metin($x0 + $g['x'], $taban, $g['metin'], $g['yazitipi'], $g['punto'], $g['renk']);

            if ($g['url'] !== null) {
                $this->pdf->baglanti($x0 + $g['x'] - 1, $this->y, $g['genislik'] + 2, $satir['yukseklik'], (string) $g['url']);
            }
        };

        foreach ($satir['ogeler'] as $o) {
            $ayni = $grup !== null && !isset($o['rozet']) && !isset($grup['rozet'])
                && $grup['yazitipi'] === $o['yazitipi'] && $grup['punto'] === $o['punto'] && $grup['renk'] === $o['renk'] && $grup['url'] === $o['url']
                && abs($grup['x'] + $grup['genislik'] - $o['x']) < 0.01;

            if ($ayni) {
                $grup['metin']    .= $o['metin'];
                $grup['genislik'] += $o['genislik'];

                continue;
            }

            $yazGrup($grup);
            $grup = $o;
        }

        $yazGrup($grup);
    }
}
