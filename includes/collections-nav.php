<?php
/**
 * Tabs across the Billing & Collections pages: bills on account, collection receipts, checks received, statements.
 *
 * @var string $collectionsTab 'receivables' | 'receipts' | 'checks' | 'statement'
 */
$collectionsTabs = [
    'receivables' => ['Bills', 'wallet', 'pages/collections.php'],
    'receipts'    => ['Collection Receipts', 'receipt', 'pages/collection-receipts.php'],
    'checks'      => ['Checks Received', 'file', 'pages/checks.php'],
    'statement'   => ['Statement of Account', 'printer', 'pages/soa.php'],
];
?>
<nav class="report-tabs no-print" aria-label="Collections">
    <?php foreach ($collectionsTabs as $tabKey => [$tabLabel, $tabIcon, $tabPath]): ?>
        <a href="<?= e(url($tabPath)) ?>" class="report-tab<?= $collectionsTab === $tabKey ? ' is-active' : '' ?>" data-tab="<?= e($tabKey) ?>"<?= $collectionsTab === $tabKey ? ' aria-current="page"' : '' ?>>
            <?= icon($tabIcon) ?> <?= e($tabLabel) ?>
        </a>
    <?php endforeach; ?>
</nav>
