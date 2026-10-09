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
 *  sirada     analiz işçiyi bekliyorsa "12 dakikadır sırada", yoksa null
 *  isci_calisiyor / kuyruk_takili  KuyrukDurumu: işçi 2 dakikadır
 *             görülmediyse ve iş bekliyorsa ekran uyarı gösterir.
 *
 *  Teknik ayrıntı (parça hatası, sağlayıcı yanıtı) burada yoktur;
 *  yalnız log'dadır. Policy verilirse iç hata (kota, servis) yalnız
 *  yöneticiye ve analizi başlatana adıyla söylenir; öteki görenler
 *  HataKodlari::GENEL'i okur. Policy'siz çağrı (CLI, test) ayrıntılıdır.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Services;

use Modules\Transkript\HataKodlari;
use Modules\Transkript\Repositories\AnalizRepository;
use Modules\Transkript\Repositories\KaynakRepository;
use Modules\Transkript\TranskriptPolicy;
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

    public function __construct(private readonly PDO $db, private readonly ?TranskriptPolicy $policy = null)
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
        $sirada   = $analiz !== null && $analiz['durum'] === 'bekliyor' && empty($analiz['calisiyor']);
        $suruyor  = $analiz !== null && !in_array($analiz['durum'], AnalizRepository::BITMIS_DURUMLAR, true);
        $kuyruk   = $suruyor ? (new KuyrukDurumu($this->db))->getir() : null;
        $ayrinti  = $analiz === null || $this->policy === null || $this->policy->canSeeErrorDetail($analiz);
        $hataKodu = HataKodlari::gorunenKod($analiz['hata_kodu'] ?? null, $ayrinti);

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
            'etiket'        => self::etiket($analiz, $ayrinti),
            'hata'          => $hataKodu !== null
                ? ['kod' => $hataKodu, 'mesaj' => HataKodlari::gorunenMesaj($analiz['hata_kodu'], $ayrinti)]
                : null,
            'rapor_hazir'   => $rapor !== null,
            'rapor_analiz_id' => $rapor !== null ? (int) $rapor['id'] : null,
            'sirada_sn'     => $sirada ? (int) $analiz['gecen_sn'] : null,
            'sirada'        => $sirada ? KuyrukDurumu::siradaMetni((int) $analiz['gecen_sn']) : null,
            // Analiz bitmişse işçi sorulmaz (sorgu yok, uyarı yok).
            'isci_calisiyor' => $kuyruk === null || $kuyruk['calisiyor'],
            'kuyruk_takili'  => $kuyruk !== null && $kuyruk['takildi'],
        ];
    }

    /**
     * Kullanıcıya gösterilecek tek satır.
     *
     * @param array<string,mixed>|null $analiz AnalizRepository::ilerlemeBilgisi
     * @param bool $ayrinti iç hata nedeni gösterilsin mi (bkz. HataKodlari::gorunenMesaj)
     */
    public static function etiket(?array $analiz, bool $ayrinti = true): string
    {
        if ($analiz === null) {
            return 'Henüz analiz edilmedi';
        }

        if ($analiz['durum'] === 'hata') {
            return HataKodlari::gorunenMesaj($analiz['hata_kodu'] ?? null, $ayrinti);
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
