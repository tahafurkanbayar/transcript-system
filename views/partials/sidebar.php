<?php
/**
 * =====================================================================
 *  PARÇA: Sol menü (panel)
 * ---------------------------------------------------------------------
 *  Menü öğeleri bir DİZİDEN üretilir; her öğe bir yetkiye bağlıdır.
 *  Asıl koruma routes/web.php'deki ara katmanlardadır; menü gizleme
 *  yalnızca arayüz düzenidir.
 *
 *  SIRA: Genel · modüllerin kendi bölümleri (SidebarMenu; ör. Transkript)
 *  · Modüller (tek bağlantılı modüller) · Site ve iletişim · Yönetim ·
 *  Hesabım. Tek öğeli başlıklar azaltıldı (İçerik + İletişim birleşti).
 *  Altta kullanıcı kartı, "Siteyi görüntüle" ve "Çıkış"; telefonda
 *  çekmecenin sağ üstünde kapatma düğmesi.
 * =====================================================================
 */

use App\Core\Modules\Modules;
use App\Core\Setting;
use App\Core\SidebarMenu;

/** @var App\Models\User|null $currentUser */
$user = $currentUser ?? null;

$unread = can('messages.view')
    ? (new App\Repositories\MessageRepository(App\Core\Database::connection()))->countUnread()
    : 0;

/* Ayarlar tek satırda değil, konu başlıklarına göre gruplanmıştır
 * (bkz. SettingsController). Alt menü bu grupları birebir yansıtır;
 * yeni bir ayar grubu eklendiğinde menüye elle dokunmak gerekmez. */
$ayarAltMenu = [];

foreach (Setting::groupLabels() as $anahtar => $baslik) {
    $ayarAltMenu[] = [
        'route' => 'panel/ayarlar/' . $anahtar,
        'label' => $baslik,
    ];
}

/* ---------------------------------------------------------------------
 *  MODÜL MENÜSÜ
 * ---------------------------------------------------------------------
 *  1) Kendi BÖLÜMÜNÜ kaydeden modül (SidebarMenu::add, events.php) o
 *     bölümle "Genel"in altına girer: birden çok sayfa, sayaç, etiket.
 *  2) Öteki açık modüller module.json → "menu" bloğundaki TEK
 *     bağlantıyla "Modüller" başlığı altında görünür:
 *
 *      "menu": { "route": "panel/stok", "icon": "database",
 *                "label": "Stok", "can": "stok.view" }
 *
 *  Çekirdek, hangi modüllerin var olduğunu bilmez — listeyi modüllerin
 *  kendisi doldurur.
 * ------------------------------------------------------------------ */
$modulBolumleri = SidebarMenu::collect($user);
$modulOgeleri   = [];

foreach (Modules::enabled() as $modul) {
    // "menu": false → modül menüde görünmek istemiyor (bkz. Module::parseMenu).
    if ($modul->menu !== null && !SidebarMenu::has($modul->ad)) {
        $modulOgeleri[] = $modul->menu;
    }
}

$menu = [
    [
        'label' => 'Genel',
        'items' => [
            // exact: "panel" her panel/* adresinin öneki; tam eşleşme olmazsa hep aktif görünür.
            ['route' => 'panel', 'icon' => 'dashboard', 'label' => 'Kontrol Paneli', 'can' => 'dashboard.view', 'exact' => true],
        ],
    ],
    ...$modulBolumleri,
];

if ($modulOgeleri !== []) {
    $menu[] = ['label' => 'Modüller', 'items' => $modulOgeleri];
}

$menu[] = [
    'label' => 'Site ve iletişim',
    'items' => [
        ['route' => 'panel/sayfalar', 'icon' => 'files', 'label' => 'Sayfalar', 'can' => 'pages.view'],
        ['route' => 'panel/mesajlar', 'icon' => 'inbox', 'label' => 'Mesajlar', 'can' => 'messages.view', 'badge' => $unread, 'badge_tur' => 'uyari',
         'title' => $unread > 0 ? $unread . ' okunmamış mesaj' : ''],
        ['route' => 'panel/eposta',   'icon' => 'send',  'label' => 'E-posta',  'can' => 'mail.view'],
    ],
];

$menu[] = [
    'label' => 'Yönetim',
    'items' => [
        ['route' => 'panel/kullanicilar', 'icon' => 'users',    'label' => 'Kullanıcılar',   'can' => 'users.view'],
        ['route' => 'panel/ayarlar',      'icon' => 'settings', 'label' => 'Site Ayarları',  'can' => 'settings.view', 'children' => $ayarAltMenu],
        ['route' => 'panel/sistem',       'icon' => 'server',   'label' => 'Sistem Bilgisi', 'can' => 'system.view'],
    ],
];

