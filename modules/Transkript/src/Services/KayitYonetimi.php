<?php
/**
 * =====================================================================
 *  KayitYonetimi – kaydı silmek ve süren analizi iptal etmek
 * ---------------------------------------------------------------------
 *  Yetki burada değil TranskriptPolicy'de sorulur (canDeleteSource,
 *  canCancelAnalysis); bu sınıf yalnız işi yapar.
 *
 *  SİL: kaynak satırı silinir; analizler, parçalar, rapor maddeleri,
 *  transcript, segmentler, sohbetler yabancı anahtarların ON DELETE
 *  CASCADE kuralıyla gider. Kuyrukta BEKLEYEN ve başarısız işleri de
 *  aynı işlemde silinir. O anda ÇALIŞAN iş silinmez (işçinin elinde);
 *  bittiğinde analizi bulamaz ve hiçbir şey yazmadan çıkar.
 *  ai_kullanim_kaydi'na dokunulmaz: harcanan token ve maliyet kayıt
 *  silinse de istatistikte kalır.
 *
 *  YouTube kaynağı herkes için ortak önbellektir (sahip_id = 0): silinince
 *  bütün kullanıcılar için gider, yeniden analiz AI çağrısı gerektirir.
 *  Bu yüzden ortak kaynağı yalnız yönetici siler (policy).
 *
 *  İPTAL: analiz "hata" olur, kodu iptal_edildi. Bekleyen işleri silinir.
 *  Çalışan bir iş AI çağrısını bitirir ama sonucu analize işleyemez:
 *  AnalizRepository bitmiş analizin durumunu değiştirmez (ilerle,
 *  raporuYaz). "Yeniden dene" yeni bir analiz açar: transcript yeniden
 *  kullanılır (YouTube'a gidilmez), parçalar yeniden analiz edilir.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Services;

use App\Core\Config;
use Modules\Transkript\Repositories\AnalizRepository;
use PDO;
use Throwable;

final class KayitYonetimi
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @param  array<string,mixed> $kaynak transkript_kaynaklar satırı
     * @return array{analiz: int, is: int} silinen analiz ve kuyruk işi sayısı
     */
    public function sil(array $kaynak): array
    {
        $kaynakId = (int) $kaynak['id'];

        $sorgu = $this->db->prepare('SELECT id FROM transkript_analizler WHERE kaynak_id = :kaynak');
        $sorgu->execute([':kaynak' => $kaynakId]);
        $analizler = array_map('intval', $sorgu->fetchAll(PDO::FETCH_COLUMN));

        $this->db->beginTransaction();

        try {
            $isler = $this->bekleyenIsleriSil($analizler);
            $this->db->prepare('DELETE FROM transkript_kaynaklar WHERE id = :id')->execute([':id' => $kaynakId]);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }

        return ['analiz' => count($analizler), 'is' => $isler];
    }

    /** Analiz sürüyorsa iptal eder. @return bool false → zaten bitmişti */
    public function iptal(int $analizId): bool
    {
        $this->db->beginTransaction();

        try {
            $iptal = (new AnalizRepository($this->db))->hataylaBitir($analizId, 'iptal_edildi');

            if ($iptal) {
                $this->bekleyenIsleriSil([$analizId]);
            }

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }

        return $iptal;
    }

    /**
     * Bu analizlerin bekleyen ve başarısız "transkript" işlerini siler.
     * Yük JSON'dur ({"analiz_id":12,"parca":3}); kimlik PHP'de okunur,
     * LIKE ile metin aranmaz ("1" "12"yi yakalamasın).
     *
     * @param list<int> $analizIdleri
     */
    public function bekleyenIsleriSil(array $analizIdleri): int
    {
        if ($analizIdleri === []) {
            return 0;
        }

        $tablo = preg_replace('/[^a-zA-Z0-9_]/', '', (string) Config::get('queue.table', 'isler')) ?: 'isler';

        $sorgu = $this->db->prepare(
            "SELECT id, veri FROM `" . $tablo . "` WHERE kuyruk = 'transkript' AND durum IN ('bekliyor', 'basarisiz')"
        );
        $sorgu->execute();

        $silinecek = self::isleriSec($sorgu->fetchAll(PDO::FETCH_ASSOC), $analizIdleri);

        $sil = $this->db->prepare('DELETE FROM `' . $tablo . "` WHERE id = :id AND durum IN ('bekliyor', 'basarisiz')");

        foreach ($silinecek as $id) {
            $sil->execute([':id' => $id]);
        }

        return count($silinecek);
    }

    /**
     * Kuyruk satırlarından bu analizlere ait olanların kimlikleri.
     *
     * @param  list<array{id: int|string, veri: string}> $satirlar
     * @param  list<int>                                 $analizIdleri
     * @return list<int>
     */
    public static function isleriSec(array $satirlar, array $analizIdleri): array
    {
        $aranan = array_flip($analizIdleri);
        $secilen = [];

        foreach ($satirlar as $satir) {
            $yuk = json_decode((string) $satir['veri'], true);

            if (is_array($yuk) && isset($aranan[(int) ($yuk['analiz_id'] ?? 0)])) {
                $secilen[] = (int) $satir['id'];
            }
        }

        return $secilen;
    }
}
