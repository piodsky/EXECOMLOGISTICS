<?php
/**
 * Tabs across the Customer Orders pages: quotations, customer POs (PO Outgoing), delivery receipts, order tracking.
 *
 * @var string $ordersTab 'quotes' | 'orders' | 'deliveries' | 'tracking'
 */
$ordersTabs = [
    'quotes'     => ['Quotations', 'tag', 'pages/quotations.php'],
    'orders'     => ['PO Outgoing', 'file', 'pages/customer-orders.php'],
    'deliveries' => ['Delivery Receipts', 'truck', 'pages/deliveries.php'],
    'tracking'   => ['Order Tracking', 'clock', 'pages/order-tracking.php'],
];
?>
<nav class="report-tabs no-print" aria-label="Customer orders">
    <?php foreach ($ordersTabs as $tabKey => [$tabLabel, $tabIcon, $tabPath]): ?>
        <a href="<?= e(url($tabPath)) ?>" class="report-tab<?= $ordersTab === $tabKey ? ' is-active' : '' ?>" data-tab="<?= e($tabKey) ?>"<?= $ordersTab === $tabKey ? ' aria-current="page"' : '' ?>>
            <?= icon($tabIcon) ?> <?= e($tabLabel) ?>
        </a>
    <?php endforeach; ?>
</nav>
