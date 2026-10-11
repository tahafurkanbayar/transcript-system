<?php
/**
 * =====================================================================
 *  Kategoriler – videonun içerik türü, konu alanı, seviyesi ve etiketleri
 * ---------------------------------------------------------------------
 *  Kullanıcı isteği (11.10.2026): "videoların kategorisini belirleyelim,
 *  veritabanında tutalım; ileride ders videosundan soru / test, teknoloji
 *  tanıtımından makale gibi başka içerikler üreteceğiz". İki eksen:
 *
 *    içerik türü  videonun BİÇİMİ: ders anlatımı, vlog, haber, röportaj …
 *                 (ileride hangi üretimin anlamlı olduğunu bu belirler)
 *    konu alanı   videonun ANA KONUSU: teknoloji, spor, siyaset, sağlık …
 *                 (+ isteğe bağlı ikincil alan)
 *
 *  seviye: eğitim içeriğinde başlangıç / orta / ileri, öteki her şeyde
 *  genel. ana_konu: tek satır konu. etiketler: 3–8 anahtar kelime.
 *
 *  Kimlikler (slug) ASCII ve kalıcıdır; veritabanına ve API'ye bunlar
 *  yazılır, ekranda Türkçe adları görünür. Yeni değer EKLENEBİLİR, var
 *  olan değiştirilmez (kayıtlı satırlar ona bağlı).
 *
 *  AI çıktısına güvenme (CLAUDE.md kural 7): dogrula() listede olmayan
 *  değeri "diger"e çeker, metinleri kırpar; elle girişte katidir.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Kategori;

use Modules\Transkript\Services\GecersizSonuc;
use Modules\Transkript\Services\ResultValidator;
use Modules\Transkript\Services\Search;

final class Kategoriler
{
    /** Sınıflandırma isteminin sürümü (prompts/kategori.<sürüm>.txt); kayıtla birlikte saklanır. */
    public const ISTEM_SURUMU = 'v1';

    public const DIGER = 'diger';

    /** İçerik türü (biçim): kimlik => [ad, isteme giden tanım]. */
    public const TURLER = [
        'ders_anlatimi'    => ['Ders anlatımı', 'bir konuyu ders gibi öğretir: okul dersi, sınav hazırlığı, kurs, akademik anlatım'],
        'egitim_rehber'    => ['Eğitim / nasıl yapılır', 'adım adım uygulama: yazılım veya araç eğitimi, tarif, kurulum, el işi'],
        'konferans_sunum'  => ['Konferans / sunum', 'sahnede ya da toplulukta tek konuşmacının sunumu: TED, seminer, açılış konuşması'],
        'belgesel'         => ['Belgesel / bilgilendirici', 'bir konuyu anlatıcı ve görsellerle bilgilendirici biçimde işler: belgesel, animasyonlu anlatım'],
        'haber'            => ['Haber', 'güncel olay ya da gelişmeyi aktaran haber, bülten, son dakika'],
        'roportaj_sohbet'  => ['Röportaj / sohbet', 'iki ya da daha çok kişinin karşılıklı konuşması: röportaj, podcast, söyleşi'],
        'tartisma_panel'   => ['Tartışma / panel', 'farklı görüşlerin tartışıldığı program: panel, münazara, açık oturum'],
        'yorum_analiz'     => ['Yorum / analiz', 'bir kişinin bir konu ya da olay hakkındaki yorumu, değerlendirmesi, köşe yazısı tarzı'],
        'inceleme_tanitim' => ['İnceleme / ürün tanıtımı', 'ürün, hizmet, kitap, film ya da teknoloji incelemesi, kutu açılışı, karşılaştırma'],
        'vlog'             => ['Vlog', 'kişinin günlük hayatını, gezisini, deneyimini kamera karşısında paylaştığı video'],
        'hikaye_anlati'    => ['Hikâye / kurgu', 'kısa film, öykü, kurgu anlatı, sesli kitap'],
        'eglence_mizah'    => ['Eğlence / mizah', 'skeç, şaka, stand-up, eğlence programı'],
        'muzik'            => ['Müzik', 'şarkı, klip, konser, mix; ağırlıkla müzik'],
        'mac_etkinlik'     => ['Maç / etkinlik yayını', 'spor karşılaşması, maç özeti, canlı etkinlik ya da tören yayını'],
        'oyun'             => ['Oyun videosu', 'oyun oynanışı, oyun yayını'],
        'reklam_tanitim'   => ['Reklam / kurumsal tanıtım', 'reklam filmi, marka ya da kurum tanıtımı'],
        self::DIGER        => ['Diğer', 'yukarıdakilerin hiçbiri'],
    ];

    /** Konu alanı: kimlik => [ad, isteme giden tanım]. */
    public const ALANLAR = [
        'teknoloji'       => ['Teknoloji', 'yazılım, yapay zekâ, internet, donanım, bilişim'],
        'bilim'           => ['Bilim', 'fizik, kimya, biyoloji, astronomi, evrim, matematik'],
        'saglik'          => ['Sağlık', 'tıp, beslenme, spor sağlığı, hastalıklar'],
        'psikoloji'       => ['Psikoloji', 'zihin, davranış, alışkanlıklar, ruh sağlığı'],
        'egitim_sinav'    => ['Eğitim / sınav', 'okul müfredatı, sınav hazırlığı (LGS, YKS, KPSS), dil öğrenimi, eğitim sistemi'],
        'tarih'           => ['Tarih', 'tarihî olaylar, dönemler, kişiler'],
        'siyaset'         => ['Siyaset', 'politika, seçim, devlet, uluslararası ilişkiler'],
        'ekonomi_finans'  => ['Ekonomi / finans', 'ekonomi, piyasa, yatırım, kişisel finans'],
        'is_kariyer'      => ['İş / girişimcilik', 'iş dünyası, girişimcilik, kariyer, pazarlama, yönetim'],
        'kisisel_gelisim' => ['Kişisel gelişim', 'motivasyon, verimlilik, hayat tavsiyeleri'],
        'spor'            => ['Spor', 'spor dalları, takımlar, sporcular, antrenman'],
        'kultur_sanat'    => ['Kültür / sanat', 'edebiyat, sinema, tiyatro, resim, kültür'],
        'muzik'           => ['Müzik', 'müzik, sanatçılar, enstrümanlar'],
        'eglence'         => ['Eğlence / magazin', 'eğlence dünyası, ünlüler, mizah'],
        'oyun'            => ['Oyun', 'video oyunları, masa oyunları, e-spor'],
        'yasam'           => ['Yaşam', 'yemek, seyahat, moda, ev, aile, hobiler'],
        'din_felsefe'     => ['Din / felsefe', 'din, inanç, felsefe, etik'],
        'doga_cevre'      => ['Doğa / çevre', 'hayvanlar, iklim, çevre, doğa'],
        'otomotiv'        => ['Otomotiv', 'araçlar, ulaşım'],
        'hukuk'           => ['Hukuk', 'yasalar, haklar, yargı'],
        'savunma'         => ['Savunma / güvenlik', 'savunma sanayii, ordu, siber güvenlik, güvenlik politikaları'],
        self::DIGER       => ['Diğer', 'yukarıdakilerin hiçbiri'],
    ];

    /** Seviye (yalnız eğitim içeriğinde anlamlı). */
    public const SEVIYELER = [
        'genel'     => 'Genel',
        'baslangic' => 'Başlangıç',
        'orta'      => 'Orta',
        'ileri'     => 'İleri',
    ];

    /** İkincil alan yoksa. */
    public const YOK = 'yok';

    public const ETIKET_EN_COK = 8;

    /** Anlamsız, her videoya uyan etiketler. */
    private const BOS_ETIKETLER = ['video', 'youtube', 'izle', 'kanal', 'abone', 'icerik', 'bilgi', 'turkce', 'genel', 'diger'];

    public static function turAdi(?string $kimlik): string
    {
        return self::TURLER[$kimlik ?? ''][0] ?? self::TURLER[self::DIGER][0];
    }

    public static function alanAdi(?string $kimlik): string
    {
        return self::ALANLAR[$kimlik ?? ''][0] ?? self::ALANLAR[self::DIGER][0];
    }

    public static function seviyeAdi(?string $kimlik): string
    {
        return self::SEVIYELER[$kimlik ?? ''] ?? self::SEVIYELER['genel'];
    }

    /**
     * AI (ya da elle) çıktısını kayda hazır hale getirir.
     *
     * $kati = false (AI): bilinmeyen tür / alan "diger", bilinmeyen seviye
     * "genel"; yalnız tür ya da alan HİÇ yoksa GecersizSonuc (onarım
     * istenir). $kati = true (yönetici formu): bilinmeyen değer hatadır.
     *
     * @param  array<string,mixed> $veri
     * @return array{icerik_turu: string, konu_alani: string, ikincil_alan: ?string, seviye: string, ana_konu: string, etiketler: list<string>}
     *
     * @throws GecersizSonuc
     */
    public static function dogrula(array $veri, bool $kati = false): array
    {
        $sorunlar = [];

        foreach (['icerik_turu', 'konu_alani'] as $alan) {
            if (!is_string($veri[$alan] ?? null) || trim($veri[$alan]) === '') {
                $sorunlar[] = sprintf('"%s" alanı eksik.', $alan);
            }
        }

        $tur    = self::kimlik($veri['icerik_turu'] ?? null, self::TURLER);
        $alan   = self::kimlik($veri['konu_alani'] ?? null, self::ALANLAR);
        $ikinci = self::kimlik($veri['ikincil_alan'] ?? null, self::ALANLAR);
        $seviye = self::kimlik($veri['seviye'] ?? null, array_map(static fn (string $ad): array => [$ad], self::SEVIYELER));

        if ($kati) {
            if ($tur === null) {
                $sorunlar[] = 'İçerik türü listede yok.';
            }

            if ($alan === null) {
                $sorunlar[] = 'Konu alanı listede yok.';
            }

            $ham = trim((string) ($veri['ikincil_alan'] ?? ''));

            if ($ikinci === null && $ham !== '' && $ham !== self::YOK) {
                $sorunlar[] = 'İkincil alan listede yok.';
            }

            if ($seviye === null && trim((string) ($veri['seviye'] ?? '')) !== '') {
                $sorunlar[] = 'Seviye listede yok.';
            }
        }

        if ($sorunlar !== []) {
            throw new GecersizSonuc(array_values(array_unique($sorunlar)));
        }

        $alan ??= self::DIGER;

        return [
            'icerik_turu'  => $tur ?? self::DIGER,
            'konu_alani'   => $alan,
            // Ana alanla aynı ya da "diger" ikincil alan anlamsız.
            'ikincil_alan' => $ikinci !== null && $ikinci !== $alan && $ikinci !== self::DIGER ? $ikinci : null,
            'seviye'       => $seviye ?? 'genel',
            'ana_konu'     => ResultValidator::metin($veri['ana_konu'] ?? null, 200),
            'etiketler'    => self::etiketler($veri['etiketler'] ?? []),
        ];
    }

    /**
     * Etiketler: metin, 2–40 karakter, büyük / küçük harf ve Türkçe harf
     * farkı gözetmeden tekrarsız, en çok 8. Virgüllü metin de kabul edilir
     * (yönetici formu).
     *
     * @return list<string>
     */
    public static function etiketler(mixed $liste): array
    {
        if (is_string($liste)) {
            $liste = explode(',', $liste);
        }

        $sonuc  = [];
        $gorulen = [];

        foreach (is_array($liste) ? $liste : [] as $etiket) {
            $etiket = trim(ResultValidator::metin($etiket, 60), " \t#.,;");

            if ($etiket === '' || mb_strlen($etiket) < 2 || mb_strlen($etiket) > 40) {
                continue;
            }

            $anahtar = Search::trFold($etiket);

            if (isset($gorulen[$anahtar]) || in_array($anahtar, self::BOS_ETIKETLER, true)) {
                continue;
            }

            $gorulen[$anahtar] = true;
            $sonuc[] = $etiket;

            if (count($sonuc) >= self::ETIKET_EN_COK) {
                break;
            }
        }

        return $sonuc;
    }

    /**
     * Ekran / API / dışa aktarma için: kimlikler + Türkçe adlar.
     *
     * @param  array<string,mixed>|null $k KategoriRepository::bul()
     * @return array<string,mixed>|null
     */
    public static function disaVer(?array $k): ?array
    {
        if ($k === null) {
            return null;
        }

        $ikinci = $k['ikincil_alan'] ?? null;

        return [
            'icerik_turu'      => (string) $k['icerik_turu'],
            'icerik_turu_adi'  => self::turAdi((string) $k['icerik_turu']),
            'konu_alani'       => (string) $k['konu_alani'],
            'konu_alani_adi'   => self::alanAdi((string) $k['konu_alani']),
            'ikincil_alan'     => $ikinci !== null && $ikinci !== '' ? (string) $ikinci : null,
            'ikincil_alan_adi' => $ikinci !== null && $ikinci !== '' ? self::alanAdi((string) $ikinci) : null,
            'seviye'           => (string) ($k['seviye'] ?? 'genel'),
            'seviye_adi'       => self::seviyeAdi((string) ($k['seviye'] ?? 'genel')),
            'ana_konu'         => (string) ($k['ana_konu'] ?? ''),
            'etiketler'        => array_values((array) ($k['etiketler'] ?? [])),
            'belirleyen'       => (string) ($k['belirleyen'] ?? 'ai'),
        ];
    }

    /**
     * Tek satır özet: "Ders anlatımı · Bilim + Eğitim / sınav · Başlangıç".
     *
     * @param array<string,mixed> $k disaVer() çıktısı
     */
    public static function ozet(array $k): string
    {
        return $k['icerik_turu_adi'] . ' · ' . $k['konu_alani_adi'] . ($k['ikincil_alan_adi'] !== null ? ' + ' . $k['ikincil_alan_adi'] : '')
            . ($k['seviye'] !== 'genel' ? ' · ' . $k['seviye_adi'] : '');
    }

    /**
     * Sınıflandırma isteminin girdisi: başlık, kanal, süre, dil ve
     * raporun özeti (transcript değil; kısa ve ucuz).
     *
     * @param  array<string,mixed> $kaynak transkript_kaynaklar satırı
     * @param  array<string,mixed> $rapor  doğrulanmış rapor
     * @return array{baslik: string, kanal: string, sure: string, dil: string, ozet: string, konular: string, noktalar: string, kavramlar: string}
     */
    public static function girdi(array $kaynak, array $rapor, string $dil = ''): array
    {
        $liste = static function (mixed $maddeler, string $anahtar, int $enCok): string {
            $satirlar = [];

            foreach (is_array($maddeler) ? $maddeler : [] as $m) {
                $metin = is_array($m) ? ResultValidator::metin($m[$anahtar] ?? null, 160) : '';

                if ($metin !== '') {
                    $satirlar[] = '- ' . $metin;
                }

                if (count($satirlar) >= $enCok) {
                    break;
                }
            }

            return $satirlar === [] ? '(yok)' : implode("\n", $satirlar);
        };

        $sure = (int) ($kaynak['sure_sn'] ?? 0);

        return [
            'baslik'    => ResultValidator::metin($kaynak['baslik'] ?? null, 300) ?: 'bilinmiyor',
            'kanal'     => ResultValidator::metin($kaynak['kanal_adi'] ?? null, 200) ?: 'bilinmiyor',
            'sure'      => $sure > 0 ? sprintf('%d dakika', max(1, (int) round($sure / 60))) : 'bilinmiyor',
            'dil'       => $dil !== '' ? $dil : 'bilinmiyor',
            'ozet'      => ResultValidator::metin($rapor['summary'] ?? null, 1500) ?: '(yok)',
            'konular'   => $liste($rapor['topics'] ?? null, 'title', 12),
            'noktalar'  => $liste($rapor['important_points'] ?? null, 'text', 6),
            'kavramlar' => $liste($rapor['technical_information'] ?? null, 'name', 12),
        ];
    }

    /** İstemdeki seçenek listesi: "- kimlik: tanım" satırları. @param array<string,array{0:string,1:string}> $liste */
    public static function secenekMetni(array $liste): string
    {
        $satirlar = [];

        foreach ($liste as $kimlik => [$ad, $tanim]) {
            $satirlar[] = '- ' . $kimlik . ': ' . $tanim;
        }

        return implode("\n", $satirlar);
    }

    /**
     * Kimlik ya da Türkçe ad → kimlik ("Ders anlatımı", "ders-anlatimi"
     * → ders_anlatimi); bulunamazsa null.
     *
     * @param array<string,array<int,string>> $liste
     */
    private static function kimlik(mixed $deger, array $liste): ?string
    {
        if (!is_string($deger)) {
            return null;
        }

        $katli = trim((string) preg_replace('/[^a-z0-9]+/', '_', Search::trFold(trim($deger))), '_');

        if ($katli === '') {
            return null;
        }

        if (isset($liste[$katli])) {
            return $katli;
        }

        foreach ($liste as $kimlik => $tanim) {
            if (trim((string) preg_replace('/[^a-z0-9]+/', '_', Search::trFold($tanim[0])), '_') === $katli) {
                return $kimlik;
            }
        }

        return null;
    }
}
