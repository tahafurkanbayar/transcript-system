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
use App\Core\Flash;
use App\Core\Log\Logger;
use App\Core\Queue\Queue;
use App\Core\Request;
use App\Core\Response;
use App\Core\Setting;
use App\Http\Controller;
use Modules\Transkript\HataKodlari;
use Modules\Transkript\Repositories\AnalizRepository;
use Modules\Transkript\Repositories\KaynakRepository;
use Modules\Transkript\Repositories\KutuphaneRepository;
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
        $kapsam     = in_array($request->input('kapsam'), KaynakRepository::LISTE_KAPSAMLARI, true) ? $request->input('kapsam') : '';
        $filtre = [
            'q'      => mb_substr($request->input('q'), 0, 100),
            'durum'  => self::listeDurumu($request->input('durum'), $policy->canAnalyze()),
            // Yönetici dışında boş = kütüphanem (KaynakRepository::kendi); ikisi aynı adreste kalsın.
            'kapsam' => !$policy->isAdmin() && $kapsam === 'kutuphanem' ? '' : $kapsam,
            // Kişiye göre süzgeç yalnız yöneticide.
            'kullanici' => $policy->isAdmin() ? (string) ($request->int('kullanici', 1) ?? '') : '',
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
            // Yönetici herkesinkini; öteki roller KENDİ raporlarını sayar
            // (kütüphanesi; listenin varsayılan kapsamı). Token / maliyet
            // kartları yalnız yöneticide (kullanıcı kararı).
            'istatistik' => $policy->canSeeAllCosts()
                ? $repo->istatistik(null)
                : $repo->kendiIstatistik((int) $user?->id),
            'hepsi'      => $policy->canSeeAllCosts(),
            // Yönetici: "Kim" süzgecinin seçenekleri. Öteki roller: paylaşılan rapor sayısı.
            'kisiler'    => $policy->isAdmin() ? $repo->islemYapanlar() : [],
            'paylasilan' => $policy->isAdmin() ? 0 : $repo->paylasilanSayisi(),
            'benId'      => (int) $user?->id,
            // Analiz başlatabilene bugünkü hakkı ("3 / 10 · saatlik 1 / 20").
            'hak'        => $policy->canAnalyze() ? (new AnalizBaslatici($this->db, $user))->hakDurumu() : null,
            // Yönetici: geçici sebeple duran analiz sayısı ("Bekleyenleri yeniden dene").
            'bekleyen'   => $policy->isAdmin() ? count($repo->devamEdilebilirKaynaklar()) : 0,
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
        $kutuphane = new KutuphaneRepository($this->db);

        // Kayıt düğmeleri tek sorguyla: kütüphanede mi (çıkar düğmesi)?
        $kaynak['kutuphanede'] = $kutuphane->iceriyor((int) Auth::id(), (int) $kaynak['id']);

        Varliklar::youtubeIzinleri();

        $this->view('Transkript::goster', [
            'title'  => trim((string) $kaynak['baslik']) !== '' ? (string) $kaynak['baslik'] : 'Analiz raporu',
            'kaynak' => $kaynak,
            'veri'   => $veri,
            'policy' => $policy,
            'kuyruk' => (new KuyrukDurumu($this->db))->getir(),
            'surenAnaliz' => (new KaynakRepository($this->db))->suregelenAnaliz((int) $kaynak['id']),
            // "Analizi yeniden dene" yalnız son analizi başlatana ve yöneticiye (canRetry).
            'sonAnaliz'   => (new AnalizRepository($this->db))->kaynaginSonAnalizi((int) $kaynak['id']),
            // Yönetici "Tamamen sil" derken başka kaç kullanıcının kütüphanesinde olduğunu görür.
            'kutuphaneSayisi' => $policy->isAdmin() ? $kutuphane->sahipSayisi((int) $kaynak['id'], Auth::id()) : 0,
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

        // SRT / VTT altyazı Pro özelliği (Uyelik\Planlar); kaynağı görmek yetmez.
        if (!(new TranskriptPolicy(Auth::user()))->canDownload($bicim)) {
            throw HttpException::forbidden('Altyazı indirme Pro planında. Üyeliğim sayfasından Pro\'ya geçebilirsiniz.');
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
            AnalizBaslatici::ONBELLEKTEN => 'Bu video daha önce analiz edildi; rapor kütüphanenize eklendi (analiz hakkınızdan düşmedi).',
            AnalizBaslatici::SURUYOR     => 'Bu videonun analizi sürüyor; bitince rapor kütüphanenizde olacak.',
            AnalizBaslatici::DEVAM_ETTI  => 'Analiz kaldığı yerden sürüyor; biten bölümler korundu.',
            default                      => 'Analiz başladı.',
        }, [
            'sonuc'     => $sonuc['sonuc'],
            'kaynak_id' => $kaynakId,
            'analiz_id' => (int) $sonuc['analiz']['id'],
            'durum'     => (string) $sonuc['analiz']['durum'],
            'ilerleme'  => (int) $sonuc['analiz']['ilerleme'],
            'adres'     => url('panel/transkript/' . $kaynakId),
            // Önbellekten dönen raporu bu kişi yeniden analiz edebilir mi (yönetici ya da raporu başlatan)?
            'yeniden_analiz' => $sonuc['sonuc'] === AnalizBaslatici::ONBELLEKTEN
                && (new TranskriptPolicy(Auth::user()))->canReanalyze($sonuc['analiz']),
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
     * POST panel/transkript/{kaynak_id}/sil — TAMAMEN sil: kaynak,
     * analizleri, transcript'i, herkesin kütüphanesindeki satırı ve
     * bekleyen kuyruk işleri (Services\KayitYonetimi). Ortak YouTube
     * kaynağını yalnız yönetici siler (TranskriptPolicy::canDeleteSource);
     * kullanıcı kendi listesini "Kütüphanemden çıkar" ile temizler.
     * Harcanan AI maliyeti kayıtlarda kalır.
     */
    public function sil(Request $request, string $kaynak_id): void
    {
        $kaynak = $this->gorulebilirKaynak($kaynak_id);
        $policy = new TranskriptPolicy(Auth::user());

        if (!$policy->canDeleteSource($kaynak)) {
            Response::error($policy->canRemoveFromLibrary($kaynak)
                ? 'Ortak videoyu yalnızca yöneticiler tamamen silebilir. Listenizden kaldırmak için "Kütüphanemden çıkar"ı kullanın.'
                : 'Bu kaydı silme yetkiniz yok.', 403);
        }

        $sonuc = (new KayitYonetimi($this->db))->sil($kaynak);

        Logger::info('Transkript kaydı silindi', [
            'kaynak' => (int) $kaynak['id'], 'video' => (string) $kaynak['dis_kimlik'],
            'analiz' => $sonuc['analiz'], 'is' => $sonuc['is'], 'kullanici' => Auth::id(),
        ], 'transkript');

        Response::success('Kayıt silindi.', ['adres' => url('panel/transkript')]);
    }

    /**
     * POST panel/transkript/{kaynak_id}/kutuphaneden-cikar — videoyu
     * yalnız bu kullanıcının kütüphanesinden çıkarır; ortak kayıt, rapor
     * ve başkalarının kütüphanesi kalır. Paylaşılan rapor listede görünmeye
     * devam eder (paylaşımı yönetici kaldırır).
     */
    public function kutuphanedenCikar(Request $request, string $kaynak_id): void
    {
        $kaynak = $this->gorulebilirKaynak($kaynak_id);
        $policy = new TranskriptPolicy(Auth::user());

        if (!$policy->canRemoveFromLibrary($kaynak)) {
            Response::error('Bu video kütüphanenizde değil.', 409);
        }

        (new KutuphaneRepository($this->db))->cikar((int) Auth::id(), (int) $kaynak['id']);

        Logger::info('Video kütüphaneden çıkarıldı', ['kaynak' => (int) $kaynak['id'], 'kullanici' => Auth::id()], 'transkript');

        Response::success('Video kütüphanenizden çıkarıldı. Rapor silinmedi; aynı bağlantıyı yeniden eklerseniz hakkınızdan düşmez.', [
            'adres'  => url('panel/transkript'),
            'gorunur' => (int) ($kaynak['paylasildi'] ?? 0) === 1 || $policy->isAdmin(),
        ]);
    }

    /**
     * POST panel/transkript/{kaynak_id}/paylas — paylas=1 "Üyelerle
     * paylaş", paylas=0 paylaşımı kaldırır (yalnız yönetici; rota
     * can:transkript.admin). Paylaşılan rapor transkript.view yetkisi
     * olan herkesin listesinde görünür.
     */
    public function paylas(Request $request, string $kaynak_id): void
    {
        $kaynak = $this->gorulebilirKaynak($kaynak_id);

        if ((int) $kaynak['sahip_id'] !== 0) {
            Response::error('Yüklenen dosyalar paylaşılamaz.', 422);
        }

        $paylas = $request->bool('paylas');

        (new KaynakRepository($this->db))->paylasimYaz((int) $kaynak['id'], $paylas);

        Logger::info($paylas ? 'Rapor üyelerle paylaşıldı' : 'Rapor paylaşımı kaldırıldı', ['kaynak' => (int) $kaynak['id'], 'kullanici' => Auth::id()], 'transkript');

        Response::success($paylas ? 'Rapor üyelerle paylaşıldı; panele giren herkes görebilir.' : 'Paylaşım kaldırıldı; rapor yalnız kütüphanesine ekleyenlere görünür.', [
            'adres' => url('panel/transkript/' . (int) $kaynak['id']),
        ]);
    }

    /**
     * POST panel/transkript/{kaynak_id}/iptal — kaynağın süren analizini
     * durdurur: analiz "iptal edildi" hatasıyla biter, bekleyen işleri
     * silinir. Yönetici ya da analizi başlatan (canCancelAnalysis).
     *
     * Sıra: görmüyorsa 404 → analizi başlatan değilse 403 → süren analiz
     * yoksa 409 (cowork 5. tur: durum yetkiden önce soruluyordu).
     */
    public function iptal(Request $request, string $kaynak_id): void
    {
        $kaynak = $this->gorulebilirKaynak($kaynak_id);
        $repo   = new KaynakRepository($this->db);
        $analiz = $repo->suregelenAnaliz((int) $kaynak['id']);
        $son    = $analiz ?? (new AnalizRepository($this->db))->kaynaginSonAnalizi((int) $kaynak['id']);

        if (!(new TranskriptPolicy(Auth::user()))->canCancelAnalysis($son ?? [])) {
            Response::error('Bu analizi yalnızca başlatan kişi ya da bir yönetici iptal edebilir.', 403);
        }

        if ($analiz === null) {
            Response::error('Bu kaynağın süren bir analizi yok.', 409);
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
     * POST panel/transkript/yeniden-dene — "Bekleyenleri yeniden dene"
     * (yalnız yönetici). Son analizi geçici sebeple (kota, servis, ayar)
     * duran her kaynak kaldığı yerden sürer; biten parçalar korunur.
     * AI çağrısı burada yapılmaz, işler kuyruğa girer.
     */
    public function bekleyenleriSurdur(Request $request): void
    {
        $baslatici = new AnalizBaslatici($this->db, Auth::user());
        $adet      = 0;

        foreach ((new KaynakRepository($this->db))->devamEdilebilirKaynaklar() as $kaynak) {
            if ($baslatici->devamEt($kaynak) !== null) {
                $adet++;
            }
        }

        Logger::info('Bekleyen analizler sürdürüldü', ['adet' => $adet, 'kullanici' => Auth::id()], 'transkript');

        $adet > 0
            ? Flash::success($adet . ' analiz kaldığı yerden sürüyor; biten bölümler korundu.')
            : Flash::info('Sürdürülecek analiz yok.');

        Response::redirect(url('panel/transkript'));
    }

    /**
     * POST panel/transkript/kuyruk/tik — komut satırısız kuyruk.
     *
     * İşçi görülmüyorsa açık panel sayfasının ilerleme yoklaması bunu
     * arka planda çağırır. Veritabanı kilidi (GET_LOCK) aynı anda tek
     * işleyici bırakır: kilit doluysa hemen 202. Kilidi alan TEK bir işi
     * (bir adım ya da bir parça) çalıştırır ve kilidi bırakır. Sekme
     * kapansa da başladığı işi bitirir (ignore_user_abort). Oturum
     * kilidi hemen bırakılır; iş sürerken aynı kullanıcının diğer
     * istekleri (ilerleme yoklaması) beklemez. İşletim sistemi komutu
     * ya da süreç başlatılmaz.
     */
    public function kuyrukTik(Request $request): void
    {
        // Rota yalnız giriş ister; analiz başlatabilen (ayarla üye de) işletir.
        if (!(new TranskriptPolicy(Auth::user()))->canAnalyze()) {
            Response::error('Bu işlem için yetkiniz yok.', 403);
        }

        if (!KuyrukDurumu::tarayicidanIslenir()) {
            Response::error('Kuyruğu tarayıcı üzerinden işleme kapalı.', 403);
        }

        if (KuyrukDurumu::isciGorulduMu()) {
            Response::json(['success' => true, 'type' => 'info', 'description' => 'Kuyruk işçisi çalışıyor.', 'islenen' => 0], 202);
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $kilit = $this->db->query("SELECT GET_LOCK('transkript_kuyruk_tik', 0)")->fetchColumn();

        if ((int) $kilit !== 1) {
            Response::json(['success' => true, 'type' => 'info', 'description' => 'Kuyruk başka bir sekmede işleniyor.', 'islenen' => 0], 202);
        }

        ignore_user_abort(true);
        @set_time_limit(max(300, (int) \App\Core\Config::get('queue.timeout', 300) + 60));

        try {
            $islendi = Queue::runNext('transkript');
        } catch (Throwable $e) {
            Logger::error('Kuyruk tarayıcıdan işlenirken hata', ['ayrinti' => $e->getMessage(), 'kullanici' => Auth::id()], 'transkript');
            $islendi = false;
        } finally {
            $this->db->query("SELECT RELEASE_LOCK('transkript_kuyruk_tik')")->fetchColumn();
        }

        Response::success($islendi ? 'Bir iş işlendi.' : 'Sırada hazır iş yok.', ['islenen' => $islendi ? 1 : 0]);
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
