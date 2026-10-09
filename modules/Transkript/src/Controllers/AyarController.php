<?php
/**
 * =====================================================================
 *  AyarController – panel/transkript/ayarlar (yalnız transkript.admin)
 * ---------------------------------------------------------------------
 *  Yapay zekâ modeli, altyazı tercihi, süre sınırı, kotalar ve maliyet
 *  tahmini fiyatı (PanelAyarlari). Anahtarların değeri hiçbir zaman
 *  gösterilmez; yalnız "tanımlı / tanımsız".
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Controllers;

use App\Core\Auth;
use App\Core\Demo;
use App\Core\Env;
use App\Core\Flash;
use App\Core\Log\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Http\Controller;
use Modules\Transkript\Ai\Ai;
use Modules\Transkript\Ayarlar;
use Modules\Transkript\PanelAyarlari;
use Modules\Transkript\Services\KuyrukDurumu;

final class AyarController extends Controller
{
    /** GET panel/transkript/ayarlar */
    public function goster(Request $request): void
    {
        $this->sayfa([], []);
    }

    /** POST panel/transkript/ayarlar — hata varsa form girilen değerlerle yeniden çizilir. */
    public function kaydet(Request $request): void
    {
        if (Demo::enabled() && Demo::isDemoUser(Auth::user())) {
            Flash::error('Demo hesabında ayarlar kilitli.');
            Response::redirect(url('panel/transkript/ayarlar'));
        }

        $girdi = [];

        foreach (array_keys(PanelAyarlari::ALANLAR) as $alan) {
            $girdi[$alan] = mb_substr($request->input($alan), 0, 100);
        }

        $hatalar = PanelAyarlari::kaydet($this->db, $girdi);

        if ($hatalar !== []) {
            http_response_code(422);
            $this->sayfa($girdi, $hatalar);

            return;
        }

        Logger::info('Transkript ayarları kaydedildi', ['kullanici' => Auth::id(), 'dolu' => array_keys(array_filter($girdi, static fn (string $d): bool => $d !== ''))], 'transkript');
        Flash::success('Transkript ayarları kaydedildi. Yeni analizlere uygulanır.');
        Response::redirect(url('panel/transkript/ayarlar'));
    }

    /**
     * @param array<string,string> $girdi   hata sonrası kullanıcının yazdığı
     * @param array<string,string> $hatalar alan => Türkçe hata
     */
    private function sayfa(array $girdi, array $hatalar): void
    {
        $degerler = [];
        $varsayilanlar = [];

        foreach (PanelAyarlari::ALANLAR as $alan => $tanim) {
            $degerler[$alan]      = array_key_exists($alan, $girdi) ? $girdi[$alan] : (PanelAyarlari::ham($alan) ?? '');
            $varsayilanlar[$alan] = self::varsayilanMetni($alan, Ayarlar::varsayilan($tanim['yol'][0]));
        }

        $this->view('Transkript::ayarlar', [
            'title'         => 'Transkript ayarları',
            'subtitle'      => 'Yapay zekâ modeli, altyazı tercihi, süre sınırı, kotalar, maliyet tahmini ve ana sayfa.',
            'degerler'      => $degerler,
            'varsayilanlar' => $varsayilanlar,
            'hatalar'       => $hatalar,
            'durum'         => [
                'saglayici'   => Ai::label(),
                'ai_anahtar'  => Ai::isConfigured(),
                'yt_anahtar'  => trim(Env::get('YOUTUBE_API_KEY')) !== '',
                'transcript'  => implode(' → ', (array) Ayarlar::get('transcript.saglayicilar', [])),
                'istem'       => (string) Ayarlar::get('ai.istem_surumu', 'v1'),
                'kuyruk'      => (new KuyrukDurumu($this->db))->getir(),
            ],
        ]);
    }

    private static function varsayilanMetni(string $alan, mixed $deger): string
    {
        return match ($alan) {
            'ai_model'     => (string) ($deger ?: Ai::model()),
            'altyazi_dili' => $deger === [] ? 'videonun kendi dili' : 'Türkçe altyazı varsa onu',
            'ana_sayfa'    => $deger === false ? 'genel vitrin' : 'Transkript tanıtım sayfası',
            'fiyat_giris', 'fiyat_cikis' => (float) $deger > 0 ? '$' . number_format((float) $deger, 2, ',', '.') : 'sağlayıcının fiyat tablosu',
            default        => (string) ((int) $deger === 0 && (float) $deger === 0.0 ? '0 (kapalı)' : str_replace('.', ',', (string) $deger)),
        };
    }
}
