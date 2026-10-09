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
use Modules\Transkript\Ai\YedekliSaglayici;
use Modules\Transkript\Ayarlar;
use Modules\Transkript\PanelAyarlari;
use Modules\Transkript\Repositories\AiKullanimRepository;
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

        foreach (PanelAyarlari::ALANLAR as $alan => $tanim) {
            /* Ekranda olmayan model alanı (sağlayıcı şu an zincirde değil)
             * formla gelmez; kayıtlı değeri silinmesin. */
            $girdi[$alan] = $tanim['tur'] === 'model' && $request->raw($alan) === null
                ? (PanelAyarlari::ham($alan) ?? '')
                : mb_substr($request->input($alan), 0, 100);
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

        $kullanim = new AiKullanimRepository($this->db);

        $this->view('Transkript::ayarlar', [
            'title'         => 'Transkript ayarları',
            'subtitle'      => 'Yapay zekâ modeli, altyazı tercihi, süre sınırı, kotalar, maliyet tahmini ve ana sayfa.',
            'degerler'      => $degerler,
            'varsayilanlar' => $varsayilanlar,
            'hatalar'       => $hatalar,
            'cagrilar'      => $kullanim->sonCagrilar(50),
            'durum'         => [
                'saglayici'   => Ai::label(),
                'zincir'      => self::zincirTablosu(Ai::zincirDurumu(), $kullanim->saglayiciOzeti()),
                'ai_anahtar'  => Ai::isConfigured(),
                'yt_anahtar'  => trim(Env::get('YOUTUBE_API_KEY')) !== '',
                'transcript'  => implode(' → ', (array) Ayarlar::get('transcript.saglayicilar', [])),
                'istem'       => (string) Ayarlar::get('ai.istem_surumu', 'v1'),
                'kuyruk'      => (new KuyrukDurumu($this->db))->getir(),
            ],
        ]);
    }

    /**
     * POST panel/transkript/ayarlar/dinlenme-sifirla — zincirdeki bütün
     * sağlayıcılar hemen yeniden denenir (anahtar / model düzeltildikten
     * sonra 6 saat beklenmesin).
     */
    public function dinlenmeSifirla(Request $request): void
    {
        YedekliSaglayici::hepsiniSifirla(Ai::zincir());

        Logger::info('Yapay zekâ dinlenmeleri sıfırlandı', ['kullanici' => Auth::id()], 'transkript');
        Flash::success('Dinlenmeler sıfırlandı; bütün sağlayıcılar bir sonraki çağrıda yeniden denenecek.');
        Response::redirect(url('panel/transkript/ayarlar'));
    }

    /**
     * Ayarlar ekranındaki sağlayıcı tablosu: zincirin durumu (anahtar değeri
     * yok) + dinlenme kaydı + kullanım kaydından son başarı / hata ve
     * bugünkü çağrılar.
     *
     * @param  list<array<string,mixed>>          $zincir Ai::zincirDurumu()
     * @param  array<string,array<string,mixed>> $ozet   AiKullanimRepository::saglayiciOzeti()
     * @return list<array<string,mixed>>
     */
    public static function zincirTablosu(array $zincir, array $ozet, ?int $simdi = null): array
    {
        $simdi ??= time();

        return array_map(static function (array $h) use ($ozet, $simdi): array {
            $kayit = YedekliSaglayici::dinlenmeKaydi($h['ad']);
            $kalan = YedekliSaglayici::dinlenmeKalan($h['ad'], $simdi, $h['model']);

            return $h + [
                'hal'      => match (true) {
                    !$h['tanimli']                                                          => 'atlandi',
                    $kalan > 0 && in_array($kayit['kod'] ?? '', YedekliSaglayici::DEVRE_DISI, true) => 'devre_disi',
                    $kalan > 0                                                              => 'dinleniyor',
                    default                                                                 => 'hazir',
                },
                'sebep'    => $kalan > 0 ? (YedekliSaglayici::SEBEPLER[$kayit['kod'] ?? ''] ?? 'hata') : '',
                'kod'      => $kalan > 0 ? (string) ($kayit['kod'] ?? '') : '',
                'acilis'   => $kalan > 0 ? YedekliSaglayici::saat($simdi + $kalan, $simdi) : null,
                'kalan_dk' => $kalan > 0 ? max(1, (int) ceil($kalan / 60)) : 0,
            ] + ($ozet[$h['ad']] ?? ['son_basari' => null, 'son_hata' => null, 'son_hata_kodu' => null, 'bugun_cagri' => 0, 'bugun_token' => 0]);
        }, $zincir);
    }

    private static function varsayilanMetni(string $alan, mixed $deger): string
    {
        if ((PanelAyarlari::ALANLAR[$alan]['tur'] ?? '') === 'model') {
            return Ai::ortamModeli((string) PanelAyarlari::ALANLAR[$alan]['saglayici']);
        }

        return match ($alan) {
            'ai_model'     => (string) ($deger ?: Ai::model()),
            'altyazi_dili' => $deger === [] ? 'videonun kendi dili' : 'Türkçe altyazı varsa onu',
            'ana_sayfa'    => $deger === false ? 'genel vitrin' : 'Transkript tanıtım sayfası',
            'fiyat_giris', 'fiyat_cikis' => (float) $deger > 0 ? '$' . number_format((float) $deger, 2, ',', '.') : 'sağlayıcının fiyat tablosu',
            default        => (string) ((int) $deger === 0 && (float) $deger === 0.0 ? '0 (kapalı)' : str_replace('.', ',', (string) $deger)),
        };
    }
}
