<?php
/**
 * =====================================================================
 *  KaynakController – Transkript modülünün panel ekranları
 * ---------------------------------------------------------------------
 *  Liste + yeni analiz (panel/transkript), rapor sayfası
 *  (panel/transkript/{kaynak_id}), analiz başlatma ve ilerleme JSON'u.
 *
 *  İSKELET (2. adım): rotalar ve yetkiler yerinde; ekranların içi
 *  8. adımda, analiz başlatma 4. ve 6. adımlarda dolar. Hazır olmayan
 *  AJAX uçları 501 döner.
 *
 *  HTTP isteğinde uzun iş yapılmaz: analiz yalnızca kayıt açar ve
 *  ilk işi kuyruğa bırakır (bkz. docs/TEKNIK_RAPOR.md, 7. bölüm).
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Controllers;

use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Http\Controller;

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

    /** POST panel/transkript/analiz — panel AJAX: {success, type, description} */
    public function analiz(Request $request): void
    {
        Response::error('Analiz başlatma henüz hazır değil.', 501);
    }

    /** GET panel/transkript/ilerleme/{kaynak_id} — 3 saniyede bir çekilen küçük JSON */
    public function ilerleme(Request $request, string $kaynak_id): void
    {
        self::kimlik($kaynak_id);

        Response::error('İlerleme bilgisi henüz hazır değil.', 501);
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
