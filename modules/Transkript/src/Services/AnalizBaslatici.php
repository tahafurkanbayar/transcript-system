<?php
/**
 * =====================================================================
 *  AnalizBaslatici – "Analiz Et" isteğinin TEK yolu (panel + API)
 * ---------------------------------------------------------------------
 *  HTTP isteğinde uzun iş yapılmaz: yalnız kayıt açılır ve ilk iş
 *  (KaynakBilgisiAl) kuyruğa bırakılır. İstek milisaniyeler sürer.
 *
 *  SIRA
 *    1) URL → 11 karakterlik video kimliği (UrlParser); geçersizse ret
 *    2) yetki: transkript.analyze; yeniden=1 yalnız transkript.admin
 *    3) kaynak varsa
 *         analizi sürüyorsa          → SURUYOR (ikinci iş açılmaz)
 *         güncel tamamlanmış analizi → ONBELLEKTEN (AI'a gidilmez)
 *    4) yeni AI işi maliyetlidir: kullanıcı başına saatlik (Throttle)
 *       ve günlük sınır, bütün kullanıcılar için günlük token / maliyet
 *       tavanı (kotaKontrolu; önbellekten dönen istek sayılmaz)
 *    5) video doğrulanmamışsa (kaynak yok ya da başlıksız) oEmbed ön
 *       kontrolü: yok / gizli video 422 ile reddedilir, KAYIT AÇILMAZ;
 *       varsa başlık, kanal ve küçük resim hemen yazılır. oEmbed'e
 *       ulaşılamazsa (en çok 4 sn) kontrol atlanır, iş kendisi denetler.
 *       Süresi bilinen ve analiz.en_uzun_dk'yı aşan video da reddedilir.
 *    6) kaynak satırı kilitlenir, sürmekte olan analiz yeniden
 *       kontrol edilir; analiz kaydı ve ilk iş aynı işlemde açılır
 *       → BASLATILDI
 *
 *  Yeni analiz güncel DEĞİLDİR; tamamlanınca güncel olur ve eskisini
 *  güncellikten düşürür (AnalizRepository::raporuYaz). Yeniden analiz
 *  sürerken eski rapor görünmeye devam eder.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Services;

use App\Core\Env;
use App\Core\Queue\Queue;
use App\Core\Throttle;
use App\Models\User;
use Modules\Transkript\Ayarlar;
use Modules\Transkript\HataKodlari;
use Modules\Transkript\Jobs\KaynakBilgisiAl;
use Modules\Transkript\Kaynak\KaynakHatasi;
use Modules\Transkript\Kaynak\UrlParser;
use Modules\Transkript\Kaynak\VideoBilgisi;
use Modules\Transkript\Kaynak\YoutubeMetadata;
use Modules\Transkript\Repositories\AnalizRepository;
use Modules\Transkript\Repositories\KaynakRepository;
use Modules\Transkript\TranskriptPolicy;
use PDO;
use Throwable;

final class AnalizBaslatici
{
    public const BASLATILDI  = 'baslatildi';
    public const SURUYOR     = 'suruyor';
    public const ONBELLEKTEN = 'onbellekten';

    public function __construct(
        private readonly PDO $db,
        private readonly ?User $user,
        private readonly ?YoutubeMetadata $onKontrol = null,
    ) {
    }

    /**
     * @return array{sonuc: string, kaynak: array<string,mixed>, analiz: array<string,mixed>}
     *
     * @throws AnalizReddedildi
     */
    public function youtube(string $url, bool $yeniden = false): array
    {
        try {
            $videoId = UrlParser::cozumle($url);
        } catch (KaynakHatasi $e) {
            throw new AnalizReddedildi($e->kod, $e->kullaniciMesaji(), 422);
        }

        $policy = new TranskriptPolicy($this->user);

        if (!$policy->canAnalyze()) {
            throw new AnalizReddedildi('yetkisiz', 'Analiz başlatma yetkiniz yok.', 403);
        }

        if ($yeniden && !$policy->canReanalyze()) {
            throw new AnalizReddedildi('yetkisiz', 'Yeniden analiz yalnızca yöneticilere açıktır.', 403);
        }

        $kaynaklar = new KaynakRepository($this->db);
        $kaynak    = $kaynaklar->youtubeBul($videoId);

        if ($kaynak !== null && ($hazir = $this->hazirAnaliz($kaynak, $yeniden)) !== null) {
            return $hazir;
        }

        if ($kaynak !== null && self::cokUzun($kaynak['sure_sn'] !== null ? (int) $kaynak['sure_sn'] : null)) {
            throw new AnalizReddedildi('video_cok_uzun', HataKodlari::mesaj('video_cok_uzun'), 422);
        }

        $this->kotaKontrolu();
        $this->sinirKontrolu();

        // Başlığı olan kaynak daha önce doğrulanmıştır; oEmbed'e yeniden gidilmez.
        $bilgi = $kaynak === null || trim((string) $kaynak['baslik']) === '' ? $this->videoyuDogrula($videoId) : null;

        $kaynak ??= $kaynaklar->youtubeBulVeyaOlustur($videoId, $this->user?->id);

        if ($bilgi !== null) {
            $kaynaklar->bilgiyiYaz((int) $kaynak['id'], $bilgi);
            $kaynak = $kaynaklar->bul((int) $kaynak['id']) ?? $kaynak;
        }

        return $this->ac($kaynak, $yeniden);
    }

    /**
     * analiz.en_uzun_dk'yı aşıyor mu? Süre bilinmiyorsa (null) hayır;
     * iş, süre öğrenilince yeniden sorar (KaynakBilgisiAl, TranskriptAl).
     */
    public static function cokUzun(?int $sureSn): bool
    {
        $dakika = (int) Ayarlar::get('analiz.en_uzun_dk', 180);

        return $dakika > 0 && $sureSn !== null && $sureSn > $dakika * 60;
    }

    /**
     * oEmbed ön kontrolü (YoutubeMetadata::onKontrol). Video yoksa ya da
     * gizliyse ret; ulaşılamazsa null (kontrol atlanır).
     *
     * @throws AnalizReddedildi
     */
    private function videoyuDogrula(string $videoId): ?VideoBilgisi
    {
        try {
            return ($this->onKontrol ?? YoutubeMetadata::onKontrolIcin())->onKontrol($videoId);
        } catch (KaynakHatasi $e) {
            /* oEmbed 401 hem gizli hem de yerleştirmeye kapalı (ama herkese
             * açık, analiz edilebilir) videoda döner. YouTube Data API
             * anahtarı varsa ikisini iş ayırt eder; yoksa iş de oEmbed'e
             * bakıp aynı kararı vereceğinden burada reddedilir. */
            if ($e->kod === 'gizli_video' && trim(Env::get('YOUTUBE_API_KEY')) !== '') {
                return null;
            }

            throw new AnalizReddedildi($e->kod, $e->kullaniciMesaji(), 422);
        }
    }

    /**
     * Sürmekte olan ya da (yeniden istenmediyse) güncel tamamlanmış analiz.
     *
     * @param  array<string,mixed> $kaynak
     * @return array{sonuc: string, kaynak: array<string,mixed>, analiz: array<string,mixed>}|null
     */
    private function hazirAnaliz(array $kaynak, bool $yeniden): ?array
    {
        $kaynaklar = new KaynakRepository($this->db);
        $id        = (int) $kaynak['id'];

        if (($suren = $kaynaklar->suregelenAnaliz($id)) !== null) {
            return ['sonuc' => self::SURUYOR, 'kaynak' => $kaynak, 'analiz' => $suren];
        }

        if (!$yeniden && ($guncel = $kaynaklar->guncelTamamlanmisAnaliz($id)) !== null) {
            return ['sonuc' => self::ONBELLEKTEN, 'kaynak' => $kaynak, 'analiz' => $guncel];
        }

        return null;
    }

    /**
     * Günlük kotalar: kullanıcının bugün başlattığı analiz sayısı ve
     * bütün kullanıcıların bugün harcadığı token / tahmini maliyet.
     *
     * @throws AnalizReddedildi gunluk_sinir | gunluk_butce (429)
     */
    private function kotaKontrolu(): void
    {
        $muaf  = (bool) Ayarlar::get('analiz.yonetici_muaf', true) && (new TranskriptPolicy($this->user))->isAdmin();
        $sinir = $muaf ? 0 : (int) Ayarlar::get('analiz.gunluk_sinir', 10);

        $bugunku = 0;

        if ($sinir > 0) {
            $sorgu = $this->db->prepare('SELECT COUNT(*) FROM transkript_analizler WHERE kullanici_id = :ben AND created_at >= CURDATE()');
            $sorgu->execute([':ben' => (int) ($this->user?->id ?? 0)]);
            $bugunku = (int) $sorgu->fetchColumn();
        }

        $kullanim = $this->db->prepare(
            "SELECT COALESCE(SUM(giris_token + cikis_token), 0) AS token, COALESCE(SUM(maliyet_usd), 0) AS maliyet
               FROM ai_kullanim_kaydi WHERE modul = 'transkript' AND created_at >= CURDATE()"
        );
        $kullanim->execute();
        $bugun = $kullanim->fetch(PDO::FETCH_ASSOC) ?: [];

        $ret = self::kotaKarari(
            $bugunku,
            $sinir,
            (int) ($bugun['token'] ?? 0),
            (int) Ayarlar::get('analiz.gunluk_token_tavani', 0),
            (float) ($bugun['maliyet'] ?? 0),
            (float) Ayarlar::get('analiz.gunluk_maliyet_tavani', 0),
        );

        if ($ret !== null) {
            throw new AnalizReddedildi($ret['kod'], $ret['mesaj'], 429, max(60, strtotime('tomorrow') - time()));
        }
    }

    /**
     * Saf karar (test edilir). Sınırı 0 olan kota denetlenmez.
     *
     * @return array{kod: string, mesaj: string}|null
     */
    public static function kotaKarari(int $bugunku, int $sinir, int $token, int $tokenTavani, float $maliyet, float $maliyetTavani): ?array
    {
        if (($tokenTavani > 0 && $token >= $tokenTavani) || ($maliyetTavani > 0 && $maliyet >= $maliyetTavani)) {
            return [
                'kod'   => 'gunluk_butce',
                'mesaj' => 'Bugünkü yapay zekâ bütçesi doldu; yeni analizler yarın başlayabilir. Önceden analiz edilmiş videoların raporları açık.',
            ];
        }

        if ($sinir > 0 && $bugunku >= $sinir) {
            return [
                'kod'   => 'gunluk_sinir',
                'mesaj' => sprintf('Bugünkü analiz hakkınız doldu (%d / %d). Yarın yeniden deneyebilirsiniz; önceden analiz edilmiş videoları açmak hakkınızdan düşmez.', $bugunku, $sinir),
            ];
        }

        return null;
    }

    private function sinirKontrolu(): void
    {
        $sinir = (int) Ayarlar::get('analiz.saatlik_sinir', 20);

        if ($sinir <= 0 || ((bool) Ayarlar::get('analiz.yonetici_muaf', true) && (new TranskriptPolicy($this->user))->isAdmin())) {
            return;
        }

        $bekle = Throttle::attempt('transkript-analiz:' . ($this->user?->id ?? 0), $sinir, 3600);

        if ($bekle > 0) {
            throw new AnalizReddedildi(
                'rate_limit',
                sprintf('Saatlik analiz sınırına ulaştınız. %d dakika sonra yeniden deneyin.', (int) ceil($bekle / 60)),
                429,
                $bekle,
            );
        }
    }

    /**
     * @param  array<string,mixed> $kaynak
     * @return array{sonuc: string, kaynak: array<string,mixed>, analiz: array<string,mixed>}
     */
    private function ac(array $kaynak, bool $yeniden): array
    {
        $kaynaklar = new KaynakRepository($this->db);
        $analizler = new AnalizRepository($this->db);

        $this->db->beginTransaction();

        try {
            $kaynaklar->kilitle((int) $kaynak['id']);

            // Kilit beklenirken başka bir istek analizi açmış olabilir.
            $hazir = $this->hazirAnaliz($kaynak, $yeniden);

            if ($hazir === null) {
                $analizId = $analizler->olustur((int) $kaynak['id'], $this->user?->id);
                Queue::push(new KaynakBilgisiAl($analizId));
            }

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }

        return $hazir ?? [
            'sonuc'  => self::BASLATILDI,
            'kaynak' => $kaynak,
            'analiz' => $analizler->bul($analizId) ?? ['id' => $analizId, 'durum' => 'bekliyor', 'ilerleme' => 0],
        ];
    }
}
