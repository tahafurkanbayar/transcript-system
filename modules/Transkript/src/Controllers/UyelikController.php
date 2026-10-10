<?php
/**
 * =====================================================================
 *  UyelikController – Üyeliğim (herkes) ve Üyelikler (yönetici)
 * ---------------------------------------------------------------------
 *  Kurallar Uyelik\UyelikYonetimi'nde; burada istek → işlem → Flash +
 *  yönlendirme. Formlar sıradan POST'tur (AJAX yok); sonuç sayfanın
 *  üstünde bildirim olarak görünür.
 *
 *    GET  panel/transkript/uyelik                  Üyeliğim: plan, bugünkü kullanım, Pro'ya geç
 *    POST panel/transkript/uyelik/talep            "Pro'ya geç" talebi
 *    POST panel/transkript/uyelik/talebi-geri-cek  bekleyen talebi geri çek
 *    GET  panel/transkript/uyelikler               yönetici: talepler, Pro üyeler, ödeme geçmişi
 *    POST panel/transkript/uyelikler/ver           Pro ver / uzat / talebi onayla
 *    POST panel/transkript/uyelikler/kaldir        Pro'yu kaldır (rol → üye)
 *    POST panel/transkript/uyelikler/reddet        talebi gerekçesiyle reddet
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Controllers;

use App\Core\Auth;
use App\Core\Demo;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Http\Controller;
use App\Repositories\UserRepository;
use Modules\Transkript\Ayarlar;
use Modules\Transkript\Repositories\UyelikRepository;
use Modules\Transkript\Services\AnalizBaslatici;
use Modules\Transkript\TranskriptPolicy;
use Modules\Transkript\Uyelik\Planlar;
use Modules\Transkript\Uyelik\UyelikHatasi;
use Modules\Transkript\Uyelik\UyelikYonetimi;

final class UyelikController extends Controller
{
    /** GET panel/transkript/uyelik */
    public function benim(Request $request): void
    {
        $kullanici = Auth::user();
        $repo      = new UyelikRepository($this->db);

        $this->view('Transkript::uyelik', [
            'title'         => 'Üyeliğim',
            'subtitle'      => 'Planınız, bugünkü kullanımınız ve Pro üyelik.',
            'policy'        => new TranskriptPolicy($kullanici),
            'haklar'        => Planlar::haklar($kullanici),
            'hak'           => (new AnalizBaslatici($this->db, $kullanici))->hakDurumu(),
            'ozet'          => $repo->ozet((int) $kullanici?->id),
            'talep'         => $repo->sonTalep((int) $kullanici?->id),
            'donemler'      => $repo->sonDonemler(10, (int) $kullanici?->id),
            'karsilastirma' => Planlar::karsilastirma(),
            'odemeBilgisi'  => trim((string) Ayarlar::get('uyelik.odeme_bilgisi', '')),
        ]);
    }

    /** POST panel/transkript/uyelik/talep */
    public function talep(Request $request): void
    {
        $this->demoKilidi('panel/transkript/uyelik');

        try {
            (new UyelikYonetimi($this->db))->talepOlustur(Auth::user(), (int) $request->input('sure_ay'), $request->input('mesaj'));
            Flash::success('Talebiniz alındı. Ödeme talimatı aşağıda; yönetici ödemeyi görünce Pro üyeliğiniz açılır ve e-posta alırsınız.');
        } catch (UyelikHatasi $e) {
            Flash::error($e->getMessage());
        }

        Response::redirect(url('panel/transkript/uyelik'));
    }

    /** POST panel/transkript/uyelik/talebi-geri-cek */
    public function talebiGeriCek(Request $request): void
    {
        $this->demoKilidi('panel/transkript/uyelik');

        (new UyelikYonetimi($this->db))->talepIptal(Auth::user())
            ? Flash::success('Talebiniz geri çekildi.')
            : Flash::error('Bekleyen bir talebiniz yok.');

        Response::redirect(url('panel/transkript/uyelik'));
    }

    /** GET panel/transkript/uyelikler */
    public function yonetim(Request $request): void
    {
        // Sayfa açılırken süresi dolanlar işlenir: liste her zaman güncel.
        (new UyelikYonetimi($this->db))->suresiDolanlariIsle();

        $repo = new UyelikRepository($this->db);

        $this->view('Transkript::uyelikler', [
            'title'         => 'Üyelikler',
            'subtitle'      => 'Pro üyelik talepleri, Pro üyeler ve ödeme geçmişi.',
            'talepler'      => $repo->bekleyenTalepler(),
            'proUyeler'     => $repo->proUyeler(),
            'donemler'      => $repo->sonDonemler(30),
            'gelir'         => $repo->gelir(),
            'karsilastirma' => Planlar::karsilastirma(),
            'onKullanici'   => mb_substr($request->input('kullanici'), 0, 190),
        ]);
    }

    /** POST panel/transkript/uyelikler/ver — talep_id varsa talebi onaylar. */
    public function ver(Request $request): void
    {
        $this->demoKilidi('panel/transkript/uyelikler');

        $yonetim = new UyelikYonetimi($this->db);
        $talepId = $request->int('talep_id', 1);
        $ay      = $request->int('ay', 0, UyelikYonetimi::EN_COK_AY);
        $tutar   = self::tutar($request->input('tutar'));
        $not     = $request->input('odeme_notu');

        try {
            if ($tutar === false) {
                throw new UyelikHatasi('Tutarı sayı olarak yazın (ör. 149 ya da 149,90) ya da boş bırakın.');
            }

            if ($talepId !== null) {
                $sonuc = $yonetim->talepOnayla($talepId, $ay, $tutar, $not, Auth::id());
            } else {
                if ($ay === null) {
                    throw new UyelikHatasi(sprintf('Süre seçin (1 ile %d ay; süresiz için 0).', UyelikYonetimi::EN_COK_AY));
                }

                $sonuc = $yonetim->proVer($this->kullaniciKimligi($request), $ay, $tutar, $not, Auth::id());
            }

            Flash::success(($sonuc['yukseltildi'] ? 'Pro üyelik açıldı' : 'Pro üyelik uzatıldı')
                . ($sonuc['bitis'] !== null ? '; bitiş ' . date('d.m.Y', (int) strtotime($sonuc['bitis'])) . '.' : ' (süresiz).')
                . self::epostaNotu($sonuc['eposta']));
        } catch (UyelikHatasi $e) {
            Flash::error($e->getMessage());
        }

        Response::redirect(url('panel/transkript/uyelikler'));
    }

    /** POST panel/transkript/uyelikler/kaldir */
    public function kaldir(Request $request): void
    {
        $this->demoKilidi('panel/transkript/uyelikler');

        try {
            $eposta = (new UyelikYonetimi($this->db))->kaldir((int) $request->int('kullanici_id', 1), Auth::id());
            Flash::success('Pro üyelik kaldırıldı; kullanıcı Ücretsiz plana döndü ve API anahtarları kapatıldı.' . self::epostaNotu($eposta));
        } catch (UyelikHatasi $e) {
            Flash::error($e->getMessage());
        }

        Response::redirect(url('panel/transkript/uyelikler'));
    }

    /** POST panel/transkript/uyelikler/reddet */
    public function reddet(Request $request): void
    {
        $this->demoKilidi('panel/transkript/uyelikler');

        try {
            $eposta = (new UyelikYonetimi($this->db))->talepReddet((int) $request->int('talep_id', 1), $request->input('yanit'), Auth::id());
            Flash::success('Talep reddedildi; üye notunuzu Üyeliğim sayfasında görür.' . self::epostaNotu($eposta));
        } catch (UyelikHatasi $e) {
            Flash::error($e->getMessage());
        }

        Response::redirect(url('panel/transkript/uyelikler'));
    }

    /**
     * "149", "149,90", "1.490,50", "1490.5" → float; boş → null; geçersiz → false.
     */
    public static function tutar(string $ham): float|null|false
    {
        $ham = str_replace([' ', '₺', 'TL'], '', trim($ham));

        if ($ham === '') {
            return null;
        }

        if (str_contains($ham, ',')) {
            $ham = str_replace(['.', ','], ['', '.'], $ham);
        }

        return is_numeric($ham) && (float) $ham >= 0 ? round((float) $ham, 2) : false;
    }

    /** Bildirim sonucu: e-posta ayarı yoksa yönetici bunu bilsin (gönderildi demeyelim). */
    private static function epostaNotu(bool $gitti): string
    {
        return $gitti
            ? ' Kullanıcıya e-posta gönderildi.'
            : ' E-posta gönderilemedi (Ayarlar → E-posta ayarlarını kontrol edin); kullanıcı durumu panelde görür.';
    }

    /** Formdaki kullanıcı: gizli kullanici_id ya da yazılan kullanıcı adı / e-posta. */
    private function kullaniciKimligi(Request $request): int
    {
        if (($id = $request->int('kullanici_id', 1)) !== null) {
            return $id;
        }

        $kimlik = trim($request->input('kullanici'));

        if ($kimlik === '') {
            throw new UyelikHatasi('Kullanıcı adını ya da e-posta adresini yazın.');
        }

        // Sütunlar utf8mb4_unicode_ci: büyük / küçük harf fark etmez.
        $kullanici = (new UserRepository($this->db))->findForLogin($kimlik)
            ?? throw new UyelikHatasi('Bu kullanıcı adı ya da e-postayla bir hesap bulunamadı.', 404);

        return $kullanici->id;
    }

    private function demoKilidi(string $geri): void
    {
        if (Demo::enabled() && Demo::isDemoUser(Auth::user())) {
            Flash::error('Demo hesabında üyelik işlemleri kilitli.');
            Response::redirect(url($geri));
        }
    }
}
