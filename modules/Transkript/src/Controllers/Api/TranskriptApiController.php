<?php
/**
 * =====================================================================
 *  TranskriptApiController – /api/v1/transkript/… (dış istemciler)
 * ---------------------------------------------------------------------
 *  "Authorization: Bearer cy_…" anahtarıyla çağrılır; kapsam ve hız
 *  sınırı starter'ın ApiGuard'ındadır ("okuma" anahtarı POST atamaz).
 *  Panel ekranlarıyla AYNI servisleri çağırır; iş mantığı iki yerde
 *  yazılmaz.
 *
 *  Yanıt zarfı: { success, data, meta } / { success:false, error:{code, message} }
 *
 *  İSKELET (2. adım): uçlar tanımlı, henüz 501 döner. Doldurulma
 *  sırası docs/TEKNIK_RAPOR.md 8. ve 12. bölümde.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Controllers\Api;

use App\Core\Api\ApiResponse;
use App\Core\Request;
use App\Http\Controller;

final class TranskriptApiController extends Controller
{
    /** POST api/v1/transkript/analiz — YouTube URL'si; cache'te varsa mevcut analiz, yoksa yeni iş (202) */
    public function analiz(Request $request): void
    {
        self::hazirDegil();
    }

    /** POST api/v1/transkript/dosya — ses / video yükleme (5b. aşama) */
    public function dosya(Request $request): void
    {
        self::hazirDegil();
    }

    /** GET api/v1/transkript/kaynaklar?tur=&durum=&q=&sayfa= */
    public function kaynaklar(Request $request): void
    {
        self::hazirDegil();
    }

    /** GET api/v1/transkript/kaynaklar/{kaynak_id} — kaynak kartı + durum + ilerleme */
    public function kaynak(Request $request, string $kaynak_id): void
    {
        self::hazirDegil();
    }

    /** GET api/v1/transkript/kaynaklar/{kaynak_id}/ozet — rapor */
    public function ozet(Request $request, string $kaynak_id): void
    {
        self::hazirDegil();
    }

    /** GET api/v1/transkript/kaynaklar/{kaynak_id}/transkript?bas=&bit= — segmentler */
    public function transkript(Request $request, string $kaynak_id): void
    {
        self::hazirDegil();
    }

    /** POST api/v1/transkript/kaynaklar/{kaynak_id}/sohbet — soru; cevap + zaman damgaları (4. aşama) */
    public function sohbet(Request $request, string $kaynak_id): void
    {
        self::hazirDegil();
    }

    /** GET api/v1/transkript/istatistik — günlük analiz, token, maliyet (5. aşama) */
    public function istatistik(Request $request): void
    {
        self::hazirDegil();
    }

    private static function hazirDegil(): never
    {
        ApiResponse::error('Bu uç henüz hazır değil.', 501, 'henuz_hazir_degil');
    }
}
