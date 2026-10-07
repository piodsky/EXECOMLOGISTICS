<?php
/**
 * Sidebar — items come from config/menu.php, in sections ('group'); an item is hidden when the user lacks its
 * permission (the page itself also enforces this) or its optional 'show' permission. A section heading is shown
 * only when the user has an item in it; Buying / Selling headings link to their Overview (Flow::canOpen()).
 *
 * @var string $activeKey  menu key of the page; 'overview-buy' / 'overview-sell' on the Overview pages
 */
$menuGroups = ['overview' => null, 'sell' => 'Selling', 'service' => 'Service', 'buy' => 'Buying', 'stock' => 'Stock', 'admin' => 'Admin'];
$menuItems  = [];
foreach (config('menu', []) as $key => $item) {
    if (can_open_menu($item) && (!isset($item['show']) || Auth::can($item['show']))) {
        $menuItems[$item['group'] ?? 'admin'][$key] = $item;
    }
}
?>
<aside class="sidebar" id="sidebar">
    <!-- Phone: the menu is a full page (Menu tab of the bottom bar); this head shows only there -->
    <div class="sidebar__phone-head">
        <span class="avatar"><?= icon('user') ?></span>
        <span><strong><?= e($user['full_name']) ?></strong><small><?= e($roleName) ?> · <?= e(Branch::label()) ?></small></span>
    </div>
    <h2 class="sidebar__phone-title">Menu</h2>
    <nav class="sidebar__nav" aria-label="Main menu">
        <?php foreach ($menuGroups as $group => $groupLabel): ?>
            <?php if (empty($menuItems[$group])) continue; ?>
            <?php if ($groupLabel !== null): ?>
                <?php $overview = in_array($group, ['buy', 'sell'], true) && Flow::canOpen($group); ?>
                <?php if ($overview): ?>
                    <a class="nav-group<?= $activeKey === 'overview-' . $group ? ' is-active' : '' ?>" href="<?= e(url($group === 'buy' ? 'pages/buying.php' : 'pages/selling.php')) ?>"
                       title="<?= e($groupLabel) ?> overview: every step at a glance"<?= $activeKey === 'overview-' . $group ? ' aria-current="page"' : '' ?>>
                        <span><?= e($groupLabel) ?></span><small>Overview <?= icon('chevron-right') ?></small>
                    </a>
                <?php else: ?>
                    <span class="nav-group"><span><?= e($groupLabel) ?></span></span>
                <?php endif; ?>
            <?php endif; ?>
            <?php foreach ($menuItems[$group] as $key => $item): ?>
                <a href="<?= e(url(menu_path($item))) ?>"
                   class="nav-link<?= $key === $activeKey ? ' is-active' : '' ?>" title="<?= e($item['label']) ?>"
                   <?= $key === $activeKey ? 'aria-current="page"' : '' ?>>
                    <?= icon($item['icon']) ?>
                    <span><?= e($item['label']) ?></span>
                </a>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar__bottom">
        <?php if (Auth::can('pos.access')): ?>
            <a class="scan-card" href="<?= e(url('pages/pos.php')) ?>" data-scan-trigger title="Scan barcode (F2)">
                <?= icon('barcode') ?>
                <span>
                    <strong>Scan Barcode</strong>
                    <small>Use scanner or type</small>
                </span>
                <kbd>F2</kbd>
            </a>
        <?php endif; ?>

        <form class="sidebar__signout" action="<?= e(url('logout.php')) ?>" method="post">
            <?= Csrf::field() ?>
            <button type="submit" class="btn btn--light btn--block"><?= icon('logout') ?> Sign out</button>
        </form>
        <div class="sidebar__footer">
            <strong>EXECOM Logistics</strong>
            <small>Inventory <span>•</span> Sales <span>•</span> Distribution</small>
            <small class="sidebar__version">v<?= e(config('app.version')) ?></small>
        </div>
    </div>
</aside>
<div class="sidebar-backdrop" data-sidebar-toggle hidden></div>
