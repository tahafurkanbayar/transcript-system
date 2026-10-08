<?php
/**
 * =====================================================================
 *  ParcaAnalizEt – tek parçanın map analizi (20 → 80)
 * ---------------------------------------------------------------------
 *  Yük: ['analiz_id' => 12, 'parca' => 7]. Birden çok işçi varsa
 *  parçalar paralel işlenir.
 *
 *  BİTMİŞ PARÇA YENİDEN AI'A GİTMEZ: parça önce "alınır"
 *  (bekliyor → isleniyor); tamamlanmış ya da başkasının elindeki parça
 *  alınamaz. 20 parçadan 17'si bittikten sonra hata olursa yeniden
 *  başlatmada yalnız kalan 3 parça işlenir.
 *
 *  HATA
 *    geçici (ağ, hız sınırı)  parça bırakılır, kuyruk yeniden dener (3 deneme)
 *    kalıcı (bozuk yanıt)     parça "hata" olur; rapor o parça olmadan,
 *                             uyarıyla tamamlanır
 *    AI ayarı yok (anahtar)   bütün analiz durur: hiçbir parça işlenemez
 *    denemeler tükendi        failed(): parça "hata", zincir sürer
 *
 *  SON PARÇA: biten (tamamlanan + hatalı) sayısı toplama ulaşınca
 *  AnalizBirlestir bir kez kuyruğa girer (atomik aşama geçişi
 *  parca → birlestirme; aynı anda biten iki parçadan yalnız biri koyar).
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Jobs;

use App\Core\Log\Logger;
use App\Core\Queue\Queue;
use Modules\Transkript\Repositories\AnalizRepository;
use Modules\Transkript\Services\Cleaner;
use Modules\Transkript\Services\Pipeline;
use Throwable;

final class ParcaAnalizEt extends TranskriptIsi
{
    public function __construct(int $analizId, private readonly int $parca)
    {
        parent::__construct($analizId);
    }

    public function toPayload(): array
    {
        return ['analiz_id' => $this->analizId, 'parca' => $this->parca];
    }

    public static function fromPayload(array $payload): static
    {
        return new static((int) ($payload['analiz_id'] ?? 0), (int) ($payload['parca'] ?? 0));
    }

    public function handle(): void
    {
        $analiz = $this->analizler()->bul($this->analizId);

        if ($analiz === null || in_array($analiz['durum'], AnalizRepository::BITMIS_DURUMLAR, true)) {
            return;
        }

        if (!$this->analizler()->parcayiAl($this->analizId, $this->parca, self::bayatSn())) {
            // Bitmiş ya da başka işçide. Birleştirme kaçmasın diye yine de bakılır.
            $this->sonParcaysaBirlestir();

            return;
        }

        try {
            $this->calis($analiz);
        } catch (Throwable $e) {
            $kod = self::kaliciKod($e);

            if ($kod === null) {
                $this->analizler()->parcayiBirak($this->analizId, $this->parca, $e->getMessage());

                throw $e;
            }

            $this->analizler()->parcayiHataylaBitir($this->analizId, $this->parca, $kod . ': ' . $e->getMessage());

            if ($kod === 'ai_ayar_eksik') {
                $this->kaliciHata($kod, $e);

                return;
            }

            Logger::warning('Parça kalıcı hatayla atlandı', [
                'analiz' => $this->analizId, 'parca' => $this->parca, 'kod' => $kod, 'ayrinti' => $e->getMessage(),
            ], 'transkript');
        }

        $this->ilerlemeyiYaz();
        $this->sonParcaysaBirlestir();
    }

    protected function calis(array $analiz): void
    {
        $parca = $this->analizler()->parca($this->analizId, $this->parca)
            ?? throw new \RuntimeException('Parça yok: ' . $this->analizId . '/' . $this->parca);

        $bloklar = Cleaner::fromAyarlar()->hazirla(
            $this->segmentler()->segmentler((int) $analiz['metin_id'], (int) $parca['baslangic_ms'], (int) $parca['bitis_ms'])
        );

        $sonuc = (new Pipeline())->parcaAnalizEt($parca, $bloklar);

        $this->aiKullanim()->hepsiniKaydet($sonuc['kullanim'], 'analiz', $this->analizId);
        $this->analizler()->parcayiBitir($this->analizId, $this->parca, $sonuc['sonuc']);
    }

    /** Denemeler tükendi: yalnız bu parça düşer, analiz sürer. */
    public function failed(Throwable $e): void
    {
        $this->analizler()->parcayiHataylaBitir($this->analizId, $this->parca, 'Denemeler tükendi: ' . $e->getMessage());

        Logger::error('Parça denemeleri tükendi; rapor bu parça olmadan tamamlanacak', [
            'analiz' => $this->analizId, 'parca' => $this->parca, 'ayrinti' => $e->getMessage(),
        ], 'transkript');

        $this->ilerlemeyiYaz();
        $this->sonParcaysaBirlestir();
    }

    private function ilerlemeyiYaz(): void
    {
        $sayi = $this->analizler()->parcaSayilari($this->analizId);

        $this->analizler()->ilerlemeYaz($this->analizId, 20 + intdiv(60 * $sayi['biten'], max(1, $sayi['toplam'])));
    }

    private function sonParcaysaBirlestir(): void
    {
        $sayi = $this->analizler()->parcaSayilari($this->analizId);

        if ($sayi['toplam'] === 0 || $sayi['biten'] < $sayi['toplam']) {
            return;
        }

        $this->birlikte(function (): void {
            if ($this->analizler()->asamaGecisi($this->analizId, 'parca', 'birlestirme')) {
                $this->analizler()->ilerlemeYaz($this->analizId, 80);
                Queue::push(new AnalizBirlestir($this->analizId));
            }
        });
    }
}
