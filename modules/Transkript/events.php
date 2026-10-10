<?php
/**
 * =====================================================================
 *  Transkript MODÜLÜ – Açılışta kurulan bağlantılar
 * ---------------------------------------------------------------------
 *  Modül açıkken her istekte bir kez yüklenir (Modules::boot). Burada
 *  yalnız KAYIT yapılır; ağır iş yok.
 *
 *  ZAMANLANMIŞ GÖREV "transkript-kuyrugu"
 *    Komut satırında bootstrap önce routes/schedule.php'yi, ardından
 *    konsol çekirdeği Modules::boot() ile bu dosyayı yükler; görev
 *    çekirdeğe dokunmadan "php cy schedule:run" listesine girer.
 *
 *    Starter'ın "kuyruk-isle" görevi dakikada en çok 10 işi, kuyruk
 *    ayırmadan çalıştırır. Uzun bir video 15–25 iş açar ve her AI işi
 *    10–60 saniye sürer; tek başına yetişmez. Bu görev yalnız
 *    "transkript" kuyruğunu, bütçe dolana kadar (varsayılan 240 sn)
 *    işler. Görevler ada göre kilitli olduğundan önceki çalışma
 *    sürerken yenisi başlamaz; "kuyruk-isle" ile birlikte en çok iki
 *    iş aynı anda yürür. İşler buna hazırdır (aşama ve parça kilidi).
 *
 *    Kalıcı işçi (php cy queue:work, supervisor) kullanan kurulumda
 *    config.php → kuyruk.zamanlanmis_gorev = false.
 *
 *    Görev her turda "işçi görüldü" nabzı yazar (KuyrukDurumu::imle);
 *    nabız 2 dakikadır yoksa ve iş sırada bekliyorsa panel "Kuyruk
 *    işçisi çalışmıyor" uyarısı gösterir. Windows'ta cron yerine proje
 *    kökündeki kuyruk-isci.bat (README → "Kuyruk işçisi").
 * =====================================================================
 */

declare(strict_types=1);

use App\Core\DashboardCards;
use App\Core\DashboardPanels;
use App\Core\Database;
use App\Core\Env;
use App\Core\HomePage;
use App\Core\Log\Logger;
use App\Core\Request;
use App\Core\Queue\Queue;
use App\Core\Schedule\Schedule;
use App\Core\View;
use App\Models\User;
use Modules\Transkript\Ai\Ai;
use Modules\Transkript\Ayarlar;
use Modules\Transkript\Controllers\AnasayfaController;
use Modules\Transkript\Controllers\AyarController;
use Modules\Transkript\Repositories\AiKullanimRepository;
use Modules\Transkript\Repositories\KaynakRepository;
use Modules\Transkript\Repositories\UyelikRepository;
use Modules\Transkript\Services\AnalizBaslatici;
use Modules\Transkript\Services\HesapTemizligi;
use Modules\Transkript\Services\KuyrukDurumu;
use Modules\Transkript\TranskriptPolicy;
use Modules\Transkript\Uyelik\Planlar;
use Modules\Transkript\Uyelik\UyelikYonetimi;

if (PHP_SAPI === 'cli' && (bool) Ayarlar::get('kuyruk.zamanlanmis_gorev', true)) {
    Schedule::call('transkript-kuyrugu', static function (): void {
        $bitis = time() + max(30, (int) Ayarlar::get('kuyruk.butce_sn', 240));
        $sinir = max(1, (int) Ayarlar::get('kuyruk.en_cok_is', 50));

        KuyrukDurumu::imle();

        for ($i = 0; $i < $sinir && time() < $bitis; $i++) {
            if (!Queue::runNext('transkript')) {
                break;
            }

            KuyrukDurumu::imle();
        }
    })->everyMinute()->describe('Transkript analiz işlerini çalıştırır');
}

/* Silinen hesapların kütüphane, sohbet, ilgi alanı ve kişisel özet
 * kayıtları her gece silinir (HesapTemizligi; aydınlatma metni bunu söyler). */
