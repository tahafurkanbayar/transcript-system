<?php
/**
 * =====================================================================
 *  KaynakRepository – transkript_kaynaklar tablosunun TEK kapısı
 * ---------------------------------------------------------------------
 *  SQL yalnızca burada yazılır ve her sorgu hazırlıklıdır.
 *
 *  BUL YA DA OLUŞTUR, YARIŞSIZ: aynı URL'yi iki kişi aynı anda girerse
 *  "önce SELECT, yoksa INSERT" iki satır açmaya çalışır, ikincisi
 *  UNIQUE'e çarpar. Bunun yerine tek bir
 *      INSERT … ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)
 *  çalışır: satır varsa onun kimliği döner, yoksa yenisi açılır.
 *
 *  CACHE: YouTube kaynağı herkes için ortaktır (sahip_id = 0). Güncel ve
 *  tamamlanmış bir analizi varsa yeni AI çağrısı yapılmaz.
 *
 *  GÖRÜNÜRLÜK kişiseldir: yönetici dışındakiler yalnız kütüphanelerindeki
 *  (transkript_kutuphane) ve paylaşılan (paylasildi = 1) raporu görür.
 *  Liste, sayaçlar, Kontrol Paneli ve API bu kuralı TEK yerden alır:
 *  gorunurluk(). PHP karşılığı TranskriptPolicy::canViewSource().
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Repositories;

use InvalidArgumentException;
use Modules\Transkript\HataKodlari;
use Modules\Transkript\Kaynak\UrlParser;
use Modules\Transkript\Kaynak\VideoBilgisi;
use Modules\Transkript\Services\Search;
use PDO;
use RuntimeException;

final class KaynakRepository
{
    public const DURUMLAR = ['normal', 'gizli', 'silinmis'];

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * YouTube kaynağını bulur ya da açar (sahip_id = 0, herkese ortak).
     *
     * @return array<string,mixed>
     */
    public function youtubeBulVeyaOlustur(string $videoId, ?int $olusturanId): array
    {
        if (!UrlParser::gecerliKimlik($videoId)) {
            throw new InvalidArgumentException('Geçersiz video kimliği.');
        }

        $statement = $this->db->prepare(
            "INSERT INTO transkript_kaynaklar (tur, dis_kimlik, sahip_id, olusturan_id)
             VALUES ('youtube', :kimlik, 0, :olusturan)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)"
        );
        $statement->execute([':kimlik' => $videoId, ':olusturan' => $olusturanId]);

        $id = (int) $this->db->lastInsertId();

        return $this->bul($id) ?? throw new RuntimeException('Kaynak açılamadı: ' . $videoId);
    }

    /** @return array<string,mixed>|null */
    public function bul(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM transkript_kaynaklar WHERE id = :id');
        $statement->execute([':id' => $id]);

        $satir = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($satir) ? $satir : null;
    }

    /**
     * Satırı işlem sonuna kadar kilitler (SELECT … FOR UPDATE). Aynı
     * kaynağa aynı anda gelen iki "Analiz Et" sırayla geçer; ikincisi
     * birincinin açtığı analizi görür. Yalnız açık işlem içinde anlamlı.
     */
    public function kilitle(int $id): void
    {
        $statement = $this->db->prepare('SELECT id FROM transkript_kaynaklar WHERE id = :id FOR UPDATE');
        $statement->execute([':id' => $id]);
        $statement->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function youtubeBul(string $videoId): ?array
    {
        $statement = $this->db->prepare(
            "SELECT * FROM transkript_kaynaklar
              WHERE tur = 'youtube' AND dis_kimlik = :kimlik AND sahip_id = 0"
        );
        $statement->execute([':kimlik' => $videoId]);

        $satir = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($satir) ? $satir : null;
    }

    /** Video kartını yazar; kaynak yeniden görünür olduysa durumu normale döner. */
    public function bilgiyiYaz(int $id, VideoBilgisi $bilgi): void
    {
        $statement = $this->db->prepare(
            "UPDATE transkript_kaynaklar
                SET baslik = :baslik, kanal_adi = :kanal_adi, kanal_id = :kanal_id,
                    yayin_tarihi = :yayin_tarihi, sure_sn = :sure_sn, kucuk_resim = :kucuk_resim,
                    dil = :dil, durum = 'normal'
              WHERE id = :id"
        );
        $statement->execute([
            ':baslik'       => mb_substr($bilgi->baslik, 0, 255),
            ':kanal_adi'    => $bilgi->kanalAdi !== null ? mb_substr($bilgi->kanalAdi, 0, 255) : null,
            ':kanal_id'     => $bilgi->kanalId !== null && preg_match('/^[\x20-\x7E]{1,64}$/', $bilgi->kanalId) ? $bilgi->kanalId : null,
            ':yayin_tarihi' => $bilgi->yayinTarihi,
            ':sure_sn'      => $bilgi->sureSn,
            ':kucuk_resim'  => $bilgi->kucukResim !== null ? mb_substr($bilgi->kucukResim, 0, 500) : null,
            ':dil'          => $bilgi->dil,
            ':id'           => $id,
        ]);
    }

    /**
     * Süre meta veriden gelmediyse (oEmbed süre vermez) transcript'in
     * son segmentinden yazılır. Meta veri süresi varsa ona dokunulmaz.
     */
    public function sureYoksaYaz(int $id, int $sureSn): void
    {
        if ($sureSn <= 0) {
            return;
        }

        $this->db->prepare('UPDATE transkript_kaynaklar SET sure_sn = :sure WHERE id = :id AND (sure_sn IS NULL OR sure_sn = 0)')
            ->execute([':sure' => $sureSn, ':id' => $id]);
    }

    /**
     * Paylaşımı yazar ("Üyelerle paylaş" / "Paylaşımı kaldır"; yalnız
     * yönetici, TranskriptPolicy::canShare). Değiştiyse true.
     */
    public function paylasimYaz(int $id, bool $paylas): bool
    {
        $sorgu = $this->db->prepare('UPDATE transkript_kaynaklar SET paylasildi = :paylas WHERE id = :id');
        $sorgu->execute([':paylas' => $paylas ? 1 : 0, ':id' => $id]);

        return $sorgu->rowCount() > 0;
    }

    /* =================================================================
     *  GÖRÜNÜRLÜK, LİSTE VE SAYAÇLAR
     * ============================================================== */

    /**
     * Yönetici olmayanın görebildiği kaynaklar (k takma adıyla): kendi
     * yüklediği dosya, kütüphanesindeki ya da paylaşılan YouTube kaynağı.
     * TranskriptPolicy::canViewSource ile AYNI kural; sorguya iki
     * parametre ekler (yerel hazırlıkta aynı ad iki kez kullanılamaz).
     *
     * @return array{0: string, 1: array<string,int>} [koşul, parametreler]
     */
    public static function gorunurluk(int $kullaniciId): array
    {
        return [
            '(k.sahip_id = :gor_sahip OR (k.sahip_id = 0 AND (k.paylasildi = 1 OR EXISTS (
                SELECT 1 FROM transkript_kutuphane gku WHERE gku.kaynak_id = k.id AND gku.kullanici_id = :gor_ben))))',
            [':gor_sahip' => $kullaniciId, ':gor_ben' => $kullaniciId],
        ];
    }

    /** Liste durum filtresi → son analizin koşulu. İptal edilen "hata" sayılmaz, ayrı süzülür. */
    public const LISTE_DURUMLARI = ['hazir', 'suruyor', 'hata', 'iptal'];

    /** Liste kapsam filtresi: kütüphanem · paylaşılan. Boş: görebildiği her şey. */
    public const LISTE_KAPSAMLARI = ['kutuphanem', 'paylasilan'];

    /**
     * Kaynaklar, son analizleriyle birlikte; en son analiz edilen başta.
     *
     * GÖRÜNÜRLÜK: $hepsi (transkript.admin) her şeyi, öteki roller
     * gorunurluk() kadarını görür. Her satır "kutuphanede" (görenin
     * kütüphanesinde mi) ve "paylasildi" taşır; düğmeler buna göre çizilir.
     *
     * ARAMA başlık ve kanal adında, utf8mb4_unicode_ci ile: turkish_ci'de
     * "ai" "AI"yı bulmaz (README, "Arama neden katlanmış sütunda?").
     * Video kimliğinde parça da eşleşir ("jNQX" → jNQXAC9IVRw); kimlik
     * büyük/küçük harfe duyarlıdır (ascii_bin), yalnız kimlik karakterli
     * sorguda aranır.
     *
     * @param  array{tur?:string,durum?:string,q?:string,kapsam?:string} $filtre
     * @return array{satirlar: list<array<string,mixed>>, toplam: int}
     */
    public function liste(array $filtre, int $sayfa, int $boyut, ?int $gorenId, bool $hepsi): array
    {
        $kosullar     = [];
        $parametreler = [];
        $ben          = (int) $gorenId;

        if (!$hepsi) {
            [$kosul, $p] = self::gorunurluk($ben);
            $kosullar[]  = $kosul;
            $parametreler += $p;
        }

        $kapsam = (string) ($filtre['kapsam'] ?? '');

        if ($kapsam === 'kutuphanem') {
            $kosullar[] = 'EXISTS (SELECT 1 FROM transkript_kutuphane kku WHERE kku.kaynak_id = k.id AND kku.kullanici_id = :kapsam_ben)';
            $parametreler[':kapsam_ben'] = $ben;
        } elseif ($kapsam === 'paylasilan') {
            $kosullar[] = 'k.paylasildi = 1';
        }

        $tur = (string) ($filtre['tur'] ?? '');

        if (in_array($tur, ['youtube', 'ses_dosyasi', 'video_dosyasi'], true)) {
            $kosullar[] = 'k.tur = :tur';
            $parametreler[':tur'] = $tur;
        }

        $kosullar[] = match ((string) ($filtre['durum'] ?? '')) {
            'hazir'   => 'EXISTS (SELECT 1 FROM transkript_analizler r WHERE r.kaynak_id = k.id AND r.guncel_mi = 1 AND r.durum = \'tamamlandi\')',
            'suruyor' => 'a.durum NOT IN (\'tamamlandi\', \'hata\')',
            'hata'    => 'a.durum = \'hata\' AND (a.hata_kodu IS NULL OR a.hata_kodu <> \'iptal_edildi\')',
            'iptal'   => 'a.durum = \'hata\' AND a.hata_kodu = \'iptal_edildi\'',
            default   => '1 = 1',
        };

        $q = trim((string) ($filtre['q'] ?? ''));

        if ($q !== '') {
            $kalip = '%' . Search::escapeLike(mb_substr($q, 0, 100)) . '%';
            $kimlik = preg_match('/^[A-Za-z0-9_-]{2,64}$/', $q) === 1 ? ' OR k.dis_kimlik LIKE :q3' : '';
            $kosullar[] = '(k.baslik COLLATE utf8mb4_unicode_ci LIKE :q1 OR k.kanal_adi COLLATE utf8mb4_unicode_ci LIKE :q2' . $kimlik . ')';
            $parametreler += [':q1' => $kalip, ':q2' => $kalip] + ($kimlik !== '' ? [':q3' => $kalip] : []);
        }

        $kaynak = 'FROM transkript_kaynaklar k
                   JOIN transkript_analizler a ON a.id = (SELECT MAX(a2.id) FROM transkript_analizler a2 WHERE a2.kaynak_id = k.id)
                  WHERE ' . implode(' AND ', $kosullar);

        $say = $this->db->prepare('SELECT COUNT(*) ' . $kaynak);
        $say->execute($parametreler);
        $toplam = (int) $say->fetchColumn();

        $sorgu = $this->db->prepare(
            "SELECT k.id, k.tur, k.dis_kimlik, k.sahip_id, k.baslik, k.kanal_adi, k.sure_sn, k.kucuk_resim, k.yayin_tarihi,
                    k.durum AS kaynak_durumu, k.paylasildi,
                    EXISTS (SELECT 1 FROM transkript_kutuphane sku WHERE sku.kaynak_id = k.id AND sku.kullanici_id = :satir_ben) AS kutuphanede,
                    a.id AS analiz_id, a.kullanici_id, a.durum, a.asama, a.ilerleme, a.hata_kodu, a.hata_ozeti, a.created_at,
                    (SELECT r.id FROM transkript_analizler r
                      WHERE r.kaynak_id = k.id AND r.guncel_mi = 1 AND r.durum = 'tamamlandi'
                      ORDER BY r.id DESC LIMIT 1) AS rapor_analiz_id,
                    (SELECT r.kullanici_id FROM transkript_analizler r
                      WHERE r.kaynak_id = k.id AND r.guncel_mi = 1 AND r.durum = 'tamamlandi'
                      ORDER BY r.id DESC LIMIT 1) AS rapor_kullanici_id
             " . $kaynak . '
             ORDER BY a.id DESC
             LIMIT :limit OFFSET :offset'
        );

        $sorgu->bindValue(':satir_ben', $ben, PDO::PARAM_INT);

        foreach ($parametreler as $ad => $deger) {
            $sorgu->bindValue($ad, $deger, is_int($deger) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }

        $boyut = max(1, min(100, $boyut));
        $sorgu->bindValue(':limit', $boyut, PDO::PARAM_INT);
        $sorgu->bindValue(':offset', max(0, ($sayfa - 1) * $boyut), PDO::PARAM_INT);
        $sorgu->execute();

        return ['satirlar' => $sorgu->fetchAll(PDO::FETCH_ASSOC), 'toplam' => $toplam];
    }

    /**
     * Özet kutucukları. Sayılan şey RAPORDUR, deneme değil (test raporu:
     * 2 video analiz edilmişken "5 analiz, 4 video" yazıyordu; bekleyen
     * ve hatalı denemeler de sayılıyordu):
     *
     *   kaynak   raporu hazır (güncel + tamamlanmış analizi olan) video
     *   bugun    bugün TAMAMLANAN analiz
     *   suruyor  son analizi süren kaynak · hata  son analizi hatalı kaynak
     *   token / maliyet  bütün AI çağrıları (başarısız ve silinen kayıtlar dahil:
     *            para harcandı); yönetici değilse yalnız kendi analizleri
     *
     * $kullaniciId verilirse yalnız o kullanıcının başlattığı analizler.
     *
     * @return array{bugun:int, token:int, maliyet:float, kaynak:int, suruyor:int, hata:int}
     */
    public function istatistik(?int $kullaniciId): array
    {
        $kendi = $kullaniciId !== null ? ' AND a.kullanici_id = :ben' : '';
        $p     = $kullaniciId !== null ? [':ben' => $kullaniciId] : [];

        $rapor = $this->db->prepare(
            "SELECT COALESCE(SUM(a.bitti_at >= CURDATE()), 0) AS bugun,
                    COUNT(DISTINCT CASE WHEN a.guncel_mi = 1 THEN a.kaynak_id END) AS kaynak
               FROM transkript_analizler a
              WHERE a.durum = 'tamamlandi'" . $kendi
        );
        $rapor->execute($p);
        $r = $rapor->fetch(PDO::FETCH_ASSOC) ?: [];

        // Son analize göre (liste ile aynı kural).
        $son = $this->db->prepare(
            "SELECT COALESCE(SUM(a.durum NOT IN ('tamamlandi', 'hata')), 0) AS suruyor,
                    COALESCE(SUM(a.durum = 'hata' AND (a.hata_kodu IS NULL OR a.hata_kodu <> 'iptal_edildi')), 0) AS hata
               FROM transkript_analizler a
              WHERE a.id IN (SELECT MAX(a2.id) FROM transkript_analizler a2 GROUP BY a2.kaynak_id)" . $kendi
        );
        $son->execute($p);
        $s = $son->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'bugun'   => (int) ($r['bugun'] ?? 0),
            'kaynak'  => (int) ($r['kaynak'] ?? 0),
            'suruyor' => (int) ($s['suruyor'] ?? 0),
            'hata'    => (int) ($s['hata'] ?? 0),
        ] + $this->kullanim($kullaniciId);
    }

    /**
     * Yönetici olmayanın kutucukları (analist de üye de): GÖREBİLDİĞİ
     * raporlar, yani gorunurluk() kapsamı: kütüphanesindeki ve paylaşılan
     * YouTube kayıtları ile kendi dosyaları. Liste, Kontrol Paneli ve API
     * aynı kapsamı sayar; "kaynak", liste "Raporu hazır" süzgecinin
     * toplamıyla aynıdır (tests/transkript-yetki.php sınar).
     *
     *   kaynak   görebildiği, raporu hazır video
     *   bugun    bugün kütüphanesine eklediği video (analiz ya da önbellek)
     *   suruyor / hata  görebildiği kaynaklardan son analizi süren / hatalı
     *   token / maliyet  0: yalnız yöneticiye gösterilir (istatistik(null))
     *
     * @return array{bugun:int, token:int, maliyet:float, kaynak:int, suruyor:int, hata:int}
     */
    public function gorunenIstatistik(int $kullaniciId): array
    {
        [$kosul, $p] = self::gorunurluk($kullaniciId);

        $rapor = $this->db->prepare(
            "SELECT COUNT(*) FROM transkript_kaynaklar k
              WHERE $kosul
                AND EXISTS (SELECT 1 FROM transkript_analizler r WHERE r.kaynak_id = k.id AND r.guncel_mi = 1 AND r.durum = 'tamamlandi')"
        );
        $rapor->execute($p);
        $kaynak = (int) $rapor->fetchColumn();

        $son = $this->db->prepare(
            "SELECT COALESCE(SUM(a.durum NOT IN ('tamamlandi', 'hata')), 0) AS suruyor,
                    COALESCE(SUM(a.durum = 'hata' AND (a.hata_kodu IS NULL OR a.hata_kodu <> 'iptal_edildi')), 0) AS hata
               FROM transkript_kaynaklar k
               JOIN transkript_analizler a ON a.id = (SELECT MAX(a2.id) FROM transkript_analizler a2 WHERE a2.kaynak_id = k.id)
              WHERE $kosul"
        );
        $son->execute($p);
        $s = $son->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'bugun'   => (new KutuphaneRepository($this->db))->bugunEklenen($kullaniciId),
            'kaynak'  => $kaynak,
            'suruyor' => (int) ($s['suruyor'] ?? 0),
            'hata'    => (int) ($s['hata'] ?? 0),
            'token'   => 0,
            'maliyet' => 0.0,
        ];
    }

    /**
     * Harcanan token ve tahmini maliyet: bütün AI çağrıları (başarısız ve
     * silinen kayıtlar dahil: para harcandı) ya da yalnız $kullaniciId'nin
     * başlattığı analizlerinki.
     *
     * @return array{token:int, maliyet:float}
     */
    private function kullanim(?int $kullaniciId): array
    {
        $kullanim = $this->db->prepare(
            $kullaniciId === null
                ? "SELECT COALESCE(SUM(u.giris_token + u.cikis_token), 0) AS token, COALESCE(SUM(u.maliyet_usd), 0) AS maliyet
                     FROM ai_kullanim_kaydi u WHERE u.modul = 'transkript'"
                : "SELECT COALESCE(SUM(u.giris_token + u.cikis_token), 0) AS token, COALESCE(SUM(u.maliyet_usd), 0) AS maliyet
                     FROM ai_kullanim_kaydi u
                     JOIN transkript_analizler a ON a.id = u.ref_id
                    WHERE u.modul = 'transkript' AND u.ref_tur = 'analiz' AND a.kullanici_id = :ben"
        );
        $kullanim->execute($kullaniciId === null ? [] : [':ben' => $kullaniciId]);
        $u = $kullanim->fetch(PDO::FETCH_ASSOC) ?: [];

        return ['token' => (int) ($u['token'] ?? 0), 'maliyet' => round((float) ($u['maliyet'] ?? 0), 4)];
    }

    /**
     * Bugün harcanan token ve tahmini maliyet (bütün kullanıcılar; kontrol
     * paneli kartı).
     *
     * @return array{token:int, maliyet:float}
     */
    public function bugunkuKullanim(): array
    {
        $satir = $this->db->query(
            "SELECT COALESCE(SUM(giris_token + cikis_token), 0) AS token, COALESCE(SUM(maliyet_usd), 0) AS maliyet
               FROM ai_kullanim_kaydi WHERE modul = 'transkript' AND created_at >= CURDATE()"
        )->fetch(PDO::FETCH_ASSOC) ?: [];

        return ['token' => (int) ($satir['token'] ?? 0), 'maliyet' => round((float) ($satir['maliyet'] ?? 0), 4)];
    }

    /**
     * Yöneticinin Kontrol Paneli'ndeki "Son analizler": en yeni analiz
     * çalışmaları, kaynağı ve başlatanıyla (silinmiş kullanıcıda ad boş).
     *
     * @return list<array<string,mixed>>
     */
    public function sonAnalizler(int $adet = 5): array
    {
        $sorgu = $this->db->prepare(
            "SELECT a.id AS analiz_id, a.kaynak_id, a.durum, a.asama, a.ilerleme, a.hata_kodu, a.kullanici_id,
                    a.created_at, a.basladi_at, a.bitti_at,
                    TIMESTAMPDIFF(SECOND, COALESCE(a.basladi_at, a.created_at), COALESCE(a.bitti_at, a.updated_at, a.created_at)) AS sure_sn,
                    k.baslik, k.dis_kimlik, k.tur,
                    TRIM(CONCAT(COALESCE(u.ad, ''), ' ', COALESCE(u.soyad, ''))) AS baslatan, u.kullanici_adi
               FROM transkript_analizler a
               JOIN transkript_kaynaklar k ON k.id = a.kaynak_id
               LEFT JOIN kullanicilar u ON u.id = a.kullanici_id
              ORDER BY a.id DESC
              LIMIT :adet"
        );
        $sorgu->bindValue(':adet', max(1, min(20, $adet)), PDO::PARAM_INT);
        $sorgu->execute();

        return $sorgu->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Son analizi geçici sebeple duran kaynaklar (HataKodlari::DEVAM_EDILEBILIR):
     * yöneticinin "Bekleyenleri yeniden dene" düğmesi bunları sürdürür.
     *
     * @return list<array<string,mixed>>
     */
    public function devamEdilebilirKaynaklar(int $enCok = 100): array
    {
        $kodlar = HataKodlari::DEVAM_EDILEBILIR;
        $yerler = implode(', ', array_map(static fn (int $i): string => ':k' . $i, array_keys($kodlar)));

        $statement = $this->db->prepare(
            "SELECT k.* FROM transkript_kaynaklar k
               JOIN transkript_analizler a ON a.id = (SELECT MAX(a2.id) FROM transkript_analizler a2 WHERE a2.kaynak_id = k.id)
              WHERE a.durum = 'hata' AND a.hata_kodu IN ($yerler)
              ORDER BY a.id
              LIMIT " . max(1, min(500, $enCok))
        );

        foreach ($kodlar as $i => $kod) {
            $statement->bindValue(':k' . $i, $kod);
        }

        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** gizli / silinmiş video (KaynakHatasi) kaydı. */
    public function durumYaz(int $id, string $durum): void
    {
        if (!in_array($durum, self::DURUMLAR, true)) {
            throw new InvalidArgumentException('Geçersiz kaynak durumu: ' . $durum);
        }

        $statement = $this->db->prepare('UPDATE transkript_kaynaklar SET durum = :durum WHERE id = :id');
        $statement->execute([':durum' => $durum, ':id' => $id]);
    }

    /**
     * Cache: güncel ve tamamlanmış analiz. Varsa yeni AI çağrısı yapılmaz.
     *
     * @return array<string,mixed>|null
     */
    public function guncelTamamlanmisAnaliz(int $kaynakId): ?array
    {
        $statement = $this->db->prepare(
            "SELECT * FROM transkript_analizler
              WHERE kaynak_id = :kaynak AND guncel_mi = 1 AND durum = 'tamamlandi'
              ORDER BY id DESC LIMIT 1"
        );
        $statement->execute([':kaynak' => $kaynakId]);

        $satir = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($satir) ? $satir : null;
    }

    /**
     * Sürmekte olan analiz: "Analiz Et"e iki kez basılınca ikinci iş açılmasın.
     *
     * @return array<string,mixed>|null
     */
    public function suregelenAnaliz(int $kaynakId): ?array
    {
        $statement = $this->db->prepare(
            "SELECT * FROM transkript_analizler
              WHERE kaynak_id = :kaynak AND durum NOT IN ('tamamlandi', 'hata')
              ORDER BY id DESC LIMIT 1"
        );
        $statement->execute([':kaynak' => $kaynakId]);

        $satir = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($satir) ? $satir : null;
    }
}
