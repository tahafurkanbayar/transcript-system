<?php
/**
 * =====================================================================
 *  Pipeline – AI aşamaları: map (parça), reduce (birleştirme), maddeler
 * ---------------------------------------------------------------------
 *  docs/TEKNIK_RAPOR.md 6. bölüm. İş sınıfları yalnızca bu servisi
 *  çağırır; hangi modelin, hangi istemin kullanıldığını bilmez.
 *
 *      map     parça blokları "[hh:mm:ss] metin" satırları olarak
 *              prompts/parca_analizi.<sürüm>.txt ile gider
 *      reduce  parça JSON'ları (transcript değil) tek çağrıda
 *              prompts/birlestirme.<sürüm>.txt ile birleşir
 *
 *  HER ÇAĞRI (onarım dahil) hemen ai_kullanim_kaydi'na yazılır:
 *  model, token, maliyet, süre ve durum (basarili / gecersiz / hata).
 *  İş sonradan çökse de harcanan token kayıtta kalır.
 *
 *  JSON GÜVENLİĞİ: sağlayıcının şema zorlaması açılır (sendJson), yanıt
 *  ResultValidator'dan geçer. Geçmezse modele BİR KEZ onarım isteği
 *  gider (bozuk yanıt + sorun listesi); o da geçmezse AiHatasi
 *  "yanit_cozulemedi" (kalıcı): parça düşer, rapor onsuz tamamlanır.
 *  Model yanıtlamayı reddederse "reddedildi" (kalıcı).
 *
 *  ogeler(): reduce çıktısını transkript_analiz_ogeleri satırlarına çevirir.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Services;

use Closure;
use Modules\Transkript\Ai\Ai;
use Modules\Transkript\Ai\AiHatasi;
use Modules\Transkript\Ai\GirisSiniri;
use Modules\Transkript\Ai\Provider;
use Modules\Transkript\Ai\YedekliSaglayici;
use Modules\Transkript\Ayarlar;
use Modules\Transkript\Repositories\AiKullanimRepository;
use Modules\Transkript\Transcript\Segment;
use Modules\Transkript\Zaman;

final class Pipeline
{
    private const ONEM = [
        'critical' => 'kritik', 'kritik' => 'kritik',
        'important' => 'onemli', 'onemli' => 'onemli', 'önemli' => 'onemli',
        'useful' => 'faydali', 'faydali' => 'faydali', 'faydalı' => 'faydali',
        'low' => 'dusuk', 'dusuk' => 'dusuk', 'düşük' => 'dusuk',
    ];

    /* =================================================================
     *  ŞEMALAR (JSON Schema alt kümesi; sağlayıcıya göre çevrilir)
     * ============================================================== */

    private const METIN = ['type' => 'string'];
    private const ZAMAN = ['type' => 'string', 'description' => 'hh:mm:ss, transcript satırlarındaki zaman damgalarından biri'];

    /** Map ve reduce'un ortak madde listeleri. */
    private const MADDELER = [
        'important_points' => ['type' => 'array', 'items' => [
            'type'       => 'object',
            'properties' => ['text' => self::METIN, 'at' => self::ZAMAN, 'importance' => ['type' => 'string', 'enum' => ResultValidator::ONEM]],
            'required'   => ['text', 'at', 'importance'],
        ]],
        'technical_information' => ['type' => 'array', 'items' => [
            'type'       => 'object',
            'properties' => ['name' => self::METIN, 'category' => self::METIN, 'description' => self::METIN, 'at' => self::ZAMAN],
            'required'   => ['name', 'category', 'description', 'at'],
        ]],
        'claims' => ['type' => 'array', 'items' => [
            'type'       => 'object',
            'properties' => ['text' => self::METIN, 'at' => self::ZAMAN, 'speaker_confidence' => ['type' => 'string', 'enum' => ResultValidator::GUVEN]],
            'required'   => ['text', 'at', 'speaker_confidence'],
        ]],
        'ideas' => ['type' => 'array', 'items' => [
            'type'       => 'object',
            'properties' => ['text' => self::METIN, 'type' => self::METIN, 'at' => self::ZAMAN],
            'required'   => ['text', 'type', 'at'],
        ]],
        'timestamps' => ['type' => 'array', 'items' => [
            'type'       => 'object',
            'properties' => ['at' => self::ZAMAN, 'label' => self::METIN],
            'required'   => ['at', 'label'],
        ]],
    ];

    public const PARCA_SEMASI = [
        'type'       => 'object',
        'properties' => ['topic' => self::METIN, 'summary' => self::METIN] + self::MADDELER,
        'required'   => ['topic', 'summary', 'important_points', 'technical_information', 'claims', 'ideas', 'timestamps'],
    ];

    public const BIRLESTIRME_SEMASI = [
        'type'       => 'object',
        'properties' => [
            'summary'          => self::METIN,
            'detailed_summary' => self::METIN,
            'topics'           => ['type' => 'array', 'items' => [
                'type'       => 'object',
                'properties' => ['title' => self::METIN, 'start' => self::ZAMAN, 'end' => self::ZAMAN, 'summary' => self::METIN],
                'required'   => ['title', 'start', 'end', 'summary'],
            ]],
        ] + self::MADDELER + [
            'recommendations' => ['type' => 'array', 'items' => [
                'type'       => 'object',
                'properties' => ['text' => self::METIN],
                'required'   => ['text'],
            ]],
        ],
        'required' => ['summary', 'detailed_summary', 'topics', 'important_points', 'technical_information',
                       'claims', 'ideas', 'timestamps', 'recommendations'],
    ];

    /**
     * @param ?Closure(string): array{0: Provider, 1: string} $saglayici
     *        Aşama → [sağlayıcı, sağlayıcı adı]. null → config + .env
     *        (Ai::make). Testler sahte sağlayıcı verir.
     */
    public function __construct(
        private readonly AiKullanimRepository $kullanim,
        private readonly int $analizId,
        private readonly ResultValidator $dogrulayici = new ResultValidator(),
        private readonly ?Closure $saglayici = null,
    ) {
    }

    /* =================================================================
     *  MAP
     * ============================================================== */

    /**
     * @param array<string,mixed> $parca        transkript_parcalar satırı
     * @param list<Segment>       $bloklar      Cleaner::hazirla() çıktısı (istemin satırları)
     * @param list<int>           $baslangiclar parçadaki gerçek segment başlangıçları (zaman yaslama)
     * @param array<string,mixed> $baglam       baslik, kanal, dil, toplam (parça sayısı)
     * @return array{sonuc: array<string,mixed>, model: string, uyarilar: list<string>}
     */
    public function parcaAnalizEt(array $parca, array $bloklar, array $baslangiclar, array $baglam): array
    {
        $basMs = (int) $parca['baslangic_ms'];
        $bitMs = (int) $parca['bitis_ms'];
        $istem = Istem::yukle('parca_analizi');

        $satirlar = array_map(
            static fn (Segment $b): string => '[' . Zaman::yaz($b->baslangicMs) . '] '
                . ($b->konusmaci !== null && $b->konusmaci !== '' ? '(' . $b->konusmaci . ') ' : '') . $b->metin,
            $bloklar
        );

        $kullanici = $istem->kullanici([
            'baslik'     => Istem::veri(self::bilgi($baglam['baslik'] ?? null)),
            'kanal'      => Istem::veri(self::bilgi($baglam['kanal'] ?? null)),
            'dil'        => Istem::veri(self::bilgi($baglam['dil'] ?? null)),
            // v3: bölümün kelime sayısına göre üst sınırlar (eski istemlerde yer tutucu yok).
            'uzunluk'    => self::parcaUzunlugu(self::kelimeSayisi(implode(' ', array_map(static fn (Segment $b): string => $b->metin, $bloklar)))),
            'sira'       => (int) $parca['sira'],
            'toplam'     => max((int) $parca['sira'], (int) ($baglam['toplam'] ?? 0)),
            'baslangic'  => Zaman::yaz($basMs),
            'bitis'      => Zaman::yaz($bitMs),
            'transcript' => Istem::veri(implode("\n", $satirlar)),
        ]);

        return $this->jsonIste('map', $istem, $kullanici, self::PARCA_SEMASI,
            fn (array $veri): array => $this->dogrulayici->parca($veri, $basMs, $bitMs, $baslangiclar));
    }

    /* =================================================================
     *  REDUCE
     * ============================================================== */

    /**
     * @param list<array<string,mixed>> $parcaSonuclari doğrulanmış map sonuçları, zaman sırasıyla
     * @param list<int>                 $baslangiclar   transcript'in bütün segment başlangıçları
     * @param array<string,mixed>       $baglam         baslik, kanal, eksik (list<string> aralık)
     * @return array{sonuc: array<string,mixed>, model: string, uyarilar: list<string>}
     */
    public function birlestir(array $parcaSonuclari, int $bitMs, array $baslangiclar, array $baglam): array
    {
        [$istem, $kullanici] = self::birlestirmeIstemi($parcaSonuclari, $bitMs, $baglam);

        return $this->jsonIste('reduce', $istem, $kullanici, self::BIRLESTIRME_SEMASI,
            fn (array $veri): array => $this->dogrulayici->birlestirme($veri, $bitMs, $baslangiclar));
    }

    /**
     * Birleştirme istemi: sistem + kullanıcı metni.
     *
     * @param  list<array<string,mixed>> $parcaSonuclari
     * @param  array<string,mixed>       $baglam
     * @return array{0: Istem, 1: string}
     */
    public static function birlestirmeIstemi(array $parcaSonuclari, int $bitMs, array $baglam): array
    {
        $istem = Istem::yukle('birlestirme');
        $eksik = array_values(array_filter((array) ($baglam['eksik'] ?? []), 'is_string'));
        $kelime = (int) ($baglam['kelime'] ?? 0);

        return [$istem, $istem->kullanici([
            'baslik'       => Istem::veri(self::bilgi($baglam['baslik'] ?? null)),
            'kanal'        => Istem::veri(self::bilgi($baglam['kanal'] ?? null)),
            'sure'         => Zaman::yaz($bitMs),
            'kelime'       => $kelime,
            'uzunluk'      => self::raporUzunlugu(intdiv($bitMs, 1000), $kelime),
            'bolum_sayisi' => count($parcaSonuclari),
            'eksik'        => $eksik === [] ? 'yok' : implode(', ', $eksik),
            'bolumler'     => self::bolumSatirlari($parcaSonuclari),
        ])];
    }

    /** Birleştirme isteğinin yaklaşık token sayısı (GirisSiniri). @param list<array<string,mixed>> $parcaSonuclari */
    public static function birlestirmeBoyutu(array $parcaSonuclari, int $bitMs, array $baglam): int
    {
        [$istem, $kullanici] = self::birlestirmeIstemi($parcaSonuclari, $bitMs, $baglam);

        return GirisSiniri::tahmin($istem->sistem, [['content' => $kullanici]]);
    }

    /**
     * Bölüm başına bir JSON satırı. JSON_HEX_TAG "<" ">" işaretlerini
     * <'ye çevirir: AI çıktısı veri bloğunu kapatamaz.
     *
     * @param list<array<string,mixed>> $sonuclar
     */
    private static function bolumSatirlari(array $sonuclar): string
    {
        return implode("\n", array_map(
            static fn (array $s): string => (string) json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_INVALID_UTF8_SUBSTITUTE),
            $sonuclar
        ));
    }

    /* =================================================================
     *  KADEMELİ BİRLEŞTİRME (uzun video, küçük bağlamlı sağlayıcı)
     * =================================================================
     *  Birleştirme girdisi o an açık sağlayıcıların sınırını aşınca
     *  (AnalizBirlestir) ardışık bölümler gruplara ayrılır; her grup bir
     *  ARA ÖZETE çevrilir. Ara özet bölüm analiziyle aynı biçimdedir
     *  (chunk, topic, summary, listeler): son birleştirme onları bölüm gibi
     *  okur, birleştirme istemi değişmez. İstem: ara_birlestirme.v1.
     * ============================================================== */

    /**
     * Birleştirme; girdi o an açık sağlayıcılara sığmıyorsa önce ara özetler.
     * Bir istek sırasında "büyük girdi" hatası gelirse (sınır o an öğrenildi
     * ya da büyük bağlamlı sağlayıcı dinlenmeye girdi) kapasite yeniden
     * ölçülüp yeniden gruplanır; biten ara özetler korunur ($ara / $araKaydet).
     *
     * @param  list<array<string,mixed>>          $sonuclar  bölüm sonuçları, zaman sırasıyla
     * @param  Closure(): ?int                     $kapasite  Pipeline::kapasite (testte sabit)
     * @param  array<string, array<string,mixed>> $ara       önceki denemelerin ara özetleri
     * @param  ?Closure(array<string, array<string,mixed>>): void $araKaydet her ara özetten sonra
     * @param  ?Closure(int, int, int): void      $ilerleme  (seviye, biten grup, toplam grup)
     * @return array{sonuc: array<string,mixed>, model: string, uyarilar: list<string>, kademeli: bool}
     *
     * @throws AiHatasi
     */
    public function birlestirKademeli(array $sonuclar, int $bitMs, array $baslangiclar, array $baglam, Closure $kapasite,
        array $ara = [], ?Closure $araKaydet = null, ?Closure $ilerleme = null): array
    {
        for ($deneme = 1; ; $deneme++) {
            $kap   = $kapasite();
            $girdi = $sonuclar;

            try {
                if ($kap !== null && count($girdi) > 1 && self::birlestirmeBoyutu($girdi, $bitMs, $baglam) > $kap) {
                    $girdi = $this->araOzetler($girdi, $bitMs, $baslangiclar, $baglam, $kap, $ara, $araKaydet, $ilerleme);
                }

                return $this->birlestir($girdi, $bitMs, $baslangiclar, $baglam) + ['kademeli' => $girdi !== $sonuclar];
            } catch (AiHatasi $e) {
                if (!self::buyukGirdi($e) || $deneme >= 4) {
                    throw $e;
                }
            }
        }
    }

    /** İstek açık sağlayıcılara büyük mü (413 / bağlam aşımı ya da yalnız büyük bağlamlı biri bekleniyor)? */
    public static function buyukGirdi(AiHatasi $e): bool
    {
        return $e->kod === 'cok_buyuk' || $e->sebep === AiHatasi::SEBEP_BUYUK_GIRDI;
    }

    /**
     * Ardışık sonuçları gruplayıp ara özete çevirir; toplam kapasiteye
     * sığana (ya da daha küçültülemeyene) kadar seviye seviye.
     *
     * @param  list<array<string,mixed>>          $girdi
     * @param  array<string, array<string,mixed>> $ara
     * @return list<array<string,mixed>>
     */
    private function araOzetler(array $girdi, int $bitMs, array $baslangiclar, array $baglam, int $kap, array &$ara, ?Closure $araKaydet, ?Closure $ilerleme): array
    {
        $enCok       = max(2, (int) Ayarlar::get('ai.kademeli.grup_en_cok', 4));
        $seviyeEnCok = max(1, (int) Ayarlar::get('ai.kademeli.seviye_en_cok', 4));
        $butce       = max(1, $kap - self::araBirlestirmeBoyutu([], $baglam));

        for ($seviye = 1; $seviye <= $seviyeEnCok && count($girdi) > 1 && self::birlestirmeBoyutu($girdi, $bitMs, $baglam) > $kap; $seviye++) {
            $boyutlar = array_map(static fn (array $s): int => GirisSiniri::metinBoyutu(self::bolumSatirlari([$s])) + 1, $girdi);
            $gruplar  = self::gruplar($boyutlar, $butce, $enCok);

            // Her grup tek öğe: daha küçültülemez (tek bölüm bile sınırı aşıyor).
            if (count($gruplar) === count($girdi)) {
                break;
            }

            $toplam = count(array_filter($gruplar, static fn (array $g): bool => count($g) > 1));
            $biten  = 0;
            $yeni   = [];

            foreach ($gruplar as $grup) {
                if (count($grup) === 1) {
                    $yeni[] = $girdi[$grup[0]];

                    continue;
                }

                $uyeler  = array_map(static fn (int $k): array => $girdi[$k], $grup);
                $anahtar = 's' . $seviye . ':' . ($uyeler[0]['chunk']['start'] ?? '?') . '-' . ($uyeler[count($uyeler) - 1]['chunk']['end'] ?? '?');

                if (!isset($ara[$anahtar])) {
                    $ara[$anahtar] = $this->araBirlestir($uyeler, $baslangiclar, $baglam)['sonuc'];

                    if ($araKaydet !== null) {
                        $araKaydet($ara);
                    }
                }

                $yeni[] = $ara[$anahtar];

                if ($ilerleme !== null) {
                    $ilerleme($seviye, ++$biten, $toplam);
                }
            }

            $girdi = $yeni;
        }

        return $girdi;
    }

    /**
     * @param  list<array<string,mixed>> $grup         ardışık bölüm (ya da ara özet) sonuçları
     * @param  list<int>                 $baslangiclar transcript'in segment başlangıçları
     * @param  array<string,mixed>       $baglam       baslik, kanal
     * @return array{sonuc: array<string,mixed>, model: string, uyarilar: list<string>}
     */
    public function araBirlestir(array $grup, array $baslangiclar, array $baglam): array
    {
        [$istem, $kullanici, $basMs, $bitMs] = self::araBirlestirmeIstemi($grup, $baglam);

        return $this->jsonIste('reduce', $istem, $kullanici, self::PARCA_SEMASI,
            fn (array $veri): array => $this->dogrulayici->parca($veri, $basMs, $bitMs, $baslangiclar));
    }

    /**
     * @param  list<array<string,mixed>> $grup
     * @param  array<string,mixed>       $baglam
     * @return array{0: Istem, 1: string, 2: int, 3: int} istem, kullanıcı metni, başlangıç ve bitiş (ms)
     */
    public static function araBirlestirmeIstemi(array $grup, array $baglam): array
    {
        $istem = Istem::yukle('ara_birlestirme', 'v1');
        $basMs = $grup === [] ? 0 : (int) (Zaman::oku((string) ($grup[0]['chunk']['start'] ?? '')) ?? 0);
        $bitMs = $grup === [] ? 0 : (int) (Zaman::oku((string) ($grup[count($grup) - 1]['chunk']['end'] ?? '')) ?? $basMs);

        return [$istem, $istem->kullanici([
            'baslik'       => Istem::veri(self::bilgi($baglam['baslik'] ?? null)),
            'kanal'        => Istem::veri(self::bilgi($baglam['kanal'] ?? null)),
            'baslangic'    => Zaman::yaz($basMs),
            'bitis'        => Zaman::yaz($bitMs),
            'bolum_sayisi' => count($grup),
            'bolumler'     => self::bolumSatirlari($grup),
        ]), $basMs, $bitMs];
    }

    /** Ara birleştirme isteğinin yaklaşık token sayısı. @param list<array<string,mixed>> $grup */
    public static function araBirlestirmeBoyutu(array $grup, array $baglam): int
    {
        [$istem, $kullanici] = self::araBirlestirmeIstemi($grup, $baglam);

        return GirisSiniri::tahmin($istem->sistem, [['content' => $kullanici]]);
    }

    /**
     * Ardışık öğeleri boyut bütçesine göre gruplar (sıra korunur). Bir grup
     * en çok $enCok öğe alır ve toplamı $butce'yi aşmaz; tek başına bütçeyi
     * aşan öğe kendi grubunda kalır (AnalizBirlestir onu olduğu gibi geçirir).
     *
     * @param  list<int> $boyutlar öğe başına yaklaşık token
     * @return list<list<int>>     öğe indisleri
     */
    public static function gruplar(array $boyutlar, int $butce, int $enCok = 4): array
    {
        $gruplar = [];
        $grup    = [];
        $toplam  = 0;
        $enCok   = max(2, $enCok);

        foreach (array_values($boyutlar) as $i => $boyut) {
            if ($grup !== [] && ($toplam + $boyut > $butce || count($grup) >= $enCok)) {
                $gruplar[] = $grup;
                $grup      = [];
                $toplam    = 0;
            }

            $grup[]  = $i;
            $toplam += $boyut;
        }

        if ($grup !== []) {
            $gruplar[] = $grup;
        }

        return $gruplar;
    }

    /**
     * Şu an birleştirme isteği alabilecek sağlayıcıların en büyük sınırı
     * (yaklaşık giriş token'ı): zincirde anahtarı tanımlı, dinlenmeyen ve
     * devre dışı olmayanlar. Sınırı bilinmeyen (0) varsa PHP_INT_MAX; hiçbiri
     * açık değilse null (hepsi dinleniyor: iş zaten ertelenir).
     */
    public static function kapasite(?int $simdi = null): ?int
    {
        $simdi ??= time();
        $enBuyuk = null;

        foreach (Ai::zincirDurumu() as $halka) {
            if (!$halka['tanimli'] || YedekliSaglayici::dinlenmeKalan($halka['ad'], $simdi, $halka['model']) > 0) {
                continue;
            }

            $sinir   = GirisSiniri::sinir($halka['ad'], $halka['model']);
            $enBuyuk = max($enBuyuk ?? 0, $sinir === 0 ? PHP_INT_MAX : $sinir);
        }

        return $enBuyuk;
    }

    /* =================================================================
     *  UZUNLUK KURALI (istem v3)
     * =================================================================
     *  v2 her videoya aynı aralıkları istiyordu ("5–10 cümle özet, 3–7
     *  öneri"); 18 saniyelik tek cümlelik videoya 5 cümle özet ve genel
     *  geçer öneriler yazıldı. Sınırlar video süresi ve transcript'in
     *  kelime sayısından hesaplanır, istemde "Uzunluk:" satırına girer.
     *  Hangisi küçükse o belirler: 10 dakikalık müzik videosundaki 200
     *  kelime de "kısa" sayılır.
     * ============================================================== */

    /** Bu süreden (sn) ya da kelimeden az: kısa video. */
    public const KISA_SN     = 120;
    public const KISA_KELIME = 300;

    /** Bu süreden (sn) ya da kelimeden az: orta; üstü uzun. */
    public const ORTA_SN     = 900;
    public const ORTA_KELIME = 2500;

    public static function raporUzunlugu(int $sureSn, int $kelime): string
    {
        $olcu = sprintf('%s, ~%d kelime', Zaman::yaz($sureSn * 1000), $kelime);

        if ($sureSn < self::KISA_SN || $kelime < self::KISA_KELIME) {
            return 'Video çok kısa (' . $olcu . '). summary 1–2 cümle. detailed_summary boş metin ("") ya da tek kısa paragraf.'
                . ' topics en fazla 2, important_points en fazla 3, timestamps en fazla 3.'
                . " technical_information, claims, ideas ve recommendations yalnızca transcript'te açıkça geçen içerik varsa; yoksa boş dizi.";
        }

        if ($sureSn < self::ORTA_SN || $kelime < self::ORTA_KELIME) {
            return 'Orta uzunlukta video (' . $olcu . '). summary 3–4 cümle. detailed_summary 2–4 paragraf.'
                . ' topics 2–6, important_points en fazla 8, timestamps en fazla 10, recommendations en fazla 4.';
        }

        return 'Uzun video (' . $olcu . '). summary 5–8 cümle. detailed_summary konu başına bir paragraf.'
            . ' topics 4–12, important_points 5–15, timestamps 6–20, recommendations en fazla 7.';
    }

    public static function parcaUzunlugu(int $kelime): string
    {
        if ($kelime < self::KISA_KELIME) {
            return 'Bu bölüm çok kısa (~' . $kelime . ' kelime). summary 1–2 cümle; important_points en fazla 2; timestamps en fazla 2;'
                . ' diğer listeler yalnızca açıkça söylenen içerik varsa, yoksa boş dizi.';
        }

        return 'Normal bölüm (~' . $kelime . ' kelime). summary 2–4 cümle; important_points 3–7; timestamps 1–4.';
    }

    public static function kelimeSayisi(string $metin): int
    {
        return count(preg_split('/\s+/u', trim($metin), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /* =================================================================
     *  İSTEK + DOĞRULAMA + TEK ONARIM
     * ============================================================== */

    /**
     * @param  array<string,mixed>                                          $sema
     * @param  Closure(array<string,mixed>): array{sonuc: array<string,mixed>, uyarilar: list<string>} $dogrula
     * @return array{sonuc: array<string,mixed>, model: string, uyarilar: list<string>}
     *
     * @throws AiHatasi
     */
    private function jsonIste(string $asama, Istem $istem, string $kullanici, array $sema, Closure $dogrula): array
    {
        [$saglayici, $ad] = $this->saglayici !== null ? ($this->saglayici)($asama) : self::saglayiciUret($asama);

        $ek       = $ad === 'gemini' ? (array) Ayarlar::get('ai.' . $asama . '.gemini_ek', []) : [];
        $mesajlar = [['role' => 'user', 'content' => $kullanici]];
        $enCok    = max(500, (int) Ayarlar::get('ai.onarim_en_cok_karakter', 6000));

        for ($deneme = 1; ; $deneme++) {
            $baslangic = hrtime(true);

            try {
                $yanit = $saglayici->sendJson($mesajlar, $istem->sistem, $sema, $ek);
            } catch (AiHatasi $e) {
                if ($saglayici instanceof YedekliSaglayici) {
                    $this->denemeleriKaydet($saglayici);
                } else {
                    $this->kaydet($ad, $saglayici->model(), null, $baslangic, 'hata', $e->kod, $e->getMessage());
                }

                throw $e;
            }

            // Zincirde önce düşenler "hata" satırı olur; yanıtı veren adıyla yazılır.
            if ($saglayici instanceof YedekliSaglayici) {
                $this->denemeleriKaydet($saglayici);
                $ad = $saglayici->sonAd();
            }

            $durum = 'gecersiz';

            try {
                if ($yanit['refusal'] !== null) {
                    $durum = 'reddedildi';

                    throw new AiHatasi(sprintf('Model yanıtlamadı (%s, %s).', $asama, $yanit['refusal']), 'reddedildi');
                }

                $veri = ResultValidator::ayikla($yanit['text']) ?? throw new GecersizSonuc(['Yanıt geçerli bir JSON nesnesi değil.']);
                $sonuc = $dogrula($veri);
                $durum = 'basarili';

                return $sonuc + ['model' => $yanit['model']];
            } catch (GecersizSonuc $e) {
                if ($deneme >= 2) {
                    throw new AiHatasi(sprintf('%s yanıtı onarımdan sonra da geçersiz: %s', $asama, $e->getMessage()), 'yanit_cozulemedi');
                }

                $mesajlar[] = ['role' => 'assistant', 'content' => $yanit['text'] !== '' ? mb_substr($yanit['text'], 0, $enCok) : '(boş yanıt)'];
                $mesajlar[] = ['role' => 'user', 'content' => self::onarimIstegi($e->sorunlar, $yanit['stop_reason'] === 'max_tokens')];
            } finally {
                $this->kaydet($ad, (string) $yanit['model'], $yanit, $baslangic, $durum);
            }
        }
    }

    /** @param list<string> $sorunlar */
    private static function onarimIstegi(array $sorunlar, bool $kesildi): string
    {
        return 'Önceki yanıtın kullanılamadı: ' . implode(' ', $sorunlar)
            . ($kesildi ? ' Yanıt uzunluk sınırında kesildi; maddeleri kısaltarak yaz.' : '')
            . ' Aynı analizi yalnızca şemaya uygun, geçerli TEK bir JSON nesnesi olarak yeniden döndür;'
            . ' açıklama ya da kod bloğu ekleme.';
    }

    /** Yedek zincirinde düşen sağlayıcılar: token yok, süre ve ad var. */
    private function denemeleriKaydet(YedekliSaglayici $zincir): void
    {
        foreach ($zincir->denemeler() as $d) {
            $this->kullanim->kaydet([
                'saglayici'   => $d['ad'],
                'model'       => $d['model'],
                'giris_token' => 0,
                'cikis_token' => 0,
                'maliyet_usd' => 0.0,
                'sure_ms'     => $d['sure_ms'],
            ], 'analiz', $this->analizId, 'hata', $d['kod'], $d['mesaj']);
        }
    }

    /** @param array<string,mixed>|null $yanit */
    private function kaydet(string $saglayici, string $model, ?array $yanit, int|float $baslangic, string $durum, ?string $hataKodu = null, ?string $hataAyrinti = null): void
    {
        $this->kullanim->kaydet([
            'saglayici'   => $saglayici,
            'model'       => $model,
            'giris_token' => (int) ($yanit['usage']['input_tokens'] ?? 0),
            'cikis_token' => (int) ($yanit['usage']['output_tokens'] ?? 0),
            'maliyet_usd' => self::maliyet($yanit, $saglayici),
            'sure_ms'     => (int) ((hrtime(true) - $baslangic) / 1_000_000),
        ], 'analiz', $this->analizId, $durum, $hataKodu ?? ($durum === 'basarili' ? null : $durum), $hataAyrinti);
    }

    /**
     * Tahmini maliyet: sağlayıcının sabit fiyatı varsa onunla (EVREN: 0,
     * 1 Kasım 2026'ya kadar ücretsiz), yoksa panelde / config'te 1M token
     * fiyatı girildiyse onunla, yoksa sağlayıcının fiyat tablosundan
     * (yanıttaki "cost").
     *
     * @param array<string,mixed>|null $yanit
     */
    public static function maliyet(?array $yanit, string $saglayici = ''): float
    {
        $sabit = Ai::sabitFiyat($saglayici);

        if ($sabit !== null) {
            $giris = $sabit['giris'];
            $cikis = $sabit['cikis'];

            return round(((int) ($yanit['usage']['input_tokens'] ?? 0) * $giris + (int) ($yanit['usage']['output_tokens'] ?? 0) * $cikis) / 1_000_000, 8);
        }

        $giris = (float) Ayarlar::get('ai.fiyat.giris', 0.0);
        $cikis = (float) Ayarlar::get('ai.fiyat.cikis', 0.0);

        if ($yanit === null || ($giris <= 0 && $cikis <= 0)) {
            return (float) ($yanit['cost'] ?? 0.0);
        }

        return round(((int) ($yanit['usage']['input_tokens'] ?? 0) * $giris + (int) ($yanit['usage']['output_tokens'] ?? 0) * $cikis) / 1_000_000, 8);
    }

    /**
     * config (ai.<aşama>) + .env'den sağlayıcı. .env'de AI_PROVIDERS ile
     * birden çok sağlayıcı verildiyse YedekliSaglayici döner (ad "zincir"):
     * birincil önce, kotası dolarsa sıradaki. Anahtarı tanımlı olmayan
     * halka atlanır; tek halka kalırsa düz sağlayıcı (eski davranış).
     *
     * Aşamanın modeli (config / panel, yoksa AI_MODEL) yalnız birincile
     * uygulanır; diğerleri kendi <AD>_MODEL'ini ya da varsayılanını
     * kullanır. Gemini'ye özel ek (thinkingConfig) yalnız Gemini halkasına.
     *
     * @return array{0: Provider, 1: string}
     */
    public static function saglayiciUret(string $asama): array
    {
        $ayar     = (array) Ayarlar::get('ai.' . $asama, []);
        $zincir   = Ai::zincir();
        $birincil = strtolower(trim((string) ($ayar['saglayici'] ?? '')));
        $birincil = $birincil !== '' ? $birincil : $zincir[0];
        $adlar    = array_values(array_unique([$birincil, ...$zincir]));

        $model = trim((string) ($ayar['model'] ?? ''));
        /* config'teki aşama modeli .env'deki asıl sağlayıcıya aittir; boşsa (ve
         * öteki halkalarda) Ai::varsayilanModel: panel (EVREN modeli, Gemini
         * modeli …), <AD>_MODEL, AI_MODEL (yalnız sahibinde), varsayılan. */
        $model = $birincil === Ai::provider() ? $model : '';

        $maxToken  = (int) ($ayar['max_token'] ?? 8192);
        $zamanAsim = (int) ($ayar['zaman_asimi'] ?? 120);

        if (count($adlar) === 1) {
            return [Ai::make($birincil, $model, $maxToken, $zamanAsim), $birincil];
        }

        $halkalar = [];

        foreach ($adlar as $i => $ad) {
            if (!Ai::zincirdeKullanilabilir($ad, $i === 0)) {
                continue;
            }

            $halkalar[] = [
                'ad'        => $ad,
                'saglayici' => Ai::make($ad, $i === 0 ? $model : '', $maxToken, $zamanAsim, $i === 0),
                'ek'        => $ad === 'gemini' ? (array) ($ayar['gemini_ek'] ?? []) : [],
            ];
        }

        return match (count($halkalar)) {
            // Hiçbirinin anahtarı yok: birincilin Türkçe "anahtar yok" hatası.
            0       => [Ai::make($birincil, $model, $maxToken, $zamanAsim), $birincil],
            1       => [$halkalar[0]['saglayici'], $halkalar[0]['ad']],
            default => [new YedekliSaglayici($halkalar), 'zincir'],
        };
    }

    private static function bilgi(mixed $deger): string
    {
        $metin = ResultValidator::metin($deger, 300);

        return $metin !== '' ? $metin : 'bilinmiyor';
    }

    /* =================================================================
     *  RAPOR MADDELERİ
     * ============================================================== */

    /**
     * Reduce çıktısını transkript_analiz_ogeleri satırlarına çevirir.
     * Bozuk madde atlanır; zaman okunamazsa null kalır (konu ve nokta
     * zamansız da gösterilebilir).
     *
     * @param  array<string,mixed> $rapor
     * @return list<array<string,mixed>>
     */
    public static function ogeler(array $rapor): array
    {
        $ogeler = [];

        $ekle = static function (string $tur, mixed $liste, callable $donustur) use (&$ogeler): void {
            if (!is_array($liste)) {
                return;
            }

            foreach ($liste as $madde) {
                if (!is_array($madde)) {
                    continue;
                }

                $oge = $donustur($madde);

                if ($oge !== null && trim((string) $oge['baslik']) !== '') {
                    $ogeler[] = ['tur' => $tur] + $oge;
                }
            }
        };

        $ekle('konu', $rapor['topics'] ?? null, static fn (array $m): array => [
            'baslik'       => (string) ($m['title'] ?? $m['topic'] ?? ''),
            'aciklama'     => self::metinVeyaNull($m['summary'] ?? null),
            'baslangic_ms' => self::ms($m['start'] ?? $m['at'] ?? null),
            'bitis_ms'     => self::ms($m['end'] ?? null),
        ]);

        $ekle('nokta', $rapor['important_points'] ?? null, static fn (array $m): array => [
            'baslik'       => (string) ($m['text'] ?? ''),
            'baslangic_ms' => self::ms($m['at'] ?? null),
            'onem'         => self::ONEM[strtolower((string) ($m['importance'] ?? ''))] ?? null,
        ]);

        $ekle('teknik', $rapor['technical_information'] ?? null, static fn (array $m): array => [
            'baslik'       => (string) ($m['name'] ?? ''),
            'aciklama'     => self::metinVeyaNull($m['description'] ?? null),
            'kategori'     => self::metinVeyaNull($m['category'] ?? null),
            'baslangic_ms' => self::ms($m['at'] ?? null),
        ]);

        $ekle('iddia', $rapor['claims'] ?? null, static fn (array $m): array => [
            'baslik'       => (string) ($m['text'] ?? ''),
            'baslangic_ms' => self::ms($m['at'] ?? null),
            'dogrulama'    => self::metinVeyaNull($m['verification'] ?? $m['speaker_confidence'] ?? null),
        ]);

        $ekle('fikir', $rapor['ideas'] ?? null, static fn (array $m): array => [
            'baslik'       => (string) ($m['text'] ?? ''),
            'kategori'     => self::metinVeyaNull($m['type'] ?? null),
            'baslangic_ms' => self::ms($m['at'] ?? null),
        ]);

        $ekle('zaman', $rapor['timestamps'] ?? null, static fn (array $m): ?array => self::ms($m['at'] ?? null) === null ? null : [
            'baslik'       => (string) ($m['label'] ?? ''),
            'baslangic_ms' => self::ms($m['at'] ?? null),
        ]);

        return $ogeler;
    }

    private static function ms(mixed $zaman): ?int
    {
        return is_string($zaman) || is_int($zaman) ? Zaman::oku((string) $zaman) : null;
    }

    private static function metinVeyaNull(mixed $deger): ?string
    {
        return is_string($deger) && trim($deger) !== '' ? trim($deger) : null;
    }
}
