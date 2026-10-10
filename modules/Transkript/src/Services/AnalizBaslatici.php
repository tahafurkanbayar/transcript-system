<?php
/**
 * =====================================================================
 *  AnalizBaslatici – "Analiz Et" isteğinin TEK yolu (panel + API)
 * ---------------------------------------------------------------------
 *  HTTP isteğinde uzun iş yapılmaz: yalnız kayıt açılır ve ilk iş
 *  (KaynakBilgisiAl) kuyruğa bırakılır. İstek milisaniyeler sürer.
 *
 *  SIRA (önce yetki, sonra girdi, sonra sahiplik, en son durum)
 *    1) yetki: TranskriptPolicy::canAnalyze (Pro üye, yönetici; planında
 *       açıksa üye) → 403, veritabanına gidilmez
 *    2) URL → 11 karakterlik video kimliği (UrlParser); geçersizse 422
 *    3) yeniden=1: yönetici her raporu, öteki yalnız KENDİ başlattığı
 *       güncel raporu yeniden analiz eder → değilse 403
 *    4) kaynak varsa
 *         analizi sürüyorsa          → SURUYOR (ikinci iş açılmaz)
 *         güncel tamamlanmış analizi → ONBELLEKTEN (AI'a gidilmez)
 *    4) yeni AI işi maliyetlidir: kullanıcının ÜYELİK PLANINA göre
 *       saatlik (Throttle) ve günlük sınır (Uyelik\Planlar::haklar),
 *       bütün kullanıcılar için günlük token / maliyet tavanı
 *       (kotaKontrolu; önbellekten dönen istek sayılmaz)
 *    5) video doğrulanmamışsa (kaynak yok ya da başlıksız) oEmbed ön
 *       kontrolü: yok / gizli video 422 ile reddedilir, KAYIT AÇILMAZ;
 *       varsa başlık, kanal ve küçük resim hemen yazılır. oEmbed'e
 *       ulaşılamazsa (en çok 4 sn) kontrol atlanır, iş kendisi denetler.
 *       Süresi bilinen ve planın (ya da sistemin) en uzun video
 *       sınırını aşan video da reddedilir (uzunlukHatasi).
 *    6) kaynak satırı kilitlenir, sürmekte olan analiz yeniden
 *       kontrol edilir; analiz kaydı ve ilk iş aynı işlemde açılır
 *       → BASLATILDI
 *
 *  Yeni analiz güncel DEĞİLDİR; tamamlanınca güncel olur ve eskisini
 *  güncellikten düşürür (AnalizRepository::raporuYaz). Yeniden analiz
 *  sürerken eski rapor görünmeye devam eder.
 *
 *  KALDIĞI YERDEN DEVAM (devamEt): kaynağın SON analizi geçici bir
 *  sebeple (kota, servis, ayar; HataKodlari::DEVAM_EDILEBILIR) durduysa
 *  "Analiz Et" / "Yeniden dene" yeni analiz açmaz, o analizi sürdürür:
 *  tamamlanan parçalar korunur, yalnız kalanlar AI'a gider. Yeni analiz
 *  sayılmadığı için günlük / saatlik kotaya girmez. YALNIZ analizi
 *  başlatan ya da yönetici sürdürür (TranskriptPolicy::canRetry);
 *  başkası aynı videoyu gönderirse kendi analizi açılır (kendi
 *  kotasından). Yönetici "Bekleyenleri yeniden dene" ile hepsini sürdürür.
 *
 *  KÜTÜPHANE: her başarılı istek videoyu isteyenin kütüphanesine ekler
 *  (transkript_kutuphane). Yeni ya da sürdürülen analiz "baslatti";
 *  önbellekten dönen ya da başkasının süren analizine düşen istek
 *  "onbellek" (AI çağrısı yok, kotadan düşmez).
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Services;

use App\Core\Env;
use App\Core\Log\Logger;
use App\Core\Queue\Job;
use App\Core\Queue\Queue;
use App\Core\Throttle;
use App\Models\User;
use Modules\Transkript\Ayarlar;
use Modules\Transkript\HataKodlari;
use Modules\Transkript\Jobs\AnalizBirlestir;
use Modules\Transkript\Jobs\KaynakBilgisiAl;
use Modules\Transkript\Jobs\ParcaAnalizEt;
use Modules\Transkript\Jobs\RaporuTamamla;
use Modules\Transkript\Jobs\TranskriptAl;
use Modules\Transkript\Jobs\TranskriptIsi;
use Modules\Transkript\Kaynak\KaynakHatasi;
use Modules\Transkript\Kaynak\UrlParser;
use Modules\Transkript\Kaynak\VideoBilgisi;
use Modules\Transkript\Kaynak\YoutubeMetadata;
use Modules\Transkript\Repositories\AnalizRepository;
use Modules\Transkript\Repositories\KaynakRepository;
use Modules\Transkript\Repositories\KutuphaneRepository;
use Modules\Transkript\TranskriptPolicy;
use Modules\Transkript\Uyelik\Planlar;
use PDO;
use Throwable;

final class AnalizBaslatici
{
    /**
     * Günlük haktan düşen analizler: kullanıcının bugün başlattıkları.
     * HİÇ yapay zekâ çağrısı yapılmadan biten (iptal edilen ya da kalıcı
     * hatayla düşen: video yok, altyazı yok, konuşma yok) analiz sayılmaz
     * (cowork 6. tur K4). Süren analiz sayılır. kotaKontrolu ve hakDurumu
     * aynı sorguyu kullanır.
     */
    public const BUGUN_SAYILAN = "SELECT COUNT(*) FROM transkript_analizler a
         WHERE a.kullanici_id = :ben AND a.created_at >= CURDATE()
           AND NOT (a.durum = 'hata' AND NOT EXISTS (
               SELECT 1 FROM ai_kullanim_kaydi u WHERE u.modul = 'transkript' AND u.ref_tur = 'analiz' AND u.ref_id = a.id))";

    public const BASLATILDI  = 'baslatildi';
    public const SURUYOR     = 'suruyor';
    public const ONBELLEKTEN = 'onbellekten';
    public const DEVAM_ETTI  = 'devam_etti';

    /** Durulan aşama → sürdürülürken yazılacak durum. */
    private const DEVAM_DURUMU = [
        'kaynak'      => 'bekliyor',
        'transkript'  => 'transkript_aliniyor',
        'parca'       => 'analiz_ediliyor',
        'birlestirme' => 'analiz_ediliyor',
        'rapor'       => 'analiz_ediliyor',
    ];

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
        $policy = new TranskriptPolicy($this->user);

        if (!$policy->canAnalyze()) {
            throw new AnalizReddedildi('yetkisiz', 'Analiz başlatma yetkiniz yok.', 403);
        }

        try {
            $videoId = UrlParser::cozumle($url);
        } catch (KaynakHatasi $e) {
            throw new AnalizReddedildi($e->kod, $e->kullaniciMesaji(), 422);
        }

        $kaynaklar = new KaynakRepository($this->db);
        $kaynak    = $kaynaklar->youtubeBul($videoId);

        if ($yeniden && !$policy->isAdmin()
            && !$policy->canReanalyze($kaynak !== null ? $kaynaklar->guncelTamamlanmisAnaliz((int) $kaynak['id']) : null)) {
            throw new AnalizReddedildi('yetkisiz', 'Yeniden analiz yalnızca raporu başlatan kişiye ve yöneticilere açıktır.', 403);
        }

        return $this->kutuphaneyeEkle($this->youtubeKaynagi($videoId, $kaynak, $yeniden));
    }

    /**
     * youtube()'un yetki ve girdi denetiminden sonraki kısmı.
     *
     * @param  array<string,mixed>|null $kaynak
     * @return array{sonuc: string, kaynak: array<string,mixed>, analiz: array<string,mixed>}
     *
     * @throws AnalizReddedildi
     */
    private function youtubeKaynagi(string $videoId, ?array $kaynak, bool $yeniden): array
    {
        $kaynaklar = new KaynakRepository($this->db);

        if ($kaynak !== null && ($hazir = $this->hazirAnaliz($kaynak, $yeniden)) !== null) {
            return $hazir;
        }

        // Son analiz geçici sebeple durduysa (ve başlatan bu kişiyse) yenisi açılmaz: kaldığı yerden sürer.
        if ($kaynak !== null && !$yeniden && ($devam = $this->devamEt($kaynak)) !== null) {
            return $devam;
        }

        $planDk = Planlar::haklar($this->user)['en_uzun_dk'];

        if ($kaynak !== null && ($uzun = self::uzunlukHatasi($kaynak['sure_sn'] !== null ? (int) $kaynak['sure_sn'] : null, $planDk)) !== null) {
            throw new AnalizReddedildi($uzun, $uzun === 'plan_video_uzun'
                ? sprintf('Bu video planınızın süre sınırını aşıyor (%s planında en çok %s). Daha uzun videolar için Üyeliğim sayfasından Pro\'ya geçebilirsiniz.', Planlar::haklar($this->user)['ad'], Planlar::sureMetni($planDk))
                : HataKodlari::mesaj('video_cok_uzun'), 422);
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
     * Sınırı aşıyor mu? $dakika verilmezse sistemin tavanı
     * (analiz.en_uzun_dk). Süre bilinmiyorsa (null) hayır; iş, süre
     * öğrenilince yeniden sorar (KaynakBilgisiAl, TranskriptAl).
     */
    public static function cokUzun(?int $sureSn, ?int $dakika = null): bool
    {
        $dakika ??= (int) Ayarlar::get('analiz.en_uzun_dk', 180);

        return $dakika > 0 && $sureSn !== null && $sureSn > $dakika * 60;
    }

    /**
     * Hangi sınır aşıldı? Sistemin tavanı → video_cok_uzun (herkese
     * söylenir), yalnız planın sınırı → plan_video_uzun (Pro'da analiz
     * edilebilir). $planDk: Planlar::haklar()['en_uzun_dk'] (sistem
     * tavanı zaten içinde). Aşılmadıysa null.
     */
    public static function uzunlukHatasi(?int $sureSn, int $planDk): ?string
    {
        return match (true) {
            self::cokUzun($sureSn)          => 'video_cok_uzun',
            self::cokUzun($sureSn, $planDk) => 'plan_video_uzun',
            default                         => null,
        };
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
        $sinir = Planlar::haklar($this->user)['gunluk_sinir'];

        $bugunku = 0;

        if ($sinir > 0) {
            $sorgu = $this->db->prepare(self::BUGUN_SAYILAN);
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
                'mesaj' => sprintf('Bugünkü analiz hakkınız doldu (%d / %d). Yarın yeniden deneyebilirsiniz; önceden analiz edilmiş videoları açmak hakkınızdan düşmez. Daha yüksek sınır için: Üyeliğim.', $bugunku, $sinir),
            ];
        }

        return null;
    }

    private function sinirKontrolu(): void
    {
        $sinir = Planlar::haklar($this->user)['saatlik_sinir'];

        if ($sinir <= 0) {
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
     * Kaynağın SON analizi geçici bir sebeple durduysa onu kaldığı yerden
     * sürdürür; değilse null. Kaynak satırı kilitlenir, durum ve işler
     * aynı işlemde yazılır (iki istek aynı analizi iki kez sürdüremez).
     *
     * @param  array<string,mixed> $kaynak
     * @return array{sonuc: string, kaynak: array<string,mixed>, analiz: array<string,mixed>}|null
     */
    public function devamEt(array $kaynak): ?array
    {
        $policy = new TranskriptPolicy($this->user);

        if (!$policy->canAnalyze()) {
            return null;
        }

        $analizler = new AnalizRepository($this->db);
        $analizId  = null;

        $this->db->beginTransaction();

        try {
            (new KaynakRepository($this->db))->kilitle((int) $kaynak['id']);

            $son   = $analizler->kaynaginSonAnalizi((int) $kaynak['id']);
            $asama = $son !== null ? explode(':', (string) $son['asama'])[0] : '';

            // Başkasının duran analizine dokunulmaz (yönetici hariç).
            if ($son !== null && $policy->canRetry($son)
                && $son['durum'] === 'hata' && HataKodlari::devamEdilebilir($son['hata_kodu'])
                && isset(self::DEVAM_DURUMU[$asama]) && $analizler->devamEttir((int) $son['id'], self::DEVAM_DURUMU[$asama])) {
                $analizId = (int) $son['id'];

                foreach ($this->devamIsleri($analizler, $analizId, $asama) as $is) {
                    Queue::push($is);
                }
            }

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }

        if ($analizId === null) {
            return null;
        }

        TranskriptIsi::ertelemeyiUnut($analizId);

        Logger::info('Analiz kaldığı yerden sürdürüldü', ['analiz' => $analizId, 'asama' => $asama, 'kullanici' => $this->user?->id], 'transkript');

        return ['sonuc' => self::DEVAM_ETTI, 'kaynak' => $kaynak, 'analiz' => $analizler->bul($analizId) ?? ['id' => $analizId, 'durum' => self::DEVAM_DURUMU[$asama], 'ilerleme' => 0]];
    }

    /**
     * Kullanıcının bugünkü hakkı (liste ve Kontrol Paneli satırı:
     * "Bugünkü hakkınız 3 / 10 · saatlik 1 / 20"). Sayaçlar kotaKontrolu
     * ve sinirKontrolu ile aynıdır; sayaç artırılmaz.
     *
     * @return array{muaf: bool, gunluk: int, gunluk_sinir: int, saatlik: int, saatlik_sinir: int, bekleme_sn: int, yenilenme: string, plan: ?string, plan_adi: string, en_uzun_dk: int}
     *   bekleme_sn > 0 → hak dolu; yenilenme "HH:MM" (saatlik) ya da "yarın 00:00" (günlük);
     *   plan / plan_adi / en_uzun_dk: Uyelik\Planlar::haklar()
     */
    public function hakDurumu(?int $simdi = null): array
    {
        $simdi ??= time();
        $haklar  = Planlar::haklar($this->user);
        $muaf    = $haklar['muaf'];
        $gunlukSinir  = $haklar['gunluk_sinir'];
        $saatlikSinir = $haklar['saatlik_sinir'];
        $gunluk  = 0;
        $saatlik = 0;

        if ($gunlukSinir > 0) {
            $sorgu = $this->db->prepare(self::BUGUN_SAYILAN);
            $sorgu->execute([':ben' => (int) ($this->user?->id ?? 0)]);
            $gunluk = (int) $sorgu->fetchColumn();
        }

        if ($saatlikSinir > 0) {
            $saatlik = $saatlikSinir - Throttle::remaining('transkript-analiz:' . ($this->user?->id ?? 0), $saatlikSinir, 3600);
        }

        return self::hakKarari($muaf, $gunluk, $gunlukSinir, $saatlik, $saatlikSinir, $simdi)
            + ['plan' => $haklar['plan'], 'plan_adi' => $haklar['ad'], 'en_uzun_dk' => $haklar['en_uzun_dk']];
    }

    /**
     * Saf karar (test edilir). Günlük hak dolduysa yenilenme gece yarısı;
     * yalnız saatlik dolduysa saat başı (Throttle'ın sabit penceresi).
     *
     * @return array{muaf: bool, gunluk: int, gunluk_sinir: int, saatlik: int, saatlik_sinir: int, bekleme_sn: int, yenilenme: string}
     */
    public static function hakKarari(bool $muaf, int $gunluk, int $gunlukSinir, int $saatlik, int $saatlikSinir, int $simdi): array
    {
        $bekleme   = 0;
        $yenilenme = '';

        if ($gunlukSinir > 0 && $gunluk >= $gunlukSinir) {
            $bekleme   = max(60, (int) strtotime('tomorrow', $simdi) - $simdi);
            $yenilenme = 'yarın 00:00';
        } elseif ($saatlikSinir > 0 && $saatlik >= $saatlikSinir) {
            $saatBasi  = (int) (floor($simdi / 3600) * 3600) + 3600;
            $bekleme   = max(1, $saatBasi - $simdi);
            $yenilenme = date('H:i', $saatBasi);
        }

        return [
            'muaf'          => $muaf,
            'gunluk'        => $gunluk,
            'gunluk_sinir'  => $gunlukSinir,
            'saatlik'       => max(0, $saatlik),
            'saatlik_sinir' => $saatlikSinir,
            'bekleme_sn'    => $bekleme,
            'yenilenme'     => $yenilenme,
        ];
    }

    /**
     * Başarılı isteğin videosunu isteyenin kütüphanesine ekler. Yeni ya da
     * sürdürülen analiz "baslatti", önbellek ve başkasının süren analizi
     * "onbellek".
     *
     * @param  array{sonuc: string, kaynak: array<string,mixed>, analiz: array<string,mixed>} $sonuc
     * @return array{sonuc: string, kaynak: array<string,mixed>, analiz: array<string,mixed>}
     */
    private function kutuphaneyeEkle(array $sonuc): array
    {
        if ($this->user !== null) {
            $baslatti = in_array($sonuc['sonuc'], [self::BASLATILDI, self::DEVAM_ETTI], true)
                || (int) ($sonuc['analiz']['kullanici_id'] ?? 0) === $this->user->id;

            (new KutuphaneRepository($this->db))->ekle(
                $this->user->id,
                (int) $sonuc['kaynak']['id'],
                $baslatti ? KutuphaneRepository::BASLATTI : KutuphaneRepository::ONBELLEK,
            );
        }

        return $sonuc;
    }

    /**
     * Durulan aşamanın işleri. Parça aşamasında yalnız bekleyen parçalar;
     * hepsi bittiyse birleştirme.
     *
     * @return list<Job>
     */
    private function devamIsleri(AnalizRepository $analizler, int $analizId, string $asama): array
    {
        return match ($asama) {
            'kaynak'      => [new KaynakBilgisiAl($analizId)],
            'transkript'  => [new TranskriptAl($analizId)],
            'birlestirme' => [new AnalizBirlestir($analizId)],
            'rapor'       => [new RaporuTamamla($analizId)],
            default       => ($bekleyen = $analizler->bekleyenParcalar($analizId)) !== []
                ? array_map(static fn (int $sira): Job => new ParcaAnalizEt($analizId, $sira), $bekleyen)
                : ($analizler->asamaGecisi($analizId, 'parca', 'birlestirme') ? [new AnalizBirlestir($analizId)] : []),
        };
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
