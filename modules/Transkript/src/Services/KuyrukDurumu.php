<?php
/**
 * =====================================================================
 *  KuyrukDurumu – "işçi çalışıyor mu, iş sırada mı takıldı?"
 * ---------------------------------------------------------------------
 *  Analiz yalnız kuyruk işçisi çalışırken ilerler (CLAUDE.md, kural 6).
 *  Cron'u olmayan bir makinede (Windows / XAMPP) işçi hiç başlamaz;
 *  analiz sessizce "Sırada bekliyor %0"da kalıyor, sayfa da "arka
 *  planda sürer" diyordu. Liste, rapor sayfası, ilerleme JSON'u ve
 *  kontrol paneli kartı durumu buradan okur.
 *
 *  İŞÇİ EN SON NE ZAMAN GÖRÜLDÜ? Şunların en yenisi:
 *    - nabız: her Transkript işi başlarken ve modülün zamanlanmış
 *      görevi her turda önbelleğe zaman yazar (imle(), yalnız CLI);
 *    - zamanlayıcının durum dosyası: "transkript-kuyrugu" ve çekirdeğin
 *      "kuyruk-isle" görevinin son çalışması (schedule:run her dakika);
 *    - şu an "calisiyor" durumunda olan, zaman aşımına uğramamış iş.
 *
 *  TAKILDI: hazır bekleyen en eski "transkript" işi ESIK_SN'den uzun
 *  süredir sırada VE işçi ESIK_SN içinde görülmedi. İki koşul birlikte
 *  aranır: boşta bekleyen kalıcı işçi (queue:work) nabız yazmaz ama
 *  yeni işi saniyeler içinde alır; böylece yanlış alarm çıkmaz.
 *
 *  Tablo okunamazsa (migration bekliyor) "bilinmiyor" sayılır ve uyarı
 *  çıkmaz; durum sayfası yüzünden panel çökmemeli.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Services;

use App\Core\Cache\Cache;
use App\Core\Config;
use App\Core\Log\Logger;
use App\Core\Schedule\Schedule;
use Modules\Transkript\Ayarlar;
use PDO;
use Throwable;

final class KuyrukDurumu
{
    /** İşçi bu kadar saniyedir görülmediyse ve iş bu kadar beklediyse uyarı. */
    public const ESIK_SN = 120;

    /** Uyarıda gösterilen komutlar (proje klasöründe çalıştırılır). */
    public const KOMUT_ELLE   = 'php cy queue:work --queue=transkript';
    public const KOMUT_CRON   = '* * * * * cd /yol/site && php cy schedule:run';
    public const KOMUT_WINDOWS = 'kuyruk-isci.bat';
    /** Bir kez çift tıklanır: işçiyi Görev Zamanlayıcı'ya kaydeder (oturum açılınca, gizli, tek örnek). */
    public const KOMUT_KUR     = 'kuyruk-isci-kur.bat';

    private const NABIZ    = 'transkript.isci_nabiz';
    private const GOREVLER = ['transkript-kuyrugu', 'kuyruk-isle'];

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * İşçi çalışıyor işareti. Yalnız komut satırında yazılır: panelden
     * "Şimdi işle" ile çalışan iş, işçi varmış gibi göstermesin.
     */
    public static function imle(): void
    {
        if (PHP_SAPI !== 'cli') {
            return;
        }

        try {
            Cache::put(self::NABIZ, time(), 3600);
        } catch (Throwable) {
            // Önbellek yazılamazsa zamanlayıcının durum dosyası yine okunur.
        }
    }

    /**
     * @return array{
     *     bilinmiyor: bool,
     *     calisiyor: bool,
     *     takildi: bool,
     *     bekleyen: int,
     *     calisan: int,
     *     en_eski_sn: int,
     *     son_gorulme_sn: int|null
     * }
     */
    public function getir(): array
    {
        try {
            $sayilar = $this->sayilar();
        } catch (Throwable $e) {
            Logger::warning('Kuyruk durumu okunamadı', ['ayrinti' => $e->getMessage()], 'transkript');

            return [
                'bilinmiyor' => true, 'calisiyor' => true, 'takildi' => false,
                'bekleyen' => 0, 'calisan' => 0, 'en_eski_sn' => 0, 'son_gorulme_sn' => null, 'takili' => 0,
            ];
        }

        $son     = self::sonGorulme();
        $gorulme = $son > 0 ? max(0, time() - $son) : null;

        return self::karar($sayilar['bekleyen'], $sayilar['calisan'], $sayilar['en_eski_sn'], $gorulme) + ['takili' => $sayilar['takili']];
    }

    /**
     * Saf karar (test edilir; veritabanı ve saat yok).
     *
     * @return array{bilinmiyor: bool, calisiyor: bool, takildi: bool, bekleyen: int, calisan: int, en_eski_sn: int, son_gorulme_sn: int|null}
     */
    public static function karar(int $bekleyen, int $calisan, int $enEskiSn, ?int $sonGorulmeSn): array
    {
        $calisiyor = $calisan > 0 || ($sonGorulmeSn !== null && $sonGorulmeSn <= self::ESIK_SN);

        return [
            'bilinmiyor'     => false,
            'calisiyor'      => $calisiyor,
            'takildi'        => !$calisiyor && $bekleyen > 0 && $enEskiSn >= self::ESIK_SN,
            'bekleyen'       => $bekleyen,
            'calisan'        => $calisan,
            'en_eski_sn'     => $enEskiSn,
            'son_gorulme_sn' => $sonGorulmeSn,
        ];
    }

    /**
     * Panelden "Şimdi işle" açık mı? Geliştirme ortamında (APP_ENV yayın
     * değil) ya da config.php → kuyruk.panelden_isle = true ise. Cron'u
     * olmayan barındırma için yedektir (docs/TEKNIK_RAPOR.md, 7. bölüm).
     */
    public static function paneldenIslenebilir(): bool
    {
        return !Config::isProduction() || (bool) Ayarlar::get('kuyruk.panelden_isle', false);
    }

    /**
     * Kuyruk tarayıcı üzerinden işlenir mi (komut satırısız)? Ayar
     * kuyruk.tarayicidan_isle: true / false; null (varsayılan) →
     * geliştirme ortamında açık, yayında kapalı. $varsayilan: panel
     * değerine bakmadan config'in söylediği (ayar ekranındaki "Varsayılan").
     */
    public static function tarayicidanIslenir(bool $varsayilan = false): bool
    {
        $ayar = $varsayilan ? Ayarlar::varsayilan('kuyruk.tarayicidan_isle') : Ayarlar::get('kuyruk.tarayicidan_isle');

        return is_bool($ayar) ? $ayar : !Config::isProduction();
    }

    /**
     * Gerçek bir işçi (CLI: zamanlanmış görev ya da queue:work) son
     * ESIK_SN içinde görüldü mü? Tarayıcıdan işlenen iş nabız yazmaz
     * (imle yalnız CLI); bu yüzden tarayıcı kendini işçi sanmaz.
     */
    public static function isciGorulduMu(?int $simdi = null): bool
    {
        $son = self::sonGorulme();

        return $son > 0 && ($simdi ?? time()) - $son <= self::ESIK_SN;
    }

    /** Nabzı bu kadar saniye gelmeyen iş ölmüştür (config kuyruk.nabiz_esik_sn, QUEUE_TIMEOUT'tan büyük olmaz). */
    public static function nabizEsigi(): int
    {
        return max(60, min((int) Config::get('queue.timeout', 300), (int) Ayarlar::get('kuyruk.nabiz_esik_sn', 300)));
    }

    /**
     * Nabzı eşikten eski "transkript" işlerini kuyruğa geri bırakır (deneme
     * hakkı bitmişse başarısız sayar, çekirdeğin releaseStuck'ı gibi). Çekirdek
     * QUEUE_TIMEOUT'u bekler (bu kurulumda 900 sn); modülün işleri nabız
     * attığı için 5 dk yeter. Zamanlanmış görev ve tarayıcı tik'i işlemeye
     * başlamadan çağırır.
     *
     * @return int geri bırakılan iş sayısı
     */
    public static function bayatlariBirak(?PDO $db = null): int
    {
        $db  ??= \App\Core\Database::connection();
        $esik  = self::nabizEsigi();
        $tablo = self::tablo();

        $guncelle = $db->prepare(
            'UPDATE `' . $tablo . "`
                SET durum = IF(deneme >= max_deneme, 'basarisiz', 'bekliyor'),
                    hata  = IF(deneme >= max_deneme, 'İş nabzı kesildi ve deneme hakkı bitti (işçi çöktü ya da kapatıldı).', hata),
                    ayrildi_at = NULL
              WHERE kuyruk = 'transkript' AND durum = 'calisiyor'
                AND ayrildi_at IS NOT NULL AND ayrildi_at < NOW() - INTERVAL :esik SECOND"
        );
        $guncelle->bindValue(':esik', $esik, PDO::PARAM_INT);
        $guncelle->execute();
        $adet = $guncelle->rowCount();

        if ($adet > 0) {
            Logger::warning('Nabzı kesilen Transkript işleri kuyruğa geri bırakıldı', ['adet' => $adet, 'esik_sn' => $esik], 'transkript');
        }

        return $adet;
    }

    /** Bu iş (sınıf + yük) kuyrukta bekliyor ya da çalışıyor mu? */
    public static function isKuyruktaMi(string $sinif, string $veri): bool
    {
        $statement = \App\Core\Database::connection()->prepare(
            'SELECT COUNT(*) FROM `' . self::tablo() . "` WHERE sinif = :sinif AND veri = :veri AND durum IN ('bekliyor', 'calisiyor')"
        );
        $statement->execute([':sinif' => $sinif, ':veri' => $veri]);

        return (int) $statement->fetchColumn() > 0;
    }

    /** Kuyruk tablosunun adı (config queue.table, yalnız harf / rakam / _). */
    public static function tablo(): string
    {
        return preg_replace('/[^a-zA-Z0-9_]/', '', (string) Config::get('queue.table', 'isler')) ?: 'isler';
    }

    /** @return array{bekleyen: int, calisan: int, en_eski_sn: int, takili: int} */
    private function sayilar(): array
    {
        $tablo = self::tablo();

        $sorgu = $this->db->prepare(
            "SELECT COALESCE(SUM(durum = 'bekliyor' AND hazir_at <= NOW()), 0) AS bekleyen,
                    COALESCE(MAX(CASE WHEN durum = 'bekliyor' AND hazir_at <= NOW()
                                      THEN TIMESTAMPDIFF(SECOND, hazir_at, NOW()) END), 0) AS en_eski,
                    COALESCE(SUM(durum = 'calisiyor' AND ayrildi_at > NOW() - INTERVAL :zaman SECOND), 0) AS calisan,
                    COALESCE(SUM(durum = 'calisiyor' AND ayrildi_at <= NOW() - INTERVAL :zaman2 SECOND), 0) AS takili
               FROM `" . $tablo . "`
              WHERE kuyruk = 'transkript'"
        );
        $sorgu->bindValue(':zaman', self::nabizEsigi(), PDO::PARAM_INT);
        $sorgu->bindValue(':zaman2', self::nabizEsigi(), PDO::PARAM_INT);
        $sorgu->execute();

        $satir = $sorgu->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'bekleyen'   => (int) ($satir['bekleyen'] ?? 0),
            'calisan'    => (int) ($satir['calisan'] ?? 0),
            'en_eski_sn' => max(0, (int) ($satir['en_eski'] ?? 0)),
            // Nabzı 5 dk'dır gelmeyen "çalışıyor" iş: ölmüş; işçi (ya da tarayıcı) bir sonraki turda geri bırakır.
            'takili'     => (int) ($satir['takili'] ?? 0),
        ];
    }

    /** Nabız ve zamanlayıcı kayıtlarının en yenisi (unix); hiç yoksa 0. */
    private static function sonGorulme(): int
    {
        $son = 0;

        try {
            $son = (int) Cache::get(self::NABIZ, 0);
        } catch (Throwable) {
        }

        $durum = Schedule::state();

        foreach (self::GOREVLER as $gorev) {
            $son = max($son, (int) ($durum[$gorev] ?? 0));
        }

        return $son;
    }

    /** "12 dakikadır sırada" — rapor sayfası ve ilerleme JSON'u. */
    public static function siradaMetni(int $saniye): string
    {
        $dakika = intdiv(max(0, $saniye), 60);

        return match (true) {
            $dakika < 1   => '1 dakikadan kısa süredir sırada',
            $dakika < 60  => $dakika . ' dakikadır sırada',
            default       => intdiv($dakika, 60) . ' saat ' . ($dakika % 60) . ' dakikadır sırada',
        };
    }
}
