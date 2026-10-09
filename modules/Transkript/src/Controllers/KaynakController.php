<?php
/**
 * =====================================================================
 *  KaynakController – Transkript modülünün panel ekranları
 * ---------------------------------------------------------------------
 *  Liste + yeni analiz (panel/transkript), rapor sayfası
 *  (panel/transkript/{kaynak_id}), analiz başlatma ve ilerleme JSON'u.
 *
 *  Ayrıca transcript (okuma + arama) JSON'u ve modülün JS / CSS
 *  dosyaları (Varliklar). Rapor ve liste verisi API ile ortak
 *  servislerden gelir (RaporVerisi, KaynakDurumu, KaynakRepository).
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
use App\Core\Exceptions\HttpException;
use App\Core\Log\Logger;
use App\Core\Queue\Queue;
use App\Core\Request;
use App\Core\Response;
use App\Core\Setting;
use App\Http\Controller;
use Modules\Transkript\HataKodlari;
use Modules\Transkript\Repositories\KaynakRepository;
use Modules\Transkript\Repositories\SegmentRepository;
use Modules\Transkript\Services\AnalizBaslatici;
use Modules\Transkript\Services\AnalizReddedildi;
use Modules\Transkript\Services\Cleaner;
use Modules\Transkript\Services\DisaAktarim;
use Modules\Transkript\Services\KaynakDurumu;
use Modules\Transkript\Services\KayitYonetimi;
use Modules\Transkript\Services\KuyrukDurumu;
use Modules\Transkript\Services\RaporVerisi;
use Modules\Transkript\Services\Search;
use Modules\Transkript\TranskriptPolicy;
use Modules\Transkript\Transcript\Segment;
use Modules\Transkript\Varliklar;
use Modules\Transkript\Zaman;
use Throwable;

final class KaynakController extends Controller
{
    /** GET panel/transkript?q=&durum=&sayfa= — yeni analiz + sunucu taraflı sayfalı liste */
    public function index(Request $request): void
    {
        $user   = Auth::user();
        $policy = new TranskriptPolicy($user);
        $repo   = new KaynakRepository($this->db);

        $varsayilan = self::listeDurumu('', $policy->canAnalyze());
        $filtre = [
            'q'     => mb_substr($request->input('q'), 0, 100),
            'durum' => self::listeDurumu($request->input('durum'), $policy->canAnalyze()),
        ];
        $boyut = max(5, min(50, (int) Setting::get('sistem_sayfa_basina', '10') ?: 10));
        $sayfa = $request->int('sayfa', 1, 100000) ?? 1;
        $liste = $repo->liste($filtre, $sayfa, $boyut, $user?->id, $policy->isAdmin());

        Varliklar::youtubeIzinleri();

        $this->view('Transkript::index', [
            'title'      => 'Transkript',
            'subtitle'   => 'YouTube videolarından zaman damgalı transcript ve AI analizi.',
            'policy'     => $policy,
            'filtre'     => $filtre,
            'varsayilanDurum' => $varsayilan,
            'liste'      => $liste,
            'sayfa'      => $sayfa,
            'boyut'      => $boyut,
            // Yönetici herkesinkini; öteki roller GÖREBİLDİĞİ raporları sayar
            // (rol listesine değil yetkiye bağlı). Token / maliyet kartları
            // yalnız yöneticide (kullanıcı kararı: üye ve editör görmez).
            'istatistik' => $policy->canSeeAllCosts()
                ? $repo->istatistik(null)
                : $repo->gorunenIstatistik((int) $user?->id),
            'hepsi'      => $policy->canSeeAllCosts(),
            // panel/transkript?url=… → kutu dolu gelir (yer imi, paylaşım bağlantısı).
            'onUrl'      => mb_substr($request->input('url'), 0, 500),
            'kuyruk'     => (new KuyrukDurumu($this->db))->getir(),
        ]);
    }

    /** GET panel/transkript/{kaynak_id} — rapor; analiz sürüyorsa canlı ilerleme */
    public function goster(Request $request, string $kaynak_id): void
    {
        $kaynak = $this->gorulebilirKaynak($kaynak_id);
        $policy = new TranskriptPolicy(Auth::user());
        $veri   = (new RaporVerisi($this->db, $policy))->getir($kaynak);
        $adres  = parse_url(url_full(''));

        Varliklar::youtubeIzinleri();

        $this->view('Transkript::goster', [
            'title'  => trim((string) $kaynak['baslik']) !== '' ? (string) $kaynak['baslik'] : 'Analiz raporu',
            'kaynak' => $kaynak,
            'veri'   => $veri,
            'policy' => $policy,
            'kuyruk' => (new KuyrukDurumu($this->db))->getir(),
            'surenAnaliz' => (new KaynakRepository($this->db))->suregelenAnaliz((int) $kaynak['id']),
            // Gömülü oynatıcının postMessage hedefi: bu sayfanın kökeni.
            'origin' => ($adres['scheme'] ?? 'http') . '://' . ($adres['host'] ?? 'localhost') . (isset($adres['port']) ? ':' . $adres['port'] : ''),
        ]);
    }

    /**
     * GET panel/transkript/{kaynak_id}/transkript?q= — rapor sayfasındaki
     * Transcript bölümü. q boşsa 15–30 sn'lik okunur bloklar, doluysa
     * arama sonuçları (Search: FULLTEXT, kısa sorguda LIKE). Metin düz
     * metindir; vurgulamayı JS textContent ile yapar.
     */
    public function transkript(Request $request, string $kaynak_id): void
    {
        $kaynak = $this->gorulebilirKaynak($kaynak_id);
        $metin  = (new SegmentRepository($this->db))->sonMetin((int) $kaynak['id']);
        $q      = mb_substr($request->input('q'), 0, 100);

        if ($metin === null) {
            Response::success("Bu kaynağın transcript'i henüz yok.", ['satirlar' => [], 'kelimeler' => [], 'arama' => $q !== '']);
        }

        if ($q === '') {
            $satirlar = array_map(
                static fn (Segment $b): array => self::satir($b->baslangicMs, $b->metin),
                Cleaner::fromAyarlar()->hazirla((new SegmentRepository($this->db))->segmentler((int) $metin['id']))
            );

            Response::success(count($satirlar) . ' bölüm', ['satirlar' => $satirlar, 'kelimeler' => [], 'arama' => false]);
        }

        // Tek harf aranmaz; "0 sonuç" yerine nedeni söylenir (JS zaten istemez).
        if (mb_strlen(trim($q)) < 2) {
            Response::success('En az 2 harf yazın.', ['satirlar' => [], 'kelimeler' => [], 'arama' => true]);
        }

        $satirlar = array_map(
            static fn (array $s): array => self::satir((int) $s['baslangic_ms'], (string) $s['metin']),
            (new Search($this->db))->segmentler((int) $metin['id'], $q, 100, Search::SIRA_ZAMAN)
        );

        Response::success(count($satirlar) . ' sonuç', ['satirlar' => $satirlar, 'kelimeler' => Search::queryWords($q), 'arama' => true]);
    }

    /**
     * GET panel/transkript/{kaynak_id}/indir/{bicim} — rapor.md,
     * transkript.txt, transkript.srt, transkript.vtt. AI çağrısı yok;
     * kayıtlı rapordan ve segmentlerden üretilir. Rapor / transcript
     * yoksa 404.
     */
    public function indir(Request $request, string $kaynak_id, string $bicim): void
    {
        $kaynak = $this->gorulebilirKaynak($kaynak_id);

        if (!isset(DisaAktarim::BICIMLER[$bicim])) {
            throw HttpException::notFound();
        }

        if ($bicim === 'rapor.md') {
            $veri = (new RaporVerisi($this->db, new TranskriptPolicy(Auth::user())))->getir($kaynak);

            if ($veri['rapor'] === null) {
                throw HttpException::notFound();
            }

            $icerik = DisaAktarim::markdown($kaynak, $veri['rapor'], $veri['analiz'], $veri['transcript']);
        } else {
            $segmentler = new SegmentRepository($this->db);
            $metin      = $segmentler->sonMetin((int) $kaynak['id']) ?? throw HttpException::notFound();
            $satirlar   = $segmentler->segmentler((int) $metin['id']);

            $icerik = $bicim === 'transkript.txt'
                ? DisaAktarim::metin(Cleaner::fromAyarlar()->hazirla($satirlar))
                : DisaAktarim::altyazi($satirlar, substr($bicim, -3));
        }

        self::dosyaGonder($icerik, DisaAktarim::dosyaAdi($kaynak, $bicim), DisaAktarim::BICIMLER[$bicim][0]);
    }

    /**
     * GET panel/transkript/varlik/{dosya} — modülün JS / CSS dosyası.
     * Beyaz liste dışı ad 404. ?v= (dosya zamanı) varsa bir yıl önbellek.
     */
    public function varlik(Request $request, string $dosya): void
    {
        $yol    = Varliklar::yol($dosya) ?? throw HttpException::notFound();
        $etiket = '"' . substr(sha1($dosya . filemtime($yol) . filesize($yol)), 0, 20) . '"';

        if (!headers_sent()) {
            header_remove('Pragma');
            header('Content-Type: ' . Varliklar::tur($dosya));
            header('Cache-Control: ' . ($request->input('v') !== '' ? 'private, max-age=31536000, immutable' : 'private, no-cache'));
            header('ETag: ' . $etiket);
        }

        if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etiket) {
            http_response_code(304);
            exit;
        }

        readfile($yol);
        exit;
    }

    /**
     * POST panel/transkript/analiz — url, yeniden (0/1)
     * Panel AJAX zarfı: {success, type, description} + kaynak_id, analiz_id,
     * sonuc (baslatildi / suruyor / onbellekten), durum, ilerleme, adres.
     */
    public function analiz(Request $request): void
    {
        try {
            $sonuc = (new AnalizBaslatici($this->db, Auth::user()))
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
        $durum = (new KaynakDurumu($this->db, new TranskriptPolicy(Auth::user())))->getir($this->gorulebilirKaynak($kaynak_id));

        Response::success($durum['etiket'], $durum);
    }

    /**
     * POST panel/transkript/{kaynak_id}/sil — kaynak, analizleri, transcript'i
     * ve bekleyen kuyruk işleri (Services\KayitYonetimi). Ortak YouTube
     * kaynağını yalnız yönetici siler (TranskriptPolicy::canDeleteSource);
     * harcanan AI maliyeti kayıtlarda kalır.
     */
    public function sil(Request $request, string $kaynak_id): void
    {
        $kaynak = $this->gorulebilirKaynak($kaynak_id);

        if (!(new TranskriptPolicy(Auth::user()))->canDeleteSource($kaynak)) {
            Response::error('Bu kaydı silme yetkiniz yok. Ortak videoları yalnızca yöneticiler silebilir.', 403);
        }

        $sonuc = (new KayitYonetimi($this->db))->sil($kaynak);

        Logger::info('Transkript kaydı silindi', [
            'kaynak' => (int) $kaynak['id'], 'video' => (string) $kaynak['dis_kimlik'],
            'analiz' => $sonuc['analiz'], 'is' => $sonuc['is'], 'kullanici' => Auth::id(),
        ], 'transkript');

        Response::success('Kayıt silindi.', ['adres' => url('panel/transkript')]);
    }

    /**
     * POST panel/transkript/{kaynak_id}/iptal — kaynağın süren analizini
     * durdurur: analiz "iptal edildi" hatasıyla biter, bekleyen işleri
     * silinir. Yönetici ya da analizi başlatan (canCancelAnalysis).
     */
    public function iptal(Request $request, string $kaynak_id): void
    {
        $kaynak = $this->gorulebilirKaynak($kaynak_id);
        $analiz = (new KaynakRepository($this->db))->suregelenAnaliz((int) $kaynak['id']);

        if ($analiz === null) {
            Response::error('Bu kaynağın süren bir analizi yok.', 409);
        }

        if (!(new TranskriptPolicy(Auth::user()))->canCancelAnalysis($analiz)) {
            Response::error('Bu analizi yalnızca başlatan kişi ya da bir yönetici iptal edebilir.', 403);
        }

        $iptal = (new KayitYonetimi($this->db))->iptal((int) $analiz['id']);

        if ($iptal) {
            Logger::info('Analiz iptal edildi', ['analiz' => (int) $analiz['id'], 'kaynak' => (int) $kaynak['id'], 'kullanici' => Auth::id()], 'transkript');
        }

        Response::success($iptal ? 'Analiz iptal edildi.' : 'Analiz bu arada bitti; iptal edilecek bir şey kalmadı.', [
            'adres' => url('panel/transkript/' . (int) $kaynak['id']),
        ]);
    }

    /**
     * POST panel/transkript/kuyruk/isle — "Şimdi işle" (yalnız yönetici).
     *
     * Kuyruk işçisi çalışmayan geliştirme makinesi (cron'suz Windows /
     * XAMPP) ve cron'u olmayan barındırma için yedek; docs/TEKNIK_RAPOR.md
     * 7. bölüm. Yayında yalnız kuyruk.panelden_isle açıkken çalışır.
     * Sıradaki "transkript" işleri ~20 saniye boyunca işlenir; o anda
     * başlamış bir AI adımı bitene kadar istek sürer. Asıl çözüm işçidir
     * (php cy schedule:run her dakika; Windows'ta kuyruk-isci.bat).
     */
    public function kuyruguIsle(Request $request): void
    {
        if (!KuyrukDurumu::paneldenIslenebilir()) {
            Response::error('Panelden işleme yayın ortamında kapalı. Sunucuda kuyruk işçisini çalıştırın: ' . KuyrukDurumu::KOMUT_ELLE, 403);
        }

        @set_time_limit(300);
        ignore_user_abort(true);

        $bitis   = microtime(true) + 20;
        $islenen = 0;

        try {
            while ($islenen < 25 && microtime(true) < $bitis && Queue::runNext('transkript')) {
                $islenen++;
            }
        } catch (Throwable $e) {
            Logger::error('Kuyruk panelden işlenirken hata', ['ayrinti' => $e->getMessage(), 'kullanici' => Auth::id()], 'transkript');

            Response::error(HataKodlari::mesaj('beklenmeyen_hata'), 500);
        }

        $durum = (new KuyrukDurumu($this->db))->getir();

        Logger::info('Kuyruk panelden işlendi', ['islenen' => $islenen, 'bekleyen' => $durum['bekleyen'], 'kullanici' => Auth::id()], 'transkript');

        Response::success(
            $islenen === 0 ? 'Sırada çalıştırılacak iş yok.' : $islenen . ' iş çalıştırıldı.' . ($durum['bekleyen'] > 0 ? ' Sırada ' . $durum['bekleyen'] . ' iş daha var.' : ''),
            ['islenen' => $islenen, 'bekleyen' => $durum['bekleyen']]
        );
    }

    /**
     * Listenin durum süzgeci. Analiz başlatamayanın süren ve hatalı kayıtla
     * işi yoktur: varsayılanı "hazir"dır; "Tüm durumlar" seçeneği "hepsi"
     * gönderir. Analiz başlatanın varsayılanı süzgeçsizdir ('').
     */
    public static function listeDurumu(string $girdi, bool $analizEdebilir): string
    {
        return match (true) {
            in_array($girdi, KaynakRepository::LISTE_DURUMLARI, true) => $girdi,
            $girdi === 'hepsi' => '',
            default            => $analizEdebilir ? '' : 'hazir',
        };
    }

    /** Üretilen metni indirilecek dosya olarak gönderir (ad ASCII; bkz. DisaAktarim::dosyaAdi). */
    private static function dosyaGonder(string $icerik, string $ad, string $tur): never
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (!headers_sent()) {
            header_remove('Pragma');
            header('Content-Type: ' . $tur);
            header('Content-Length: ' . strlen($icerik));
            header('Cache-Control: private, no-store');
            header('Content-Disposition: attachment; filename="' . $ad . '"; filename*=UTF-8\'\'' . rawurlencode($ad));
        }

        echo $icerik;
        exit;
    }

    /** Kaynak yoksa ya da kullanıcı göremiyorsa 404 (varlığı da sızmaz). @return array<string,mixed> */
    private function gorulebilirKaynak(string $kaynakId): array
    {
        $kaynak = (new KaynakRepository($this->db))->bul(self::kimlik($kaynakId));

        if ($kaynak === null || !(new TranskriptPolicy(Auth::user()))->canViewSource($kaynak)) {
            throw HttpException::notFound();
        }

        return $kaynak;
    }

    /** @return array{sn:int,zaman:string,metin:string} */
    private static function satir(int $ms, string $metin): array
    {
        return ['sn' => intdiv($ms, 1000), 'zaman' => Zaman::yaz($ms), 'metin' => $metin];
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