if (PHP_SAPI === 'cli') {
    Schedule::call('transkript-hesap-temizligi', static function (): void {
        $silinen = array_filter((new HesapTemizligi(Database::connection()))->temizle());

        if ($silinen !== []) {
            Logger::info('Silinen hesapların Transkript kayıtları temizlendi', $silinen, 'transkript');
        }
    })->daily('03:40')->describe('Silinen hesapların Transkript kişisel kayıtlarını siler');

    /* Pro üyelik: süresi dolan Ücretsiz plana döner, bitime yakın olana
     * e-posta (Uyelik\UyelikYonetimi). */
    Schedule::call('transkript-uyelik-suresi', static function (): void {
        $yonetim = new UyelikYonetimi(Database::connection());
        $yonetim->suresiDolanlariIsle();
        $yonetim->hatirlat();
    })->hourly()->describe('Süresi dolan Pro üyelikleri Ücretsiz plana döndürür, bitime yakın olanlara e-posta atar');
}

/* Görev zamanlayıcısı kurulmamış sunucuda da Pro süresi kaçmasın: panel
 * istekleri arasında en çok 10 dakikada bir (zaman damgası dosyada;
 * sırası gelmediyse veritabanına gidilmez). */
if (PHP_SAPI !== 'cli' && Env::exists(CY_BASE . '/.env')) {
    UyelikYonetimi::araSira(static fn (): PDO => Database::connection());
}

/* ---------------------------------------------------------------------
 *  KONTROL PANELİ KARTLARI (DashboardCards kancası; çekirdeğe dokunmadan)
 * ---------------------------------------------------------------------
 *  Raporlar: transkript.view olan herkese. Yönetici herkesinkini, öteki
 *  roller KENDİSİNİNKİNİ (kütüphanesi; listedeki "Raporu hazır" ile aynı
 *  sayı, paylaşılanlar hariç) görür. Süren ve hatalı sayısı ile bugünkü
 *  analiz hakkı yalnız analiz başlatabilene (TranskriptPolicy).
 *  Bugünkü kullanım: yalnız yönetici.
 * ------------------------------------------------------------------ */
DashboardCards::add('transkript-raporlar', static function (?User $user): ?array {
    if ($user === null || !$user->can('transkript.view')) {
        return null;
    }

    $db     = Database::connection();
    $policy = new TranskriptPolicy($user);
    $repo   = new KaynakRepository($db);
    $sayi   = $policy->isAdmin() ? $repo->istatistik(null) : $repo->kendiIstatistik($user->id);
    // Bugünkü hak ayrı kartta ("Planınız"); burada yalnız kayıtlar.
    $alt    = $policy->canAnalyze() ? array_filter([
        $sayi['suruyor'] > 0 ? $sayi['suruyor'] . ' sürüyor' : '',
        $sayi['hata'] > 0 ? $sayi['hata'] . ' hata' : '',
    ]) : [];

    return [
        'ikon'   => 'files',
        'renk'   => $sayi['hata'] > 0 && $policy->canAnalyze() ? 'warning' : 'success',
        'etiket' => $policy->isAdmin() ? 'Transkript raporu' : 'Transkript raporlarınız',
        'deger'  => $sayi['kaynak'],
        'ipucu'  => $alt !== [] ? implode(' · ', $alt) : ($policy->isAdmin() ? 'raporu hazır video' : 'kütüphanenizdeki'),
        'yol'    => 'panel/transkript',
    ];
});

/* ---------------------------------------------------------------------
 *  KONTROL PANELİ: üyelik planı (yönetici olmayan) ve üyelikler (yönetici)
 * ---------------------------------------------------------------------
 *  Üye planını, bugünkü hakkını (AnalizBaslatici::hakDurumu, listeyle
 *  aynı sayaç) ve Pro'nun bitişini görür; kart Üyeliğim'e gider.
 *  Yönetici Pro üye sayısını ve onay bekleyen talepleri görür.
 * ------------------------------------------------------------------ */
