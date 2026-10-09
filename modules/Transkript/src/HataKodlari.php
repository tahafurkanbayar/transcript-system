<?php
/**
 * =====================================================================
 *  HataKodlari – analiz hata kodu → kullanıcıya gösterilecek Türkçe mesaj
 * ---------------------------------------------------------------------
 *  transkript_analizler.hata_kodu buradaki anahtarlardan biridir. Panel
 *  ve API aynı kodu ve aynı mesajı kullanır; teknik ayrıntı yalnızca
 *  log'dadır. (9. adımda her kod için "kalıcı mı, yeniden denenir mi"
 *  kararı ve eksik durumlar tamamlanacak.)
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript;

use Modules\Transkript\Kaynak\KaynakHatasi;

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
        'beklenmeyen_hata'      => 'Beklenmeyen bir hata oluştu; ayrıntı kayda geçti.',
    ];

    public static function mesaj(?string $kod): string
    {
        if ($kod === null || $kod === '') {
            return '';
        }

        if ($kod === 'video_cok_uzun' && ($dakika = (int) Ayarlar::get('analiz.en_uzun_dk', 180)) > 0) {
            return sprintf('Bu video çok uzun; en çok %d dakikalık videolar analiz edilebiliyor.', $dakika);
        }

        return KaynakHatasi::MESAJLAR[$kod] ?? self::MESAJLAR[$kod] ?? self::MESAJLAR['beklenmeyen_hata'];
    }
}
