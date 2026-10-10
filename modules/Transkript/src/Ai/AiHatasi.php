<?php
/**
 * =====================================================================
 *  AiHatasi – AI çağrısının neden başarısız olduğunu söyleyen istisna
 * ---------------------------------------------------------------------
 *  Kaynak repoda (ai-integration-system) sağlayıcılar düz
 *  RuntimeException fırlatıyordu; mesaj doğrudan sohbet ekranına
 *  basılıyordu. Burada çağrılar kuyruk işlerinde çalışır ve iş şunu
 *  bilmek zorundadır: "bu hata yeniden denenince geçer mi?"
 *
 *      rate_limit, sunucu, baglanti, zaman_asimi  → geçici: yeniden dene
 *      yetkisiz, anahtar_yok, model_bulunamadi,
 *      istek_hatasi, cok_buyuk, yanit_cozulemedi,
 *      ayar_eksik, sartlar_onaylanmadi (EVREN 403),
 *      dusunme_butcesi (EVREN: yanıt boş, bütçe
 *      düşünmeye gitti)                           → kalıcı: yeniden deneme
 *
 *  getMessage() TEKNİK ayrıntıdır (sağlayıcının kendi mesajı dahil);
 *  yalnızca log'a yazılır. Kullanıcıya gösterilecek Türkçe metin
 *  hata koduna göre ayrıca seçilir (9. adım).
 *
 *  $bekleme: sağlayıcının "şu kadar bekle" dediği saniye (Retry-After,
 *  Gemini retryDelay). Yedek zinciri dinlenme süresini buna göre
 *  kurar. "zincir_dinleniyor" (bütün sağlayıcılar dinlenmede) hata
 *  değildir: iş en erken açılışa kadar ertelenir (TranskriptIsi).
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Ai;

use RuntimeException;
use Throwable;

final class AiHatasi extends RuntimeException
{
    /** Yeniden denendiğinde geçebilecek hatalar. */
    private const GECICI = ['rate_limit', 'sunucu', 'baglanti', 'zaman_asimi'];

    public function __construct(
        string $message,
        public readonly string $kod,
        public readonly int $httpDurumu = 0,
        ?Throwable $previous = null,
        public readonly ?int $bekleme = null,
    ) {
        parent::__construct($message, $httpDurumu, $previous);
    }

    public function yenidenDenenebilir(): bool
    {
        return in_array($this->kod, self::GECICI, true);
    }

    /** HTTP durumundan hata kodu (sağlayıcı özel durumları kendisi ezer). */
    public static function kodFor(int $status): string
    {
        return match (true) {
            $status === 401, $status === 403 => 'yetkisiz',
            $status === 404                  => 'model_bulunamadi',
            $status === 413                  => 'cok_buyuk',
            $status === 429                  => 'rate_limit',
            $status === 529, $status >= 500  => 'sunucu',
            default                          => 'istek_hatasi',
        };
    }
}
