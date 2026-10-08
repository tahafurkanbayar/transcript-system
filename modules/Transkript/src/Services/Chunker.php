<?php
/**
 * =====================================================================
 *  Chunker – transcript'i AI'a gidecek parçalara böler
 * ---------------------------------------------------------------------
 *  docs/TEKNIK_RAPOR.md 6. bölüm: hedef ~8–10 dakika ya da ~4.000 token
 *  (hangisi önce dolarsa), 30 saniye örtüşme, segment sınırından
 *  bölünür. 3 saatlik video ~20 parça eder.
 *
 *  KESİM NOKTASI (yalnız cümle sonuyla biten bloktan: . ? ! …)
 *      1) token hedefi doldu → ondan sonraki ilk cümle sonu
 *      2) süre 8 dk'yı geçti → 8–10 dk aralığında 9 dk'ya EN YAKIN cümle
 *         sonu. ÖLÇÜLEN: "8 dk'dan sonraki ilk cümle sonu" parçaları hep
 *         ~8 dk'da kesiyor, örtüşmeyle 3 saat 25 parça ediyordu.
 *      3) en çok süreye (10 dk) ya da token tavanına (hedefin 1,25 katı)
 *         kadar cümle sonu yoksa — otomatik altyazıda noktalama yoktur —
 *         aday aralıktaki EN UZUN SESSİZLİK (bloklar arası boşluk);
 *         konuşmacı orada büyük olasılıkla cümleyi bitirmiştir. Hiç
 *         sessizlik yoksa (≤ 300 ms) 9 dk'ya en yakın blok.
 *    Tek başına sınırı aşan blok bölünmez.
 *
 *  ÖRTÜŞME: sonraki parça, önceki parçanın son 30 saniyesine denk gelen
 *  bloktan başlar; kesim noktasındaki konu iki parçada da bağlamıyla
 *  görünür. İlerleme garantilidir (her parça en az bir blok ilerler).
 *
 *  KUYRUK: kalan kısım en az sürenin dörtte birinden kısaysa ve birlikte
 *  token tavanını aşmıyorsa yeni parça açılmaz, son parçaya eklenir
 *  (2 dakikalık bir parça için ayrı AI çağrısı ödenmez).
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Services;

use Modules\Transkript\Ayarlar;
use Modules\Transkript\Transcript\Segment;

final class Chunker
{
    /** Bundan kısa boşluk "sessizlik" sayılmaz. */
    private const SESSIZLIK_MS = 300;

    public function __construct(
        private readonly int $enAzMs = 480000,
        private readonly int $enCokMs = 600000,
        private readonly int $hedefToken = 4000,
        private readonly int $ortusmeMs = 30000,
        private readonly float $karakterBasinaToken = 3.5,
    ) {
    }

    public static function fromAyarlar(): self
    {
        return new self(
            (int) Ayarlar::get('parca.en_az_sn', 480) * 1000,
            (int) Ayarlar::get('parca.en_cok_sn', 600) * 1000,
            (int) Ayarlar::get('parca.hedef_token', 4000),
            (int) Ayarlar::get('parca.ortusme_sn', 30) * 1000,
            (float) Ayarlar::get('parca.karakter_basina_token', 3.5),
        );
    }

    public function tahminiToken(string $metin): int
    {
        return (int) ceil(mb_strlen($metin) / max(1.0, $this->karakterBasinaToken));
    }

    /**
     * @param  list<Segment> $bloklar Cleaner::hazirla() çıktısı (sıralı)
     * @return list<Parca>
     */
    public function parcala(array $bloklar): array
    {
        $bloklar = array_values($bloklar);
        $n = count($bloklar);

        if ($n === 0) {
            return [];
        }

        $tokenler = array_map(fn (Segment $b): int => $this->tahminiToken($b->metin), $bloklar);
        $tavan    = (int) ceil($this->hedefToken * 1.25);
        $parcalar = [];
        $bas      = 0;

        while ($bas < $n) {
            $son = $this->kesimNoktasi($bloklar, $tokenler, $bas, $tavan);

            // Kuyruk: kalan çok kısaysa ve token tavanını aşmıyorsa bu parçaya kat.
            if ($son < $n - 1
                && $bloklar[$n - 1]->bitisMs - $bloklar[$son]->bitisMs < intdiv($this->enAzMs, 4)
                && array_sum(array_slice($tokenler, $bas)) <= $tavan) {
                $son = $n - 1;
            }

            $dilim = array_slice($bloklar, $bas, $son - $bas + 1);

            $parcalar[] = new Parca(
                count($parcalar) + 1,
                $bloklar[$bas]->baslangicMs,
                $bloklar[$son]->bitisMs,
                array_sum(array_slice($tokenler, $bas, $son - $bas + 1)),
                $dilim,
            );

            if ($son >= $n - 1) {
                break;
            }

            $bas = $this->sonrakiBaslangic($bloklar, $bas, $son);
        }

        return $parcalar;
    }

    /**
     * $bas'tan başlayan parçanın son bloğunun indisi.
     *
     * @param list<Segment> $bloklar
     * @param list<int>     $tokenler
     */
    private function kesimNoktasi(array $bloklar, array $tokenler, int $bas, int $tavan): int
    {
        $n = count($bloklar);
        $token = 0;
        $hedefeUlasti = null;   // hedefe ulaşılan ilk blok
        $son = $bas;
        $orta = intdiv($this->enAzMs + $this->enCokMs, 2);
        $sureAdayi = null;      // cümle sonu, süresi ortaya (9 dk) en yakın
        $sureFarki = PHP_INT_MAX;

        for ($i = $bas; $i < $n; $i++) {
            $sure = $bloklar[$i]->bitisMs - $bloklar[$bas]->baslangicMs;
            $token += $tokenler[$i];

            // Sınırı aşan blok bu parçaya alınmaz (ilk blok hariç: tek başına büyükse yine de alınır).
            if ($i > $bas && ($sure > $this->enCokMs || $token > $tavan)) {
                break;
            }

            $son = $i;

            if ($hedefeUlasti === null && ($sure >= $this->enAzMs || $token >= $this->hedefToken)) {
                $hedefeUlasti = $i;
            }

            if ($hedefeUlasti === null || !Cleaner::cumleSonu($bloklar[$i]->metin)) {
                continue;
            }

            // Token hedefi doldu: ilk cümle sonunda kes (parça şişmesin).
            if ($token >= $this->hedefToken) {
                return $i;
            }

            // Süre hedefi doldu: 8–10 dk aralığında 9 dk'ya en yakın cümle sonu.
            if (abs($sure - $orta) < $sureFarki) {
                $sureFarki = abs($sure - $orta);
                $sureAdayi = $i;
            }
        }

        if ($sureAdayi !== null) {
            return $sureAdayi;
        }

        if ($hedefeUlasti === null || $son === $n - 1) {
            return $son;   // hedefe ulaşılamadan bitti ya da metin sonu
        }

        // Cümle sonu yok: [hedefeUlasti, son] aralığında en uzun sessizlikten kes.
        $enIyi = $son;
        $enUzunBosluk = -1;
        $ortayaEnYakin = $son;
        $ortaFarki = PHP_INT_MAX;

        for ($i = $hedefeUlasti; $i <= $son && $i < $n - 1; $i++) {
            $bosluk = $bloklar[$i + 1]->baslangicMs - $bloklar[$i]->bitisMs;
            $fark   = abs($bloklar[$i]->bitisMs - $bloklar[$bas]->baslangicMs - $orta);

            if ($bosluk > $enUzunBosluk) {
                $enUzunBosluk = $bosluk;
                $enIyi = $i;
            }

            if ($fark < $ortaFarki) {
                $ortaFarki = $fark;
                $ortayaEnYakin = $i;
            }
        }

        /* Gerçek bir sessizlik yoksa (ÖLÇÜLEN: otomatik altyazıda örtüşme
         * düzeltilince boşluklar 0 kalıyor ve hep ilk aday seçiliyordu)
         * 9 dk'ya en yakın blokta kes. */
        return $enUzunBosluk > self::SESSIZLIK_MS ? $enIyi : $ortayaEnYakin;
    }

    /** Örtüşme: önceki parçanın son 30 sn'sine denk gelen ilk blok. */
    private function sonrakiBaslangic(array $bloklar, int $bas, int $son): int
    {
        $esik = $bloklar[$son]->bitisMs - $this->ortusmeMs;

        for ($j = $bas + 1; $j <= $son; $j++) {
            if ($bloklar[$j]->bitisMs > $esik) {
                return $j;
            }
        }

        return $son + 1;
    }
}
