<?php
/**
 * Tabs across the Payables pages: supplier invoices and disbursement vouchers.
 *
 * @var string $payablesTab 'invoices' | 'disbursements'
 */
$payablesTabs = [
    'invoices'      => ['Supplier Invoices', 'clipboard', 'pages/payables.php'],
    'disbursements' => ['Disbursements', 'wallet', 'pages/disbursements.php'],
];
?>
<nav class="report-tabs no-print" aria-label="Payables">
    <?php foreach ($payablesTabs as $tabKey => [$tabLabel, $tabIcon, $tabPath]): ?>
        <a href="<?= e(url($tabPath)) ?>" class="report-tab<?= $payablesTab === $tabKey ? ' is-active' : '' ?>" data-tab="<?= e($tabKey) ?>"<?= $payablesTab === $tabKey ? ' aria-current="page"' : '' ?>>
            <?= icon($tabIcon) ?> <?= e($tabLabel) ?>
        </a>
    <?php endforeach; ?>
</nav>
