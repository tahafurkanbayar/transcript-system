<?php
/**
 * =====================================================================
 *  IsNabzi – çalışan kuyruk işinin "hâlâ yaşıyorum" işareti
 * ---------------------------------------------------------------------
 *  Üç kilit bir işin çalıştığını söyler; üçü de zamanla bayatlar:
 *    · kuyruk satırı (isler.ayrildi_at): çekirdek Queue::releaseStuck,
 *      QUEUE_TIMEOUT (300 sn) aşılınca işi kuyruğa geri bırakır;
 *    · analizin aşama kilidi (transkript_analizler.updated_at) ve
 *    · parça kilidi (transkript_parcalar.updated_at): 300 sn sonra başka
 *      işçi devralabilir.
 *
 *  Uzun EVREN çağrısı (akışta en çok 900 sn, zincirde yedeklerle daha
 *  uzun) bu sınırı aşabiliyordu: iş hâlâ çalışırken ikinci bir işçi
 *  (ya da tarayıcı) aynı işi alıp AI'ı yeniden çağırabilirdi. Nabız, iş
 *  sürdükçe üçünü de ARALIK_SN'de bir tazeler (HttpProvider'ın ilerleme
 *  geri çağrısından da: istek sürerken). İş ölürse (süreç kapandı,
 *  PHP öldü) nabız durur; 5 dakika sonra çekirdek işi geri bırakır ve
 *  iş kaldığı yerden sürer (biten parçalar yeniden AI'a gitmez).
 *
 *  Çekirdeğe dokunulmaz: yalnız kuyruk satırının ayrildi_at'i güncellenir.
 *  Komut satırında ayrıca işçi nabzı (KuyrukDurumu::imle) yazılır: panel
 *  uzun bir işi "işçi yok" sanıp tarayıcıdan paralel iş başlatmaz.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Jobs;

use Closure;
use Modules\Transkript\Ai\HttpProvider;
use Modules\Transkript\Services\KuyrukDurumu;
use Throwable;

final class IsNabzi
{
    /** En sık bu kadar saniyede bir yazılır. */
    public const ARALIK_SN = 30;

    private static ?Closure $yaz = null;

    private static float $son = 0.0;

    /** İş başlarken: yazıcıyı kurar, HTTP ilerlemesine bağlar, ilk nabzı atar. */
    public static function basla(Closure $yaz): void
    {
        self::$yaz = $yaz;
        self::$son = 0.0;

        HttpProvider::nabizKancasi(static fn () => self::at());
        self::at();
    }

    /** Aralık dolduysa nabız yazar (hata işi durdurmaz). */
    public static function at(): void
    {
        if (self::$yaz === null || microtime(true) - self::$son < self::ARALIK_SN) {
            return;
        }

        self::$son = microtime(true);

        try {
            (self::$yaz)();
        } catch (Throwable) {
            // Veritabanı anlık yanıt vermediyse bir sonraki nabız dener.
        }

        KuyrukDurumu::imle();
    }

    public static function bitir(): void
    {
        self::$yaz = null;
        HttpProvider::nabizKancasi(null);
    }

    /** Testler: yazıcı kurulu mu? */
    public static function etkin(): bool
    {
        return self::$yaz !== null;
    }
}
