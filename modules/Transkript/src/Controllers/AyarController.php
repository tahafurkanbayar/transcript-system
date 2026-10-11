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
use App\Core\Setting;
use App\Http\Controller;
use Modules\Transkript\Ai\Ai;
use Modules\Transkript\Ai\EvrenHesap;
use Modules\Transkript\Ai\EvrenProvider;
use Modules\Transkript\Ai\YedekliSaglayici;
use Modules\Transkript\Ayarlar;
use Modules\Transkript\PanelAyarlari;
use Modules\Transkript\Repositories\AiKullanimRepository;
use Modules\Transkript\Services\KuyrukDurumu;
use PDO;

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
            $girdi[$alan] = match (true) {
                isset($tanim['saglayici']) && $request->raw($alan) === null => PanelAyarlari::ham($alan) ?? '',
                // Çok satırlı metin: sınırın bir fazlası alınır ki uzunluk hatası görünsün.
                $tanim['tur'] === 'metin' => mb_substr(str_replace("\r\n", "\n", $request->input($alan)), 0, (int) ($tanim['en_cok_karakter'] ?? 1000) + 1),
                default => mb_substr($request->input($alan), 0, 100),
            };
        }

        $hatalar = PanelAyarlari::kaydet($this->db, $girdi);

        if ($hatalar !== []) {
            http_response_code(422);
            $this->sayfa($girdi, $hatalar);

            return;
        }

        Logger::info('Transkript ayarları kaydedildi', ['kullanici' => Auth::id(), 'dolu' => array_keys(array_filter($girdi, static fn (string $d): bool => $d !== ''))], 'transkript');
        Flash::success('Transkript ayarları kaydedildi. Yeni analizlere uygulanır.');

        foreach (PanelAyarlari::uyarilar($girdi) as $uyari) {
            Flash::warning($uyari);
        }

        // Kayıttan sonra aynı sekme açılsın (views/ayarlar.php; JS gönderir).
        $sekme = $request->input('sekme');
        Response::redirect(url('panel/transkript/ayarlar') . (in_array($sekme, ['uyelik', 'yapay-zeka', 'butce', 'site'], true) ? '#' . $sekme : ''));
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
            'subtitle'      => 'Üyelik planları ve sınırlar, yapay zekâ modeli, bütçe, ana sayfa ve sistem durumu.',
            'degerler'      => $degerler,
            'varsayilanlar' => $varsayilanlar,
            'hatalar'       => $hatalar,
            'cagrilar'      => $kullanim->sonCagrilar(50),
            'durum'         => [
                'saglayici'   => Ai::label(),
                'zincir'      => self::zincirTablosu(Ai::zincirDurumu(), $kullanim->saglayiciOzeti()),
                'ai_anahtar'  => Ai::isConfigured(),
                // Son EVREN yanıtının bildirdiği kalan günlük token (API'ye gidilmez).
                'evren_kota'  => EvrenProvider::sonKota(),
                // Panelden verilen şart onayı (sürüm, tarih); yoksa null.
                'evren_sart'  => self::sartOnayi(),
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
        $zincir     = Ai::zincir();
        $sifirlanan = YedekliSaglayici::hepsiniSifirla($zincir, $request->bool('kota_dahil'));
        $korunan    = array_values(array_diff($zincir, $sifirlanan));

        Logger::info('Yapay zekâ dinlenmeleri sıfırlandı', ['kullanici' => Auth::id(), 'korunan' => $korunan], 'transkript');
        Flash::success($korunan === []
            ? 'Dinlenmeler sıfırlandı; bütün sağlayıcılar bir sonraki çağrıda yeniden denenecek.'
            : 'Dinlenmeler sıfırlandı. Günlük kotası dolan ' . implode(', ', array_map([Ai::class, 'etiket'], $korunan))
                . ' açılış saatine kadar dinlenmeye devam ediyor (sıfırlamak için kutuyu işaretleyin).');
        // Düğme Durum sekmesinde: sayfa oraya dönsün (cowork testi 2026-10-11).
        Response::redirect(url('panel/transkript/ayarlar') . '#durum');
    }

    /* =================================================================
     *  EVREN KULLANIM ŞARTLARI
     * =================================================================
     *  Şartlar anahtar başınadır ve onaylanmadan her çağrı 403 alır.
     *  Onay YALNIZ burada, yöneticinin metni okuyup "Okudum, onaylıyorum"
     *  kutusunu işaretleyip göndermesiyle verilir (kullanıcı kararı,
     *  2026-10-11). Kod kendiliğinden ya da arka planda onaylamaz.
     *  Sayfa EVREN'e iki kısa istek atar (durum + metin); yöneticinin
     *  açıkça istediği tek seferlik bir işlemdir.
     * ============================================================== */

    /** GET panel/transkript/ayarlar/evren-sartlar — şart durumu ve metni. */
    public function evrenSartlar(Request $request): void
    {
        $hesap = EvrenHesap::ortamdan();

        if ($hesap === null) {
            Flash::error('.env içinde EVREN_API_KEY tanımlı değil.');
            Response::redirect(url('panel/transkript/ayarlar') . '#durum');
        }

        $durumYaniti = $hesap->sartDurumu();
        $bilgi       = EvrenHesap::sartBilgisi($durumYaniti->json());
        $metin       = null;

        if ($bilgi['onayli'] === true) {
            // Başka yerden (EVREN Test Alanı) onaylanmış: kaydı eşitle, EVREN yeniden denensin.
            self::sartOnayiniYaz($this->db, (int) ($bilgi['guncel_surum'] ?? $bilgi['onaylanan_surum'] ?? 0), false);
        }

        // Metin onaydan sonra da okunabilsin (onaylanan metin).
        if ($durumYaniti->basarili()) {
            $metinYaniti = $hesap->sartMetni();
            $metin       = $metinYaniti->basarili() ? self::sartMetniOku($metinYaniti->govde, $metinYaniti->json()) : null;
        }

        $this->view('Transkript::evren-sartlar', [
            'title'    => 'EVREN kullanım şartları',
            'subtitle' => 'EVREN LLM API, şartlar onaylanmadan istek kabul etmez. Şartlar anahtar başınadır.',
            'bilgi'    => $bilgi,
            'erisim'   => $durumYaniti->basarili(),
            'hata'     => $durumYaniti->basarili() ? null : $hesap->maskele(mb_strimwidth($durumYaniti->hata !== '' ? $durumYaniti->hata : $durumYaniti->govde, 0, 300, '…')),
            'metin'    => $metin,
            'kayit'    => self::sartOnayi(),
        ]);
    }

    /** POST panel/transkript/ayarlar/evren-sartlar — yöneticinin onayı. */
    public function evrenSartOnayla(Request $request): void
    {
        $geri  = url('panel/transkript/ayarlar/evren-sartlar');
        $hesap = EvrenHesap::ortamdan();

        if (Demo::enabled() && Demo::isDemoUser(Auth::user())) {
            Flash::error('Demo hesabında ayarlar kilitli.');
            Response::redirect($geri);
        }

        if ($hesap === null) {
            Flash::error('.env içinde EVREN_API_KEY tanımlı değil.');
            Response::redirect(url('panel/transkript/ayarlar') . '#durum');
        }

        if (!$request->bool('okudum')) {
            Flash::error('Onaylamak için "Okudum, onaylıyorum" kutusunu işaretleyin.');
            Response::redirect($geri);
        }

        // Okunan sürüm hâlâ güncel mi? (Metin sayfa açıkken değiştiyse yeniden okunsun.)
        $surum = (int) $request->input('surum');
        $guncel = EvrenHesap::sartBilgisi($hesap->sartDurumu()->json())['guncel_surum'];

        if ($surum < 1 || $guncel === null || $surum !== $guncel) {
            Flash::error('Şartların sürümü değişmiş ya da okunamadı; metni yeniden okuyup onaylayın.');
            Response::redirect($geri);
        }

        $yanit = $hesap->sartlariOnayla($surum);

        if (!$yanit->basarili()) {
            Logger::warning('EVREN şart onayı reddedildi', ['kullanici' => Auth::id(), 'durum' => $yanit->durum, 'ayrinti' => $hesap->maskele(mb_substr($yanit->govde, 0, 300))], 'transkript');
            Flash::error('EVREN onayı kabul etmedi (HTTP ' . $yanit->durum . '): ' . $hesap->maskele(mb_strimwidth((string) ($yanit->json()['error']['message'] ?? $yanit->hata ?: $yanit->govde), 0, 200, '…')));
            Response::redirect($geri);
        }

        self::sartOnayiniYaz($this->db, $surum, true);
        Logger::info('EVREN kullanım şartları onaylandı', ['kullanici' => Auth::id(), 'surum' => $surum], 'transkript');
        Flash::success('EVREN kullanım şartları (v' . $surum . ') onaylandı. EVREN bir sonraki yapay zekâ çağrısında kullanılacak.');
        Response::redirect(url('panel/transkript/ayarlar') . '#durum');
    }

    /** Kayıtlı onay: sürüm ve tarih (Durum sekmesi); yoksa null. @return array{surum:int, tarih:string}|null */
    public static function sartOnayi(): ?array
    {
        $kayit = json_decode((string) (Setting::all()[PanelAyarlari::ONEK . 'evren_sart'] ?? ''), true);

        return is_array($kayit) && (int) ($kayit['surum'] ?? 0) > 0
            ? ['surum' => (int) $kayit['surum'], 'tarih' => (string) ($kayit['tarih'] ?? '')]
            : null;
    }

    /** Onayı kaydeder ve EVREN'in dinlenmesini (şartlar yüzünden devre dışı) bitirir. */
    private static function sartOnayiniYaz(PDO $db, int $surum, bool $yeni): void
    {
        $eski = self::sartOnayi();

        if ($yeni || $eski === null || $eski['surum'] !== $surum) {
            Setting::set($db, PanelAyarlari::ONEK . 'evren_sart', (string) json_encode(['surum' => $surum, 'tarih' => date('Y-m-d H:i:s')]), PanelAyarlari::GRUP, false);
        }

        YedekliSaglayici::dinlenmeyiBitir('evren');
    }

    /** Şart metni: JSON gövdede text / content / markdown alanı, yoksa gövdenin kendisi (200 KB'a kadar). */
    public static function sartMetniOku(string $govde, ?array $json): string
    {
        $metin = $json !== null ? ($json['text'] ?? $json['content'] ?? $json['markdown'] ?? $json['body'] ?? null) : null;

        return mb_substr(is_string($metin) ? $metin : $govde, 0, 200000);
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

            $satir = $h + [
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
            ] + ($ozet[$h['ad']] ?? []) + ['son_basari' => null, 'son_hata' => null, 'son_hata_kodu' => null, 'son_hata_ayrinti' => null, 'son_model' => null, 'bugun_cagri' => 0, 'bugun_token' => 0];

            return $satir + [
                /* "auto": son başarılı çağrıda yanıt veren model tek satır
                 * ("auto → google/gemma-4-31b"); yoksa ayardaki model. */
                'gorunen_model' => is_string($satir['son_model']) && str_starts_with($satir['son_model'], $satir['model'] . ' → ')
                    ? $satir['son_model'] : (string) $satir['model'],
                // Son hatadan sonra başarılı çağrı olduysa hata eskidi: soluk, "(çözüldü)".
                'hata_cozuldu'  => $satir['son_hata'] !== null && $satir['son_basari'] !== null
                    && strtotime((string) $satir['son_hata']) < strtotime((string) $satir['son_basari']),
            ];
        }, $zincir);
    }

    private static function varsayilanMetni(string $alan, mixed $deger): string
    {
        if (isset(PanelAyarlari::ALANLAR[$alan]['saglayici'])) {
            return Ai::ortamModeli((string) PanelAyarlari::ALANLAR[$alan]['saglayici']);
        }

        $tur = PanelAyarlari::ALANLAR[$alan]['tur'] ?? '';

        if ($tur === 'acik_kapali') {
            return $deger ? 'açık' : 'kapalı';
        }

        if ($tur === 'metin') {
            return (string) $deger !== '' ? (string) $deger : 'boş';
        }

        return match ($alan) {
            'para_birimi'  => (string) ($deger ?: 'TL'),
            'pro_fiyat_aylik', 'pro_fiyat_yillik' => (float) $deger > 0 ? str_replace('.', ',', (string) $deger) : '0 (fiyat gösterilmez)',
            'uye_gunluk', 'pro_gunluk', 'uye_saatlik', 'pro_saatlik', 'uye_en_uzun_dk', 'pro_en_uzun_dk'
                => (int) $deger === 0 ? '0 (sınırsız)' : (string) (int) $deger,
            'altyazi_dili' => $deger === [] ? 'videonun kendi dili' : 'Türkçe altyazı varsa onu',
            'ana_sayfa'    => $deger === false ? 'genel vitrin' : 'Transkript tanıtım sayfası',
            'fiyat_giris', 'fiyat_cikis' => (float) $deger > 0 ? '$' . number_format((float) $deger, 2, ',', '.') : 'sağlayıcının fiyat tablosu',
            default        => (string) ((int) $deger === 0 && (float) $deger === 0.0 ? '0 (kapalı)' : str_replace('.', ',', (string) $deger)),
        };
    }
}
