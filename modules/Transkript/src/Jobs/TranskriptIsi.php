<?php
/**
 * =====================================================================
 *  TranskriptIsi – analiz işlerinin ortak tabanı
 * ---------------------------------------------------------------------
 *  İş zinciri (docs/TEKNIK_RAPOR.md, 7. bölüm):
 *
 *      KaynakBilgisiAl → TranskriptAl → ParcaAnalizEt × N
 *          → AnalizBirlestir → RaporuTamamla
 *
 *  YÜK YALNIZ KİMLİK TAŞIR: ['analiz_id' => 12] (parçada + 'parca').
 *  Gerisi veritabanından okunur; iş kuyrukta beklerken veri değişse de
 *  güncelini görür.
 *
 *  ORTAK AKIŞ (handle)
 *    1) analiz yoksa ya da bittiyse hiçbir şey yapmadan biter
 *    2) aşamayı ALIR (AnalizRepository::asamayiAl); alamazsa başka bir
 *       iş çalışıyordur ya da analiz ilerlemiştir → biter. Kuyruk aynı
 *       işi iki kez başlatsa da AI çağrısı iki kez yapılmaz.
 *    3) calis()
 *    4) KALICI hata (gizli video, transcript yok, AI anahtarı yok):
 *       analiz "hata" olur, iş BAŞARIYLA biter — yeniden denemek boşuna.
 *       GEÇİCİ hata (ağ, hız sınırı, 5xx): aşama bırakılır, istisna
 *       kuyruğa gider; kuyruk 60, 120, 240 sn arayla yeniden dener.
 *       Denemeler tükenirse failed() analizi "hata" yapar.
 *       YAPAY ZEKÂ DİNLENİYOR (zincirdeki hepsi kota / hız sınırında, ya
 *       da tek sağlayıcının günlük kotası doldu): hata DEĞİL. Kilit
 *       bırakılır, aynı iş en erken açılışa kadar ertelenir (Queue::later,
 *       deneme hakkı yeni); analiz "sürüyor" kalır, ekran devam saatini
 *       gösterir. En çok ai.yedek.erteleme_gun gün; sonra kalıcı hata.
 *
 *  DURUM VE SONRAKİ İŞ AYNI İŞLEMDE (birlikte): kuyruk aynı veritabanı
 *  bağlantısını kullanır; "durum ilerledi ama sonraki iş kuyruğa
 *  girmedi" (ya da tersi) ara hali oluşmaz.
 *
 *  Kuyruk adı "transkript": modülün zamanlanmış görevi (events.php)
 *  bu kuyruğu işler; çekirdeğin "kuyruk-isle" görevi de alabilir.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Jobs;

use App\Core\Cache\Cache;
use App\Core\Config;
use App\Core\Database;
use App\Core\Log\Logger;
use App\Core\Queue\Job;
use App\Core\Queue\Queue;
use App\Repositories\UserRepository;
use Modules\Transkript\Ai\Ai;
use Modules\Transkript\Ai\AiHatasi;
use Modules\Transkript\Ai\YedekliSaglayici;
use Modules\Transkript\Ayarlar;
use Modules\Transkript\Kaynak\KaynakHatasi;
use Modules\Transkript\Repositories\AiKullanimRepository;
use Modules\Transkript\Repositories\AnalizRepository;
use Modules\Transkript\Repositories\KaynakRepository;
use Modules\Transkript\Repositories\SegmentRepository;
use Modules\Transkript\Services\KuyrukDurumu;
use Modules\Transkript\Services\Pipeline;
use Modules\Transkript\Transcript\TranscriptHatasi;
use Modules\Transkript\Uyelik\Planlar;
use PDO;
use Throwable;

abstract class TranskriptIsi extends Job
{
    /** Bu işin aldığı aşama (kaynak, transkript, birlestirme, rapor). */
    protected const ASAMA = '';

    /** Analizi durduran, yeniden denenmeyecek AI hataları. */
    private const AI_AYAR_HATALARI = ['anahtar_yok', 'ayar_eksik', 'yetkisiz', 'model_bulunamadi', 'sartlar_onaylanmadi'];

    /** Bu kodlarla parça değil bütün analiz durur: kalan parçalar da başarısız olur. */
    public const ANALIZI_DURDURAN = ['ai_ayar_eksik', 'ai_kota_doldu'];

    /** Ertelenen analizin devam zamanı (Cache; KaynakDurumu gösterir). */
    public const ERTELEME_ONEKI = 'transkript.ertelendi.';

    /** Ertelemenin sebebi (AiHatasi::$sebep; '' = sağlayıcılar meşgul). */
    public const ERTELEME_SEBEBI_ONEKI = 'transkript.erteleme_sebebi.';

    /** İlk erteleme zamanı: erteleme_gun aşılınca iş hatayla biter. */
    private const ILK_ERTELEME_ONEKI = 'transkript.ilk_erteleme.';

    public function __construct(protected readonly int $analizId)
    {
    }

    public function queue(): string
    {
        return 'transkript';
    }

    public function toPayload(): array
    {
        return ['analiz_id' => $this->analizId];
    }

    public static function fromPayload(array $payload): static
    {
        return new static((int) ($payload['analiz_id'] ?? 0));
    }

    public function handle(): void
    {
        KuyrukDurumu::imle();

        $analiz = $this->analizler()->bul($this->analizId);

        if ($analiz === null || in_array($analiz['durum'], AnalizRepository::BITMIS_DURUMLAR, true)) {
            return;
        }

        if (!$this->analizler()->asamayiAl($this->analizId, static::ASAMA, self::bayatSn())) {
            return;
        }

        try {
            $this->calis($analiz);
        } catch (Throwable $e) {
            if ($this->erteleyebilir($e, fn () => $this->analizler()->asamayiBirak($this->analizId, static::ASAMA))) {
                return;
            }

            $kod = self::kaliciKod($e);

            if ($kod !== null) {
                $this->kaliciHata($kod, $e);

                return;
            }

            $this->analizler()->asamayiBirak($this->analizId, static::ASAMA);

            throw $e;
        }
    }

    /** @param array<string,mixed> $analiz */
    abstract protected function calis(array $analiz): void;

    /** Bütün denemeler tükendi. */
    public function failed(Throwable $e): void
    {
        $kod = self::hataKodu($e);

        $this->analizler()->hataylaBitir($this->analizId, $kod, self::zincirOzeti($e));

        Logger::error('Analiz işi denemeleri tükendi', [
            'analiz'  => $this->analizId,
            'is'      => static::class,
            'kod'     => $kod,
            'ayrinti' => $e->getMessage(),
        ], 'transkript');
    }

    /* =================================================================
     *  YARDIMCILAR
     * ============================================================== */

    /** Analizi kalıcı hatayla bitirir; gizli / silinmiş videoyu kaynakta da işaretler. */
    protected function kaliciHata(string $kod, Throwable $e): void
    {
        if ($e instanceof KaynakHatasi && in_array($e->kod, ['gizli_video', 'silinmis_video'], true)) {
            $analiz = $this->analizler()->bul($this->analizId);

            if ($analiz !== null) {
                $this->kaynaklar()->durumYaz((int) $analiz['kaynak_id'], $e->kod === 'gizli_video' ? 'gizli' : 'silinmis');
            }
        }

        $this->analizler()->hataylaBitir($this->analizId, $kod, self::zincirOzeti($e));

        Logger::warning('Analiz kalıcı hatayla bitti', [
            'analiz'  => $this->analizId,
            'is'      => static::class,
            'kod'     => $kod,
            'ayrinti' => $e->getMessage(),
        ], 'transkript');
    }

    /**
     * Yapay zekâ dinleniyorsa kaç saniye sonra yeniden denenecek;
     * ertelenecek bir durum değilse null.
     */
    public static function erteleme(Throwable $e, ?int $simdi = null): ?int
    {
        if (!$e instanceof AiHatasi) {
            return null;
        }

        return match ($e->kod) {
            'zincir_dinleniyor' => max(30, (int) $e->bekleme),
            // Tek sağlayıcı (zincir yok): günlük kota yenilenene kadar (saati sağlayıcı bildirdiyse o: EVREN 03:00).
            'kota_doldu'        => (int) $e->bekleme > 0 ? min((int) $e->bekleme, 2 * 86400) : YedekliSaglayici::kotaSifirlanmasinaKadar($simdi ?? time()),
            default             => null,
        };
    }

    /**
     * Yapay zekâ dinleniyorsa işi o saate kurar: kilit bırakılır ($birak),
     * aynı iş Queue::later ile yeniden kuyruğa girer (deneme hakkı yeni).
     * İlk ertelemeden bu yana erteleme_gun geçtiyse false: kalıcı hata.
     */
    protected function erteleyebilir(Throwable $e, callable $birak): bool
    {
        $sn = self::erteleme($e);

        if ($sn === null) {
            return false;
        }

        $simdi = time();
        $sinir = max(1, (int) Ayarlar::get('ai.yedek.erteleme_gun', 2)) * 86400;
        $ilk   = Cache::get(self::ILK_ERTELEME_ONEKI . $this->analizId);

        if (is_int($ilk) && $simdi - $ilk > $sinir) {
            return false;
        }

        if (!is_int($ilk)) {
            Cache::put(self::ILK_ERTELEME_ONEKI . $this->analizId, $simdi, $sinir + 86400);
        }

        // Açılıştan az sonra: aynı anda açılan sağlayıcıya hemen yüklenilmesin.
        $sn = min($sn + 15, $sinir);

        $this->birlikte(function () use ($birak, $sn): void {
            $birak();
            Queue::later($this, $sn);
            // Ertelenen süre işlem değil beklemedir ("Son analizler", K6).
            $this->analizler()->beklemeEkle($this->analizId, $sn);
        });

        Cache::put(self::ERTELEME_ONEKI . $this->analizId, $simdi + $sn, $sn + 3600);
        // Ekrandaki gerçek sebep: "meşgul" mü, yoksa girdi açık sağlayıcılara büyük mü?
        Cache::put(self::ERTELEME_SEBEBI_ONEKI . $this->analizId, $e instanceof AiHatasi ? (string) $e->sebep : '', $sn + 3600);

        Logger::info('Analiz ertelendi: yapay zekâ servisleri dinleniyor', [
            'analiz'  => $this->analizId,
            'is'      => static::class,
            'devam'   => date('Y-m-d H:i', $simdi + $sn),
            'ayrinti' => $e->getMessage(),
        ], 'transkript');

        return true;
    }

    /** Analiz elle sürdürülünce (AnalizBaslatici::devamEt) erteleme süresi yeniden başlar. */
    public static function ertelemeyiUnut(int $analizId): void
    {
        Cache::forget(self::ILK_ERTELEME_ONEKI . $analizId);
        Cache::forget(self::ERTELEME_ONEKI . $analizId);
        Cache::forget(self::ERTELEME_SEBEBI_ONEKI . $analizId);
    }

    /** Yapay zekâ hatasında yöneticiye zincirin özeti (sağlayıcı mesajı yok); değilse null. */
    private static function zincirOzeti(Throwable $e): ?string
    {
        if (!$e instanceof AiHatasi) {
            return null;
        }

        $ozet = YedekliSaglayici::ozet(Ai::zincirDurumu());

        return $ozet !== '' ? $ozet : null;
    }

    /** Durum güncellemesi ve sonraki işin kuyruğa girmesi tek işlemde. */
    protected function birlikte(callable $is): void
    {
        $db = $this->db();
        $db->beginTransaction();

        try {
            $is();
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Hata yeniden denenmeyecekse analizin hata kodu; geçiciyse null.
     */
    public static function kaliciKod(Throwable $e): ?string
    {
        return match (true) {
            $e instanceof KaynakHatasi     => $e->yenidenDenenebilir() ? null : $e->kod,
            $e instanceof TranscriptHatasi => $e->yenidenDenenebilir() ? null : $e->kod,
            $e instanceof AiHatasi         => $e->yenidenDenenebilir() ? null : match (true) {
                in_array($e->kod, self::AI_AYAR_HATALARI, true) => 'ai_ayar_eksik',
                // Erteleme süresi doldu (bkz. erteleyebilir): kota / dinlenme.
                $e->kod === 'kota_doldu', $e->kod === 'zincir_dinleniyor' => 'ai_kota_doldu',
                $e->kod === 'yanit_cozulemedi'                  => 'ai_yanit_gecersiz',
                default                                         => 'ai_api_hatasi',
            },
            default                        => null,
        };
    }

    /** Denemeler tükenince yazılacak kod. */
    public static function hataKodu(Throwable $e): string
    {
        return self::kaliciKod($e) ?? match (true) {
            $e instanceof KaynakHatasi, $e instanceof TranscriptHatasi => $e->kod,
            $e instanceof AiHatasi                                     => $e->kod === 'rate_limit' ? 'rate_limit' : 'ai_api_hatasi',
            default                                                    => 'beklenmeyen_hata',
        };
    }

    /** Takılı sayılma süresi: kuyruğun zaman aşımıyla aynı. */
    protected static function bayatSn(): int
    {
        return max(60, (int) Config::get('queue.timeout', 300));
    }

    protected function db(): PDO
    {
        return Database::connection();
    }

    /**
     * Analizi başlatanın planındaki en uzun video (dakika, sistem tavanı
     * içinde; Uyelik\Planlar::haklar). Başlatan yoksa ya da silindiyse
     * sistemin tavanı. 0 = sınır yok.
     *
     * @param array<string,mixed> $analiz
     */
    protected function planDakikasi(array $analiz): int
    {
        $id = (int) ($analiz['kullanici_id'] ?? 0);

        return Planlar::haklar($id > 0 ? (new UserRepository($this->db()))->find($id) : null)['en_uzun_dk'];
    }

    protected function analizler(): AnalizRepository
    {
        return new AnalizRepository($this->db());
    }

    protected function kaynaklar(): KaynakRepository
    {
        return new KaynakRepository($this->db());
    }

    protected function segmentler(): SegmentRepository
    {
        return new SegmentRepository($this->db());
    }

    protected function aiKullanim(): AiKullanimRepository
    {
        return new AiKullanimRepository($this->db());
    }

    protected function pipeline(): Pipeline
    {
        return new Pipeline($this->aiKullanim(), $this->analizId);
    }

    /**
     * İstemdeki video bilgisi: başlık, kanal, transcript dili.
     *
     * @param  array<string,mixed> $analiz
     * @return array<string,mixed>
     */
    protected function baglam(array $analiz): array
    {
        $kaynak = $this->kaynaklar()->bul((int) $analiz['kaynak_id']) ?? [];
        $metin  = $this->segmentler()->metin((int) ($analiz['metin_id'] ?? 0)) ?? [];

        return [
            'baslik' => $kaynak['baslik'] ?? null,
            'kanal'  => $kaynak['kanal_adi'] ?? null,
            'dil'    => $metin['dil'] ?? null,
        ];
    }
}