DashboardCards::add('transkript-plan', static function (?User $user): ?array {
    $plan = Planlar::plan($user);

    if ($user === null || $plan === null || !$user->can('transkript.view')) {
        return null;
    }

    $db  = Database::connection();
    $hak = (new AnalizBaslatici($db, $user))->hakDurumu();
    $pro = $plan === Planlar::PRO;

    try {
        $ozet = $pro ? (new UyelikRepository($db))->ozet($user->id) : null;
    } catch (Throwable) {
        $ozet = null; // tablo henüz yok
    }

    return [
        'ikon'   => $pro ? 'star' : 'user',
        'renk'   => $hak['bekleme_sn'] > 0 ? 'warning' : 'brand',
        'etiket' => 'Planınız: ' . Planlar::ad($plan),
        'deger'  => !Planlar::haklar($user)['analiz'] ? 'Analiz kapalı'
            : ($hak['gunluk_sinir'] > 0 ? $hak['gunluk'] . ' / ' . $hak['gunluk_sinir'] . ' analiz' : 'Sınırsız analiz'),
        'ipucu'  => match (true) {
            !$pro                                     => 'bugünkü hakkınız · Pro\'ya geç →',
            $ozet !== null && $ozet['bitis'] !== null => 'bugünkü hakkınız · Pro bitişi ' . date('d.m.Y', (int) strtotime($ozet['bitis'])),
            default                                   => 'bugünkü hakkınız · Pro, süresiz',
        },
        'yol'    => 'panel/transkript/uyelik',
    ];
});

DashboardCards::add('transkript-uyelikler', static function (?User $user): ?array {
    if ($user === null || !$user->can('transkript.admin')) {
        return null;
    }

    try {
        $repo     = new UyelikRepository(Database::connection());
        $bekleyen = $repo->bekleyenSayisi();
        $pro      = count($repo->proUyeler());
    } catch (Throwable) {
        return null; // tablo henüz yok (migration bekliyor)
    }

    return [
        'ikon'   => 'star',
        'renk'   => $bekleyen > 0 ? 'warning' : 'brand',
        'etiket' => 'Pro üye',
        'deger'  => $pro,
        'ipucu'  => $bekleyen > 0 ? $bekleyen . ' talep onay bekliyor →' : 'bekleyen talep yok',
        'yol'    => 'panel/transkript/uyelikler',
    ];
});

DashboardCards::add('transkript-bugun', static function (?User $user): ?array {
    if ($user === null || !$user->can('transkript.admin')) {
        return null;
    }

    $bugun = (new KaynakRepository(Database::connection()))->bugunkuKullanim();

    return [
        'ikon'   => 'activity',
        'renk'   => 'brand',
        'etiket' => 'Bugünkü yapay zekâ kullanımı',
        'deger'  => number_format($bugun['token'], 0, ',', '.') . ' token',
        'ipucu'  => '$' . number_format($bugun['maliyet'], 4, ',', '.') . ' tahmini (ücretli katman fiyatıyla)',
        'yol'    => 'panel/transkript/ayarlar',
    ];
});

/* ---------------------------------------------------------------------
 *  KONTROL PANELİ: yapay zekâ sağlayıcıları (yalnız yönetici)
 * ---------------------------------------------------------------------
 *  Kaç sağlayıcı hazır, ilk açılacak olan ne zaman, bugün kaç çağrı.
 *  Ayrıntı Transkript ayarlarındaki sağlayıcı tablosunda (aynı veri:
 *  AyarController::zincirTablosu). Hiçbiri hazır değilse kırmızı.
 * ------------------------------------------------------------------ */
DashboardCards::add('transkript-saglayicilar', static function (?User $user): ?array {
    if ($user === null || !$user->can('transkript.admin')) {
        return null;
    }

    $tablo = AyarController::zincirTablosu(Ai::zincirDurumu(), (new AiKullanimRepository(Database::connection()))->saglayiciOzeti());
    $kullanilan = array_values(array_filter($tablo, static fn (array $h): bool => $h['hal'] !== 'atlandi'));
    $hazir  = count(array_filter($kullanilan, static fn (array $h): bool => $h['hal'] === 'hazir'));
    $cagri  = array_sum(array_column($tablo, 'bugun_cagri'));
    $dinlenen = array_values(array_filter($kullanilan, static fn (array $h): bool => $h['hal'] !== 'hazir'));
    usort($dinlenen, static fn (array $a, array $b): int => $a['kalan_dk'] <=> $b['kalan_dk']);

    return [
        'ikon'   => 'server',
        'renk'   => $hazir === 0 ? 'danger' : ($dinlenen !== [] ? 'warning' : 'success'),
        'etiket' => 'Yapay zekâ sağlayıcıları',
        'deger'  => $hazir . ' / ' . count($kullanilan) . ' hazır',
        'ipucu'  => implode(' · ', array_filter([
            $dinlenen !== [] ? $dinlenen[0]['etiket'] . ' ' . $dinlenen[0]['acilis'] . ' açılır' : '',
            'bugün ' . $cagri . ' çağrı',
        ])),
        'yol'    => 'panel/transkript/ayarlar#tk-saglayicilar',
    ];
});

