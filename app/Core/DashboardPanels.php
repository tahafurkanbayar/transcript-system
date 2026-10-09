<?php
/**
 * =====================================================================
 *  DashboardPanels – Modüllerin kontrol paneline eklediği bölümler
 * ---------------------------------------------------------------------
 *  DashboardCards tek sayılık kart içindir. Bir modül panelde kısa bir
 *  LİSTE göstermek isterse (ör. "son 5 sipariş") kartların altına bir
 *  bölüm ekler; modules/Ad/events.php içinde:
 *
 *      DashboardPanels::add('siparis-son', static function (?User $user): ?string {
 *          if ($user === null || !$user->can('siparis.view')) {
 *              return null;                       // bu role bölüm yok
 *          }
 *          return View::capture('Siparis::_parcalar/son', [...]);
 *      });
 *
 *  Dönen değer HTML'dir ve olduğu gibi basılır: içindeki her metni
 *  modülün kendi görünümü e() ile kaçışlar (çekirdek görünüm kuralı).
 *  Sağlayıcı yalnızca kontrol paneli açılırken çağrılır (tembel). Hata
 *  verirse bölüm atlanır ve günlüğe yazılır; panel çökmez.
 *
 *  transcript-system için eklendi (2026-10-09, kullanıcı kararı:
 *  yöneticiye "Son analizler" listesi). Modüle özel kod içermez.
 * =====================================================================
 */

declare(strict_types=1);

namespace App\Core;

use App\Core\Log\Logger;
use App\Models\User;
use Throwable;

final class DashboardPanels
{
    /** @var array<string,callable(?User):(string|null)> */
    private static array $providers = [];

    public static function add(string $id, callable $provider): void
    {
        self::$providers[$id] = $provider;
    }

    /** @return list<string> boş olmayan bölümlerin HTML'i, eklenme sırasıyla */
    public static function collect(?User $user): array
    {
        $panels = [];

        foreach (self::$providers as $id => $provider) {
            try {
                $html = $provider($user);
            } catch (Throwable $e) {
                Logger::error('Panel bölümü üretilemedi: ' . $id . ' – ' . $e->getMessage(), ['bolum' => $id], 'error');

                continue;
            }

            if (is_string($html) && trim($html) !== '') {
                $panels[] = $html;
            }
        }

        return $panels;
    }

    /** Testler için. */
    public static function forget(): void
    {
        self::$providers = [];
    }
}
