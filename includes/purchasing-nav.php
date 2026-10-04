<?php
/**
 * Tabs across the Purchasing pages (purchase requests / PO Internal; the PO tab only with PurchaseOrders::canView()).
 *
 * @var string $purchasingTab 'requests' | 'orders'
 */
$purchasingTabs = [
    'requests' => ['Purchase Requests', 'clipboard', 'pages/purchase-requests.php', true],
    'orders'   => ['PO Internal', 'cart', 'pages/purchase-orders.php', PurchaseOrders::canView()],
];
?>
<nav class="report-tabs no-print" aria-label="Purchasing">
    <?php foreach ($purchasingTabs as $tabKey => [$tabLabel, $tabIcon, $tabPath, $tabOk]): ?>
        <?php if (!$tabOk) continue; ?>
        <a href="<?= e(url($tabPath)) ?>" class="report-tab<?= $purchasingTab === $tabKey ? ' is-active' : '' ?>" data-tab="<?= e($tabKey) ?>"<?= $purchasingTab === $tabKey ? ' aria-current="page"' : '' ?>>
            <?= icon($tabIcon) ?> <?= e($tabLabel) ?>
        </a>
    <?php endforeach; ?>
</nav>