/* ---------------------------------------------------------------------
 *  KONTROL PANELİ: kuyruk işçisi görülmüyorsa yöneticiye kart
 * ---------------------------------------------------------------------
 *  Çekirdeğin Sistem → Kuyruklar kartı modülü tanımaz; uyarı çekirdeğe
 *  dokunmadan DashboardCards kancasıyla eklenir. İşçi çalışıyorsa kart
 *  yok. Çalışmıyorsa: tarayıcıdan işleme açıksa sarı "tarayıcı
 *  üzerinden işleniyor" (sayfa açıkken ilerler), kapalıysa kırmızı.
 * ------------------------------------------------------------------ */
DashboardCards::add('transkript-kuyruk', static function (?User $user): ?array {
    if ($user === null || !$user->can('transkript.admin')) {
        return null;
    }

    $durum = (new KuyrukDurumu(Database::connection()))->getir();

    if ($durum['bilinmiyor'] || KuyrukDurumu::isciGorulduMu()) {
        return null;
    }

    if (KuyrukDurumu::tarayicidanIslenir()) {
        return [
            'ikon'   => 'refresh',
            'renk'   => $durum['takildi'] ? 'warning' : 'brand',
            'etiket' => 'Kuyruk tarayıcı üzerinden işleniyor',
            'deger'  => $durum['bekleyen'] . ' iş sırada',
            'ipucu'  => 'işçi yok; analiz, panel sayfası açıkken ilerler',
            'yol'    => 'panel/transkript',
        ];
    }

    return [
        'ikon'   => 'alert',
        'renk'   => 'danger',
        'etiket' => 'Kuyruk işçisi çalışmıyor',
        'deger'  => $durum['bekleyen'] . ' iş sırada',
        'ipucu'  => KuyrukDurumu::KOMUT_ELLE,
        'yol'    => 'panel/transkript',
    ];
});

/* ---------------------------------------------------------------------
 *  KONTROL PANELİ: "Son analizler" listesi (yalnız yönetici)
 * ---------------------------------------------------------------------
 *  Çekirdeğin DashboardPanels kancası; son 5 analiz, durum, başlatan,
 *  süre. Herkesin kaydıdır: yalnız transkript.admin görür.
 * ------------------------------------------------------------------ */
DashboardPanels::add('transkript-son-analizler', static function (?User $user): ?string {
    if ($user === null || !$user->can('transkript.admin')) {
        return null;
    }

    return View::capture('Transkript::_parcalar/son-analizler', [
        'analizler' => (new KaynakRepository(Database::connection()))->sonAnalizler(5),
    ]);
});

/* ---------------------------------------------------------------------
 *  ANA SAYFA: sitenin vitrini modülün tanıtım sayfasıdır
 * ---------------------------------------------------------------------
 *  Çekirdeğin "" rotası modüle bırakılamaz (ilk kayıt kazanır); çekirdek
 *  HomePage kancasıyla çiziciyi sorar. Ayar kapalıysa (config.php →
 *  anasayfa.tanitim ya da Transkript ayarları → Ana sayfa) false döner
 *  ve Panel → Ayarlar → Ana Sayfa'daki genel vitrin görünür. Ayar
 *  yalnız ana sayfa istenince okunur.
 * ------------------------------------------------------------------ */
if (PHP_SAPI !== 'cli') {
    HomePage::set('Transkript', static function (Request $request): bool {
        if (!(bool) Ayarlar::get('anasayfa.tanitim', true)) {
            return false;
        }

        (new AnasayfaController())->goster($request);

        return true;
    },
        // Site Ayarları → Ana Sayfa uyarısı: tanıtım sayfası açıkken o ayarlar etkisiz.
        static fn (): bool => (bool) Ayarlar::get('anasayfa.tanitim', true),
        'panel/transkript/ayarlar',
    );
}
