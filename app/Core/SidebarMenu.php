<?php
/**
 * =====================================================================
 *  SidebarMenu – Modüllerin sol menüye eklediği BÖLÜMLER
 * ---------------------------------------------------------------------
 *  module.json'daki "menu" bloğu modüle tek bir bağlantı verir ("Modüller"
 *  başlığı altında). Birden çok sayfası olan modül (ör. Transkript:
 *  Analizler, Üyeliğim, Ayarlar) kendi BÖLÜMÜNÜ kaydeder; menü bölümü
 *  "Genel"in hemen altına koyar ve o modül "Modüller" altında ayrıca
 *  görünmez. modules/Ad/events.php içinde:
 *
 *      SidebarMenu::add('Stok', static function (?User $user): ?array {
 *          if ($user === null || !$user->can('stok.view')) {
 *              return null;                         // bu role bölüm yok
 *          }
 *          return [
 *              'label' => 'Stok',
 *              'items' => [
 *                  ['route' => 'panel/stok', 'icon' => 'database', 'label' => 'Ürünler',
 *                   'badge' => 3, 'badge_tur' => 'uyari'],          // sayaç (uyari | bilgi)
 *                  ['route' => 'panel/stok/ayarlar', 'icon' => 'settings', 'label' => 'Ayarlar',
 *                   'can' => 'stok.admin', 'etiket' => 'Yeni'],      // küçük etiket
 *              ],
 *          ];
 *      });
 *
 *  Öğe alanları: route, icon, label (zorunlu); can (yetki; yoksa herkese),
 *  aktif (true / false: sayfanın etkin olup olmadığını modül söyler; yoksa
 *  adrese göre), badge (sayı; 0 gösterilmez), badge_tur, etiket (kısa
 *  metin, ör. plan adı), title (fareyle üstüne gelince açıklama), hash
 *  (sayfa içi bölüm: "tk_analiz" → adres#tk_analiz), vurgu (true: bölümün
 *  ana eylemi, ör. "Yeni analiz"; çerçeveli görünür).
 *
 *  Sağlayıcı her panel sayfasında bir kez çağrılır (tembel). Hata verirse
 *  bölüm atlanır ve günlüğe yazılır; menü çökmez. Menüde gizlemek güvenlik
 *  değildir: asıl koruma rotalardaki ara katmanlardadır.
 * =====================================================================
 */

declare(strict_types=1);

namespace App\Core;

use App\Core\Log\Logger;
use App\Models\User;
use Throwable;

final class SidebarMenu
{
    /** Sayaç renkleri: boş = gri, uyari = sarı (yanıt bekleyen), bilgi = mavi (süren iş). */
    public const BADGE_TURLERI = ['', 'uyari', 'bilgi'];

    /** @var array<string,callable(?User):(array<string,mixed>|null)> modül adı => sağlayıcı */
    private static array $providers = [];

    public static function add(string $modul, callable $provider): void
    {
        self::$providers[$modul] = $provider;
    }

    /** Modül kendi bölümünü kaydetti mi? (Varsa "Modüller" altında tekrar gösterilmez.) */
    public static function has(string $modul): bool
    {
        return isset(self::$providers[$modul]);
    }

    /**
     * @return list<array{label:string,items:list<array<string,mixed>>}>
     */
    public static function collect(?User $user): array
    {
        $gruplar = [];

        foreach (self::$providers as $modul => $provider) {
            try {
                $grup = $provider($user);
            } catch (Throwable $e) {
                Logger::error('Sol menü bölümü üretilemedi: ' . $modul . ' – ' . $e->getMessage(), ['modul' => $modul], 'error');

                continue;
            }

            if (is_array($grup) && ($grup = self::normalize($grup)) !== null) {
                $gruplar[] = $grup;
            }
        }

        return $gruplar;
    }

    /**
     * Eksik alanları tamamlar, geçersiz öğeyi atar; öğesi kalmayan bölüm null.
     *
     * @param  array<string,mixed> $grup
     * @return array{label:string,items:list<array<string,mixed>>}|null
     */
    public static function normalize(array $grup): ?array
    {
        $ogeler = [];

        foreach ((array) ($grup['items'] ?? []) as $oge) {
            if (!is_array($oge) || !is_string($oge['route'] ?? null) || trim((string) ($oge['label'] ?? '')) === '') {
                continue;
            }

            $tur = (string) ($oge['badge_tur'] ?? '');

            $ogeler[] = [
                'route'     => trim($oge['route'], '/'),
                'icon'      => (string) ($oge['icon'] ?? 'box'),
                'label'     => (string) $oge['label'],
                'can'       => isset($oge['can']) && is_string($oge['can']) ? $oge['can'] : null,
                'aktif'     => isset($oge['aktif']) ? (bool) $oge['aktif'] : null,
                'exact'     => (bool) ($oge['exact'] ?? false),
                'badge'     => max(0, (int) ($oge['badge'] ?? 0)),
                'badge_tur' => in_array($tur, self::BADGE_TURLERI, true) ? $tur : '',
                'etiket'    => (string) ($oge['etiket'] ?? ''),
                'title'     => (string) ($oge['title'] ?? ''),
                // Yalnız harf, rakam, - ve _: adrese kaçışsız eklenir.
                'vurgu'     => (bool) ($oge['vurgu'] ?? false),
                'hash'      => preg_match('/\A[A-Za-z][A-Za-z0-9_-]{0,63}\z/', (string) ($oge['hash'] ?? '')) === 1 ? (string) $oge['hash'] : '',
            ];
        }

        if ($ogeler === []) {
            return null;
        }

        return ['label' => (string) ($grup['label'] ?? 'Modül'), 'items' => $ogeler];
    }

    /** Testler için. */
    public static function forget(): void
    {
        self::$providers = [];
    }
}
