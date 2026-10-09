<?php
/**
 * =====================================================================
 *  TranskriptApiController – /api/v1/transkript/… (dış istemciler)
 * ---------------------------------------------------------------------
 *  "Authorization: Bearer cy_…" anahtarıyla çağrılır; kapsam ve hız
 *  sınırı starter'ın ApiGuard'ındadır ("okuma" anahtarı POST atamaz).
 *  Panel ekranlarıyla AYNI servisleri çağırır; iş mantığı iki yerde
 *  yazılmaz.
 *
 *  Yanıt zarfı: { success, data, meta } / { success:false, error:{code, message} }
 *
 *  Hazır: analiz, kaynaklar (sayfalı liste), kaynaklar/{id},
 *  kaynaklar/{id}/ozet, kaynaklar/{id}/transkript. Dosya yükleme,
 *  sohbet ve istatistik sonraki aşamalarda (501).
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Controllers\Api;

use App\Core\Api\ApiResponse;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Log\Logger;
use App\Core\Request;
use App\Http\Controller;
use Modules\Transkript\HataKodlari;
use Modules\Transkript\Repositories\KaynakRepository;
use Modules\Transkript\Repositories\SegmentRepository;
use Modules\Transkript\Services\AnalizBaslatici;
use Modules\Transkript\Services\AnalizReddedildi;
use Modules\Transkript\Services\KaynakDurumu;
use Modules\Transkript\Services\RaporVerisi;
use Modules\Transkript\Services\Search;
use Modules\Transkript\TranskriptPolicy;
use Modules\Transkript\Transcript\Segment;
use Modules\Transkript\Zaman;
use Throwable;

final class TranskriptApiController extends Controller
{
    /**
     * POST api/v1/transkript/analiz — url, yeniden (0/1)
     *   yeni iş ya da sürmekte olan analiz → 202
     *   güncel rapor önbellekte            → 200, meta.onbellekten = true
     */
    public function analiz(Request $request): void
    {
        $db = Database::connection();

        try {
            $sonuc = (new AnalizBaslatici($db, Auth::user()))->youtube($request->input('url'), $request->bool('yeniden'));
        } catch (AnalizReddedildi $e) {
            if ($e->beklemeSn > 0 && !headers_sent()) {
                header('Retry-After: ' . $e->beklemeSn);
            }

            ApiResponse::error($e->getMessage(), $e->http, $e->kod);
        } catch (Throwable $e) {
            Logger::error('API: analiz başlatılamadı', ['ayrinti' => $e->getMessage(), 'kullanici' => Auth::id()], 'transkript');

            ApiResponse::error(HataKodlari::mesaj('beklenmeyen_hata'), 500, 'beklenmeyen_hata');
        }

        $onbellekten = $sonuc['sonuc'] === AnalizBaslatici::ONBELLEKTEN;

        ApiResponse::success(
            (new KaynakDurumu($db))->getir($sonuc['kaynak']),
            ['onbellekten' => $onbellekten, 'sonuc' => $sonuc['sonuc']],
            $onbellekten ? 200 : 202,
        );
    }

    /** POST api/v1/transkript/dosya — ses / video yükleme (5b. aşama) */
    public function dosya(Request $request): void
    {
        self::hazirDegil();
    }

    /**
     * GET api/v1/transkript/kaynaklar?tur=&durum=&q=&sayfa=&sayfa_boyut=
     * durum: hazir · suruyor · hata. Panel listesiyle aynı sorgu ve görünürlük.
     */
    public function kaynaklar(Request $request): void
    {
        $policy = new TranskriptPolicy(Auth::user());
        $sayfa  = $request->int('sayfa', 1, 100000) ?? 1;
        $boyut  = $request->int('sayfa_boyut', 1, 100) ?? 20;

        $liste = (new KaynakRepository(Database::connection()))->liste([
            'tur'   => $request->input('tur'),
            'durum' => $request->input('durum'),
            'q'     => mb_substr($request->input('q'), 0, 100),
        ], $sayfa, $boyut, Auth::id(), $policy->isAdmin());

        ApiResponse::paginated(array_map(static fn (array $s): array => [
            'kaynak_id'   => (int) $s['id'],
            'tur'         => (string) $s['tur'],
            'dis_kimlik'  => (string) $s['dis_kimlik'],
            'baslik'      => $s['baslik'] !== null ? (string) $s['baslik'] : null,
            'kanal_adi'   => $s['kanal_adi'] !== null ? (string) $s['kanal_adi'] : null,
            'sure_sn'     => $s['sure_sn'] !== null ? (int) $s['sure_sn'] : null,
            'kucuk_resim' => $s['kucuk_resim'] !== null ? (string) $s['kucuk_resim'] : null,
            'durum'       => (string) $s['durum'],
            'ilerleme'    => (int) $s['ilerleme'],
            'rapor_hazir' => $s['rapor_analiz_id'] !== null,
            'hata_kodu'   => $s['hata_kodu'] !== null ? (string) $s['hata_kodu'] : null,
            'tarih'       => (string) $s['created_at'],
        ], $liste['satirlar']), $liste['toplam'], $sayfa, $boyut);
    }

    /** GET api/v1/transkript/kaynaklar/{kaynak_id} — kaynak kartı + durum + ilerleme */
    public function kaynak(Request $request, string $kaynak_id): void
    {
        $db = Database::connection();

        ApiResponse::success((new KaynakDurumu($db))->getir(self::gorulebilirKaynak($db, $kaynak_id)));
    }

    /**
     * GET api/v1/transkript/kaynaklar/{kaynak_id}/ozet — güncel rapor.
     * Rapor yoksa 404 "rapor_yok" (analiz sürüyorsa durumu kaynaklar/{id}'den).
     */
    public function ozet(Request $request, string $kaynak_id): void
    {
        $db   = Database::connection();
        $veri = (new RaporVerisi($db))->getir(self::gorulebilirKaynak($db, $kaynak_id));

        if ($veri['rapor'] === null || $veri['analiz'] === null) {
            ApiResponse::error('Bu kaynağın tamamlanmış bir raporu yok.', 404, 'rapor_yok');
        }

        $analiz = $veri['analiz'];

        // Maliyet yalnız transkript.admin'e (TranskriptPolicy::canSeeAllCosts).
        if (!(new TranskriptPolicy(Auth::user()))->canSeeAllCosts()) {
            unset($analiz['maliyet_usd']);
        }

        ApiResponse::success([
            'kaynak_id'      => (int) $veri['durum']['kaynak_id'],
            'analiz'         => $analiz,
            'eksik_araliklar' => $veri['eksik'],
            'rapor'          => $veri['rapor'],
        ]);
    }

    /**
     * GET api/v1/transkript/kaynaklar/{kaynak_id}/transkript?bas=&bit=&q=
     * bas / bit saniye; q verilirse arama (en çok 100 sonuç, zaman sırası).
     */
    public function transkript(Request $request, string $kaynak_id): void
    {
        $db     = Database::connection();
        $kaynak = self::gorulebilirKaynak($db, $kaynak_id);
        $metin  = (new SegmentRepository($db))->sonMetin((int) $kaynak['id']);

        if ($metin === null) {
            ApiResponse::error("Bu kaynağın transcript'i henüz yok.", 404, 'transkript_yok');
        }

        $q = mb_substr($request->input('q'), 0, 100);

        if ($q !== '') {
            $satirlar = array_map(static fn (array $s): array => [
                'bas_ms' => (int) $s['baslangic_ms'],
                'bit_ms' => (int) $s['bitis_ms'],
                'zaman'  => Zaman::yaz((int) $s['baslangic_ms']),
                'metin'  => (string) $s['metin'],
            ], (new Search($db))->segmentler((int) $metin['id'], $q, 100, Search::SIRA_ZAMAN));
        } else {
            $bas = $request->int('bas', 0);
            $bit = $request->int('bit', 0);

            $satirlar = array_map(static fn (Segment $s): array => [
                'bas_ms' => $s->baslangicMs,
                'bit_ms' => $s->bitisMs,
                'zaman'  => Zaman::yaz($s->baslangicMs),
                'metin'  => $s->metin,
            ], (new SegmentRepository($db))->segmentler(
                (int) $metin['id'],
                $bas !== null ? $bas * 1000 : null,
                $bit !== null ? $bit * 1000 : null,
            ));
        }

        ApiResponse::success($satirlar, [
            'dil'       => (string) $metin['dil'],
            'saglayici' => (string) $metin['saglayici'],
            'otomatik'  => (bool) $metin['otomatik_mi'],
            'adet'      => count($satirlar),
        ]);
    }

    /** POST api/v1/transkript/kaynaklar/{kaynak_id}/sohbet — soru; cevap + zaman damgaları (4. aşama) */
    public function sohbet(Request $request, string $kaynak_id): void
    {
        self::hazirDegil();
    }

    /** GET api/v1/transkript/istatistik — günlük analiz, token, maliyet (5. aşama) */
    public function istatistik(Request $request): void
    {
        self::hazirDegil();
    }

    /**
     * Görülebilir kaynak; yoksa ya da yetki yoksa 404 (varlığı da sızmaz).
     *
     * @return array<string,mixed>
     */
    private static function gorulebilirKaynak(\PDO $db, string $kaynakId): array
    {
        $kaynak = ctype_digit($kaynakId) ? (new KaynakRepository($db))->bul((int) $kaynakId) : null;

        if ($kaynak === null || !(new TranskriptPolicy(Auth::user()))->canViewSource($kaynak)) {
            ApiResponse::notFound('Kaynak bulunamadı.');
        }

        return $kaynak;
    }

    private static function hazirDegil(): never
    {
        ApiResponse::error('Bu uç henüz hazır değil.', 501, 'henuz_hazir_degil');
    }
}
