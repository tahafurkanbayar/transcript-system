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
use Modules\Transkript\Ai\EvrenProvider;
use Modules\Transkript\Ai\GeminiProvider;
use Modules\Transkript\Services\KuyrukDurumu;
use PDO;

final class PanelAyarlari
{
    public const GRUP  = 'transkript';
    public const ONEK  = 'transkript_';

    /**
     * alan => tanım. "yol": değerin Ayarlar::get()'teki yolu (birden çok
     * olabilir). "tur": tamsayi | ondalik | secim | acik_kapali (açık /
     * kapalı seçimi → bool) | metin (çok satırlı, en_cok_karakter) |
     * model (serbest model kimliği). "saglayici": alan o sağlayıcının
     * modelidir (seçim ya da serbest); ekran zincir sırasıyla çizer ve
     * sırasını rozetle gösterir. "plan": üyelik planı alanı (uye | editor);
     * ekran bunları iki sütunlu tabloda çizer.
     *
     * @var array<string,array{yol: list<string>, tur: string, etiket: string, aciklama: string, en_az?: float, en_cok?: float, birim?: string, saglayici?: string, plan?: string, en_cok_karakter?: int}>
     */
    public const ALANLAR = [
        /* Sağlayıcıların modeli (kullanıcı isteği, 2026-10-09: "üç API de
         * burada görünsün"; 2026-10-11: EVREN en üstte). Her sağlayıcı kendi
         * modelini kullanır, asıl olan dahil (Ai::varsayilanModel). Ekranda
         * yalnız .env AI_PROVIDERS zincirinde olanlar, o sırayla çizilir
         * (modelAlanlari); boş: <AD>_MODEL ya da varsayılan. */
        'model_evren' => [
            'yol' => ['ai.modeller.evren'], 'tur' => 'secim', 'saglayici' => 'evren',
            'etiket' => 'EVREN modeli',
            'aciklama' => 'EVREN LLM API (yurt içi H200 kümesi). 1 Kasım 2026\'ya kadar ücretsiz, günlük 15M token. Anahtar .env\'deki EVREN_API_KEY.',
        ],
        /* Adı eski ("asıl sağlayıcının modeli" iken konmuştu); kayıtlı değer
         * korunsun diye değişmedi. Artık yalnız Gemini'nin modelidir: asıl
         * sağlayıcı EVREN olunca Gemini modeli EVREN'e gitmesin. */
        'ai_model' => [
            'yol' => ['ai.modeller.gemini'], 'tur' => 'secim', 'saglayici' => 'gemini',
            'etiket' => 'Google Gemini modeli',
            'aciklama' => 'Gemini sırası geldiğinde (önündeki sağlayıcı dinlenirken ya da hata verince) kullanılır. Yalnız Flash ailesi; bölüm analizinde düşünme kapalı çalışır. Boş: .env\'deki GEMINI_MODEL ya da varsayılan.',
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
            'etiket' => 'Sistemin en uzun videosu',
            'aciklama' => 'Herkes için (yönetici dahil) üst sınır; planların süre sınırı bunu aşamaz. 0 = sınır yok.',
        ],
        /* ÜYELİK PLANLARI (Uyelik\Planlar). Plan = rol: "uye" Ücretsiz,
         * "editor" Pro. Ekranda iki sütunlu tablo (views/ayarlar.php). */
        'uye_analiz' => [
            'yol' => ['uyelik.planlar.uye.analiz'], 'tur' => 'acik_kapali', 'plan' => 'uye',
            'etiket' => 'Analiz başlatabilir',
            'aciklama' => 'Kapalıysa Ücretsiz üye yalnız kendisiyle paylaşılan raporları okur. Pro her zaman analiz başlatır.',
        ],
        'uye_gunluk' => [
            'yol' => ['uyelik.planlar.uye.gunluk_sinir'], 'tur' => 'tamsayi', 'en_az' => 0, 'en_cok' => 10000, 'birim' => 'analiz', 'plan' => 'uye',
            'etiket' => 'Günlük yeni analiz',
            'aciklama' => 'Gün içinde yapay zekâya gönderilebilecek video. Önceden analiz edilmiş video sayılmaz. 0 = sınırsız.',
        ],
        'pro_gunluk' => [
            'yol' => ['uyelik.planlar.editor.gunluk_sinir'], 'tur' => 'tamsayi', 'en_az' => 0, 'en_cok' => 10000, 'birim' => 'analiz', 'plan' => 'editor',
            'etiket' => 'Günlük yeni analiz',
            'aciklama' => 'Gün içinde yapay zekâya gönderilebilecek video. Önceden analiz edilmiş video sayılmaz. 0 = sınırsız.',
        ],
        'uye_saatlik' => [
            'yol' => ['uyelik.planlar.uye.saatlik_sinir'], 'tur' => 'tamsayi', 'en_az' => 0, 'en_cok' => 10000, 'birim' => 'analiz', 'plan' => 'uye',
            'etiket' => 'Saatlik yeni analiz',
            'aciklama' => 'Art arda gönderimi yavaşlatır; günlük sınırdan küçük olmalı (yoksa hiç devreye girmez). 0 = sınırsız.',
        ],
        'pro_saatlik' => [
            'yol' => ['uyelik.planlar.editor.saatlik_sinir'], 'tur' => 'tamsayi', 'en_az' => 0, 'en_cok' => 10000, 'birim' => 'analiz', 'plan' => 'editor',
            'etiket' => 'Saatlik yeni analiz',
            'aciklama' => 'Art arda gönderimi yavaşlatır; günlük sınırdan küçük olmalı (yoksa hiç devreye girmez). 0 = sınırsız.',
        ],
        'uye_en_uzun_dk' => [
            'yol' => ['uyelik.planlar.uye.en_uzun_dk'], 'tur' => 'tamsayi', 'en_az' => 0, 'en_cok' => 1440, 'birim' => 'dakika', 'plan' => 'uye',
            'etiket' => 'En uzun video',
            'aciklama' => 'Bundan uzun video bu planda analiz edilmez; sistemin tavanını aşamaz. 0 = yalnız sistem tavanı.',
        ],
        'pro_en_uzun_dk' => [
            'yol' => ['uyelik.planlar.editor.en_uzun_dk'], 'tur' => 'tamsayi', 'en_az' => 0, 'en_cok' => 1440, 'birim' => 'dakika', 'plan' => 'editor',
            'etiket' => 'En uzun video',
            'aciklama' => 'Bundan uzun video bu planda analiz edilmez; sistemin tavanını aşamaz. 0 = yalnız sistem tavanı.',
        ],
        'uye_yeniden_analiz' => [
            'yol' => ['uyelik.planlar.uye.yeniden_analiz'], 'tur' => 'acik_kapali', 'plan' => 'uye',
            'etiket' => 'Yeniden analiz',
            'aciklama' => 'Kendi raporunu baştan yapay zekâya göndermek (hakkından düşer).',
        ],
        'pro_yeniden_analiz' => [
            'yol' => ['uyelik.planlar.editor.yeniden_analiz'], 'tur' => 'acik_kapali', 'plan' => 'editor',
            'etiket' => 'Yeniden analiz',
            'aciklama' => 'Kendi raporunu baştan yapay zekâya göndermek (hakkından düşer).',
        ],
        'uye_altyazi' => [
            'yol' => ['uyelik.planlar.uye.altyazi_indir'], 'tur' => 'acik_kapali', 'plan' => 'uye',
            'etiket' => 'Altyazı indirme (SRT / VTT)',
            'aciklama' => 'Rapor (Markdown) ve düz metin her planda indirilir.',
        ],
        'pro_altyazi' => [
            'yol' => ['uyelik.planlar.editor.altyazi_indir'], 'tur' => 'acik_kapali', 'plan' => 'editor',
            'etiket' => 'Altyazı indirme (SRT / VTT)',
            'aciklama' => 'Rapor (Markdown) ve düz metin her planda indirilir.',
        ],
        'pro_fiyat_aylik' => [
            'yol' => ['uyelik.planlar.editor.fiyat_aylik'], 'tur' => 'ondalik', 'en_az' => 0, 'en_cok' => 1000000,
            'etiket' => 'Pro aylık fiyat',
            'aciklama' => 'Fiyat tablosunda ve Üyeliğim sayfasında görünür. Boş ya da 0: "fiyat için iletişime geçin".',
        ],
        'pro_fiyat_yillik' => [
            'yol' => ['uyelik.planlar.editor.fiyat_yillik'], 'tur' => 'ondalik', 'en_az' => 0, 'en_cok' => 10000000,
            'etiket' => 'Pro yıllık fiyat',
            'aciklama' => 'İsteğe bağlı. Boş ya da 0: yıllık seçenek gösterilmez.',
        ],
        'para_birimi' => [
            'yol' => ['uyelik.para_birimi'], 'tur' => 'secim',
            'etiket' => 'Para birimi',
            'aciklama' => 'Fiyatların yanında yazar.',
        ],
        'odeme_bilgisi' => [
            'yol' => ['uyelik.odeme_bilgisi'], 'tur' => 'metin', 'en_cok_karakter' => 1000,
            'etiket' => 'Ödeme talimatı',
            'aciklama' => 'Üye "Pro\'ya geç" talebi gönderince görür: banka, IBAN, alıcı adı, açıklamaya ne yazacağı. Yalnız giriş yapmış kullanıcıya gösterilir; kart bilgisi ya da parola yazmayın.',
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

    /** Açık / kapalı seçimi → bool. */
    private const ACIK_KAPALI = ['acik' => true, 'kapali' => false];

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
            'model_evren'  => ['' => 'Varsayılan (' . Ai::ortamModeli('evren') . ')'] + array_combine(
                array_keys(EvrenProvider::MODELLER),
                array_map(static fn (string $id, string $aciklama): string => $id . ' — ' . $aciklama, array_keys(EvrenProvider::MODELLER), EvrenProvider::MODELLER)
            ),
            'ai_model'     => ['' => 'Varsayılan (' . Ai::ortamModeli('gemini') . ')'] + self::geminiModelleri(),
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
            'para_birimi' => [
                '' => 'Varsayılan (' . (string) Ayarlar::varsayilan('uyelik.para_birimi', 'TL') . ')',
                'TL' => 'Türk lirası (₺)',
                'USD' => 'ABD doları ($)',
                'EUR' => 'Avro (€)',
            ],
            default        => (self::ALANLAR[$alan]['tur'] ?? '') === 'acik_kapali'
                ? [
                    // Değer önde: dar sütunda kesilse de "Açık" / "Kapalı" görünsün.
                    '' => (Ayarlar::varsayilan(self::ALANLAR[$alan]['yol'][0], false) ? 'Açık' : 'Kapalı') . ' (varsayılan)',
                    'acik' => 'Açık',
                    'kapali' => 'Kapalı',
                ]
                : [],
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

    /**
     * Kaydı engellemeyen ama yöneticinin bilmesi gereken tutarsızlıklar
     * (kayıttan sonra uyarı olarak gösterilir). Boş alan varsayılanıyla
     * değerlendirilir.
     *
     * @param  array<string,string> $girdi alan => ham değer
     * @return list<string>
     */
    public static function uyarilar(array $girdi): array
    {
        $deger = static fn (string $alan): int => trim((string) ($girdi[$alan] ?? '')) !== ''
            ? (int) $girdi[$alan]
            : (int) Ayarlar::varsayilan(self::ALANLAR[$alan]['yol'][0], 0);

        $uyarilar = [];

        // Cowork 6. tur K3: saatlik 20, günlük 10 iken saatlik sınır hiç devreye girmiyordu.
        foreach (['uye' => 'Ücretsiz', 'pro' => 'Pro'] as $onek => $plan) {
            $saatlik = $deger($onek . '_saatlik');
            $gunluk  = $deger($onek . '_gunluk');

            if ($saatlik > 0 && $gunluk > 0 && $saatlik >= $gunluk) {
                $uyarilar[] = sprintf('%s planında saatlik sınır (%d) günlük sınırdan (%d) küçük değil; saatlik sınır hiç devreye girmez. Günlükten küçük bir sayı yazın (ör. %d) ya da 0 ile kapatın.', $plan, $saatlik, $gunluk, max(1, intdiv($gunluk, 2)));
            }
        }

        // Ücretli plan ücretsizden geride kalmasın (0 = sınırsız).
        $uyeGunluk = $deger('uye_gunluk');
        $proGunluk = $deger('pro_gunluk');

        if ($proGunluk > 0 && ($uyeGunluk === 0 || $uyeGunluk > $proGunluk)) {
            $uyarilar[] = sprintf('Ücretsiz planın günlük hakkı (%s) Pro\'dan (%d) yüksek; Pro\'ya geçmenin anlamı kalmaz.', $uyeGunluk === 0 ? 'sınırsız' : (string) $uyeGunluk, $proGunluk);
        }

        return $uyarilar;
    }

    /** Türkçe hata ya da null. */
    public static function dogrula(string $alan, string $ham): ?string
    {
        $tanim = self::ALANLAR[$alan] ?? null;

        if ($tanim === null) {
            return 'Bilinmeyen ayar.';
        }

        if ($tanim['tur'] === 'secim' || $tanim['tur'] === 'acik_kapali') {
            return array_key_exists($ham, self::secenekler($alan)) ? null : 'Listeden bir seçenek seçin.';
        }

        if ($tanim['tur'] === 'metin') {
            $sinir = (int) ($tanim['en_cok_karakter'] ?? 1000);

            return match (true) {
                mb_strlen($ham) > $sinir => sprintf('En çok %s karakter yazın.', self::sayi($sinir)),
                // Satır sonu ve sekme serbest; öteki denetim karakterleri değil.
                preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $ham) === 1 => 'Metinde izin verilmeyen bir karakter var.',
                default => null,
            };
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
     * Ekranda çizilecek sağlayıcı model alanları, zincir sırasıyla (.env
     * AI_PROVIDERS; ilki asıl sağlayıcı). Alanı olmayan sağlayıcı
     * (openai-compatible) atlanır.
     *
     * @return list<string>
     */
    public static function modelAlanlari(): array
    {
        $alanlar = [];

        foreach (Ai::zincir() as $ad) {
            $alan = self::modelAlani($ad);

            if ($alan !== null) {
                $alanlar[] = $alan;
            }
        }

        return $alanlar;
    }

    /** Sağlayıcının model alanı ("evren" → "model_evren", "gemini" → "ai_model"); yoksa null. */
    public static function modelAlani(string $saglayici): ?string
    {
        foreach (self::ALANLAR as $alan => $tanim) {
            if (($tanim['saglayici'] ?? null) === $saglayici) {
                return $alan;
            }
        }

        return null;
    }

    /** @return array<string,string> */
    private static function geminiModelleri(): array
    {
        $modeller = [];

        foreach (GeminiProvider::models() as $model) {
            /* config'teki gemini_ek düşünmeyi kapatır (thinkingBudget 0);
             * bu yalnız Flash modellerinde geçerli. Pro seçilirse istek
             * reddedilirdi; listede yalnız Flash ailesi var. */
            if (str_contains($model, 'flash')) {
                $modeller[$model] = $model;
            }
        }

        return $modeller;
    }

    private static function cevir(string $alan, string $ham): mixed
    {
        return match (self::ALANLAR[$alan]['tur']) {
            'tamsayi' => (int) $ham,
            'ondalik' => (float) str_replace(',', '.', $ham),
            'acik_kapali' => self::ACIK_KAPALI[$ham] ?? false,
            default   => match ($alan) {
                'altyazi_dili' => self::ALTYAZI[$ham] ?? ['tr'],
                'ana_sayfa'    => self::ANA_SAYFA[$ham] ?? true,
                'tarayici_kuyruk' => self::TARAYICI_KUYRUK[$ham] ?? null,
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
