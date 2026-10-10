<?php
/**
 * =====================================================================
 *  UyelikYonetimi – Pro üyelik verme, uzatma, kaldırma, süre dolumu ve
 *  "Pro'ya geç" talepleri
 * ---------------------------------------------------------------------
 *  Plan rolden gelir (Planlar): Pro = "editor", Ücretsiz = "uye". Bu
 *  sınıf rolü YALNIZ bu iki değer arasında değiştirir; yönetici hesabına
 *  dokunmaz (her UPDATE "AND rol = …" koşulu taşır).
 *
 *  AKIŞ (çevrim içi ödeme yok; kullanıcı kararı bekleniyor)
 *    1) Üye "Üyeliğim → Pro'ya geç" ile talep gönderir (süre: 1/3/6/12
 *       ay); yöneticiye e-posta gider, üye ödeme talimatını görür.
 *    2) Yönetici ödemeyi görünce Transkript → Üyelikler'de talebi
 *       onaylar (ya da talepsiz "Pro ver"): dönem açılır, rol "editor"
 *       olur, üyeye e-posta gider. Süren dönemin üstüne eklenir.
 *    3) Bitime uyelik.hatirlatma_gun kala e-posta; süre dolunca dönem
 *       "bitti", başka aktif dönemi yoksa rol "uye"ye döner, API
 *       anahtarları silinir (Ücretsiz planda API yok), e-posta gider.
 *
 *  SÜRE DOLUMU NEREDE ÇALIŞIR? Zamanlanmış görev saatte bir
 *  (events.php) ve görev zamanlayıcısı kurulmamış sunucuda da
 *  kaçmasın diye panel istekleri arasında en çok 10 dakikada bir
 *  (araSira). İkisi aynı anda koşarsa veritabanı kilidi (GET_LOCK)
 *  birini atlatır; e-posta iki kez gitmez.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Uyelik;

use App\Core\Log\Logger;
use App\Core\Throttle;
use App\Models\Role;
use App\Models\User;
use App\Repositories\UserRepository;
use DateTimeImmutable;
use Modules\Transkript\Ayarlar;
use Modules\Transkript\Repositories\UyelikRepository;
use PDO;
use Throwable;

final class UyelikYonetimi
{
    /** Talep formundaki süre seçenekleri (ay). */
    public const TALEP_SURELERI = [1, 3, 6, 12];

    /** Yöneticinin verebileceği en uzun süre (ay); 0 = süresiz. */
    public const EN_COK_AY = 36;

    private const KILIT = 'transkript-uyelik-suresi';

    private readonly UyelikRepository $repo;

    public function __construct(private readonly PDO $db, ?UyelikRepository $repo = null)
    {
        $this->repo = $repo ?? new UyelikRepository($db);
    }

    /* =================================================================
     *  YÖNETİCİ
     * ============================================================== */

    /**
     * Pro verir ya da uzatır. Süren dönem varsa yenisi onun bitişinde
     * başlar. $ay = 0 süresiz.
     *
     * @return array{donem_id: int, baslangic: string, bitis: ?string, yukseltildi: bool, eposta: bool}
     *   eposta: üyeye bildirim gönderilebildi mi (e-posta ayarı yoksa false)
     *
     * @throws UyelikHatasi
     */
    public function proVer(int $kullaniciId, int $ay, ?float $tutar, string $not, ?int $yoneticiId, ?int $talepId = null, ?int $simdi = null): array
    {
        if ($ay < 0 || $ay > self::EN_COK_AY) {
            throw new UyelikHatasi(sprintf('Süre 1 ile %d ay arasında olmalı (süresiz için 0).', self::EN_COK_AY));
        }

        if ($tutar !== null && ($tutar < 0 || $tutar > 10000000)) {
            throw new UyelikHatasi('Tutar 0 ya da daha büyük bir sayı olmalı.');
        }

        $kullanici = $this->hedef($kullaniciId);
        $zaman     = date('Y-m-d H:i:s', $simdi ?? time());

        $this->db->beginTransaction();

        try {
            $ozet = $this->repo->ozet($kullaniciId, true);

            if ($ozet['suresiz']) {
                throw new UyelikHatasi('Bu kullanıcının süresiz Pro üyeliği var; süre eklemek gerekmez.', 409);
            }

            $baslangic = $ozet['bitis'] !== null && $ozet['bitis'] > $zaman ? $ozet['bitis'] : $zaman;
            $bitis     = $ay === 0 ? null : self::bitisHesapla($baslangic, $ay);

            $donemId = $this->repo->donemEkle([
                'kullanici_id' => $kullaniciId,
                'baslangic'    => $baslangic,
                'bitis'        => $bitis,
                'tutar'        => $tutar,
                'para_birimi'  => Planlar::paraBirimi(),
                'odeme_notu'   => self::temizMetin($not, 255),
                'talep_id'     => $talepId,
                'olusturan_id' => $yoneticiId,
            ]);

            $yukseltildi = $this->rolYaz($kullaniciId, Role::MEMBER, Role::EDITOR);

            if ($talepId !== null && !$this->repo->talepSonuclandir($talepId, 'onaylandi', '', $yoneticiId)) {
                throw new UyelikHatasi('Bu talep zaten sonuçlanmış; sayfayı yenileyin.', 409);
            }

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }

        Logger::info('Pro üyelik verildi', [
            'kullanici' => $kullaniciId, 'ay' => $ay, 'bitis' => $bitis, 'talep' => $talepId, 'yonetici' => $yoneticiId,
        ], 'transkript');

        $eposta = Bildirim::proAcildi($kullanici, $bitis, $yukseltildi);

        return ['donem_id' => $donemId, 'baslangic' => $baslangic, 'bitis' => $bitis, 'yukseltildi' => $yukseltildi, 'eposta' => $eposta];
    }

    /**
     * "Pro'yu kaldır": aktif dönemler iptal, rol "uye", API anahtarları
     * silinir. Üyeye e-posta gidebildiyse true.
     *
     * @throws UyelikHatasi
     */
    public function kaldir(int $kullaniciId, ?int $yoneticiId): bool
    {
        $kullanici = $this->hedef($kullaniciId);

        $this->db->beginTransaction();

        try {
            $kapanan = $this->repo->aktifleriKapat($kullaniciId, 'iptal');
            $dusuruldu = $this->rolYaz($kullaniciId, Role::EDITOR, Role::MEMBER);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }

        if (!$dusuruldu && $kapanan === 0) {
            throw new UyelikHatasi('Bu kullanıcının Pro üyeliği yok.', 409);
        }

        (new UserRepository($this->db))->revokeApiTokens($kullaniciId);

        Logger::info('Pro üyelik kaldırıldı', ['kullanici' => $kullaniciId, 'donem' => $kapanan, 'yonetici' => $yoneticiId], 'transkript');

        return Bildirim::proBitti($kullanici, true);
    }

    /**
     * Süresi dolan dönemleri kapatır; başka aktif dönemi kalmayan Pro'yu
     * Ücretsiz'e düşürür. Düşürülen kullanıcı kimlikleri döner.
     *
     * @return list<int>
     */
    public function suresiDolanlariIsle(?int $simdi = null): array
    {
        if (!$this->kilitAl()) {
            return [];
        }

        try {
            $dusen = [];

            foreach ($this->repo->suresiDolanlariKapat(date('Y-m-d H:i:s', $simdi ?? time())) as $id) {
                if (!$this->rolYaz($id, Role::EDITOR, Role::MEMBER)) {
                    continue;
                }

                $dusen[] = $id;
                (new UserRepository($this->db))->revokeApiTokens($id);

                if (($kullanici = (new UserRepository($this->db))->find($id)) !== null) {
                    Bildirim::proBitti($kullanici, false);
                }
            }

            if ($dusen !== []) {
                Logger::info('Süresi dolan Pro üyelikler Ücretsiz plana döndü', ['kullanicilar' => $dusen], 'transkript');
            }

            return $dusen;
        } finally {
            $this->kilitBirak();
        }
    }

    /** "Bitmesine N gün kaldı" e-postaları; gönderilen sayısı. */
    public function hatirlat(?int $simdi = null): int
    {
        $gun = max(0, (int) Ayarlar::get('uyelik.hatirlatma_gun', 3));

        if ($gun === 0 || !$this->kilitAl()) {
            return 0;
        }

        try {
            $simdi ??= time();
            $sayi = 0;

            foreach ($this->repo->hatirlatilacaklar(date('Y-m-d H:i:s', $simdi), date('Y-m-d H:i:s', $simdi + $gun * 86400)) as $donem) {
                $kullanici = (new UserRepository($this->db))->find((int) $donem['kullanici_id']);

                // Önce işaretle: e-posta yavaşsa ya da düşerse aynı kişiye ikinci kez gitmesin.
                $this->repo->hatirlatildiYaz((int) $donem['id']);

                if ($kullanici !== null && $kullanici->rol === Role::EDITOR) {
                    Bildirim::bitiyor($kullanici, (string) $donem['bitis']);
                    $sayi++;
                }
            }

            return $sayi;
        } finally {
            $this->kilitBirak();
        }
    }

    /* =================================================================
     *  TALEPLER
     * ============================================================== */

    /**
     * "Pro'ya geç" (Ücretsiz) ya da "Süremi uzat" (Pro) talebi. Kişi
     * başına bir bekleyen talep, günde en çok 5 deneme. Süresiz Pro ve
     * yönetici talep göndermez.
     *
     * @throws UyelikHatasi
     */
    public function talepOlustur(User $kullanici, int $ay, string $mesaj): int
    {
        if (Planlar::plan($kullanici) === null) {
            throw new UyelikHatasi('Yönetici hesabının planı yoktur.', 409);
        }

        if (Planlar::plan($kullanici) === Planlar::PRO) {
            $ozet = $this->repo->ozet($kullanici->id);

            if ($ozet['suresiz'] || !$ozet['aktif']) {
                throw new UyelikHatasi('Pro üyeliğiniz süresiz; uzatmanız gerekmez.', 409);
            }
        }

        if (!in_array($ay, self::TALEP_SURELERI, true)) {
            throw new UyelikHatasi('Listeden bir süre seçin.');
        }

        $mesaj = self::temizMetin($mesaj, 500);

        if ($this->repo->bekleyenVarMi($kullanici->id)) {
            throw new UyelikHatasi('Bekleyen bir talebiniz var; yönetici yanıtlayınca yenisini gönderebilirsiniz.', 409);
        }

        if (Throttle::attempt('transkript-uyelik-talep:' . $kullanici->id, 5, 86400) > 0) {
            throw new UyelikHatasi('Bugün çok sayıda talep gönderdiniz; yarın yeniden deneyin.', 429);
        }

        $id = $this->repo->talepOlustur($kullanici->id, $ay, $mesaj);

        Logger::info('Pro üyelik talebi', ['kullanici' => $kullanici->id, 'talep' => $id, 'ay' => $ay], 'transkript');
        Bildirim::talepAlindi($kullanici, $ay, $mesaj);

        return $id;
    }

    /** Üye kendi bekleyen talebini geri çeker. */
    public function talepIptal(User $kullanici): bool
    {
        $talep = $this->repo->sonTalep($kullanici->id);

        return $talep !== null && $talep['durum'] === 'bekliyor'
            && $this->repo->talepSonuclandir((int) $talep['id'], 'iptal', '', $kullanici->id);
    }

    /**
     * Talebi onaylar: talepteki süre (ya da yöneticinin girdiği) kadar Pro.
     *
     * @return array{donem_id: int, baslangic: string, bitis: ?string, yukseltildi: bool, eposta: bool}
     *
     * @throws UyelikHatasi
     */
    public function talepOnayla(int $talepId, ?int $ay, ?float $tutar, string $not, ?int $yoneticiId): array
    {
        $talep = $this->bekleyenTalep($talepId);

        return $this->proVer((int) $talep['kullanici_id'], $ay ?? (int) $talep['sure_ay'], $tutar, $not, $yoneticiId, $talepId);
    }

    /**
     * Talebi gerekçesiyle reddeder; üyeye e-posta gidebildiyse true.
     *
     * @throws UyelikHatasi
     */
    public function talepReddet(int $talepId, string $yanit, ?int $yoneticiId): bool
    {
        $talep = $this->bekleyenTalep($talepId);
        $yanit = self::temizMetin($yanit, 500);

        if (!$this->repo->talepSonuclandir($talepId, 'reddedildi', $yanit, $yoneticiId)) {
            throw new UyelikHatasi('Bu talep zaten sonuçlanmış; sayfayı yenileyin.', 409);
        }

        Logger::info('Pro üyelik talebi reddedildi', ['talep' => $talepId, 'yonetici' => $yoneticiId], 'transkript');

        $kullanici = (new UserRepository($this->db))->find((int) $talep['kullanici_id']);

        return $kullanici !== null && Bildirim::talepReddedildi($kullanici, $yanit);
    }

    /* =================================================================
     *  ARA SIRA (görev zamanlayıcısı yoksa)
     * ============================================================== */

    /**
     * Panel istekleri arasında en çok $aralikSn'de bir süre dolumunu ve
     * hatırlatmaları işler. Zaman damgası dosyadadır; sıradaki çalışma
     * zamanı gelmediyse veritabanına hiç gidilmez. Hata isteği bozmaz.
     *
     * @param callable(): PDO $baglanti
     */
    public static function araSira(callable $baglanti, int $aralikSn = 600, ?string $dosya = null): void
    {
        $dosya ??= (defined('CY_BASE') ? CY_BASE : dirname(__DIR__, 4)) . '/storage/files/transkript-uyelik-zaman';

        clearstatcache(true, $dosya); // aynı süreçte önceki filemtime önbellekte kalmasın

        if (is_file($dosya) && (int) @filemtime($dosya) > time() - $aralikSn) {
            return;
        }

        @touch($dosya);

        try {
            $yonetim = new self($baglanti());
            $yonetim->suresiDolanlariIsle();
            $yonetim->hatirlat();
        } catch (Throwable $e) {
            Logger::warning('Üyelik süresi denetimi çalışmadı', ['hata' => $e->getMessage()], 'transkript');
        }
    }

    /* =================================================================
     *  YARDIMCILAR
     * ============================================================== */

    /**
     * Başlangıçtan $ay ay sonrası; ayın son gününü taşırmaz (31 Ocak + 1
     * ay = 28/29 Şubat). Saf (test edilir).
     */
    public static function bitisHesapla(string $baslangic, int $ay): string
    {
        $b  = new DateTimeImmutable($baslangic);
        $ai = (int) $b->format('n') - 1 + $ay;
        $yil = (int) $b->format('Y') + intdiv($ai, 12);
        $ayNo = $ai % 12 + 1;
        $gun = min((int) $b->format('j'), (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $yil, $ayNo)))->format('t'));

        return $b->setDate($yil, $ayNo, $gun)->format('Y-m-d H:i:s');
    }

    /** Tek satırlık ya da kısa metin: denetim karakterleri atılır, uzunluk kesilir. */
    public static function temizMetin(string $metin, int $sinir): string
    {
        $metin = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', str_replace("\r\n", "\n", $metin));

        return mb_substr(trim($metin), 0, $sinir);
    }

    /** @return array<string,mixed> */
    private function bekleyenTalep(int $talepId): array
    {
        $talep = $this->repo->talep($talepId) ?? throw new UyelikHatasi('Talep bulunamadı.', 404);

        if ($talep['durum'] !== 'bekliyor') {
            throw new UyelikHatasi('Bu talep zaten sonuçlanmış; sayfayı yenileyin.', 409);
        }

        return $talep;
    }

    /** @throws UyelikHatasi */
    private function hedef(int $kullaniciId): User
    {
        $kullanici = (new UserRepository($this->db))->find($kullaniciId) ?? throw new UyelikHatasi('Kullanıcı bulunamadı.', 404);

        if (!in_array($kullanici->rol, [Role::MEMBER, Role::EDITOR], true)) {
            throw new UyelikHatasi('Yönetici hesaplarına üyelik verilmez; yöneticinin plan sınırı yoktur.', 409);
        }

        return $kullanici;
    }

    /** Rolü yalnız $once iken $sonra yapar; değiştiyse true. Yönetici hesabına dokunmaz. */
    private function rolYaz(int $kullaniciId, string $once, string $sonra): bool
    {
        $sorgu = $this->db->prepare('UPDATE kullanicilar SET rol = :sonra WHERE id = :id AND rol = :once');
        $sorgu->execute([':sonra' => $sonra, ':id' => $kullaniciId, ':once' => $once]);

        return $sorgu->rowCount() === 1;
    }

    private function kilitAl(): bool
    {
        try {
            return (int) $this->db->query("SELECT GET_LOCK('" . self::KILIT . "', 0)")->fetchColumn() === 1;
        } catch (Throwable) {
            return true; // GET_LOCK yoksa (SQLite testleri) kilitsiz çalış
        }
    }

    private function kilitBirak(): void
    {
        try {
            $this->db->query("SELECT RELEASE_LOCK('" . self::KILIT . "')");
        } catch (Throwable) {
        }
    }
}
