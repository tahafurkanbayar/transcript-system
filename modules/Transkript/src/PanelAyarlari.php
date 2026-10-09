<?php
/**
 * =====================================================================
 *  PanelAyarlari – yöneticinin panelden değiştirdiği Transkript ayarları
 * ---------------------------------------------------------------------
 *  Ekran: panel/transkript/ayarlar (transkript.admin).
 *
 *  NEREDE DURUR? Çekirdeğin "ayarlar" tablosunda, "transkript" grubunda
 *  (anahtar: transkript_<alan>). Çekirdeğin Ayarlar ekranı grupları sabit
 *  bir listeden çizer (Setting::groupLabels); bu grup orada görünmez,
 *  formu da onu ezemez (duzenlenebilir = 0, Setting::set). Çekirdeğe kod
 *  eklenmedi.
 *
 *  NASIL OKUNUR? Ayarlar::get() önce config.php'ye bakar, sonra burada
 *  panelden girilmiş bir değer varsa onu üstüne yazar (ustune()). Boş
 *  bırakılan alan config.php'deki varsayılana döner. Değerler istek ve
 *  komut başına Setting::load() ile bir kez okunur.
 *
 *  ANAHTAR YOK: API anahtarları yalnız .env'de durur (CLAUDE.md, kural 5);
 *  ekran yalnız "tanımlı / tanımsız" gösterir.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript;

use App\Core\Setting;
use Modules\Transkript\Ai\Ai;
use Modules\Transkript\Services\KuyrukDurumu;
use PDO;

final class PanelAyarlari
{
    public const GRUP  = 'transkript';
    public const ONEK  = 'transkript_';

    /**
     * alan => tanım. "yol": değerin Ayarlar::get()'teki yolu (birden çok
     * olabilir). "tur": tamsayi | ondalik | secim | model (serbest model
     * kimliği; "saglayici" hangi sağlayıcının modeli olduğunu söyler).
     *
     * @var array<string,array{yol: list<string>, tur: string, etiket: string, aciklama: string, en_az?: float, en_cok?: float, birim?: string, saglayici?: string}>
     */
    public const ALANLAR = [
        'ai_model' => [
            'yol' => ['ai.map.model', 'ai.reduce.model'], 'tur' => 'secim',
            'etiket' => 'Asıl sağlayıcının modeli',
            'aciklama' => 'Asıl sağlayıcının (ilk sıradaki) modeli; bölüm analizi ve birleştirmede kullanılır. Boş: .env\'deki AI_MODEL ya da varsayılan.',
        ],
        /* Yedek sağlayıcıların modeli (kullanıcı isteği, 2026-10-09: "üç
         * API de burada görünsün"). Ekranda yalnız .env AI_PROVIDERS
         * zincirinde olan ve asıl olmayanlar çizilir (yedekModelAlanlari);
         * boş: <AD>_MODEL ya da varsayılan (Ai::varsayilanModel). */
        'model_gemini' => [
            'yol' => ['ai.modeller.gemini'], 'tur' => 'model', 'saglayici' => 'gemini',
            'etiket' => 'Google Gemini modeli',
            'aciklama' => 'Yedek olarak kullanıldığında. Yalnız Flash ailesi (düşünme kapalı çalışır).',
        ],
        'model_openrouter' => [
            'yol' => ['ai.modeller.openrouter'], 'tur' => 'model', 'saglayici' => 'openrouter',
            'etiket' => 'OpenRouter modeli',
            'aciklama' => 'Ücretsiz modeller ":free" ile biter ve sık değişir; openrouter.ai/models sayfasından JSON destekleyen bir model seçin. Kaldırılmış model 6 saat devre dışı kalır.',
        ],
        'model_groq' => [
            'yol' => ['ai.modeller.groq'], 'tur' => 'model', 'saglayici' => 'groq',
            'etiket' => 'Groq modeli',
            'aciklama' => 'console.groq.com/docs/models sayfasındaki model kimliği (ör. openai/gpt-oss-120b). Kaldırılmış model 6 saat devre dışı kalır.',
        ],
        'model_mistral' => [
            'yol' => ['ai.modeller.mistral'], 'tur' => 'model', 'saglayici' => 'mistral',
            'etiket' => 'Mistral modeli',
            'aciklama' => 'Mistral model kimliği (ör. mistral-small-latest).',
        ],
        'model_cerebras' => [
            'yol' => ['ai.modeller.cerebras'], 'tur' => 'model', 'saglayici' => 'cerebras',
            'etiket' => 'Cerebras modeli',
            'aciklama' => 'Cerebras model kimliği.',
        ],
        'model_claude' => [
            'yol' => ['ai.modeller.claude'], 'tur' => 'model', 'saglayici' => 'claude',
            'etiket' => 'Anthropic Claude modeli',
            'aciklama' => 'Ücretli. Claude model kimliği.',
        ],
        'altyazi_dili' => [
            'yol' => ['transcript.tercih_diller'], 'tur' => 'secim',
            'etiket' => 'Tercih edilen altyazı',
            'aciklama' => 'Videoda elle girilmiş Türkçe altyazı varsa onu mu, yoksa videonun kendi dilindeki altyazıyı mı kullanalım? Rapor her durumda Türkçe yazılır.',
        ],
        'en_uzun_dk' => [
            'yol' => ['analiz.en_uzun_dk'], 'tur' => 'tamsayi', 'en_az' => 0, 'en_cok' => 1440, 'birim' => 'dakika',
            'etiket' => 'En uzun video',
            'aciklama' => 'Bundan uzun video analiz edilmez. 0 = sınır yok.',
        ],
        'uye_analiz' => [
            'yol' => ['analiz.uye_baslatabilir'], 'tur' => 'secim',
            'etiket' => 'Üyeler analiz başlatabilir',
            'aciklama' => 'Açıksa üye rolü de video analiz ettirir (analistle aynı kotalar). Kapalıyken üye yalnız kendisiyle paylaşılan raporları okur.',
        ],
        'gunluk_sinir' => [
            'yol' => ['analiz.gunluk_sinir'], 'tur' => 'tamsayi', 'en_az' => 0, 'en_cok' => 10000, 'birim' => 'analiz',
            'etiket' => 'Kullanıcı başına günlük analiz',
            'aciklama' => 'Önbellekten açılan rapor sayılmaz; yöneticiler muaf. 0 = sınır yok.',
        ],
        'saatlik_sinir' => [
            'yol' => ['analiz.saatlik_sinir'], 'tur' => 'tamsayi', 'en_az' => 0, 'en_cok' => 10000, 'birim' => 'analiz',
            'etiket' => 'Kullanıcı başına saatlik analiz',
            'aciklama' => 'Kısa sürede çok istek gelmesini önler; yöneticiler muaf. 0 = sınır yok.',
        ],
        'gunluk_token_tavani' => [
            'yol' => ['analiz.gunluk_token_tavani'], 'tur' => 'tamsayi', 'en_az' => 0, 'en_cok' => 1000000000, 'birim' => 'token',
            'etiket' => 'Günlük token tavanı (herkes)',
            'aciklama' => 'Bugün bütün kullanıcıların harcadığı token bu sayıya ulaşınca yeni analiz başlamaz. 0 = kapalı.',
        ],
        'gunluk_maliyet_tavani' => [
            'yol' => ['analiz.gunluk_maliyet_tavani'], 'tur' => 'ondalik', 'en_az' => 0, 'en_cok' => 100000, 'birim' => 'USD',
            'etiket' => 'Günlük maliyet tavanı (herkes)',
            'aciklama' => 'Tahmini maliyet (ücretli katman fiyatıyla) bu tutara ulaşınca yeni analiz başlamaz; yöneticiye de uygulanır. 0 = kapalı.',
        ],
        'fiyat_giris' => [
            'yol' => ['ai.fiyat.giris'], 'tur' => 'ondalik', 'en_az' => 0, 'en_cok' => 1000, 'birim' => 'USD / 1M token',
            'etiket' => 'Giriş fiyatı',
            'aciklama' => 'Maliyet tahmini için. Boş: sağlayıcının fiyat tablosu (kodda). Yeni analizlere uygulanır.',
        ],
        'fiyat_cikis' => [
            'yol' => ['ai.fiyat.cikis'], 'tur' => 'ondalik', 'en_az' => 0, 'en_cok' => 1000, 'birim' => 'USD / 1M token',
            'etiket' => 'Çıkış fiyatı',
            'aciklama' => 'Düşünme (thinking) token\'ları çıkışa sayılır. Boş: sağlayıcının fiyat tablosu.',
        ],
        'ana_sayfa' => [
            'yol' => ['anasayfa.tanitim'], 'tur' => 'secim',
            'etiket' => 'Sitenin ana sayfası',
            'aciklama' => 'Tanıtım sayfası Transkript\'i anlatır. Genel vitrin, Panel → Ayarlar → Ana Sayfa\'dan düzenlenen sayfadır.',
        ],
        'tarayici_kuyruk' => [
            'yol' => ['kuyruk.tarayicidan_isle'], 'tur' => 'secim',
            'etiket' => 'Kuyruğu tarayıcı üzerinden işle',
            'aciklama' => 'Kuyruk işçisi çalışmıyorsa, açık bir panel sayfası işleri arka planda tek tek işletir; komut satırı gerekmez. Sayfa kapalıyken iş ilerlemez; kalıcı çözüm kuyruk-isci-kur.bat.',
        ],
    ];

    /** Altyazı seçeneği → transcript.tercih_diller değeri. */
    private const ALTYAZI = ['tr' => ['tr'], 'orijinal' => []];

    /** Ana sayfa seçeneği → anasayfa.tanitim değeri. */
    private const ANA_SAYFA = ['tanitim' => true, 'genel' => false];

    /** Tarayıcıdan kuyruk seçeneği → kuyruk.tarayicidan_isle değeri. */
    private const TARAYICI_KUYRUK = ['acik' => true, 'kapali' => false];

    /** Üye analizi seçeneği → analiz.uye_baslatabilir değeri. */
    private const UYE_ANALIZ = ['acik' => true, 'kapali' => false];

    /**
     * Ayarlar::get() sonucunun üstüne panelden girilen değeri yazar. Yol
     * tam eşleşirse değerin kendisi, üst yol istenmişse (ör. "ai.map")
     * dizinin içindeki alan değişir.
     */
    public static function ustune(string $anahtar, mixed $deger): mixed
    {
        foreach (self::ALANLAR as $alan => $tanim) {
            $ham = self::ham($alan);

            if ($ham === null) {
                continue;
            }

            foreach ($tanim['yol'] as $yol) {
                if ($yol === $anahtar) {
                    $deger = self::cevir($alan, $ham);
                } elseif (str_starts_with($yol, $anahtar . '.') && is_array($deger)) {
                    $deger = self::icineYaz($deger, explode('.', substr($yol, strlen($anahtar) + 1)), self::cevir($alan, $ham));
                }
            }
        }

        return $deger;
    }

    /** Panelden girilmiş ham değer; girilmediyse (boş) null. */
    public static function ham(string $alan): ?string
    {
        $deger = Setting::all()[self::ONEK . $alan] ?? '';

        return trim((string) $deger) === '' ? null : (string) $deger;
    }

    /**
     * Seçim alanının seçenekleri (değer => etiket); "" = varsayılan.
     *
     * @return array<string,string>
     */
    public static function secenekler(string $alan): array
    {
        return match ($alan) {
            'ai_model'     => ['' => 'Varsayılan (' . Ai::model() . ')'] + self::modeller(),
            'altyazi_dili' => [
                '' => 'Varsayılan (' . (Ayarlar::varsayilan('transcript.tercih_diller') === [] ? 'videonun kendi dili' : 'Türkçe altyazı varsa onu') . ')',
                'tr' => 'Türkçe altyazı varsa onu kullan',
                'orijinal' => 'Videonun kendi dilindeki altyazı',
            ],
            'ana_sayfa'    => [
                '' => 'Varsayılan (' . (Ayarlar::varsayilan('anasayfa.tanitim', true) ? 'Transkript tanıtım sayfası' : 'genel vitrin') . ')',
                'tanitim' => 'Transkript tanıtım sayfası',
                'genel' => 'Genel vitrin (Ayarlar → Ana Sayfa)',
            ],
            'tarayici_kuyruk' => [
                '' => 'Varsayılan (' . (KuyrukDurumu::tarayicidanIslenir(true) ? 'açık' : 'kapalı') . ')',
                'acik' => 'Açık: işçi yokken panel sayfası işler',
                'kapali' => 'Kapalı: yalnız kuyruk işçisi',
            ],
            'uye_analiz' => [
                '' => 'Varsayılan (' . (Ayarlar::varsayilan('analiz.uye_baslatabilir', false) ? 'açık' : 'kapalı') . ')',
                'acik' => 'Açık: üyeler de analiz başlatır',
                'kapali' => 'Kapalı: yalnız analist ve yönetici',
            ],
            default        => [],
        };
    }

    /**
     * Doğrulayıp yazar. Boş alan panel değerini siler (config varsayılanı).
     *
     * @param  array<string,mixed> $girdi alan => ham değer
     * @return array<string,string>       alan => Türkçe hata; boşsa kaydedildi
     */
    public static function kaydet(PDO $db, array $girdi): array
    {
        $hatalar = [];
        $yazilacak = [];

        foreach (self::ALANLAR as $alan => $tanim) {
            $ham = trim((string) ($girdi[$alan] ?? ''));

            if ($ham === '') {
                $yazilacak[$alan] = '';
                continue;
            }

            $hata = self::dogrula($alan, $ham);

            if ($hata !== null) {
                $hatalar[$alan] = $hata;
                continue;
            }

            $yazilacak[$alan] = $tanim['tur'] === 'ondalik' ? (string) (float) str_replace(',', '.', $ham) : $ham;
        }

        if ($hatalar !== []) {
            return $hatalar;
        }

        foreach ($yazilacak as $alan => $deger) {
            Setting::set($db, self::ONEK . $alan, $deger, self::GRUP, false);
        }

        return [];
    }

    /** Türkçe hata ya da null. */
    public static function dogrula(string $alan, string $ham): ?string
    {
        $tanim = self::ALANLAR[$alan] ?? null;

        if ($tanim === null) {
            return 'Bilinmeyen ayar.';
        }

        if ($tanim['tur'] === 'secim') {
            return array_key_exists($ham, self::secenekler($alan)) ? null : 'Listeden bir seçenek seçin.';
        }

        if ($tanim['tur'] === 'model') {
            // Model kimliği: "openai/gpt-oss-120b", "google/gemma-4-31b-it:free", "gemini-2.5-flash".
            return preg_match('~\A[A-Za-z0-9][A-Za-z0-9._:/@+-]{0,99}\z~', $ham) === 1
                ? null
                : 'Geçerli bir model kimliği yazın (ör. ' . Ai::ortamModeli((string) $tanim['saglayici']) . '); boşluk ve Türkçe karakter olmaz.';
        }

        $sayi = str_replace(',', '.', $ham);

        if ($tanim['tur'] === 'tamsayi' ? !ctype_digit($sayi) : !is_numeric($sayi)) {
            return $tanim['tur'] === 'tamsayi' ? 'Tam sayı girin (ör. 10).' : 'Sayı girin (ör. 2,5).';
        }

        if ((float) $sayi < ($tanim['en_az'] ?? 0) || (float) $sayi > ($tanim['en_cok'] ?? PHP_INT_MAX)) {
            return sprintf('%s ile %s arasında olmalı.', self::sayi($tanim['en_az'] ?? 0), self::sayi($tanim['en_cok'] ?? 0));
        }

        return null;
    }

    /**
     * Ekranda çizilecek sağlayıcı model alanları, zincir sırasıyla: .env
     * AI_PROVIDERS'taki her sağlayıcı; asıl sağlayıcı AI_PROVIDER ise onun
     * modeli zaten "ai_model" alanındadır, tekrar çizilmez.
     *
     * @return list<string>
     */
    public static function yedekModelAlanlari(): array
    {
        $zincir  = Ai::zincir();
        $alanlar = [];

        foreach ($zincir as $i => $ad) {
            if (($i === 0 && $ad === Ai::provider()) || !isset(self::ALANLAR['model_' . $ad])) {
                continue;
            }

            $alanlar[] = 'model_' . $ad;
        }

        return $alanlar;
    }

    /** @return array<string,string> */
    private static function modeller(): array
    {
        $modeller = [];

        foreach (Ai::models() as $model) {
            /* config'teki gemini_ek düşünmeyi kapatır (thinkingBudget 0);
             * bu yalnız Flash modellerinde geçerli. Pro seçilirse istek
             * reddedilirdi; listede yalnız Flash ailesi var. */
            if (Ai::provider() === 'gemini' && !str_contains($model, 'flash')) {
                continue;
            }

            $modeller[$model] = $model;
        }

        return $modeller;
    }

    private static function cevir(string $alan, string $ham): mixed
    {
        return match (self::ALANLAR[$alan]['tur']) {
            'tamsayi' => (int) $ham,
            'ondalik' => (float) str_replace(',', '.', $ham),
            default   => match ($alan) {
                'altyazi_dili' => self::ALTYAZI[$ham] ?? ['tr'],
                'ana_sayfa'    => self::ANA_SAYFA[$ham] ?? true,
                'tarayici_kuyruk' => self::TARAYICI_KUYRUK[$ham] ?? null,
                'uye_analiz'   => self::UYE_ANALIZ[$ham] ?? false,
                default        => $ham,
            },
        };
    }

    /**
     * @param array<string,mixed> $dizi
     * @param list<string>        $yol
     * @return array<string,mixed>
     */
    private static function icineYaz(array $dizi, array $yol, mixed $deger): array
    {
        $ilk = array_shift($yol);

        $dizi[$ilk] = $yol === [] ? $deger : self::icineYaz(is_array($dizi[$ilk] ?? null) ? $dizi[$ilk] : [], $yol, $deger);

        return $dizi;
    }

    private static function sayi(float|int $n): string
    {
        return rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',');
    }
}
