<?php
/**
 * Profit report (reports.view + products.cost): gross profit of completed sales in the period.
 * Sale level: net revenue (subtotal - discount, before VAT) - cost snapshot. Items / categories: line totals before
 * the sale discount; labour lines have no cost. Sales without a cost snapshot are left out (footnote). CSV export.
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('reports');
Auth::requirePermission('products.cost');
$page['title'] = 'Profit Report';

$reportPath = 'pages/report-profit.php';
require ROOT_PATH . '/includes/report-kit.php';

$totals   = Reports::profitTotals($from, $to);
$prev     = Reports::profitTotals($period['prev_from'], $period['prev_to']);
$series   = Reports::profitSeries($from, $to, $period['group']);
$items    = Reports::profitBy('item', $from, $to, 20);
$cats     = Reports::profitBy('category', $from, $to, 50);
$cashiers = Reports::profitByCashier($from, $to);

if (($_GET['export'] ?? '') === 'csv') {
    $out = $csvStart('profit', 'profit report');
    fputcsv($out, ['Costed sales', 'Net revenue (before VAT)', 'Cost', 'Gross profit', 'Margin %', 'Sales without cost']);
    fputcsv($out, [$totals['sales'], $totals['revenue'], $totals['cost'], $totals['profit'], $totals['margin'] === null ? '' : number_format($totals['margin'], 1, '.', ''), $totals['uncosted']]);
    fputcsv($out, []);
    fputcsv($out, [$period['group'] === 'month' ? 'Month' : 'Date', 'Net revenue', 'Gross profit']);
    foreach ($series as $p) {
        fputcsv($out, [$p['key'], from_cents($p['revenue_cents']), from_cents($p['profit_cents'])]);
    }
    fputcsv($out, []);
    fputcsv($out, ['Item', 'Code', 'Qty', 'Item sales (before discount)', 'Cost', 'Profit', 'Margin %']);
    foreach ($items as $r) {
        fputcsv($out, [csv_cell($r['name']), csv_cell((string) $r['code']), (int) $r['qty'], $r['revenue'], number_format((float) $r['cost'], 2, '.', ''), $r['profit'], $r['margin'] === null ? '' : number_format($r['margin'], 1, '.', '')]);
    }
    fclose($out);
    exit;
}

$profitMax = max(array_map(static fn ($r) => max(0.0, (float) $r['profit']), $cats) ?: [0]);
$delta = static function (string $cur, string $old): string {
    $c = (float) $cur;
    $o = (float) $old;
    if ($o == 0.0) {
        return $c > 0 ? 'New' : 'No change';
    }
    $d = ($c - $o) / abs($o) * 100;
    return ($d >= 0 ? '+' : '−') . number_format(abs($d), 1) . '% vs previous';
};

$pageStyles  = ['css/reports.css'];
$pageScripts = ['js/reports.js'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Reports</h1>
        <p class="muted">Gross profit = sales after discount (before VAT) minus the cost of the items at the time of sale.</p>
    </div>
    <div class="page-actions no-print">
        <a class="btn btn--light" href="<?= e($reportLink(['from' => $from, 'to' => $to, 'export' => 'csv'])) ?>" id="exportCsv"><?= icon('download') ?> Export CSV</a>
        <button type="button" class="btn btn--light" id="printReport"><?= icon('printer') ?> Print</button>
    </div>
</div>

<?php $reportTab = 'profit'; require ROOT_PATH . '/includes/reports-nav.php'; ?>
<?php require ROOT_PATH . '/includes/report-filter.php'; ?>

<section class="stats kpis" aria-label="Profit summary">
    <div class="stat kpi"><span class="stat__icon"><?= icon('wallet') ?></span>
        <div><p class="stat__label">Net Revenue</p><p class="stat__value" id="profitRevenue"><?= e(money($totals['revenue'])) ?></p>
            <p class="kpi__sub">before VAT, after discounts</p></div></div>
    <div class="stat kpi"><span class="stat__icon"><?= icon('box') ?></span>
        <div><p class="stat__label">Cost of Goods</p><p class="stat__value" id="profitCost"><?= e(money($totals['cost'])) ?></p>
            <p class="kpi__sub"><?= (int) $totals['sales'] ?> costed <?= $totals['sales'] === 1 ? 'sale' : 'sales' ?></p></div></div>
    <div class="stat kpi"><span class="stat__icon"><?= icon('trend-up') ?></span>
        <div><p class="stat__label">Gross Profit</p><p class="stat__value" id="profitTotal"><?= e(money($totals['profit'])) ?></p>
            <p class="kpi__sub"><?= e($delta($totals['profit'], $prev['profit'])) ?></p></div></div>
    <div class="stat kpi"><span class="stat__icon"><?= icon('chart') ?></span>
        <div><p class="stat__label">Margin</p><p class="stat__value" id="profitMargin"><?= e($pct($totals['margin'])) ?></p>
            <p class="kpi__sub">previous period <?= e($pct($prev['margin'])) ?></p></div></div>
</section>
<?php if ($totals['uncosted'] > 0): ?>
    <p class="report-note"><?= icon('info') ?> <?= $totals['uncosted'] ?> <?= $totals['uncosted'] === 1 ? 'sale was' : 'sales were' ?> made before costs were recorded and <?= $totals['uncosted'] === 1 ? 'is' : 'are' ?> not included.</p>
<?php endif; ?>

<div class="report-grid">
    <section class="card report-card">
        <header class="card__head"><h2><?= icon('grid') ?> Profit by Category</h2></header>
        <p class="card__sub muted">Item sales before the sale discount, minus cost. Labour has no cost.</p>
        <?php if ($cats): ?>
            <ul class="barlist" id="profitByCategory">
                <?php foreach ($cats as $c): ?>
                    <?php $barRow($c['name'], $pct($c['margin']) . ' margin', money($c['profit']), $profitMax > 0 ? max(0.0, (float) $c['profit']) / $profitMax : 0,
                        money($c['revenue']) . ' sales · ' . number_format((int) $c['qty']) . ' units'); ?>
                <?php endforeach; ?>
            </ul>
        <?php else: ?><p class="chart-empty">No costed sales in this period.</p><?php endif; ?>
    </section>

    <section class="card report-card">
        <header class="card__head"><h2><?= icon('user') ?> Profit by Cashier</h2></header>
        <div class="table-wrap">
            <table class="table report-table" id="profitByCashier">
                <thead><tr><th>Cashier</th><th class="num">Sales</th><th class="num">Net revenue</th><th class="num">Profit</th><th class="num">Margin</th></tr></thead>
                <tbody>
                <?php foreach ($cashiers as $c): ?>
                    <tr><td><?= e($c['name']) ?></td><td class="num"><?= (int) $c['count'] ?></td><td class="num"><?= e(money($c['revenue'])) ?></td>
                        <td class="num<?= (float) $c['profit'] < 0 ? ' is-neg' : '' ?>"><?= e(money($c['profit'])) ?></td><td class="num"><?= e($pct($c['margin'])) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$cashiers): ?><tr><td colspan="5" class="empty">No costed sales in this period.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<section class="card report-card">
    <header class="card__head"><h2><?= icon('tag') ?> Most Profitable Items</h2><span class="muted">Top 20</span></header>
    <div class="table-wrap">
        <table class="table report-table" id="profitItems">
            <thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Item sales</th><th class="num col-opt">Cost</th><th class="num">Profit</th><th class="num">Margin</th></tr></thead>
            <tbody>
            <?php foreach ($items as $r): ?>
                <tr><td><?= e($r['name']) ?> <small class="muted"><?= e((string) $r['code']) ?></small></td><td class="num"><?= number_format((int) $r['qty']) ?></td>
                    <td class="num"><?= e(money($r['revenue'])) ?></td><td class="num col-opt"><?= e(money(round((float) $r['cost'], 2))) ?></td>
                    <td class="num<?= (float) $r['profit'] < 0 ? ' is-neg' : '' ?>"><?= e(money($r['profit'])) ?></td><td class="num"><?= e($pct($r['margin'])) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$items): ?><tr><td colspan="6" class="empty">No costed sales in this period.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card report-card">
    <header class="card__head"><h2><?= icon('chart') ?> <?= $period['group'] === 'month' ? 'Monthly' : 'Daily' ?> Profit</h2></header>
    <div class="table-wrap">
        <table class="table report-table" id="profitSeries">
            <thead><tr><th><?= $period['group'] === 'month' ? 'Month' : 'Date' ?></th><th class="num">Net revenue</th><th class="num">Gross profit</th><th class="num">Margin</th></tr></thead>
            <tbody>
            <?php foreach (array_reverse($series) as $p): ?>
                <?php if ($p['revenue_cents'] === 0) continue; ?>
                <tr><td><?= e($p['long']) ?></td><td class="num"><?= e(money(from_cents($p['revenue_cents']))) ?></td>
                    <td class="num<?= $p['profit_cents'] < 0 ? ' is-neg' : '' ?>"><?= e(money(from_cents($p['profit_cents']))) ?></td>
                    <td class="num"><?= e($pct($p['revenue_cents'] > 0 ? $p['profit_cents'] / $p['revenue_cents'] * 100 : null)) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<div class="chart-tip" id="chartTip" role="status" hidden>
    <strong class="chart-tip__value"></strong>
    <span class="chart-tip__title"></span>
    <small class="chart-tip__note"></small>
</div>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
