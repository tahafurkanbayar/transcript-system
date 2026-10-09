<?php
/**
 * =====================================================================
 *  AnalizRepository – transkript_analizler, _parcalar, _analiz_ogeleri
 * ---------------------------------------------------------------------
 *  DURUM (kullanıcının gördüğü)  bekliyor → transkript_aliniyor →
 *      transkript_hazir → analiz_ediliyor → tamamlandi | hata
 *  AŞAMA (işlerin sırası)        kaynak → transkript → parca →
 *      birlestirme → rapor → bitti
 *
 *  ÇİFT ÇALIŞMAYA KİLİT: kuyruk aynı işi iki kez başlatabilir (işçi
 *  çökerse iş queue.timeout sonunda yeniden kuyruğa döner). Para
 *  harcayan her adım önce kendini "alır":
 *      asamayiAl()  aşama → "aşama:calisiyor"   (bir kişi alır)
 *      parcayiAl()  parça bekliyor → isleniyor (bir kişi alır)
 *  Alamayan iş hiçbir şey yapmadan biter. Çalışan çökerse kayıt
 *  queue.timeout kadar sonra "bayat" sayılır ve yeniden alınabilir.
 *  Geçici hatada bırakılır (asamayiBirak / parcayiBirak), kuyruk
 *  yeniden dener.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Repositories;

use Modules\Transkript\Services\Parca;
use PDO;

final class AnalizRepository
{
    public const BITMIS_DURUMLAR = ['tamamlandi', 'hata'];

    private const CALISIYOR = ':calisiyor';

    /** ilerle() ile yazılabilecek ek sütunlar. */
    private const EK_SUTUNLAR = ['metin_id', 'sonuc_json', 'kisa_ozet', 'detayli_ozet', 'prompt_surumu', 'model'];

    public function __construct(private readonly PDO $db)
    {
    }

    /** Yeni analiz: güncel değildir, tamamlanınca güncel olur (raporuYaz). */
    public function olustur(int $kaynakId, ?int $kullaniciId): int
    {
        $this->db->prepare(
            "INSERT INTO transkript_analizler (kaynak_id, kullanici_id, durum, asama, ilerleme, guncel_mi)
             VALUES (:kaynak, :kullanici, 'bekliyor', 'kaynak', 0, 0)"
        )->execute([':kaynak' => $kaynakId, ':kullanici' => $kullaniciId]);

        return (int) $this->db->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function bul(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM transkript_analizler WHERE id = :id');
        $statement->execute([':id' => $id]);

        $satir = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($satir) ? $satir : null;
    }

    /** @return array<string,mixed>|null */
    public function kaynaginSonAnalizi(int $kaynakId): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM transkript_analizler WHERE kaynak_id = :kaynak ORDER BY id DESC LIMIT 1');
        $statement->execute([':kaynak' => $kaynakId]);

        $satir = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($satir) ? $satir : null;
    }

    /**
     * Durum, aşama ve ilerlemeyi yazar. İlerleme geri gitmez (GREATEST);
     * başlangıç zamanı ilk ilerlemede bir kez yazılır. $asama null ise
     * aşamaya dokunulmaz (iş sürerken kendi kilidini bırakmasın).
     *
     * @param array<string,mixed> $ek metin_id, sonuc_json, kisa_ozet …
     */
    public function ilerle(int $id, string $durum, ?string $asama, int $ilerleme, array $ek = []): void
    {
        $sql = 'UPDATE transkript_analizler
                   SET durum = :durum, ilerleme = GREATEST(ilerleme, :ilerleme),
                       basladi_at = COALESCE(basladi_at, NOW())';
        $parametreler = [':durum' => $durum, ':ilerleme' => max(0, min(100, $ilerleme)), ':id' => $id];

        if ($asama !== null) {
            $sql .= ', asama = :asama';
            $parametreler[':asama'] = $asama;
        }

        foreach ($ek as $sutun => $deger) {
            if (in_array($sutun, self::EK_SUTUNLAR, true)) {
                $sql .= ', `' . $sutun . '` = :ek_' . $sutun;
                $parametreler[':ek_' . $sutun] = is_array($deger) ? json_encode($deger, JSON_UNESCAPED_UNICODE) : $deger;
            }
        }

        $this->db->prepare($sql . ' WHERE id = :id')->execute($parametreler);
    }

    /** Yalnız ilerleme yüzdesi (parçalar bittikçe). */
    public function ilerlemeYaz(int $id, int $ilerleme): void
    {
        $this->db->prepare('UPDATE transkript_analizler SET ilerleme = GREATEST(ilerleme, :ilerleme) WHERE id = :id')
            ->execute([':ilerleme' => max(0, min(100, $ilerleme)), ':id' => $id]);
    }

    /* =================================================================
     *  AŞAMA KİLİDİ
     * ============================================================== */

    /**
     * Aşamayı bu iş için alır. Aşama başkasındaysa ve bayat değilse ya da
     * analiz zaten ilerlediyse false.
     */
    public function asamayiAl(int $id, string $asama, int $bayatSn): bool
    {
        $statement = $this->db->prepare(
            "UPDATE transkript_analizler
                SET asama = :calisan
              WHERE id = :id AND durum NOT IN ('tamamlandi', 'hata')
                AND (asama = :asama OR (asama = :calisan2 AND updated_at < NOW() - INTERVAL :bayat SECOND))"
        );
        $statement->execute([
            ':calisan'  => $asama . self::CALISIYOR,
            ':calisan2' => $asama . self::CALISIYOR,
            ':asama'    => $asama,
            ':bayat'    => max(30, $bayatSn),
            ':id'       => $id,
        ]);

        return $statement->rowCount() === 1;
    }

    /** Geçici hatada aşamayı geri bırakır; kuyruk yeniden dener. */
    public function asamayiBirak(int $id, string $asama): void
    {
        $this->db->prepare('UPDATE transkript_analizler SET asama = :asama WHERE id = :id AND asama = :calisan')
            ->execute([':asama' => $asama, ':calisan' => $asama . self::CALISIYOR, ':id' => $id]);
    }

    /**
     * Tek seferlik geçiş (ör. son parça bitince parca → birlestirme).
     * Aynı anda iki iş denerse yalnız biri true alır.
     */
    public function asamaGecisi(int $id, string $eski, string $yeni): bool
    {
        $statement = $this->db->prepare(
            "UPDATE transkript_analizler SET asama = :yeni
              WHERE id = :id AND asama = :eski AND durum NOT IN ('tamamlandi', 'hata')"
        );
        $statement->execute([':yeni' => $yeni, ':eski' => $eski, ':id' => $id]);

        return $statement->rowCount() === 1;
    }

    /**
     * Kalıcı hata: analiz biter, kullanıcı hata koduna karşılık gelen
     * mesajı görür. Aşama kilidi bırakılır ("kaynak:calisiyor" → "kaynak"):
     * hangi aşamada durduğu okunur kalır.
     */
    public function hataylaBitir(int $id, string $kod): void
    {
        $this->db->prepare(
            "UPDATE transkript_analizler
                SET durum = 'hata', hata_kodu = :kod, bitti_at = NOW(),
                    asama = SUBSTRING_INDEX(asama, ':', 1)
              WHERE id = :id AND durum <> 'tamamlandi'"
        )->execute([':kod' => mb_substr($kod, 0, 50), ':id' => $id]);
    }

    /* =================================================================
     *  PARÇALAR
     * ============================================================== */

    /** @param list<Parca> $parcalar Var olan sıra numaraları atlanır (iş iki kez çalışırsa). */
    public function parcalariOlustur(int $analizId, array $parcalar): void
    {
        $statement = $this->db->prepare(
            'INSERT IGNORE INTO transkript_parcalar (analiz_id, sira, baslangic_ms, bitis_ms, tahmini_token)
             VALUES (:analiz, :sira, :bas, :bit, :token)'
        );

        foreach ($parcalar as $p) {
            $statement->execute([
                ':analiz' => $analizId,
                ':sira'   => $p->sira,
                ':bas'    => $p->baslangicMs,
                ':bit'    => $p->bitisMs,
                ':token'  => $p->tahminiToken,
            ]);
        }
    }

    /** @return list<array<string,mixed>> */
    public function parcalar(int $analizId): array
    {
        $statement = $this->db->prepare('SELECT * FROM transkript_parcalar WHERE analiz_id = :analiz ORDER BY sira');
        $statement->execute([':analiz' => $analizId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    public function parca(int $analizId, int $sira): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM transkript_parcalar WHERE analiz_id = :analiz AND sira = :sira');
        $statement->execute([':analiz' => $analizId, ':sira' => $sira]);

        $satir = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($satir) ? $satir : null;
    }

    /** Parçayı bu iş için alır: bitmişse ya da başkası (bayat olmadan) işliyorsa false. */
    public function parcayiAl(int $analizId, int $sira, int $bayatSn): bool
    {
        $statement = $this->db->prepare(
            "UPDATE transkript_parcalar
                SET durum = 'isleniyor', deneme = deneme + 1, updated_at = NOW()
              WHERE analiz_id = :analiz AND sira = :sira
                AND (durum = 'bekliyor' OR (durum = 'isleniyor' AND updated_at < NOW() - INTERVAL :bayat SECOND))"
        );
        $statement->execute([':analiz' => $analizId, ':sira' => $sira, ':bayat' => max(30, $bayatSn)]);

        return $statement->rowCount() === 1;
    }

    /** Geçici hata: parça yeniden "bekliyor"a döner, kuyruk yeniden dener. */
    public function parcayiBirak(int $analizId, int $sira, string $hata): void
    {
        $this->db->prepare(
            "UPDATE transkript_parcalar SET durum = 'bekliyor', hata = :hata
              WHERE analiz_id = :analiz AND sira = :sira AND durum = 'isleniyor'"
        )->execute([':hata' => mb_substr($hata, 0, 255), ':analiz' => $analizId, ':sira' => $sira]);
    }

    /** @param array<string,mixed> $sonuc map çıktısı (doğrulanmış) */
    public function parcayiBitir(int $analizId, int $sira, array $sonuc): void
    {
        $this->db->prepare(
            "UPDATE transkript_parcalar SET durum = 'tamamlandi', sonuc_json = :sonuc, hata = NULL
              WHERE analiz_id = :analiz AND sira = :sira"
        )->execute([':sonuc' => json_encode($sonuc, JSON_UNESCAPED_UNICODE), ':analiz' => $analizId, ':sira' => $sira]);
    }

    /** Kalıcı hata ya da denemeler tükendi: rapor bu parça olmadan, uyarıyla tamamlanır. */
    public function parcayiHataylaBitir(int $analizId, int $sira, string $hata): void
    {
        $this->db->prepare(
            "UPDATE transkript_parcalar SET durum = 'hata', hata = :hata
              WHERE analiz_id = :analiz AND sira = :sira AND durum <> 'tamamlandi'"
        )->execute([':hata' => mb_substr($hata, 0, 255), ':analiz' => $analizId, ':sira' => $sira]);
    }

    /** @return array{toplam:int,biten:int,hatali:int} biten = tamamlanan + hatalı */
    public function parcaSayilari(int $analizId): array
    {
        $statement = $this->db->prepare(
            "SELECT COUNT(*) AS toplam,
                    COALESCE(SUM(durum = 'tamamlandi'), 0) + COALESCE(SUM(durum = 'hata'), 0) AS biten,
                    COALESCE(SUM(durum = 'hata'), 0) AS hatali
               FROM transkript_parcalar WHERE analiz_id = :analiz"
        );
        $statement->execute([':analiz' => $analizId]);

        $satir = $statement->fetch(PDO::FETCH_ASSOC) ?: [];

        return ['toplam' => (int) ($satir['toplam'] ?? 0), 'biten' => (int) ($satir['biten'] ?? 0), 'hatali' => (int) ($satir['hatali'] ?? 0)];
    }

    /* =================================================================
     *  RAPOR
     * ============================================================== */

    /**
     * Doğrulanmış raporu yazar ve analizi güncel yapar. Tek işlemde:
     * yarım rapor kalmaz; iş iki kez çalışırsa maddeler yeniden yazılır.
     *
     * @param array<string,mixed>             $rapor  sonuc_json olarak saklanır
     * @param list<array<string,mixed>>       $ogeler transkript_analiz_ogeleri satırları
     * @param array{token:int,maliyet:float}  $toplam
     */
    public function raporuYaz(int $analizId, int $kaynakId, array $rapor, string $kisaOzet, string $detayliOzet, array $ogeler, array $toplam): void
    {
        $this->db->prepare('DELETE FROM transkript_analiz_ogeleri WHERE analiz_id = :analiz')->execute([':analiz' => $analizId]);

        $ekle = $this->db->prepare(
            'INSERT INTO transkript_analiz_ogeleri
                (analiz_id, tur, baslik, aciklama, baslangic_ms, bitis_ms, onem, kategori, dogrulama, sira)
             VALUES (:analiz, :tur, :baslik, :aciklama, :bas, :bit, :onem, :kategori, :dogrulama, :sira)'
        );

        foreach ($ogeler as $i => $oge) {
            $ekle->execute([
                ':analiz'    => $analizId,
                ':tur'       => $oge['tur'],
                ':baslik'    => mb_substr((string) ($oge['baslik'] ?? ''), 0, 500),
                ':aciklama'  => $oge['aciklama'] ?? null,
                ':bas'       => $oge['baslangic_ms'] ?? null,
                ':bit'       => $oge['bitis_ms'] ?? null,
                ':onem'      => $oge['onem'] ?? null,
                ':kategori'  => isset($oge['kategori']) ? mb_substr((string) $oge['kategori'], 0, 100) : null,
                ':dogrulama' => isset($oge['dogrulama']) ? mb_substr((string) $oge['dogrulama'], 0, 30) : null,
                ':sira'      => $i + 1,
            ]);
        }

        $this->db->prepare('UPDATE transkript_analizler SET guncel_mi = 0 WHERE kaynak_id = :kaynak AND id <> :analiz')
            ->execute([':kaynak' => $kaynakId, ':analiz' => $analizId]);

        $this->db->prepare(
            "UPDATE transkript_analizler
                SET durum = 'tamamlandi', asama = 'bitti', ilerleme = 100, guncel_mi = 1, hata_kodu = NULL,
                    sonuc_json = :sonuc, kisa_ozet = :kisa, detayli_ozet = :detayli,
                    toplam_token = :token, maliyet_usd = :maliyet, bitti_at = NOW()
              WHERE id = :analiz"
        )->execute([
            ':sonuc'   => json_encode($rapor, JSON_UNESCAPED_UNICODE),
            ':kisa'    => $kisaOzet,
            ':detayli' => $detayliOzet,
            ':token'   => $toplam['token'],
            ':maliyet' => $toplam['maliyet'],
            ':analiz'  => $analizId,
        ]);
    }

    /* =================================================================
     *  İLERLEME (panel her 3 sn'de bir çeker; küçük ve hızlı olmalı)
     * ============================================================== */

    /** @return array<string,mixed>|null Kaynağın son analizinin durumu */
    public function ilerlemeBilgisi(int $kaynakId): ?array
    {
        $analiz = $this->kaynaginSonAnalizi($kaynakId);

        if ($analiz === null) {
            return null;
        }

        $asama = (string) $analiz['asama'];

        return [
            'analiz_id' => (int) $analiz['id'],
            'durum'     => (string) $analiz['durum'],
            'asama'     => str_contains($asama, ':') ? strstr($asama, ':', true) : $asama,
            'calisiyor' => str_ends_with($asama, self::CALISIYOR),
            'ilerleme'  => (int) $analiz['ilerleme'],
            'parca'     => $this->parcaSayilari((int) $analiz['id']),
            'hata_kodu' => $analiz['hata_kodu'] !== null ? (string) $analiz['hata_kodu'] : null,
            'guncel_mi' => (bool) $analiz['guncel_mi'],
            // Sırada bekleme süresi; veritabanı oturumu uygulamanın saat dilimindedir.
            'gecen_sn'  => max(0, time() - (int) strtotime((string) $analiz['created_at'])),
        ];
    }
}
