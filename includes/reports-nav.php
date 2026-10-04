<?php
/**
 * Tabs across the Reports pages (only the ones the user may open). The period (from / to) travels with the tabs.
 *
 * @var string $reportTab 'sales' | 'profit' | 'jobs' | 'pricing' | 'branches'
 * @var string $from, $to
 */
$tabQuery = '?' . http_build_query(['from' => $from, 'to' => $to]);
$reportTabs = [
    'sales'    => ['Sales', 'chart', 'pages/reports.php', true],
    'profit'   => ['Profit', 'trend-up', 'pages/report-profit.php', Auth::can('products.cost')],
    'jobs'     => ['Job Orders', 'wrench', 'pages/report-jobs.php', true],
    'pricing'  => ['Price Overrides', 'tag', 'pages/report-pricing.php', true],
    'branches' => ['Branches', 'store', 'pages/report-branches.php', Branch::canSeeAll()],
];
?>
<nav class="report-tabs no-print" aria-label="Reports">
    <?php foreach ($reportTabs as $tabKey => [$tabLabel, $tabIcon, $tabPath, $tabOk]): ?>
        <?php if (!$tabOk) continue; ?>
        <a href="<?= e(url($tabPath) . $tabQuery) ?>" class="report-tab<?= $reportTab === $tabKey ? ' is-active' : '' ?>" data-tab="<?= e($tabKey) ?>"<?= $reportTab === $tabKey ? ' aria-current="page"' : '' ?>>
            <?= icon($tabIcon) ?> <?= e($tabLabel) ?>
        </a>
    <?php endforeach; ?>
</nav>
