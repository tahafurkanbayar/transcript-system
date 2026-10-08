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

use App\Core\Config;
use App\Core\Database;
use App\Core\Log\Logger;
use App\Core\Queue\Job;
use Modules\Transkript\Ai\AiHatasi;
use Modules\Transkript\Kaynak\KaynakHatasi;
use Modules\Transkript\Repositories\AiKullanimRepository;
use Modules\Transkript\Repositories\AnalizRepository;
use Modules\Transkript\Repositories\KaynakRepository;
use Modules\Transkript\Repositories\SegmentRepository;
use Modules\Transkript\Transcript\TranscriptHatasi;
use PDO;
use Throwable;

abstract class TranskriptIsi extends Job
{
    /** Bu işin aldığı aşama (kaynak, transkript, birlestirme, rapor). */
    protected const ASAMA = '';

    /** Analizi durduran, yeniden denenmeyecek AI hataları. */
    private const AI_AYAR_HATALARI = ['anahtar_yok', 'ayar_eksik', 'yetkisiz', 'model_bulunamadi'];

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

        $this->analizler()->hataylaBitir($this->analizId, $kod);

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

        $this->analizler()->hataylaBitir($this->analizId, $kod);

        Logger::warning('Analiz kalıcı hatayla bitti', [
            'analiz'  => $this->analizId,
            'is'      => static::class,
            'kod'     => $kod,
            'ayrinti' => $e->getMessage(),
        ], 'transkript');
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
            $e instanceof AiHatasi         => $e->yenidenDenenebilir() ? null
                : (in_array($e->kod, self::AI_AYAR_HATALARI, true) ? 'ai_ayar_eksik' : 'ai_api_hatasi'),
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
}
