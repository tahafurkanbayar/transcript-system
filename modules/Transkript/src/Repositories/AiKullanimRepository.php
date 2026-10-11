<?php
/**
 * =====================================================================
 *  AiKullanimRepository – ai_kullanim_kaydi tablosu
 * ---------------------------------------------------------------------
 *  Her AI / ASR / ücretli transcript çağrısı bir satırdır: model,
 *  token, maliyet, süre, sonuç. Analizin toplam token ve maliyeti
 *  buradan hesaplanır (ref_tur = 'analiz', ref_id = analiz_id).
 *  Tablonun adı geneldir; modul sütunu satırın sahibini söyler.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Repositories;

use Modules\Transkript\Ai\Ai;
use PDO;

final class AiKullanimRepository
{
    public const MODUL = 'transkript';

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @param array{saglayici:string,model:string,giris_token:int,cikis_token:int,maliyet_usd:float,sure_ms:int} $kayit
     * @param ?string $hataKodu    başarısız çağrının kodu (AiHatasi::$kod); günlük ekranı için
     * @param ?string $hataAyrinti sağlayıcının mesajı; anahtarlar maskelenir, 500 karakter (yalnız yönetici görür)
     */
    public function kaydet(array $kayit, string $refTur, ?int $refId, string $durum = 'basarili', ?string $hataKodu = null, ?string $hataAyrinti = null): void
    {
        $ayrinti = $hataAyrinti !== null ? trim(mb_substr(Ai::maskele(preg_replace('/\s+/u', ' ', $hataAyrinti) ?? ''), 0, 500)) : '';

        $this->db->prepare(
            'INSERT INTO ai_kullanim_kaydi
                (modul, ref_tur, ref_id, saglayici, model, giris_token, cikis_token, maliyet_usd, sure_ms, durum, hata_kodu, hata_ayrinti)
             VALUES (:modul, :ref_tur, :ref_id, :saglayici, :model, :giris, :cikis, :maliyet, :sure, :durum, :hata_kodu, :hata_ayrinti)'
        )->execute([
            ':hata_kodu' => $hataKodu !== null && $hataKodu !== '' ? mb_substr($hataKodu, 0, 40) : null,
            ':hata_ayrinti' => $ayrinti !== '' ? $ayrinti : null,
            ':modul'     => self::MODUL,
            ':ref_tur'   => $refTur,
            ':ref_id'    => $refId,
            ':saglayici' => mb_substr((string) $kayit['saglayici'], 0, 50),
            ':model'     => mb_substr((string) $kayit['model'], 0, 100),
            ':giris'     => max(0, (int) $kayit['giris_token']),
            ':cikis'     => max(0, (int) $kayit['cikis_token']),
            ':maliyet'   => max(0.0, (float) $kayit['maliyet_usd']),
            ':sure'      => max(0, (int) $kayit['sure_ms']),
            ':durum'     => $durum,
        ]);
    }

    /** @param list<array{saglayici:string,model:string,giris_token:int,cikis_token:int,maliyet_usd:float,sure_ms:int}> $kayitlar */
    public function hepsiniKaydet(array $kayitlar, string $refTur, ?int $refId): void
    {
        foreach ($kayitlar as $kayit) {
            $this->kaydet($kayit, $refTur, $refId);
        }
    }

    /**
     * Sağlayıcı başına son başarı, son hata (zaman + kod), bugünkü çağrı
     * ve token sayısı (Transkript ayarları → yapay zekâ sağlayıcıları).
     *
     * @return array<string, array{son_basari:?string, son_hata:?string, son_hata_kodu:?string, bugun_cagri:int, bugun_token:int}>
     */
    public function saglayiciOzeti(): array
    {
        $statement = $this->db->prepare(
            "SELECT saglayici,
                    MAX(CASE WHEN durum = 'basarili' THEN created_at END) AS son_basari,
                    MAX(CASE WHEN durum <> 'basarili' THEN created_at END) AS son_hata,
                    COALESCE(SUM(created_at >= CURDATE()), 0) AS bugun_cagri,
                    COALESCE(SUM(CASE WHEN created_at >= CURDATE() THEN giris_token + cikis_token ELSE 0 END), 0) AS bugun_token
               FROM ai_kullanim_kaydi WHERE modul = :modul GROUP BY saglayici"
        );
        $statement->execute([':modul' => self::MODUL]);

        $ozet = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $satir) {
            $ozet[(string) $satir['saglayici']] = [
                'son_basari'    => $satir['son_basari'] !== null ? (string) $satir['son_basari'] : null,
                'son_hata'      => $satir['son_hata'] !== null ? (string) $satir['son_hata'] : null,
                'son_hata_kodu' => null,
                'son_hata_ayrinti' => null,
                'son_model'     => null,
                'bugun_cagri'   => (int) $satir['bugun_cagri'],
                'bugun_token'   => (int) $satir['bugun_token'],
            ];
        }

        $kodlar = $this->db->prepare(
            "SELECT u.saglayici, u.hata_kodu, u.hata_ayrinti, u.durum
               FROM ai_kullanim_kaydi u
               JOIN (SELECT saglayici, MAX(id) AS id FROM ai_kullanim_kaydi
                      WHERE modul = :modul AND durum <> 'basarili' GROUP BY saglayici) s ON s.id = u.id"
        );
        $kodlar->execute([':modul' => self::MODUL]);

        foreach ($kodlar->fetchAll(PDO::FETCH_ASSOC) as $satir) {
            if (isset($ozet[(string) $satir['saglayici']])) {
                $ozet[(string) $satir['saglayici']]['son_hata_kodu']    = (string) ($satir['hata_kodu'] ?? $satir['durum']);
                $ozet[(string) $satir['saglayici']]['son_hata_ayrinti'] = $satir['hata_ayrinti'] !== null ? (string) $satir['hata_ayrinti'] : null;
            }
        }

        // Son başarılı çağrının modeli ("auto → zai/glm-5.3-fp8": gerçekte hangi model yanıtladı).
        $modeller = $this->db->prepare(
            "SELECT u.saglayici, u.model
               FROM ai_kullanim_kaydi u
               JOIN (SELECT saglayici, MAX(id) AS id FROM ai_kullanim_kaydi
                      WHERE modul = :modul AND durum = 'basarili' GROUP BY saglayici) s ON s.id = u.id"
        );
        $modeller->execute([':modul' => self::MODUL]);

        foreach ($modeller->fetchAll(PDO::FETCH_ASSOC) as $satir) {
            if (isset($ozet[(string) $satir['saglayici']])) {
                $ozet[(string) $satir['saglayici']]['son_model'] = (string) $satir['model'];
            }
        }

        return $ozet;
    }

    /**
     * Son çağrılar (Transkript ayarları → çağrı günlüğü). Analizin kaynağı
     * bağlantı için eklenir.
     *
     * @return list<array<string,mixed>>
     */
    public function sonCagrilar(int $adet = 50): array
    {
        $statement = $this->db->prepare(
            "SELECT u.id, u.created_at, u.saglayici, u.model, u.ref_tur, u.ref_id, u.giris_token, u.cikis_token,
                    u.sure_ms, u.durum, u.hata_kodu, u.hata_ayrinti, a.kaynak_id
               FROM ai_kullanim_kaydi u
               LEFT JOIN transkript_analizler a ON u.ref_tur = 'analiz' AND a.id = u.ref_id
              WHERE u.modul = :modul
              ORDER BY u.id DESC
              LIMIT :adet"
        );
        $statement->bindValue(':modul', self::MODUL);
        $statement->bindValue(':adet', max(1, min(200, $adet)), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{token:int,maliyet:float} Analizin toplamı */
    public function analizToplami(int $analizId): array
    {
        $statement = $this->db->prepare(
            "SELECT COALESCE(SUM(giris_token + cikis_token), 0) AS token, COALESCE(SUM(maliyet_usd), 0) AS maliyet
               FROM ai_kullanim_kaydi WHERE modul = :modul AND ref_tur = 'analiz' AND ref_id = :analiz"
        );
        $statement->execute([':modul' => self::MODUL, ':analiz' => $analizId]);

        $satir = $statement->fetch(PDO::FETCH_ASSOC) ?: [];

        return ['token' => (int) ($satir['token'] ?? 0), 'maliyet' => round((float) ($satir['maliyet'] ?? 0), 8)];
    }
}
