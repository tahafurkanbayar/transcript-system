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
use App\Core\Database;
use App\Core\HomePage;
use App\Core\Request;
use App\Core\Queue\Queue;
use App\Core\Schedule\Schedule;
use App\Models\User;
use Modules\Transkript\Ayarlar;
use Modules\Transkript\Controllers\AnasayfaController;
use Modules\Transkript\Repositories\KaynakRepository;
use Modules\Transkript\Services\AnalizBaslatici;
use Modules\Transkript\Services\KuyrukDurumu;
use Modules\Transkript\TranskriptPolicy;

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

/* ---------------------------------------------------------------------
 *  KONTROL PANELİ KARTLARI (DashboardCards kancası; çekirdeğe dokunmadan)
 * ---------------------------------------------------------------------
 *  Raporlar: transkript.view olan herkese. Yönetici herkesinkini, öteki
 *  roller GÖREBİLDİĞİNİ (kütüphanesi + paylaşılan; listedeki "Raporu
 *  hazır" ile aynı sayı) görür. Süren ve hatalı sayısı ile bugünkü
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
    $sayi   = $policy->isAdmin() ? $repo->istatistik(null) : $repo->gorunenIstatistik($user->id);
    $hak    = $policy->canAnalyze() ? (new AnalizBaslatici($db, $user))->hakDurumu() : null;
    $alt    = $policy->canAnalyze() ? array_filter([
        $sayi['suruyor'] > 0 ? $sayi['suruyor'] . ' sürüyor' : '',
        $sayi['hata'] > 0 ? $sayi['hata'] . ' hata' : '',
        $hak !== null && !$hak['muaf'] && $hak['gunluk_sinir'] > 0 ? 'bugünkü hakkınız ' . $hak['gunluk'] . ' / ' . $hak['gunluk_sinir'] : '',
    ]) : [];

    return [
        'ikon'   => 'files',
        'renk'   => $sayi['hata'] > 0 && $policy->canAnalyze() ? 'warning' : 'success',
        'etiket' => $policy->isAdmin() ? 'Transkript raporu' : 'Transkript raporlarınız',
        'deger'  => $sayi['kaynak'],
        'ipucu'  => $alt !== [] ? implode(' · ', $alt) : ($policy->isAdmin() ? 'raporu hazır video' : 'kütüphanenizdeki ve sizinle paylaşılan'),
        'yol'    => 'panel/transkript',
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
 *  KONTROL PANELİ: kuyruk işçisi çalışmıyorsa yöneticiye kırmızı kart
 * ---------------------------------------------------------------------
 *  Çekirdeğin Sistem → Kuyruklar kartı modülü tanımaz; uyarı çekirdeğe
 *  dokunmadan DashboardCards kancasıyla eklenir. Sağlayıcı yalnız
 *  kontrol paneli açılırken çağrılır (tek kısa sorgu).
 * ------------------------------------------------------------------ */
DashboardCards::add('transkript-kuyruk', static function (?User $user): ?array {
    if ($user === null || !$user->can('transkript.admin')) {
        return null;
    }

    $durum = (new KuyrukDurumu(Database::connection()))->getir();

    if (!$durum['takildi']) {
        return null;
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
    });
}
