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
 * =====================================================================
 */

declare(strict_types=1);

use App\Core\Queue\Queue;
use App\Core\Schedule\Schedule;
use Modules\Transkript\Ayarlar;

if (PHP_SAPI === 'cli' && (bool) Ayarlar::get('kuyruk.zamanlanmis_gorev', true)) {
    Schedule::call('transkript-kuyrugu', static function (): void {
        $bitis = time() + max(30, (int) Ayarlar::get('kuyruk.butce_sn', 240));
        $sinir = max(1, (int) Ayarlar::get('kuyruk.en_cok_is', 50));

        for ($i = 0; $i < $sinir && time() < $bitis; $i++) {
            if (!Queue::runNext('transkript')) {
                break;
            }
        }
    })->everyMinute()->describe('Transkript analiz işlerini çalıştırır');
}
