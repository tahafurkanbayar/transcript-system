<?php
/**
 * =====================================================================
 *  Cleaner – transcript temizleme ve cümle bloklarına birleştirme
 * ---------------------------------------------------------------------
 *  docs/TEKNIK_RAPOR.md 5. bölüm ("TranscriptCleaner"). Ham segmentler
 *  veritabanında olduğu gibi kalır; temizlik yalnızca AI'a giden
 *  kopyada yapılır, bu yüzden geri alınabilir.
 *
 *  temizle()  segment başına:
 *    · HTML varlıkları açılır (YouTube metni çoğu kez çift kaçışlıdır),
 *      etiketler atılır
 *    · [Müzik], [Alkış], [Music], [Applause], ♪ gibi işaretler atılır
 *    · boşluk sadeleşir, boş segment atılır
 *    · ardışık aynı satır tek segmente iner (otomatik altyazı tekrarı)
 *    · ÖRTÜŞME: otomatik altyazıda satırlar üst üste biner (ÖLÇÜLEN:
 *      0.199 sn'de başlayıp 4.72 sn süren satırdan sonraki 2.52'de
 *      başlıyor). Bitiş, sonraki satırın başlangıcına çekilir.
 *
 *  bloklara()  çok kısa segmentleri 15–30 sn'lik bloklara birleştirir;
 *    blok tercihen cümle sonunda (. ? ! …) kapanır, 30 sn'yi geçmez,
 *    konuşmacı değişince kapanır. Tek başına 30 sn'yi aşan segment
 *    bölünmez (zaman damgası gerçek bir segmente denk gelmeli).
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Services;

use Modules\Transkript\Ayarlar;
use Modules\Transkript\Transcript\Segment;

final class Cleaner
{
    /** Köşeli parantez içindeki konuşma dışı işaretler (Türkçe ve İngilizce). */
    private const ISARETLER = '/[\[(](?:müzik|muzik|music|alkış|alkis|applause|laughter|kahkaha|gülüşmeler|gulusmeler|gülüşme|güler|sessizlik|silence|inaudible|anlaşılmıyor|anlasilmiyor|noise|gürültü|gurultu|foreign|yabancı dil|__)[\])]/iu';

    private const CUMLE_SONU = '/[.!?…]["\'”’)\]]*$/u';

    public function __construct(
        private readonly int $blokEnAzMs = 15000,
        private readonly int $blokEnCokMs = 30000,
    ) {
    }

    public static function fromAyarlar(): self
    {
        return new self(
            (int) Ayarlar::get('temizleme.blok_en_az_sn', 15) * 1000,
            (int) Ayarlar::get('temizleme.blok_en_cok_sn', 30) * 1000,
        );
    }

    /**
     * @param  list<Segment> $segmentler
     * @return list<Segment>
     */
    public function hazirla(array $segmentler): array
    {
        return $this->bloklara($this->temizle($segmentler));
    }

    /**
     * @param  list<Segment> $segmentler
     * @return list<Segment>
     */
    public function temizle(array $segmentler): array
    {
        $temiz = [];

        foreach ($segmentler as $s) {
            $metin = self::metniTemizle($s->metin);

            if ($metin !== '') {
                $temiz[] = new Segment($s->baslangicMs, max($s->bitisMs, $s->baslangicMs), $metin, $s->konusmaci);
            }
        }

        usort($temiz, static fn (Segment $a, Segment $b): int => $a->baslangicMs <=> $b->baslangicMs);

        // Ardışık aynı satır: ilkinin bitişi uzatılır, ikincisi atılır.
        $tekil = [];

        foreach ($temiz as $s) {
            $son = end($tekil);

            if ($son instanceof Segment && $son->konusmaci === $s->konusmaci
                && mb_strtolower($son->metin) === mb_strtolower($s->metin)) {
                $tekil[count($tekil) - 1] = new Segment($son->baslangicMs, max($son->bitisMs, $s->bitisMs), $son->metin, $son->konusmaci);
                continue;
            }

            $tekil[] = $s;
        }

        // Örtüşme: bitiş sonraki başlangıcı geçmesin.
        $sonuc = [];

        foreach ($tekil as $i => $s) {
            $sonraki = $tekil[$i + 1] ?? null;
            $bitis   = $s->bitisMs;

            if ($sonraki !== null && $sonraki->baslangicMs < $bitis) {
                $bitis = max($s->baslangicMs, $sonraki->baslangicMs);
            }

            $sonuc[] = new Segment($s->baslangicMs, $bitis, $s->metin, $s->konusmaci);
        }

        return $sonuc;
    }

    /**
     * @param  list<Segment> $segmentler temizlenmiş, sıralı
     * @return list<Segment>
     */
    public function bloklara(array $segmentler): array
    {
        $bloklar = [];
        $grup = [];

        $kapat = static function (array $grup) use (&$bloklar): void {
            if ($grup === []) {
                return;
            }

            $ilk = $grup[0];
            $son = $grup[count($grup) - 1];

            $bloklar[] = new Segment(
                $ilk->baslangicMs,
                $son->bitisMs,
                implode(' ', array_map(static fn (Segment $s): string => $s->metin, $grup)),
                $ilk->konusmaci,
            );
        };

        foreach ($segmentler as $s) {
            if ($grup !== []) {
                $ilk = $grup[0];
                $son = $grup[count($grup) - 1];

                $tasar     = $s->bitisMs - $ilk->baslangicMs > $this->blokEnCokMs;
                $yeterli   = $son->bitisMs - $ilk->baslangicMs >= $this->blokEnAzMs && self::cumleSonu($son->metin);
                $konusmaci = $s->konusmaci !== $ilk->konusmaci;

                if ($tasar || $yeterli || $konusmaci) {
                    $kapat($grup);
                    $grup = [];
                }
            }

            $grup[] = $s;
        }

        $kapat($grup);

        return $bloklar;
    }

    public static function metniTemizle(string $metin): string
    {
        // Çift kaçış: "&amp;#39;" → "&#39;" → "'" (en çok iki tur).
        for ($i = 0; $i < 2; $i++) {
            $acik = html_entity_decode($metin, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if ($acik === $metin) {
                break;
            }

            $metin = $acik;
        }

        $metin = strip_tags($metin);
        $metin = (string) preg_replace(self::ISARETLER, ' ', $metin);
        $metin = str_replace(['♪', '♫', "\u{200B}"], ' ', $metin);

        return trim((string) preg_replace('/\s+/u', ' ', $metin));
    }

    public static function cumleSonu(string $metin): bool
    {
        return preg_match(self::CUMLE_SONU, $metin) === 1;
    }
}
