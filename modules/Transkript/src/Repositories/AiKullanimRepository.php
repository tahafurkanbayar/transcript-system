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
     * Bir çağrının kaydı. $kayit zorunlu: saglayici, model, giris_token,
     * cikis_token, maliyet_usd, sure_ms. İsteğe bağlı (EVREN geliştirme,
     * P1-1): baslangic (unix, kesirli; created_at = çağrının KENDİ
     * başlangıcı), asama, istenen_model, ve "olcum" dizisi (akis,
     * max_token, bitis_nedeni, dusunme_token, onbellek_token,
     * ilk_token_ms, http, kalan_kota). Zamanlar SQL'in saatinden geriye
     * sayılır (PHP ile MySQL saat dilimi farklı olsa da tutarlı).
     *
     * @param array<string,mixed> $kayit
     */
    public function kaydet(array $kayit, string $refTur, ?int $refId, string $durum = 'basarili', ?string $hataKodu = null, ?string $hataAyrinti = null): void
    {
        $ayrinti = $hataAyrinti !== null ? trim(mb_substr(Ai::maskele(preg_replace('/\s+/u', ' ', $hataAyrinti) ?? ''), 0, 500)) : '';
        $o       = is_array($kayit['olcum'] ?? null) ? $kayit['olcum'] : [];
        $simdi   = microtime(true);
        $sure    = max(0, (int) $kayit['sure_ms']);
        $bas     = is_numeric($kayit['baslangic'] ?? null) ? (float) $kayit['baslangic'] : $simdi - $sure / 1000;
        $tamsayi = static fn (mixed $v): ?int => is_numeric($v) && (int) $v >= 0 ? (int) $v : null;
        $metin   = static fn (mixed $v, int $en): ?string => is_string($v) && trim($v) !== '' ? mb_substr(trim($v), 0, $en) : null;

        $this->db->prepare(
            'INSERT INTO ai_kullanim_kaydi
                (modul, ref_tur, ref_id, saglayici, model, giris_token, cikis_token, maliyet_usd, sure_ms, durum, hata_kodu, hata_ayrinti,
                 created_at, bitis_at, asama, istenen_model, akis, max_token, bitis_nedeni, dusunme_token, onbellek_token, ilk_token_ms, http_durum, kalan_kota, tahmini)
             VALUES (:modul, :ref_tur, :ref_id, :saglayici, :model, :giris, :cikis, :maliyet, :sure, :durum, :hata_kodu, :hata_ayrinti,
                 NOW() - INTERVAL :bas_once SECOND, NOW() - INTERVAL :bit_once SECOND, :asama, :istenen, :akis, :max_token, :bitis_nedeni,
                 :dusunme, :onbellek, :ilk_token, :http, :kalan, :tahmini)'
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
            ':sure'      => $sure,
            ':durum'     => $durum,
            ':bas_once'  => max(0, (int) round($simdi - $bas)),
            ':bit_once'  => max(0, (int) round($simdi - ($bas + $sure / 1000))),
            ':asama'     => $metin($kayit['asama'] ?? null, 20),
            ':istenen'   => $metin($kayit['istenen_model'] ?? $o['istenen_model'] ?? null, 100),
            ':akis'      => isset($o['akis']) ? (int) (bool) $o['akis'] : null,
            ':max_token' => $tamsayi($o['max_token'] ?? null),
            ':bitis_nedeni' => $metin($o['bitis_nedeni'] ?? null, 30),
            ':dusunme'   => $tamsayi($o['dusunme_token'] ?? null),
            ':onbellek'  => $tamsayi($o['onbellek_token'] ?? null),
            ':ilk_token' => $tamsayi($o['ilk_token_ms'] ?? null),
            ':http'      => $tamsayi($o['http'] ?? null) ?: null,
            ':kalan'     => $tamsayi($o['kalan_kota'] ?? null),
            // Kesilen çağrı: token sağlayıcının değil, tahmin (EvrenProvider::kismiKullanim).
            ':tahmini'   => !empty($o['tahmini']) ? 1 : 0,
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
            "SELECT u.id, u.created_at, u.bitis_at, u.saglayici, u.model, u.istenen_model, u.asama, u.ref_tur, u.ref_id, u.giris_token, u.cikis_token,
                    u.dusunme_token, u.onbellek_token, u.ilk_token_ms, u.akis, u.bitis_nedeni, u.kalan_kota, u.tahmini,
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

    /**
     * Sağlayıcının modelleri, son $gun günün çağrılarından (Durum → "EVREN
     * model sağlığı"): çağrı, başarı oranı, ortalama / p95 süre, zaman aşımı,
     * düşünme bütçesi, ortalama ilk token, token ve önbellek oranı. "auto"
     * çağrıları yanıt veren modele göre sayılır (yanıt yoksa "auto").
     *
     * @param  ?Closure(string): string $kisalt model adını sadeleştirir (EvrenProvider::kisaAd)
     * @return list<array<string,mixed>> çağrı sayısına göre azalan
     */
    public function modelSagligi(string $saglayici, int $gun = 7, ?\Closure $kisalt = null): array
    {
        $statement = $this->db->prepare(
            'SELECT model, durum, hata_kodu, sure_ms, giris_token, cikis_token, onbellek_token, ilk_token_ms
               FROM ai_kullanim_kaydi
              WHERE modul = :modul AND saglayici = :saglayici AND created_at >= NOW() - INTERVAL :gun DAY
              ORDER BY id DESC LIMIT 5000'
        );
        $statement->bindValue(':modul', self::MODUL);
        $statement->bindValue(':saglayici', $saglayici);
        $statement->bindValue(':gun', max(1, $gun), PDO::PARAM_INT);
        $statement->execute();

        return self::saglikOzeti($statement->fetchAll(PDO::FETCH_ASSOC), $kisalt);
    }

    /**
     * Saf özet (test edilir).
     *
     * @param  list<array<string,mixed>>  $satirlar
     * @param  ?Closure(string): string   $kisalt
     * @return list<array<string,mixed>>
     */
    public static function saglikOzeti(array $satirlar, ?\Closure $kisalt = null): array
    {
        $gruplar = [];

        foreach ($satirlar as $r) {
            $model = (string) $r['model'];
            $ad    = $kisalt !== null ? $kisalt($model) : $model;
            $gruplar[$ad !== '' ? $ad : '?'][] = $r;
        }

        $sonuc = [];

        foreach ($gruplar as $ad => $g) {
            $sureler = array_map(static fn (array $r): int => (int) $r['sure_ms'], $g);
            sort($sureler);
            $basarili = array_values(array_filter($g, static fn (array $r): bool => $r['durum'] === 'basarili'));
            $ttft     = array_values(array_filter(array_map(static fn (array $r) => $r['ilk_token_ms'] ?? null, $basarili), 'is_numeric'));
            $giris    = array_sum(array_map(static fn (array $r): int => (int) $r['giris_token'], $basarili));
            $onbellek = array_sum(array_map(static fn (array $r): int => (int) ($r['onbellek_token'] ?? 0), $basarili));

            $sonuc[] = [
                'model'        => (string) $ad,
                'cagri'        => count($g),
                'basari'       => round(count($basarili) / count($g), 3),
                'ort_ms'       => (int) (array_sum($sureler) / count($g)),
                'p95_ms'       => $sureler[(int) floor(0.95 * (count($sureler) - 1))],
                'zaman_asimi'  => count(array_filter($g, static fn (array $r): bool => $r['hata_kodu'] === 'zaman_asimi')),
                'butce'        => count(array_filter($g, static fn (array $r): bool => $r['hata_kodu'] === 'dusunme_butcesi')),
                'ilk_token_ms' => $ttft !== [] ? (int) (array_sum($ttft) / count($ttft)) : null,
                'token'        => $giris + array_sum(array_map(static fn (array $r): int => (int) $r['cikis_token'], $basarili)),
                'onbellek'     => $giris > 0 ? round($onbellek / $giris, 3) : null,
            ];
        }

        usort($sonuc, static fn (array $a, array $b): int => $b['cagri'] <=> $a['cagri']);

        return $sonuc;
    }

    /**
     * EVREN'in son 7 günlük token'ı ve 30 güne ölçeklenmiş tahmini
     * (1 Kasım 2026 sonrası maliyet planı için; Durum sekmesi).
     *
     * @return array{token_7gun:int, token_ay:int}
     */
    public function evrenTahmini(): array
    {
        $statement = $this->db->prepare(
            "SELECT COALESCE(SUM(giris_token + cikis_token), 0) FROM ai_kullanim_kaydi
              WHERE modul = :modul AND saglayici = 'evren' AND created_at >= NOW() - INTERVAL 7 DAY"
        );
        $statement->execute([':modul' => self::MODUL]);
        $hafta = (int) $statement->fetchColumn();

        return ['token_7gun' => $hafta, 'token_ay' => (int) round($hafta * 30 / 7)];
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