$menu[] = [
    'label' => 'Hesabım',
    'items' => [
        ['route' => 'panel/hesabim', 'icon' => 'user', 'label' => 'Profilim', 'can' => 'profile.view'],
    ],
];
?>
<aside class="cy-sidebar" id="cy_sidebar" aria-label="Ana gezinme">

    <a class="cy-sidebar__brand" href="<?= e(url('panel')) ?>">
        <span class="cy-sidebar__logo">
            <?= logo_img() ?>
        </span>
        <span class="cy-sidebar__title">
            <strong><?= e(Setting::get('site_adi', $appName ?? '')) ?></strong>
            <?php /* Marka site adından farklıysa altında küçük satır. */ ?>
            <?php if (site_brand() !== Setting::get('site_adi', $appName ?? '')): ?>
                <span><?= e(site_brand()) ?></span>
            <?php endif; ?>
        </span>
    </a>
    <?php /* Yalnız telefonda / tablette (çekmece) görünür; app.js kapatır. */ ?>
    <button type="button" class="cy-sidebar__close" id="cy_sidebar_close" aria-label="Menüyü kapat">
        <?= icon('close', 'cy-icon cy-icon--sm') ?>
    </button>

    <nav class="cy-sidebar__nav">
        <?php foreach ($menu as $group): ?>
            <?php
            $visible = array_filter($group['items'], static fn (array $item): bool => ($item['can'] ?? null) === null || can($item['can']));

            if ($visible === []) {
                continue;
            }
            ?>
            <div class="cy-nav-group">
                <span class="cy-nav-group__label"><?= e($group['label']) ?></span>

                <?php foreach ($visible as $item): ?>
                    <?php $children = $item['children'] ?? []; ?>

                    <?php if ($children === []): ?>
                        <?php
                        // Modül bölümü etkin sayfayı kendisi söyleyebilir (aktif); yoksa adrese göre.
                        $aktif = $item['aktif'] ?? is_route($item['route'], $item['exact'] ?? false);
                        $badge = (int) ($item['badge'] ?? 0);
                        $tur   = (string) ($item['badge_tur'] ?? '');
                        ?>
                        <a class="cy-nav-link<?= $aktif ? ' is-active' : '' ?><?= $badge > 0 ? ' has-badge' : '' ?><?= !empty($item['vurgu']) ? ' cy-nav-link--vurgu' : '' ?>"
                           href="<?= e(url($item['route']) . (($item['hash'] ?? '') !== '' ? '#' . $item['hash'] : '')) ?>"
                           data-title="<?= e($item['label'] . ($badge > 0 ? ' (' . $badge . ')' : '')) ?>"
                           <?= ($item['title'] ?? '') !== '' ? 'title="' . e($item['title']) . '"' : '' ?>
                           <?= $aktif ? 'aria-current="page"' : '' ?>>
                            <?= icon($item['icon']) ?>
                            <span class="cy-nav-link__text"><?= e($item['label']) ?></span>
                            <?php if (($item['etiket'] ?? '') !== ''): ?>
                                <span class="cy-nav-link__tag"><?= e($item['etiket']) ?></span>
                            <?php endif; ?>
                            <?php if ($badge > 0): ?>
                                <span class="cy-nav-link__badge<?= $tur !== '' ? ' cy-nav-link__badge--' . e($tur) : '' ?>"><?= $badge > 99 ? '99+' : $badge ?></span>
                            <?php endif; ?>
                        </a>
                    <?php else: ?>
                        <?php $active = is_route($item['route']); ?>
                        <div class="cy-nav-item<?= $active ? ' is-open' : '' ?>">
                            <button type="button"
                                    class="cy-nav-link cy-nav-parent<?= $active ? ' is-active' : '' ?>"
                                    data-title="<?= e($item['label']) ?>"
                                    aria-expanded="<?= $active ? 'true' : 'false' ?>">
                                <?= icon($item['icon']) ?>
                                <span class="cy-nav-link__text"><?= e($item['label']) ?></span>
                                <?= icon('chevron', 'cy-icon cy-icon--sm cy-nav-parent__chevron') ?>
                            </button>

                            <div class="cy-nav-submenu">
                                <div class="cy-nav-submenu__inner">
                                    <a class="cy-nav-sublink<?= is_route($item['route'], true) ? ' is-active' : '' ?>"
                                       href="<?= e(url($item['route'])) ?>">Genel Bakış</a>

                                    <?php foreach ($children as $child): ?>
                                        <a class="cy-nav-sublink<?= is_route($child['route']) ? ' is-active' : '' ?>"
                                           href="<?= e(url($child['route'])) ?>"
                                           <?= is_route($child['route']) ? 'aria-current="page"' : '' ?>>
                                            <?= e($child['label']) ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </nav>

    <?php if ($user !== null): ?>
        <div class="cy-sidebar__footer">
            <a class="cy-side-user text-decoration-none" href="<?= e(url('panel/hesabim')) ?>" title="Profilim">
                <?php if ($user->avatarUrl() !== ''): ?>
                    <img class="cy-avatar cy-avatar--sm" src="<?= e($user->avatarUrl()) ?>" alt="">
                <?php else: ?>
                    <span class="cy-avatar cy-avatar--sm cy-avatar--initial"><?= e($user->initials()) ?></span>
                <?php endif; ?>

                <span class="cy-side-user__info">
                    <span class="cy-side-user__name"><?= e($user->fullName()) ?></span>
                    <span class="cy-side-user__role"><?= e($user->roleLabel()) ?></span>
                </span>
            </a>
            <div class="cy-side-actions">
                <a class="cy-side-action" href="<?= e(url('')) ?>" target="_blank" rel="noopener" title="Siteyi yeni sekmede aç" aria-label="Siteyi yeni sekmede aç">
                    <?= icon('external', 'cy-icon cy-icon--sm') ?>
                </a>
                <form method="post" action="<?= e(url('cikis')) ?>" class="m-0">
                    <?= csrf_field() ?>
                    <button type="submit" class="cy-side-action" title="Çıkış yap" aria-label="Çıkış yap">
                        <?= icon('logout', 'cy-icon cy-icon--sm') ?>
                    </button>
                </form>
            </div>
        </div>
    <?php endif; ?>
</aside>
