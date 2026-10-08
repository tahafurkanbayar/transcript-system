<?php
/**
 * =====================================================================
 *  SohbetController – "Kayda sor" (panel)
 * ---------------------------------------------------------------------
 *  MVP sonrası (13. bölüm, 4. aşama): soru gelince segmentlerde ve
 *  analiz maddelerinde FULLTEXT arama yapılır, en ilgili bölümler
 *  zaman damgalarıyla modele gider. Şimdilik 501 döner.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Http\Controller;

final class SohbetController extends Controller
{
    /** POST panel/transkript/{kaynak_id}/sohbet */
    public function sor(Request $request, string $kaynak_id): void
    {
        Response::error('Kayda soru sorma henüz hazır değil.', 501);
    }
}
