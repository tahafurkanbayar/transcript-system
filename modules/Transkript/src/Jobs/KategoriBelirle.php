<?php
/**
 * =====================================================================
 *  KategoriBelirle – raporu biten videonun kategorisi (Kategori\Kategoriler)
 * ---------------------------------------------------------------------
 *  RaporuTamamla'dan sonra kuyruğa girer (config kategori.otomatik) ya da
 *  bin/kategori-doldur.php ile. Yük yalnız kimlik: ['analiz_id' => 12].
 *
 *  Rapor ZİNCİRİN DIŞINDADIR: bu iş başarısız olsa da analiz "tamamlandı"
 *  kalır, rapor değişmez; kategori boş kalır, sonra doldurulabilir.
 *
 *  AI'A İKİ KEZ GİTMEZ
 *    - kaynağın kategorisi bu analizden zaten çıkarıldıysa ya da yönetici
 *      elle düzelttiyse ("elle") çağrı yapılmaz
 *    - aynı kaynak için aynı anda tek iş (veritabanı kilidi GET_LOCK)
 *    - iş nabzı kuyruk satırını tazeler (uzun çağrıda "takıldı" sayılıp
 *      ikinci kez başlatılmaz)
 *
 *  HATA: yapay zekâ dinleniyorsa iş açılışa ertelenir (en çok 3 kez);
 *  kalıcı hata (anahtar yok, yanıt çözülemedi …) günlüğe yazılır, iş
 *  biter; geçici hata kuyruğun yeniden denemesine bırakılır (2 deneme).
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Jobs;

use App\Core\Database;
use App\Core\Log\Logger;
use App\Core\Queue\Job;
use App\Core\Queue\Queue;
use Modules\Transkript\Ai\AiHatasi;
use Modules\Transkript\Kategori\Kategoriler;
use Modules\Transkript\Repositories\AiKullanimRepository;
use Modules\Transkript\Repositories\AnalizRepository;
use Modules\Transkript\Repositories\KategoriRepository;
use Modules\Transkript\Repositories\KaynakRepository;
use Modules\Transkript\Repositories\SegmentRepository;
use Modules\Transkript\Services\KuyrukDurumu;
use Modules\Transkript\Services\Pipeline;
use PDO;
use Throwable;

final class KategoriBelirle extends Job
{
    /** Yapay zekâ dinlenirken en çok kaç kez ertelenir. */
    private const ERTELEME_EN_COK = 3;

    public function __construct(
        public readonly int $analizId,
        private readonly int $erteleme = 0,
        private readonly ?PDO $baglanti = null,
        /** Testler sahte Pipeline verir. */
        private readonly ?Pipeline $pipeline = null,
    ) {
    }

    public function queue(): string
    {
        return KuyrukDurumu::KUYRUK;
    }

    public function tries(): int
    {
        return 2;
    }

    public function backoff(): int
    {
        return 120;
    }

    public function toPayload(): array
    {
        return ['analiz_id' => $this->analizId] + ($this->erteleme > 0 ? ['erteleme' => $this->erteleme] : []);
    }

    public static function fromPayload(array $payload): static
    {
        return new static((int) ($payload['analiz_id'] ?? 0), (int) ($payload['erteleme'] ?? 0));
    }

    public function handle(): void
    {
        $db       = $this->baglanti ?? Database::connection();
        $analizler = new AnalizRepository($db);
        $analiz   = $analizler->bul($this->analizId);

        if ($analiz === null || $analiz['durum'] !== 'tamamlandi') {
            return;
        }

        $kaynakId   = (int) $analiz['kaynak_id'];
        $kategoriler = new KategoriRepository($db);

        if (self::gerekmez($kategoriler->bul($kaynakId), $this->analizId)) {
            return;
        }

        $kilit = 'transkript_kategori_' . $kaynakId;

        if ($this->baglanti === null && (int) $db->query('SELECT GET_LOCK(' . $db->quote($kilit) . ', 0)')->fetchColumn() !== 1) {
            return;
        }

        IsNabzi::basla(function () use ($db): void {
            $db->prepare('UPDATE `' . KuyrukDurumu::tablo() . "` SET ayrildi_at = NOW() WHERE durum = 'calisiyor' AND sinif = :sinif AND veri = :veri")
                ->execute([':sinif' => self::class, ':veri' => (string) json_encode($this->toPayload(), JSON_UNESCAPED_UNICODE)]);
        });

        try {
            // Kilidi beklerken başka iş yazmış olabilir.
            if (self::gerekmez($kategoriler->bul($kaynakId), $this->analizId)) {
                return;
            }

            $kaynak = (new KaynakRepository($db))->bul($kaynakId);
            $rapor  = json_decode((string) ($analiz['sonuc_json'] ?? ''), true);

            if ($kaynak === null || !is_array($rapor)) {
                return;
            }

            $metin = (new SegmentRepository($db))->sonMetin($kaynakId);
            $girdi = Kategoriler::girdi($kaynak, $rapor, (string) ($metin['dil'] ?? ''));

            try {
                $sonuc = ($this->pipeline ?? new Pipeline(new AiKullanimRepository($db), $this->analizId, analizler: $analizler))->kategoriBelirle($girdi);
            } catch (AiHatasi $e) {
                $this->aiHatasi($e);

                return;
            }

            $kategoriler->yaz($kaynakId, $this->analizId, $sonuc['sonuc'], KategoriRepository::AI, Kategoriler::ISTEM_SURUMU, (string) $sonuc['model']);

            Logger::info('Video kategorisi belirlendi', [
                'kaynak' => $kaynakId,
                'analiz' => $this->analizId,
                'tur'    => $sonuc['sonuc']['icerik_turu'],
                'alan'   => $sonuc['sonuc']['konu_alani'],
                'model'  => $sonuc['model'],
            ], 'transkript');
        } finally {
            IsNabzi::bitir();

            if ($this->baglanti === null) {
                $db->query('SELECT RELEASE_LOCK(' . $db->quote($kilit) . ')');
            }
        }
    }

    public function failed(Throwable $e): void
    {
        Logger::warning('Video kategorisi belirlenemedi (denemeler tükendi); rapor etkilenmez', [
            'analiz'  => $this->analizId,
            'ayrinti' => $e->getMessage(),
        ], 'transkript');
    }

    /**
     * Çağrı gereksiz mi: yönetici elle düzelttiyse ya da bu analizden
     * zaten çıkarıldıysa.
     *
     * @param array<string,mixed>|null $mevcut
     */
    public static function gerekmez(?array $mevcut, int $analizId): bool
    {
        return $mevcut !== null
            && ($mevcut['belirleyen'] === KategoriRepository::ELLE || (int) ($mevcut['analiz_id'] ?? 0) === $analizId);
    }

    /** @throws AiHatasi geçici hatada (kuyruk yeniden dener) */
    private function aiHatasi(AiHatasi $e): void
    {
        $sn = TranskriptIsi::erteleme($e);

        if ($sn !== null && $this->erteleme < self::ERTELEME_EN_COK) {
            Queue::later(new self($this->analizId, $this->erteleme + 1), $sn);

            return;
        }

        if ($sn !== null || TranskriptIsi::kaliciKod($e) !== null) {
            Logger::warning('Video kategorisi belirlenemedi; rapor etkilenmez', [
                'analiz'  => $this->analizId,
                'kod'     => $e->kod,
                'ayrinti' => $e->getMessage(),
            ], 'transkript');

            return;
        }

        throw $e;
    }
}
