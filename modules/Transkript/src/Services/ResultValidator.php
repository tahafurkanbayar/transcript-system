<?php
/**
 * =====================================================================
 *  ResultValidator – AI çıktısına güvenme (CLAUDE.md, kural 7)
 * ---------------------------------------------------------------------
 *  Doğrulamadan geçmeyen JSON kaydedilmez. Üç iş yapar:
 *
 *  1) AYIKLA: düz JSON, ```json … ``` bloğu ya da metnin içindeki ilk
 *     "{" ile son "}" arası. Şema zorlaması açık olsa da model kimi
 *     zaman açıklama cümlesi ya da kod bloğu ekler.
 *
 *  2) ŞEMA: kök nesne, zorunlu metin alanları, listelerin her maddesi.
 *     Bozuk MADDE atılır (uyarıyla); bozuk KÖK GecersizSonuc fırlatır
 *     ve Pipeline bir kez onarım ister. Metinler kırpılır, kontrol
 *     karakterleri silinir, listeler sınırlanır, enum dışı değer
 *     varsayılana düşer. HTML'e dokunulmaz: metin düz metin olarak
 *     saklanır, görünümde e() / textContent ile basılır.
 *
 *  3) ZAMAN: model zaman damgasını uydurabilir. Her damga
 *       - okunamıyorsa                       atılır
 *       - parça aralığının dışındaysa (±2 sn) atılır
 *       - aralıktaysa                        EN YAKIN GERÇEK SEGMENTİN
 *                                            başlangıcına çekilir
 *     "Atılır": içerik maddesinde yalnız zaman (at) boşalır, madde
 *     kalır; zaman çizelgesi maddesi (yalnız zamandan ibaret) silinir.
 *
 *  Çıktıdaki zamanlar "hh:mm:ss" metnidir (Pipeline::ogeler milisaniyeye
 *  çevirir); ayrıca konu aralıkları sıralanır, aynı ana düşen zaman
 *  çizelgesi maddeleri teke iner.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Services;

use Modules\Transkript\Zaman;

final class ResultValidator
{
    /** Zaman damgası aralık dışına bu kadar taşabilir (yuvarlama payı). */
    public const TOLERANS_MS = 2000;

    public const ONEM  = ['critical', 'important', 'useful'];
    public const GUVEN = ['stated_as_fact', 'opinion', 'speculation'];

    private const LISTE_EN_COK   = 25;
    private const BASLIK_EN_COK  = 200;
    private const MADDE_EN_COK   = 700;
    private const OZET_EN_COK    = 5000;
    private const DETAYLI_EN_COK = 30000;

    /** @var list<string> */
    private array $uyarilar = [];

    /* =================================================================
     *  1) AYIKLAMA
     * ============================================================== */

    /** @return array<string,mixed>|null Kök nesne; JSON bulunamazsa null */
    public static function ayikla(string $metin): ?array
    {
        $metin = trim($metin);
        $adaylar = [$metin];

        if (preg_match('/```(?:json)?\s*(.+?)```/is', $metin, $m)) {
            $adaylar[] = trim($m[1]);
        }

        $bas = strpos($metin, '{');
        $son = strrpos($metin, '}');

        if ($bas !== false && $son !== false && $son > $bas) {
            $adaylar[] = substr($metin, $bas, $son - $bas + 1);
        }

        foreach ($adaylar as $aday) {
            $veri = json_decode($aday, true);

            // Liste değil, anahtarlı nesne olmalı.
            if (is_array($veri) && ($veri === [] || !array_is_list($veri))) {
                return $veri;
            }
        }

        return null;
    }

    /* =================================================================
     *  2) MAP SONUCU (tek parça)
     * ============================================================== */

    /**
     * @param  array<string,mixed> $veri
     * @param  list<int>           $baslangiclar Parçadaki gerçek segment başlangıçları (ms, sıralı)
     * @return array{sonuc: array<string,mixed>, uyarilar: list<string>}
     *
     * @throws GecersizSonuc
     */
    public function parca(array $veri, int $basMs, int $bitMs, array $baslangiclar): array
    {
        $this->uyarilar = [];
        $sorunlar = $this->zorunluMetinler($veri, ['topic', 'summary']);

        if ($sorunlar !== []) {
            throw new GecersizSonuc($sorunlar);
        }

        $zaman = fn (mixed $deger): ?string => $this->yaslan($deger, $basMs, $bitMs, $baslangiclar);

        $sonuc = [
            'chunk'                 => ['start' => Zaman::yaz($basMs), 'end' => Zaman::yaz($bitMs)],
            'topic'                 => self::metin($veri['topic'], self::BASLIK_EN_COK),
            'summary'               => self::metin($veri['summary'], self::OZET_EN_COK, true),
        ] + $this->maddeler($veri, $zaman);

        if ($sonuc['summary'] === '' && $this->bosMu($sonuc)) {
            throw new GecersizSonuc(['Analiz boş: summary ve bütün listeler boş.']);
        }

        return ['sonuc' => $sonuc, 'uyarilar' => $this->uyarilar];
    }

    /* =================================================================
     *  2) REDUCE SONUCU (bütün video)
     * ============================================================== */

    /**
     * @param  array<string,mixed> $veri
     * @param  list<int>           $baslangiclar Transcript'in bütün segment başlangıçları (ms, sıralı)
     * @return array{sonuc: array<string,mixed>, uyarilar: list<string>}
     *
     * @throws GecersizSonuc
     */
    public function birlestirme(array $veri, int $bitMs, array $baslangiclar): array
    {
        $this->uyarilar = [];
        $sorunlar = $this->zorunluMetinler($veri, ['summary']);

        if ($sorunlar === [] && self::metin($veri['summary'], self::OZET_EN_COK, true) === '') {
            $sorunlar[] = '"summary" boş olamaz.';
        }

        if ($sorunlar !== []) {
            throw new GecersizSonuc($sorunlar);
        }

        $zaman = fn (mixed $deger): ?string => $this->yaslan($deger, 0, $bitMs, $baslangiclar);

        $konular = $this->liste($veri, 'topics', function (array $m) use ($zaman): ?array {
            $baslik = self::metin($m['title'] ?? null, self::BASLIK_EN_COK);
            $bas = $zaman($m['start'] ?? null);
            $bit = $zaman($m['end'] ?? null);

            if ($bas !== null && $bit !== null && Zaman::oku($bit) < Zaman::oku($bas)) {
                $bit = null;
            }

            return $baslik === '' ? null : [
                'title'   => $baslik,
                'start'   => $bas,
                'end'     => $bit,
                'summary' => self::metin($m['summary'] ?? null, self::OZET_EN_COK, true),
            ];
        });
        $konular = self::konulariDuzenle($konular, $bitMs);

        $oneriler = $this->liste($veri, 'recommendations', static function (mixed $m): ?array {
            $metin = self::metin(is_array($m) ? ($m['text'] ?? null) : $m, self::MADDE_EN_COK);

            return $metin === '' ? null : ['text' => $metin];
        }, true);

        $sonuc = [
            'summary'          => self::metin($veri['summary'], self::OZET_EN_COK, true),
            'detailed_summary' => self::metin($veri['detailed_summary'] ?? '', self::DETAYLI_EN_COK, true),
            'topics'           => $konular,
        ] + $this->maddeler($veri, $zaman) + [
            'recommendations'  => $oneriler,
            // Kişisel filtre MVP sonrası (4b); model ne yazarsa yazsın boş.
            'user_relevance'   => null,
        ];

        return ['sonuc' => $sonuc, 'uyarilar' => $this->uyarilar];
    }

    /**
     * Konuları videoyu kesintisiz bölen aralıklara çevirir (YouTube
     * bölümleri de buradan üretilir). Model aralıkları çakıştırabiliyor
     * ("03:08–08:56" ardından "08:38–13:02"):
     *   - başlangıca göre sıralanır (başlangıcı olmayan sona),
     *   - ilk konu 00:00:00'dan başlar,
     *   - her konunun bitişi bir sonrakinin başlangıcıdır,
     *   - son konu videonun sonunda biter; hiçbir bitiş videoyu aşmaz.
     * Rapor sayfası eski raporlara da uygular (RaporVerisi); $bitMs
     * bilinmiyorsa (null) son konunun bitişine dokunulmaz.
     *
     * @param  list<array<string,mixed>> $konular title, start, end, summary
     * @return list<array<string,mixed>>
     */
    public static function konulariDuzenle(array $konular, ?int $bitMs): array
    {
        $konular = array_values(array_filter($konular, 'is_array'));

        usort($konular, static fn (array $a, array $b): int
            => (Zaman::oku((string) ($a['start'] ?? '')) ?? PHP_INT_MAX) <=> (Zaman::oku((string) ($b['start'] ?? '')) ?? PHP_INT_MAX));

        $son = count($konular) - 1;

        foreach ($konular as $i => &$konu) {
            $bas = Zaman::oku((string) ($konu['start'] ?? ''));

            if ($bas === null) {
                continue;
            }

            if ($i === 0) {
                $konu['start'] = Zaman::yaz(0);
                $bas = 0;
            }

            $sonraki = $i < $son ? Zaman::oku((string) ($konular[$i + 1]['start'] ?? '')) : null;
            $bit     = $sonraki ?? ($bitMs ?? Zaman::oku((string) ($konu['end'] ?? '')));

            if ($bit !== null && $bitMs !== null) {
                $bit = min($bit, $bitMs);
            }

            $konu['end'] = $bit !== null && $bit > $bas ? Zaman::yaz($bit) : null;
        }

        unset($konu);

        return $konular;
    }

    /* =================================================================
     *  ORTAK MADDE LİSTELERİ (map ve reduce aynı biçim)
     * ============================================================== */

    /**
     * @param  array<string,mixed>       $veri
     * @param  \Closure(mixed): ?string  $zaman
     * @return array<string,list<array<string,mixed>>>
     */
    private function maddeler(array $veri, \Closure $zaman): array
    {
        $noktalar = $this->liste($veri, 'important_points', static function (array $m) use ($zaman): ?array {
            $metin = self::metin($m['text'] ?? null, self::MADDE_EN_COK);
            $onem  = strtolower(trim((string) ($m['importance'] ?? '')));

            return $metin === '' ? null : [
                'text'       => $metin,
                'at'         => $zaman($m['at'] ?? null),
                'importance' => in_array($onem, self::ONEM, true) ? $onem : 'useful',
            ];
        });

        $teknik = $this->liste($veri, 'technical_information', static function (array $m) use ($zaman): ?array {
            $ad = self::metin($m['name'] ?? null, self::BASLIK_EN_COK);

            return $ad === '' ? null : [
                'name'        => $ad,
                'category'    => self::metinVeyaNull($m['category'] ?? null, 100),
                'description' => self::metinVeyaNull($m['description'] ?? null, self::MADDE_EN_COK),
                'at'          => $zaman($m['at'] ?? null),
            ];
        });

        $iddialar = $this->liste($veri, 'claims', static function (array $m) use ($zaman): ?array {
            $metin = self::metin($m['text'] ?? null, self::MADDE_EN_COK);
            $guven = strtolower(trim((string) ($m['speaker_confidence'] ?? '')));

            return $metin === '' ? null : [
                'text'               => $metin,
                'at'                 => $zaman($m['at'] ?? null),
                'speaker_confidence' => in_array($guven, self::GUVEN, true) ? $guven : null,
            ];
        });

        $fikirler = $this->liste($veri, 'ideas', static function (array $m) use ($zaman): ?array {
            $metin = self::metin($m['text'] ?? null, self::MADDE_EN_COK);

            return $metin === '' ? null : [
                'text' => $metin,
                'type' => self::metinVeyaNull($m['type'] ?? null, 100),
                'at'   => $zaman($m['at'] ?? null),
            ];
        });

        // Zaman çizelgesi yalnız zamandan ibaret: zamanı tutmayan madde silinir.
        $gorulen = [];
        $zamanlar = $this->liste($veri, 'timestamps', static function (array $m) use ($zaman, &$gorulen): ?array {
            $at     = $zaman($m['at'] ?? null);
            $etiket = self::metin($m['label'] ?? null, self::BASLIK_EN_COK);

            if ($at === null || $etiket === '' || isset($gorulen[$at])) {
                return null;
            }

            $gorulen[$at] = true;

            return ['at' => $at, 'label' => $etiket];
        });
        usort($zamanlar, static fn (array $a, array $b): int => Zaman::oku($a['at']) <=> Zaman::oku($b['at']));

        return [
            'important_points'      => $noktalar,
            'technical_information' => $teknik,
            'claims'                => $iddialar,
            'ideas'                 => $fikirler,
            'timestamps'            => $zamanlar,
        ];
    }

    /**
     * Liste alanını dönüştürür. Alan yoksa boş liste; liste değilse
     * uyarı + boş liste; dönüştürücü null dönerse madde atılır.
     *
     * @param  array<string,mixed> $veri
     * @return list<array<string,mixed>>
     */
    private function liste(array $veri, string $alan, callable $donustur, bool $metinMaddeOlabilir = false): array
    {
        $liste = $veri[$alan] ?? [];

        if (!is_array($liste) || !array_is_list($liste)) {
            $this->uyarilar[] = $alan . ' liste değil; atlandı.';

            return [];
        }

        $sonuc = [];
        $atilan = 0;

        foreach ($liste as $madde) {
            $donusen = is_array($madde) || $metinMaddeOlabilir ? $donustur($madde) : null;

            if ($donusen === null) {
                $atilan++;
                continue;
            }

            $sonuc[] = $donusen;
        }

        if ($atilan > 0) {
            $this->uyarilar[] = sprintf('%s: %d bozuk / zamanı tutmayan madde atıldı.', $alan, $atilan);
        }

        if (count($sonuc) > self::LISTE_EN_COK) {
            $this->uyarilar[] = sprintf('%s: %d maddeden ilk %d tutuldu.', $alan, count($sonuc), self::LISTE_EN_COK);
            $sonuc = array_slice($sonuc, 0, self::LISTE_EN_COK);
        }

        return $sonuc;
    }

    /* =================================================================
     *  3) ZAMAN DAMGASI
     * ============================================================== */

    /**
     * Zamanı en yakın gerçek segment başlangıcına çeker.
     *
     * @param  list<int> $baslangiclar sıralı
     * @return string|null "hh:mm:ss"; okunamaz ya da aralık dışıysa null
     */
    public function yaslan(mixed $deger, int $basMs, int $bitMs, array $baslangiclar): ?string
    {
        $ms = is_string($deger) || is_int($deger) ? Zaman::oku((string) $deger) : null;

        if ($ms === null || $ms < $basMs - self::TOLERANS_MS || $ms > $bitMs + self::TOLERANS_MS) {
            return null;
        }

        if ($baslangiclar === []) {
            return Zaman::yaz(max($basMs, min($bitMs, $ms)));
        }

        return Zaman::yaz(self::enYakin($baslangiclar, $ms));
    }

    /** @param list<int> $sirali */
    public static function enYakin(array $sirali, int $hedef): int
    {
        $alt = 0;
        $ust = count($sirali) - 1;

        // İkili arama: hedeften büyük-eşit ilk eleman.
        while ($alt < $ust) {
            $orta = intdiv($alt + $ust, 2);

            if ($sirali[$orta] < $hedef) {
                $alt = $orta + 1;
            } else {
                $ust = $orta;
            }
        }

        $aday = $sirali[$alt];

        if ($alt > 0 && abs($sirali[$alt - 1] - $hedef) <= abs($aday - $hedef)) {
            return $sirali[$alt - 1];
        }

        return $aday;
    }

    /* =================================================================
     *  METİN
     * ============================================================== */

    /**
     * @param  array<string,mixed> $veri
     * @param  list<string>        $alanlar
     * @return list<string>
     */
    private function zorunluMetinler(array $veri, array $alanlar): array
    {
        $sorunlar = [];

        foreach ($alanlar as $alan) {
            if (!array_key_exists($alan, $veri)) {
                $sorunlar[] = sprintf('"%s" alanı eksik.', $alan);
            } elseif (!is_string($veri[$alan])) {
                $sorunlar[] = sprintf('"%s" metin olmalı.', $alan);
            }
        }

        return $sorunlar;
    }

    /** @param array<string,mixed> $sonuc */
    private function bosMu(array $sonuc): bool
    {
        foreach (['important_points', 'technical_information', 'claims', 'ideas', 'timestamps'] as $alan) {
            if ($sonuc[$alan] !== []) {
                return false;
            }
        }

        return true;
    }

    /**
     * Düz metin: kontrol karakterleri silinir, boşluklar sadeleşir
     * (paragraf modunda satır sonları korunur), uzunluk sınırlanır.
     */
    public static function metin(mixed $deger, int $enCok, bool $paragraf = false): string
    {
        if (!is_string($deger) && !is_int($deger) && !is_float($deger)) {
            return '';
        }

        $metin = (string) $deger;

        if (!mb_check_encoding($metin, 'UTF-8')) {
            $metin = mb_convert_encoding($metin, 'UTF-8', 'UTF-8');
        }

        $metin = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $metin);

        $metin = $paragraf
            ? (string) preg_replace(['/[ \t]+/u', '/ *\R */u', '/\n{3,}/'], [' ', "\n", "\n\n"], $metin)
            : (string) preg_replace('/\s+/u', ' ', $metin);

        $metin = trim($metin);

        return mb_strlen($metin) > $enCok ? rtrim(mb_substr($metin, 0, $enCok - 1)) . '…' : $metin;
    }

    private static function metinVeyaNull(mixed $deger, int $enCok): ?string
    {
        $metin = self::metin($deger, $enCok);

        return $metin === '' ? null : $metin;
    }
}
