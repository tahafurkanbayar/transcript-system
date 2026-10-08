<?php
/**
 * =====================================================================
 *  KaynakController – Transkript modülünün panel ekranları
 * ---------------------------------------------------------------------
 *  Liste + yeni analiz (panel/transkript), rapor sayfası
 *  (panel/transkript/{kaynak_id}), analiz başlatma ve ilerleme JSON'u.
 *
 *  Ekranların içi 8. adımda dolar; analiz başlatma ve ilerleme ucu
 *  6. adımda hazır.
 *
 *  HTTP isteğinde uzun iş yapılmaz: analiz yalnızca kayıt açar ve
 *  ilk işi kuyruğa bırakır (Services\AnalizBaslatici; API de aynı
 *  servisi çağırır). Kullanıcı Türkçe mesaj görür; teknik ayrıntı
 *  yalnız log'a gider.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Log\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Http\Controller;
use Modules\Transkript\HataKodlari;
use Modules\Transkript\Repositories\KaynakRepository;
use Modules\Transkript\Services\AnalizBaslatici;
use Modules\Transkript\Services\AnalizReddedildi;
use Modules\Transkript\Services\KaynakDurumu;
use Modules\Transkript\TranskriptPolicy;
use Throwable;

final class KaynakController extends Controller
{
    /** GET panel/transkript */
    public function index(Request $request): void
    {
        $this->view('Transkript::index', [
            'title'    => 'Transkript',
            'subtitle' => 'YouTube videolarından zaman damgalı transcript ve AI analizi.',
        ]);
    }

    /** GET panel/transkript/{kaynak_id} */
    public function goster(Request $request, string $kaynak_id): void
    {
        $this->view('Transkript::goster', [
            'title'    => 'Analiz raporu',
            'kaynakId' => self::kimlik($kaynak_id),
        ]);
    }

    /**
     * POST panel/transkript/analiz — url, yeniden (0/1)
     * Panel AJAX zarfı: {success, type, description} + kaynak_id, analiz_id,
     * sonuc (baslatildi / suruyor / onbellekten), durum, ilerleme, adres.
     */
    public function analiz(Request $request): void
    {
        try {
            $sonuc = (new AnalizBaslatici(Database::connection(), Auth::user()))
                ->youtube($request->input('url'), $request->bool('yeniden'));
        } catch (AnalizReddedildi $e) {
            if ($e->beklemeSn > 0 && !headers_sent()) {
                header('Retry-After: ' . $e->beklemeSn);
            }

            Response::error($e->getMessage(), $e->http, ['kod' => $e->kod]);
        } catch (Throwable $e) {
            Logger::error('Analiz başlatılamadı', ['ayrinti' => $e->getMessage(), 'kullanici' => Auth::id()], 'transkript');

            Response::error(HataKodlari::mesaj('beklenmeyen_hata'), 500, ['kod' => 'beklenmeyen_hata']);
        }

        $kaynakId = (int) $sonuc['kaynak']['id'];

        Response::success(match ($sonuc['sonuc']) {
            AnalizBaslatici::ONBELLEKTEN => 'Bu video daha önce analiz edildi.',
            AnalizBaslatici::SURUYOR     => 'Bu videonun analizi sürüyor.',
            default                      => 'Analiz başladı.',
        }, [
            'sonuc'     => $sonuc['sonuc'],
            'kaynak_id' => $kaynakId,
            'analiz_id' => (int) $sonuc['analiz']['id'],
            'durum'     => (string) $sonuc['analiz']['durum'],
            'ilerleme'  => (int) $sonuc['analiz']['ilerleme'],
            'adres'     => url('panel/transkript/' . $kaynakId),
        ]);
    }

    /**
     * GET panel/transkript/ilerleme/{kaynak_id} — 3 saniyede bir çekilen
     * küçük JSON: aşama, yüzde, biten parça, Türkçe etiket ya da hata.
     */
    public function ilerleme(Request $request, string $kaynak_id): void
    {
        $db     = Database::connection();
        $kaynak = (new KaynakRepository($db))->bul(self::kimlik($kaynak_id));

        // Görme yetkisi olmayan kayıt "yok" sayılır; varlığı da sızmaz.
        if ($kaynak === null || !(new TranskriptPolicy(Auth::user()))->canViewSource($kaynak)) {
            throw HttpException::notFound();
        }

        $durum = (new KaynakDurumu($db))->getir($kaynak);

        Response::success($durum['etiket'], $durum);
    }

    /** Rotadaki {kaynak_id} yalnızca pozitif tam sayı olabilir; değilse 404. */
    private static function kimlik(string $deger): int
    {
        if (!ctype_digit($deger) || (int) $deger < 1) {
            throw HttpException::notFound();
        }

        return (int) $deger;
    }
}
