<?php
/**
 * =====================================================================
 *  TranskriptController – Transkript modülünün panel ekranı
 * ---------------------------------------------------------------------
 *  Görünüm "Transkript::index" biçiminde çağrılır; bu, modülün kendi
 *  views/ klasörüne işaret eder (çekirdeğin views/ klasörü kirlenmez).
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Controllers;

use App\Core\Database;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Http\Controller;
use Modules\Transkript\Repositories\TranskriptRepository;

final class TranskriptController extends Controller
{
    private TranskriptRepository $kayitlar;

    public function __construct()
    {
        parent::__construct();

        $this->kayitlar = new TranskriptRepository(Database::connection());
    }

    public function index(Request $request): void
    {
        $this->view('Transkript::index', [
            'title'    => 'Transkript',
            'subtitle' => 'Transkript modülünün örnek listeleme ekranı.',
            'kayitlar' => $this->kayitlar->all(),
            'errors'   => Flash::errors(),
            'old'      => Flash::old(),
        ]);
    }

    public function store(Request $request): void
    {
        $validator = new Validator($_POST);
        $validator->text('baslik', 'Başlık', 2, 150, true);

        if ($validator->fails()) {
            Flash::withInput($validator->errors(), $_POST);
            Response::redirect(url('panel/transkript'));
        }

        $this->kayitlar->create((string) $validator->validated()['baslik']);

        Flash::success('Kayıt eklendi.');
        Response::redirect(url('panel/transkript'));
    }

    /** Rotadaki {id} bu metoda argüman olarak gelir. */
    public function destroy(Request $request, string $id): void
    {
        $this->kayitlar->delete((int) $id);

        Flash::success('Kayıt silindi.');
        Response::redirect(url('panel/transkript'));
    }
}
