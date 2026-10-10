<?php
/**
 * =====================================================================
 *  HataKodlari – analiz hata kodu → kullanıcıya gösterilecek Türkçe mesaj
 * ---------------------------------------------------------------------
 *  transkript_analizler.hata_kodu buradaki anahtarlardan biridir. Panel
 *  ve API aynı kodu ve aynı mesajı kullanır; teknik ayrıntı yalnızca
 *  log'dadır. (9. adımda her kod için "kalıcı mı, yeniden denenir mi"
 *  kararı ve eksik durumlar tamamlanacak.)
 *
 *  KİM NEYİ GÖRÜR (rol testi): videonun kendisinden gelen neden (altyazı
 *  yok, video gizli…) herkese söylenir. Kota, servis ve ayar hataları iç
 *  işletme bilgisidir; yalnız yönetici ve analizi başlatan görür
 *  (TranskriptPolicy::canSeeErrorDetail), ötekiler GENEL metni okur.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript;

use Modules\Transkript\Kaynak\KaynakHatasi;
use Modules\Transkript\Uyelik\Planlar;

final class HataKodlari
{
    private const MESAJLAR = [
        'canli_yayin'           => 'Canlı ya da planlanmış yayın henüz analiz edilemiyor; yayın bitince yeniden deneyin.',
        'transkript_bulunamadi' => 'Bu videoda altyazı bulunamadı.',
        'altyazi_kapali'        => 'Bu videoda altyazı kapalı.',
        'transkript_api_hatasi' => 'Altyazıya şu an erişilemedi. Biraz sonra yeniden deneyin.',
        'saglayici_yok'         => 'Bu kaynak türü için transcript sağlayıcısı açık değil.',
        'rate_limit'            => 'Servis şu an yoğun. Biraz sonra yeniden deneyin.',
        'ai_api_hatasi'         => 'Yapay zekâ servisine ulaşılamadı. Biraz sonra yeniden deneyin.',
        'ai_ayar_eksik'         => 'Yapay zekâ ayarı eksik (anahtar ya da model). Yöneticinize bildirin.',
        'ai_kota_doldu'         => 'Yapay zekâ servisinin günlük kullanım kotası doldu. Yarın yeniden deneyin ya da yöneticinize bildirin.',
        'ai_yanit_gecersiz'     => 'Yapay zekâ geçerli bir rapor üretemedi. Biraz sonra yeniden deneyin.',
        'iptal_edildi'          => 'Analiz iptal edildi.',
        'rapor_hazirlanamadi'   => self::GENEL,
        'beklenmeyen_hata'      => 'Beklenmeyen bir hata oluştu; ayrıntı kayda geçti.',
    ];

    /** Ayrıntı görme hakkı olmayana gösterilen metin ve kod. */
    public const GENEL     = 'Bu video için rapor hazırlanamadı.';
    public const GENEL_KOD = 'rapor_hazirlanamadi';

    /** Videonun kendisinden gelen, herkese söylenebilecek nedenler. */
    private const HERKESE_ACIK = [
        'canli_yayin', 'transkript_bulunamadi', 'altyazi_kapali', 'iptal_edildi', 'video_cok_uzun',
        'gecersiz_url', 'video_bulunamadi', 'gizli_video', 'silinmis_video', 'oynatma_listesi', 'kanal_baglantisi',
    ];

    /**
     * Geçici sebeple duran analiz: kaldığı yerden sürdürülebilir (biten
     * parçalar korunur). Videonun kendisinden gelen hata ve iptal değil.
     */
    public const DEVAM_EDILEBILIR = [
        'ai_kota_doldu', 'ai_ayar_eksik', 'ai_api_hatasi', 'ai_yanit_gecersiz', 'rate_limit',
        'transkript_api_hatasi', 'metadata_hatasi', 'beklenmeyen_hata',
    ];

    public static function devamEdilebilir(?string $kod): bool
    {
        return $kod !== null && in_array($kod, self::DEVAM_EDILEBILIR, true);
    }

    public static function herkeseAcik(?string $kod): bool
    {
        return $kod !== null && in_array($kod, self::HERKESE_ACIK, true);
    }

    /** Yöneticiye: "yöneticinize bildirin" demek yerine nereye bakacağını söyler. */
    private const YONETICI_MESAJLARI = [
        'ai_ayar_eksik' => 'Yapay zekâ ayarı eksik ya da geçersiz (anahtar ya da model). Sağlayıcıların durumu: Transkript ayarları → Durum.',
        'ai_kota_doldu' => 'Yapay zekâ servislerinin kotası doldu ve iş erteleme süresi içinde tamamlanamadı. "Bekleyenleri yeniden dene" ile sürdürebilirsiniz.',
    ];

    /**
     * $ayrinti yoksa iç hata (kota, servis, ayar) GENEL metne döner.
     * $yonetici ise "yöneticinize bildirin" yerine yöneticiye uygun metin.
     */
    public static function gorunenMesaj(?string $kod, bool $ayrinti, bool $yonetici = false): string
    {
        if ($kod === null || $kod === '') {
            return '';
        }

        if ($yonetici && isset(self::YONETICI_MESAJLARI[$kod])) {
            return self::YONETICI_MESAJLARI[$kod];
        }

        return $ayrinti || self::herkeseAcik($kod) ? self::mesaj($kod) : self::GENEL;
    }

    /** API ve ilerleme JSON'u için: iç hata kodu da sızmasın. */
    public static function gorunenKod(?string $kod, bool $ayrinti): ?string
    {
        if ($kod === null || $kod === '') {
            return null;
        }

        return $ayrinti || self::herkeseAcik($kod) ? $kod : self::GENEL_KOD;
    }

    public static function mesaj(?string $kod): string
    {
        if ($kod === null || $kod === '') {
            return '';
        }

        if ($kod === 'video_cok_uzun' && ($dakika = (int) Ayarlar::get('analiz.en_uzun_dk', 180)) > 0) {
            return sprintf('Bu video çok uzun; en çok %d dakikalık videolar analiz edilebiliyor.', $dakika);
        }

        // Planın süre sınırı: kişiye özeldir (herkese açık değil), Pro'da analiz edilebilir.
        if ($kod === 'plan_video_uzun') {
            $sistem = (int) Ayarlar::get('analiz.en_uzun_dk', 180);
            $sinir  = static fn (string $plan): string => Planlar::sureMetni(Planlar::etkinEnUzunDk(Planlar::tanim($plan)['en_uzun_dk'], $sistem));

            return sprintf('Bu video üyelik planınızın süre sınırını aşıyor (Ücretsiz: en çok %s, Pro: %s). Üyeliğim sayfasından Pro\'ya geçebilirsiniz.', $sinir(Planlar::UCRETSIZ), $sinir(Planlar::PRO));
        }

        return KaynakHatasi::MESAJLAR[$kod] ?? self::MESAJLAR[$kod] ?? self::MESAJLAR['beklenmeyen_hata'];
    }
}
