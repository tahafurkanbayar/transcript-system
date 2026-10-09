<?php
/**
 * =====================================================================
 *  RaporVerisi – rapor sayfası ve API "ozet" ucu için TEK veri kaynağı
 * ---------------------------------------------------------------------
 *  Panelin rapor sayfası (panel/transkript/{kaynak_id}) ve API'nin
 *  kaynaklar/{id}/ozet ucu aynı diziyi kullanır.
 *
 *  RAPOR = kaynağın GÜNCEL ve TAMAMLANMIŞ analizi. Yeniden analiz
 *  sürerken eski rapor görünmeye devam eder; ilerleme ayrıca gelir
 *  (KaynakDurumu).
 *
 *  Rapor JSON'u ResultValidator'dan geçmiş hâliyle saklanır (düz metin,
 *  "hh:mm:ss" zamanlar). Burada yalnız okunur; görünüm metni e() ile,
 *  JS textContent ile basar. HTML üretilmez.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Services;

use Modules\Transkript\Ayarlar;
use Modules\Transkript\Repositories\AnalizRepository;
use Modules\Transkript\Repositories\KaynakRepository;
use Modules\Transkript\Repositories\SegmentRepository;
use Modules\Transkript\TranskriptPolicy;
use Modules\Transkript\Zaman;
use PDO;

final class RaporVerisi
{
    public const ONEM_ETIKETI = ['critical' => 'Kritik', 'important' => 'Önemli', 'useful' => 'Faydalı'];
    public const GUVEN_ETIKETI = ['stated_as_fact' => 'Kesin ifade', 'opinion' => 'Görüş', 'speculation' => 'Tahmin'];

    /** technical_information bölümünün başlığı (sayfa ve Markdown). Araç ve
     *  yöntemlerin yanında kavram ve terimleri de içerir (istem v3). */
    public const TEKNIK_BASLIK = 'Kavramlar ve teknik bilgiler';

    /** Transcript dilinin adı; listede yoksa kodun büyük harfi. */
    public const DIL_ADLARI = [
        'tr' => 'Türkçe', 'en' => 'İngilizce', 'de' => 'Almanca', 'fr' => 'Fransızca', 'es' => 'İspanyolca',
        'it' => 'İtalyanca', 'pt' => 'Portekizce', 'ru' => 'Rusça', 'ar' => 'Arapça', 'fa' => 'Farsça',
        'ja' => 'Japonca', 'ko' => 'Korece', 'zh' => 'Çince', 'hi' => 'Hintçe', 'nl' => 'Felemenkçe', 'az' => 'Azerice',
    ];

    /** $policy: bakanın hakları; iç hata nedeni yalnız yetkiliye (KaynakDurumu). */
    public function __construct(private readonly PDO $db, private readonly ?TranskriptPolicy $policy = null)
    {
    }

    /**
     * @param  array<string,mixed> $kaynak transkript_kaynaklar satırı
     * @return array{
     *     durum: array<string,mixed>,
     *     rapor: array<string,mixed>|null,
     *     analiz: array<string,mixed>|null,
     *     eksik: list<string>,
     *     transcript: array<string,mixed>|null
     * }
     */
    public function getir(array $kaynak): array
    {
        $kaynakId = (int) $kaynak['id'];
        $analiz   = (new KaynakRepository($this->db))->guncelTamamlanmisAnaliz($kaynakId);
        $rapor    = $analiz !== null ? json_decode((string) $analiz['sonuc_json'], true) : null;
        $eksik    = [];

        if ($analiz !== null) {
            foreach ((new AnalizRepository($this->db))->parcalar((int) $analiz['id']) as $parca) {
                if ($parca['durum'] !== 'tamamlandi') {
                    $eksik[] = Zaman::yaz((int) $parca['baslangic_ms']) . '–' . Zaman::yaz((int) $parca['bitis_ms']);
                }
            }
        }

        $metin = (new SegmentRepository($this->db))->sonMetin($kaynakId);

        // Eski raporlarda da konu aralıkları çakışmasın (ResultValidator v3 öncesi).
        if (is_array($rapor) && is_array($rapor['topics'] ?? null)) {
            $rapor['topics'] = ResultValidator::konulariDuzenle($rapor['topics'], $kaynak['sure_sn'] !== null ? (int) $kaynak['sure_sn'] * 1000 : null);
        }

        return [
            'durum'  => (new KaynakDurumu($this->db, $this->policy))->getir($kaynak),
            'rapor'  => is_array($rapor) ? $rapor : null,
            'analiz' => $analiz === null ? null : [
                'id'            => (int) $analiz['id'],
                // Raporu başlatan (yeniden analiz hakkı; API yalnız yöneticiye gösterir).
                'kullanici_id'  => $analiz['kullanici_id'] !== null ? (int) $analiz['kullanici_id'] : null,
                'model'         => (string) ($analiz['model'] ?? ''),
                'prompt_surumu' => (string) ($analiz['prompt_surumu'] ?? ''),
                'toplam_token'  => (int) $analiz['toplam_token'],
                'maliyet_usd'   => (float) $analiz['maliyet_usd'],
                'bitti_at'      => $analiz['bitti_at'] ?? null,
            ],
            'eksik'      => $eksik,
            'transcript' => $metin === null ? null : [
                'saglayici'      => (string) $metin['saglayici'],
                'dil'            => (string) $metin['dil'],
                'otomatik_mi'    => (bool) $metin['otomatik_mi'],
                'segment_sayisi' => (int) $metin['segment_sayisi'],
            ],
        ];
    }

    /* =================================================================
     *  GÖRÜNÜM YARDIMCILARI
     * ============================================================== */

    /** "tr" → "Türkçe"; bilinmeyen kod büyük harfle ("SV"). */
    public static function dilAdi(string $kod): string
    {
        $kod = strtolower(substr($kod, 0, 2));

        return self::DIL_ADLARI[$kod] ?? strtoupper($kod);
    }

    /**
     * Rapor güncel istem sürümünden eski mi? "v1" < "v3". Sürüm yoksa
     * (çok eski kayıt) eski sayılır.
     */
    public static function eskiIstem(string $surum): bool
    {
        $guncel = (int) ltrim((string) Ayarlar::get('ai.istem_surumu', 'v1'), 'v');

        return (int) ltrim($surum, 'v') < $guncel;
    }

    /** "00:09:05" → 545; okunamazsa null */
    public static function saniye(mixed $zaman): ?int
    {
        $ms = is_string($zaman) ? Zaman::oku($zaman) : null;

        return $ms === null ? null : intdiv($ms, 1000);
    }

    /** Mobilde ve oynatıcı yokken açılan bağlantı: YouTube uygulaması o saniyeden başlar. */
    public static function youtubeBaglantisi(string $videoId, int $saniye): string
    {
        return 'https://youtu.be/' . rawurlencode($videoId) . ($saniye > 0 ? '?t=' . $saniye : '');
    }

    /**
     * Gömülü oynatıcı adresi. youtube-nocookie: oynat'a basılana kadar
     * çerez yazılmaz. enablejsapi=1: sayfa postMessage ile seekTo
     * gönderebilir (IFrame Player API'nin iletişim yolu; dış betik
     * yüklemeye gerek yok, CSP'de script-src açılmaz).
     */
    public static function gomuluAdres(string $videoId, string $origin): string
    {
        return 'https://www.youtube-nocookie.com/embed/' . rawurlencode($videoId) . '?' . http_build_query([
            'enablejsapi' => 1,
            'rel'         => 0,
            'playsinline' => 1,
            'origin'      => $origin,
        ]);
    }

    /**
     * Raporun okunma süresi (dk): kısa özet, konular ve önemli noktalar,
     * dakikada ~200 sözcük. "19 dk video → 2 dk okuma" göstergesi.
     *
     * @param array<string,mixed> $rapor
     */
    public static function okumaDakikasi(array $rapor): int
    {
        $metin = (string) ($rapor['summary'] ?? '');

        foreach ((array) ($rapor['topics'] ?? []) as $m) {
            $metin .= ' ' . ($m['title'] ?? '') . ' ' . ($m['summary'] ?? '');
        }

        foreach ((array) ($rapor['important_points'] ?? []) as $m) {
            $metin .= ' ' . ($m['text'] ?? '');
        }

        $sozcuk = count(preg_split('/\s+/u', trim($metin), -1, PREG_SPLIT_NO_EMPTY) ?: []);

        return max(1, (int) ceil($sozcuk / 200));
    }

    /** "1:02:03" / "12:03" — süre gösterimi */
    public static function sure(?int $saniye): string
    {
        if ($saniye === null || $saniye <= 0) {
            return '—';
        }

        return $saniye >= 3600
            ? sprintf('%d:%02d:%02d', intdiv($saniye, 3600), intdiv($saniye % 3600, 60), $saniye % 60)
            : sprintf('%d:%02d', intdiv($saniye, 60), $saniye % 60);
    }

    /**
     * Teknik bilgiler kategoriye göre; kategorisiz olanlar "Diğer".
     *
     * @param  list<array<string,mixed>>                    $liste
     * @return array<string,list<array<string,mixed>>>
     */
    public static function kategoriyeGore(array $liste): array
    {
        $gruplar = [];

        foreach ($liste as $madde) {
            $kategori = trim((string) ($madde['category'] ?? ''));
            $kategori = $kategori !== '' ? self::ilkHarfBuyuk($kategori) : 'Diğer';
            $gruplar[$kategori][] = $madde;
        }

        // "Diğer" en sona.
        uksort($gruplar, static fn (string $a, string $b): int => ($a === 'Diğer') <=> ($b === 'Diğer') ?: strcmp($a, $b));

        return $gruplar;
    }

    /** Türkçe ilk harf: "işlem" → "İşlem" (mb_convert_case "Işlem" yapar). */
    public static function ilkHarfBuyuk(string $metin): string
    {
        $ilk = mb_substr($metin, 0, 1);
        $ilk = match ($ilk) {
            'i'     => 'İ',
            'ı'     => 'I',
            default => mb_strtoupper($ilk),
        };

        return $ilk . mb_substr($metin, 1);
    }
}
