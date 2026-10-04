<?php
/**
 * Tabs across the Collections pages: open on-account bills (receivables) and collection receipts.
 *
 * @var string $collectionsTab 'receivables' | 'receipts'
 */
$collectionsTabs = [
    'receivables' => ['Receivables', 'wallet', 'pages/collections.php'],
    'receipts'    => ['Collection Receipts', 'receipt', 'pages/collection-receipts.php'],
];
?>
<nav class="report-tabs no-print" aria-label="Collections">
    <?php foreach ($collectionsTabs as $tabKey => [$tabLabel, $tabIcon, $tabPath]): ?>
        <a href="<?= e(url($tabPath)) ?>" class="report-tab<?= $collectionsTab === $tabKey ? ' is-active' : '' ?>" data-tab="<?= e($tabKey) ?>"<?= $collectionsTab === $tabKey ? ' aria-current="page"' : '' ?>>
            <?= icon($tabIcon) ?> <?= e($tabLabel) ?>
        </a>
    <?php endforeach; ?>
</nav>
