<?php
/**
 * =====================================================================
 *  UyelikRepository – Pro dönemleri ve "Pro'ya geç" talepleri (SQL)
 * ---------------------------------------------------------------------
 *  Kurallar Uyelik\UyelikYonetimi'nde; burada yalnız sorgular. Tablolar:
 *  transkript_uyelikler (dönem), transkript_uyelik_talepleri (talep).
 *  Tarihler sunucu saatiyle (DATETIME, "Y-m-d H:i:s") yazılır.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Repositories;

use PDO;

final class UyelikRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /* =================================================================
     *  DÖNEMLER
     * ============================================================== */

    /**
     * Kullanıcının süren Pro'su: aktif dönem var mı, en geç bitiş, süresiz mi.
     * $kilitle: işlem içinde satırlar kilitlenir (aynı anda iki "Pro ver").
     *
     * @return array{aktif: bool, suresiz: bool, bitis: ?string, baslangic: ?string, donem: int}
     */
    public function ozet(int $kullaniciId, bool $kilitle = false): array
    {
        $sorgu = $this->db->prepare(
            "SELECT baslangic, bitis FROM transkript_uyelikler
              WHERE kullanici_id = :k AND durum = 'aktif'" . ($kilitle ? ' FOR UPDATE' : '')
        );
        $sorgu->execute([':k' => $kullaniciId]);
        $satirlar = $sorgu->fetchAll(PDO::FETCH_ASSOC);

        $suresiz = false;
        $bitis   = null;
        $ilk     = null;

        foreach ($satirlar as $s) {
            $suresiz = $suresiz || $s['bitis'] === null;
            $bitis   = $s['bitis'] !== null && ($bitis === null || $s['bitis'] > $bitis) ? (string) $s['bitis'] : $bitis;
            $ilk     = $ilk === null || $s['baslangic'] < $ilk ? (string) $s['baslangic'] : $ilk;
        }

        return [
            'aktif'     => $satirlar !== [],
            'suresiz'   => $suresiz,
            'bitis'     => $suresiz ? null : $bitis,
            'baslangic' => $ilk,
            'donem'     => count($satirlar),
        ];
    }

    /** @param array{kullanici_id: int, baslangic: string, bitis: ?string, tutar: ?float, para_birimi: string, odeme_notu: string, talep_id: ?int, olusturan_id: ?int} $v */
    public function donemEkle(array $v): int
    {
        $this->db->prepare(
            'INSERT INTO transkript_uyelikler (kullanici_id, plan, baslangic, bitis, tutar, para_birimi, odeme_notu, talep_id, olusturan_id)
             VALUES (:k, :plan, :bas, :bit, :tutar, :para, :notu, :talep, :olusturan)'
        )->execute([
            ':k'         => $v['kullanici_id'],
            ':plan'      => 'editor',
            ':bas'       => $v['baslangic'],
            ':bit'       => $v['bitis'],
            ':tutar'     => $v['tutar'],
            ':para'      => $v['para_birimi'],
            ':notu'      => $v['odeme_notu'],
            ':talep'     => $v['talep_id'],
            ':olusturan' => $v['olusturan_id'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** Kullanıcının bütün aktif dönemlerini kapatır (yönetici "Pro'yu kaldır"). */
    public function aktifleriKapat(int $kullaniciId, string $durum): int
    {
        $sorgu = $this->db->prepare("UPDATE transkript_uyelikler SET durum = :d WHERE kullanici_id = :k AND durum = 'aktif'");
        $sorgu->execute([':d' => $durum, ':k' => $kullaniciId]);

        return $sorgu->rowCount();
    }

    /**
     * Süresi dolan aktif dönemleri "bitti" yapar; dönemleri biten
     * kullanıcıların kimliklerini döner (hepsi değil: başka aktif dönemi
     * olan kullanıcı listede yoktur).
     *
     * @return list<int>
     */
    public function suresiDolanlariKapat(string $simdi): array
    {
        $sorgu = $this->db->prepare("SELECT DISTINCT kullanici_id FROM transkript_uyelikler WHERE durum = 'aktif' AND bitis IS NOT NULL AND bitis <= :s");
        $sorgu->execute([':s' => $simdi]);
        $kullanicilar = array_map('intval', $sorgu->fetchAll(PDO::FETCH_COLUMN));

        if ($kullanicilar === []) {
            return [];
        }

        $this->db->prepare("UPDATE transkript_uyelikler SET durum = 'bitti' WHERE durum = 'aktif' AND bitis IS NOT NULL AND bitis <= :s")
            ->execute([':s' => $simdi]);

        return array_values(array_filter($kullanicilar, fn (int $id): bool => !$this->ozet($id)['aktif']));
    }

    /**
     * "Bitmesine N gün kaldı" e-postası gidecek dönemler: kullanıcının EN
     * SON biten aktif dönemi (sonrasında aktif ya da süresiz dönemi yok),
     * bitişi şimdi ile şimdi + N gün arasında, henüz hatırlatılmamış.
     *
     * @return list<array<string,mixed>>
     */
    public function hatirlatilacaklar(string $simdi, string $sinir): array
    {
        $sorgu = $this->db->prepare(
            "SELECT u.id, u.kullanici_id, u.bitis
               FROM transkript_uyelikler u
              WHERE u.durum = 'aktif' AND u.hatirlatildi = 0 AND u.bitis IS NOT NULL
                AND u.bitis > :s AND u.bitis <= :sinir
                AND NOT EXISTS (SELECT 1 FROM transkript_uyelikler d
                                 WHERE d.kullanici_id = u.kullanici_id AND d.durum = 'aktif'
                                   AND (d.bitis IS NULL OR d.bitis > u.bitis))"
        );
        $sorgu->execute([':s' => $simdi, ':sinir' => $sinir]);

        return $sorgu->fetchAll(PDO::FETCH_ASSOC);
    }

    public function hatirlatildiYaz(int $donemId): void
    {
        $this->db->prepare('UPDATE transkript_uyelikler SET hatirlatildi = 1 WHERE id = :id')->execute([':id' => $donemId]);
    }

    /**
     * Pro üyeler (rolü editor olanlar) ve süreleri; dönem kaydı olmayan
     * editör "süresiz (elle verilmiş)" görünür. Yönetici ekranı.
     *
     * @return list<array<string,mixed>>
     */
    public function proUyeler(): array
    {
        return $this->db->query(
            "SELECT k.id, k.ad, k.soyad, k.kullanici_adi, k.eposta, k.durum AS hesap_durumu,
                    COUNT(u.id) AS donem,
                    MAX(u.bitis) AS bitis,
                    MAX(u.bitis IS NULL AND u.id IS NOT NULL) AS suresiz,
                    MIN(u.baslangic) AS baslangic
               FROM kullanicilar k
               LEFT JOIN transkript_uyelikler u ON u.kullanici_id = k.id AND u.durum = 'aktif'
              WHERE k.rol = 'editor'
              GROUP BY k.id, k.ad, k.soyad, k.kullanici_adi, k.eposta, k.durum
              ORDER BY (COUNT(u.id) = 0), MAX(u.bitis) IS NULL, MAX(u.bitis), k.ad"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Son dönemler (ödeme geçmişi), yeniden eskiye.
     *
     * @return list<array<string,mixed>>
     */
    public function sonDonemler(int $limit = 20, ?int $kullaniciId = null): array
    {
        $sorgu = $this->db->prepare(
            "SELECT u.*, CONCAT(k.ad, ' ', k.soyad) AS adi, k.kullanici_adi,
                    CONCAT(y.ad, ' ', y.soyad) AS olusturan_adi
               FROM transkript_uyelikler u
               LEFT JOIN kullanicilar k ON k.id = u.kullanici_id
               LEFT JOIN kullanicilar y ON y.id = u.olusturan_id"
            . ($kullaniciId !== null ? ' WHERE u.kullanici_id = :k' : '')
            . ' ORDER BY u.id DESC LIMIT ' . max(1, min(200, $limit))
        );
        $sorgu->execute($kullaniciId !== null ? [':k' => $kullaniciId] : []);

        return $sorgu->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Gelir özeti: bu ay ve toplam (para birimine göre ayrı), aktif Pro sayısı.
     *
     * @return array{bu_ay: array<string,float>, toplam: array<string,float>}
     */
    public function gelir(): array
    {
        $sonuc = ['bu_ay' => [], 'toplam' => []];
        $satirlar = $this->db->query(
            "SELECT para_birimi,
                    COALESCE(SUM(CASE WHEN created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN tutar END), 0) AS bu_ay,
                    COALESCE(SUM(tutar), 0) AS toplam
               FROM transkript_uyelikler WHERE tutar IS NOT NULL AND durum <> 'iptal'
              GROUP BY para_birimi"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($satirlar as $s) {
            $sonuc['bu_ay'][(string) $s['para_birimi']]  = (float) $s['bu_ay'];
            $sonuc['toplam'][(string) $s['para_birimi']] = (float) $s['toplam'];
        }

        return $sonuc;
    }

    /* =================================================================
     *  TALEPLER
     * ============================================================== */

    public function talepOlustur(int $kullaniciId, int $sureAy, string $mesaj): int
    {
        $this->db->prepare('INSERT INTO transkript_uyelik_talepleri (kullanici_id, plan, sure_ay, mesaj) VALUES (:k, :plan, :ay, :m)')
            ->execute([':k' => $kullaniciId, ':plan' => 'editor', ':ay' => $sureAy, ':m' => $mesaj]);

        return (int) $this->db->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function talep(int $id): ?array
    {
        $sorgu = $this->db->prepare('SELECT * FROM transkript_uyelik_talepleri WHERE id = :id');
        $sorgu->execute([':id' => $id]);

        return $sorgu->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Kullanıcının bekleyen talebi; yoksa en son sonuçlanan (reddedildiyse
     * gerekçesi Üyeliğim'de görünsün).
     *
     * @return array<string,mixed>|null
     */
    public function sonTalep(int $kullaniciId): ?array
    {
        $sorgu = $this->db->prepare(
            "SELECT * FROM transkript_uyelik_talepleri WHERE kullanici_id = :k
              ORDER BY durum = 'bekliyor' DESC, id DESC LIMIT 1"
        );
        $sorgu->execute([':k' => $kullaniciId]);

        return $sorgu->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function bekleyenVarMi(int $kullaniciId): bool
    {
        $sorgu = $this->db->prepare("SELECT 1 FROM transkript_uyelik_talepleri WHERE kullanici_id = :k AND durum = 'bekliyor' LIMIT 1");
        $sorgu->execute([':k' => $kullaniciId]);

        return $sorgu->fetchColumn() !== false;
    }

    /** @return list<array<string,mixed>> */
    public function bekleyenTalepler(): array
    {
        return $this->db->query(
            "SELECT t.*, k.ad, k.soyad, k.kullanici_adi, k.eposta, k.rol
               FROM transkript_uyelik_talepleri t
               JOIN kullanicilar k ON k.id = t.kullanici_id
              WHERE t.durum = 'bekliyor'
              ORDER BY t.id"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function bekleyenSayisi(): int
    {
        return (int) $this->db->query(
            "SELECT COUNT(*) FROM transkript_uyelik_talepleri t JOIN kullanicilar k ON k.id = t.kullanici_id WHERE t.durum = 'bekliyor'"
        )->fetchColumn();
    }

    /** Talebi sonuçlandırır; yalnız "bekliyor" iken (iki yönetici aynı anda basarsa ikincisi false). */
    public function talepSonuclandir(int $id, string $durum, string $yanit, ?int $isleyenId): bool
    {
        $sorgu = $this->db->prepare(
            "UPDATE transkript_uyelik_talepleri SET durum = :d, yanit = :y, isleyen_id = :i, islendi_at = NOW()
              WHERE id = :id AND durum = 'bekliyor'"
        );
        $sorgu->execute([':d' => $durum, ':y' => $yanit, ':i' => $isleyenId, ':id' => $id]);

        return $sorgu->rowCount() === 1;
    }
}
