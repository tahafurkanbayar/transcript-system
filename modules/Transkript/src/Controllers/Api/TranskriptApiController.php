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
 *  6. adımda analiz ve kaynaklar/{id} hazır; diğer uçlar 501 döner,
 *  doldurulma sırası docs/TEKNIK_RAPOR.md 8. ve 12. bölümde.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Controllers\Api;

use App\Core\Api\ApiResponse;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Log\Logger;
use App\Core\Request;
use App\Http\Controller;
use Modules\Transkript\HataKodlari;
use Modules\Transkript\Repositories\KaynakRepository;
use Modules\Transkript\Services\AnalizBaslatici;
use Modules\Transkript\Services\AnalizReddedildi;
use Modules\Transkript\Services\KaynakDurumu;
use Modules\Transkript\TranskriptPolicy;
use Throwable;

final class TranskriptApiController extends Controller
{
    /**
     * POST api/v1/transkript/analiz — url, yeniden (0/1)
     *   yeni iş ya da sürmekte olan analiz → 202
     *   güncel rapor önbellekte            → 200, meta.onbellekten = true
     */
    public function analiz(Request $request): void
    {
        $db = Database::connection();

        try {
            $sonuc = (new AnalizBaslatici($db, Auth::user()))->youtube($request->input('url'), $request->bool('yeniden'));
        } catch (AnalizReddedildi $e) {
            if ($e->beklemeSn > 0 && !headers_sent()) {
                header('Retry-After: ' . $e->beklemeSn);
            }

            ApiResponse::error($e->getMessage(), $e->http, $e->kod);
        } catch (Throwable $e) {
            Logger::error('API: analiz başlatılamadı', ['ayrinti' => $e->getMessage(), 'kullanici' => Auth::id()], 'transkript');

            ApiResponse::error(HataKodlari::mesaj('beklenmeyen_hata'), 500, 'beklenmeyen_hata');
        }

        $onbellekten = $sonuc['sonuc'] === AnalizBaslatici::ONBELLEKTEN;

        ApiResponse::success(
            (new KaynakDurumu($db))->getir($sonuc['kaynak']),
            ['onbellekten' => $onbellekten, 'sonuc' => $sonuc['sonuc']],
            $onbellekten ? 200 : 202,
        );
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
        $db     = Database::connection();
        $kaynak = ctype_digit($kaynak_id) ? (new KaynakRepository($db))->bul((int) $kaynak_id) : null;

        // Görme yetkisi olmayan kayıt "yok" sayılır.
        if ($kaynak === null || !(new TranskriptPolicy(Auth::user()))->canViewSource($kaynak)) {
            ApiResponse::notFound('Kaynak bulunamadı.');
        }

        ApiResponse::success((new KaynakDurumu($db))->getir($kaynak));
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
