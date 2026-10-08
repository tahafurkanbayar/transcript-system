<?php
/**
 * =====================================================================
 *  KaynakDurumu – kaynak kartı + son analizin durumu (panel + API)
 * ---------------------------------------------------------------------
 *  Panelin ilerleme ucu (3 sn'de bir) ve API'nin kaynaklar/{id} ucu
 *  aynı diziyi döner. Küçük ve hızlıdır: üç kısa sorgu.
 *
 *  durum      son analizin durumu; hiç analiz yoksa "yok"
 *  etiket     kullanıcıya gösterilecek Türkçe aşama / hata metni
 *  rapor_hazir güncel ve tamamlanmış bir rapor var mı? Yeniden analiz
 *             sürerken eski rapor görünmeye devam eder.
 *
 *  Teknik ayrıntı (parça hatası, sağlayıcı yanıtı) burada yoktur;
 *  yalnız log'dadır.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Services;

use Modules\Transkript\HataKodlari;
use Modules\Transkript\Repositories\AnalizRepository;
use Modules\Transkript\Repositories\KaynakRepository;
use PDO;

final class KaynakDurumu
{
    /** Aşama → ekranda görünen metin. */
    private const ETIKETLER = [
        'kaynak'      => 'Video bilgisi alınıyor',
        'transkript'  => 'Transcript alınıyor',
        'parca'       => 'Bölümler analiz ediliyor',
        'birlestirme' => 'Sonuçlar birleştiriliyor',
        'rapor'       => 'Rapor hazırlanıyor',
        'bitti'       => 'Analiz tamamlandı',
    ];

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @param  array<string,mixed> $kaynak transkript_kaynaklar satırı
     * @return array<string,mixed>
     */
    public function getir(array $kaynak): array
    {
        $kaynakId = (int) $kaynak['id'];
        $analiz   = (new AnalizRepository($this->db))->ilerlemeBilgisi($kaynakId);
        $rapor    = (new KaynakRepository($this->db))->guncelTamamlanmisAnaliz($kaynakId);

        return [
            'kaynak_id'     => $kaynakId,
            'tur'           => (string) $kaynak['tur'],
            'dis_kimlik'    => (string) $kaynak['dis_kimlik'],
            'baslik'        => $kaynak['baslik'] !== null ? (string) $kaynak['baslik'] : null,
            'kanal_adi'     => $kaynak['kanal_adi'] !== null ? (string) $kaynak['kanal_adi'] : null,
            'sure_sn'       => $kaynak['sure_sn'] !== null ? (int) $kaynak['sure_sn'] : null,
            'kucuk_resim'   => $kaynak['kucuk_resim'] !== null ? (string) $kaynak['kucuk_resim'] : null,
            'kaynak_durumu' => (string) $kaynak['durum'],
            'analiz_id'     => $analiz['analiz_id'] ?? null,
            'durum'         => $analiz['durum'] ?? 'yok',
            'asama'         => $analiz['asama'] ?? null,
            'ilerleme'      => $analiz['ilerleme'] ?? 0,
            'parca'         => $analiz['parca'] ?? ['toplam' => 0, 'biten' => 0, 'hatali' => 0],
            'etiket'        => self::etiket($analiz),
            'hata'          => ($analiz['hata_kodu'] ?? null) !== null
                ? ['kod' => $analiz['hata_kodu'], 'mesaj' => HataKodlari::mesaj($analiz['hata_kodu'])]
                : null,
            'rapor_hazir'   => $rapor !== null,
            'rapor_analiz_id' => $rapor !== null ? (int) $rapor['id'] : null,
        ];
    }

    /**
     * Kullanıcıya gösterilecek tek satır.
     *
     * @param array<string,mixed>|null $analiz AnalizRepository::ilerlemeBilgisi
     */
    public static function etiket(?array $analiz): string
    {
        if ($analiz === null) {
            return 'Henüz analiz edilmedi';
        }

        if ($analiz['durum'] === 'hata') {
            return HataKodlari::mesaj($analiz['hata_kodu'] ?? null);
        }

        if ($analiz['durum'] === 'bekliyor' && empty($analiz['calisiyor'])) {
            return 'Sırada bekliyor';
        }

        $metin = self::ETIKETLER[$analiz['asama'] ?? ''] ?? 'Analiz sürüyor';
        $parca = $analiz['parca'] ?? [];

        if (($analiz['asama'] ?? '') === 'parca' && ($parca['toplam'] ?? 0) > 0) {
            $metin .= sprintf(' (%d / %d)', $parca['biten'], $parca['toplam']);
        }

        return $metin;
    }
}
