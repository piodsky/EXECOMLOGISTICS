<?php
/**
 * Phone bottom navigation (shown at <= 768px, like a mobile app tab bar): the user's four most-used pages
 * (the first four the user can open from $bnTabs, in that order) + "Menu", which opens the full slide-in
 * (the sidebar, app.js [data-sidebar-toggle]). The active tab follows the page's menu key; pages that are not a
 * tab light up "Menu".
 *
 * @var string $activeKey  from includes/header.php
 */
$bnTabs = [
    // menu key => short label
    'dashboard'       => 'Home',
    'pos'             => 'POS',
    'job-orders'      => 'Jobs',
    'inventory'       => 'Stock',
    'customer-orders' => 'Orders',
    'collections'     => 'Billing',
    'sales-history'   => 'Sales',
    'purchasing'      => 'Buying',
    'customers'       => 'Customers',
    'serials'         => 'Serials',
];
$bnMenu  = config('menu', []);
$bnItems = [];
foreach ($bnTabs as $bnKey => $bnLabel) {
    if (isset($bnMenu[$bnKey]) && can_open_menu($bnMenu[$bnKey])) {
        $bnItems[$bnKey] = [$bnLabel, $bnMenu[$bnKey]];
        if (count($bnItems) === 4) {
            break;
        }
    }
}
$bnOnTab = isset($bnItems[$activeKey]);
?>
<nav class="bottom-nav" id="bottomNav" aria-label="Quick menu">
    <?php foreach ($bnItems as $bnKey => [$bnLabel, $bnItem]): ?>
        <a class="bottom-nav__item<?= $activeKey === $bnKey ? ' is-active' : '' ?>" href="<?= e(url(menu_path($bnItem))) ?>"
           data-tab="<?= e($bnKey) ?>"<?= $activeKey === $bnKey ? ' aria-current="page"' : '' ?>>
            <?= icon($bnItem['icon']) ?>
            <span><?= e($bnLabel) ?></span>
        </a>
    <?php endforeach; ?>
    <button type="button" class="bottom-nav__item<?= $bnOnTab ? '' : ' is-active' ?>" data-sidebar-toggle data-tab="menu" aria-controls="sidebar" aria-label="Menu">
        <?= icon('menu') ?>
        <span>Menu</span>
    </button>
</nav>
