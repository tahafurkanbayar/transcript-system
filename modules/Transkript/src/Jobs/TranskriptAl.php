<?php
/**
 * =====================================================================
 *  TranskriptAl – transcript, temizlik ve parçalama (5 → 20)
 * ---------------------------------------------------------------------
 *  Kaynağın transcript'i zaten varsa (aynı videonun yeniden analizi ya
 *  da iş yarıda kalıp yeniden başladıysa) sağlayıcıya gidilmez.
 *  Yoksa TranscriptZinciri; Gemini yedeği kullanıldıysa maliyeti
 *  ai_kullanim_kaydi'na yazılır.
 *
 *  Sonra Cleaner + Chunker; parçalar transkript_parcalar'a yazılır ve
 *  her parça için ParcaAnalizEt kuyruğa girer (aynı işlemde).
 *
 *  Hata: hız sınırı ve geçici sağlayıcı hatası yeniden denenir;
 *  "transcript yok" ve gizli / silinmiş video kalıcıdır.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Jobs;

use App\Core\Queue\Queue;
use Modules\Transkript\Ayarlar;
use Modules\Transkript\Kaynak\Kaynak;
use Modules\Transkript\Kaynak\KaynakHatasi;
use Modules\Transkript\Services\Chunker;
use Modules\Transkript\Services\Cleaner;
use Modules\Transkript\Transcript\TranscriptNotFound;
use Modules\Transkript\Transcript\TranscriptZinciri;

final class TranskriptAl extends TranskriptIsi
{
    protected const ASAMA = 'transkript';

    protected function calis(array $analiz): void
    {
        $kaynakId = (int) $analiz['kaynak_id'];
        $metinId  = (int) ($analiz['metin_id'] ?? 0);

        if ($metinId === 0) {
            $metinId = (int) ($this->segmentler()->sonMetin($kaynakId)['id'] ?? 0);
        }

        if ($metinId === 0) {
            $kaynak = $this->kaynaklar()->bul($kaynakId)
                ?? throw new KaynakHatasi('video_bulunamadi', 'Kaynak satırı yok: ' . $kaynakId);

            $sonuc = TranscriptZinciri::fromAyarlar()->getir(
                Kaynak::satirdan($kaynak),
                (array) Ayarlar::get('transcript.tercih_diller', ['tr'])
            );

            $metinId = $this->segmentler()->kaydet($kaynakId, $sonuc);
            $this->aiKullanim()->hepsiniKaydet($sonuc->aiKullanimi, 'analiz', $this->analizId);
        }

        $this->analizler()->ilerle($this->analizId, 'transkript_hazir', null, 15, ['metin_id' => $metinId]);

        $segmentler = $this->segmentler()->segmentler($metinId);

        if ($segmentler !== []) {
            // oEmbed süre vermez; liste ve rapor süreyi transcript'ten bilsin.
            $this->kaynaklar()->sureYoksaYaz($kaynakId, intdiv(max(array_map(static fn ($s): int => $s->bitisMs, $segmentler)), 1000));
        }

        $parcalar = Chunker::fromAyarlar()->parcala(Cleaner::fromAyarlar()->hazirla($segmentler));

        if ($parcalar === []) {
            throw new TranscriptNotFound('Temizlikten sonra transcript boş kaldı: metin ' . $metinId);
        }

        $this->birlikte(function () use ($parcalar): void {
            $this->analizler()->parcalariOlustur($this->analizId, $parcalar);
            $this->analizler()->ilerle($this->analizId, 'analiz_ediliyor', 'parca', 20);

            foreach ($parcalar as $parca) {
                Queue::push(new ParcaAnalizEt($this->analizId, $parca->sira));
            }
        });
    }
}
