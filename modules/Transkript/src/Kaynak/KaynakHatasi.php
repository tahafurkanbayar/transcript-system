<?php
/**
 * =====================================================================
 *  KaynakHatasi – kaynak (YouTube videosu) alınamadığında
 * ---------------------------------------------------------------------
 *  $kod makine okunurdur; panel ve API aynı kodu kullanır. getMessage()
 *  TEKNİK ayrıntıdır ve yalnız log'a yazılır; kullanıcıya
 *  kullaniciMesaji() gösterilir.
 *
 *      gecersiz_url, video_bulunamadi, gizli_video, silinmis_video
 *          → kalıcı: iş yeniden denenmez
 *      metadata_hatasi
 *          → geçici (ağ, 5xx, hız sınırı): iş yeniden denenir
 *
 *  Hata kodlarının tamamı 9. adımda tek tabloda toplanacak.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Kaynak;

use RuntimeException;
use Throwable;

final class KaynakHatasi extends RuntimeException
{
    public const MESAJLAR = [
        'gecersiz_url'     => 'Bu bir YouTube video bağlantısı değil. Örnek: https://www.youtube.com/watch?v=…',
        'video_bulunamadi' => 'Bu video bulunamadı. Bağlantıyı kontrol edin; video kaldırılmış olabilir.',
        'gizli_video'      => 'Bu video gizli ya da başka sitelerde oynatılmaya kapalı; analiz edilemiyor.',
        'silinmis_video'   => 'Bu video YouTube\'dan kaldırılmış.',
        'metadata_hatasi'  => 'Video bilgisi şu an alınamadı. Biraz sonra yeniden denenecek.',
        'oynatma_listesi'  => 'Oynatma listeleri henüz desteklenmiyor; listedeki tek bir videonun bağlantısını yapıştırın.',
        'kanal_baglantisi' => 'Kanal bağlantıları desteklenmiyor; kanaldaki tek bir videonun bağlantısını yapıştırın.',
        'video_cok_uzun'   => 'Video, analiz için izin verilen en uzun süreyi aşıyor.',
        'plan_video_uzun'  => 'Video, analizi başlatan kişinin üyelik planındaki süre sınırını aşıyor.',
    ];

    public function __construct(
        public readonly string $kod,
        string $teknikAyrinti = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($teknikAyrinti !== '' ? $teknikAyrinti : $this->kullaniciMesaji(), 0, $previous);
    }

    public function kullaniciMesaji(): string
    {
        return self::MESAJLAR[$this->kod] ?? 'Kaynak alınamadı.';
    }

    public function yenidenDenenebilir(): bool
    {
        return $this->kod === 'metadata_hatasi';
    }
}
