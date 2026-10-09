<?php
/**
 * =====================================================================
 *  HesapTemizligi – silinen hesapların Transkript'teki kişisel kayıtları
 * ---------------------------------------------------------------------
 *  Çekirdek bir hesabı silince (yönetici ya da hesap silme isteğinin
 *  7 günlük süresi dolunca) modül haberdar olmaz: kişiye ait satırlar
 *  ("kullanici_id" yabancı anahtarsızdır) yerinde kalıyordu. Günlük
 *  zamanlanmış görev (events.php) sahibi artık olmayan satırları siler:
 *
 *    transkript_kutuphane         kütüphane
 *    transkript_sohbetler (+mesajları)  rapor sohbetleri
 *    transkript_ilgi_alanlari     ilgi alanları
 *    transkript_kisisel_ozetler   kişisel özetler
 *
 *  Ortak video raporu (kaynak, transcript, analiz) kalır; başlatanın
 *  kimliği silinmiş bir hesabı gösterir, kişisel veri taşımaz.
 *  Aydınlatma metni bu davranışı anlatır (cowork 6. tur K9).
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Services;

use PDO;

final class HesapTemizligi
{
    /** Kişiye ait tablolar (sohbet mesajları sohbetle birlikte gider). */
    public const TABLOLAR = ['transkript_kutuphane', 'transkript_sohbetler', 'transkript_ilgi_alanlari', 'transkript_kisisel_ozetler'];

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Sahibi olmayan satırları siler.
     *
     * @return array<string,int> tablo => silinen satır
     */
    public function temizle(): array
    {
        $silinen = [];

        $silinen['transkript_sohbet_mesajlari'] = (int) $this->db->exec(
            'DELETE m FROM transkript_sohbet_mesajlari m
               JOIN transkript_sohbetler s ON s.id = m.sohbet_id
               LEFT JOIN kullanicilar u ON u.id = s.kullanici_id
              WHERE u.id IS NULL'
        );

        foreach (self::TABLOLAR as $tablo) {
            $silinen[$tablo] = (int) $this->db->exec(
                "DELETE t FROM `$tablo` t LEFT JOIN kullanicilar u ON u.id = t.kullanici_id WHERE u.id IS NULL"
            );
        }

        return $silinen;
    }
}
