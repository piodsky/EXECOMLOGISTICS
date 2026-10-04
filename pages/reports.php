<?php
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('reports');

// ---------------------------------------------------------------------
// Period + data
// ---------------------------------------------------------------------
$period = Reports::period(input_date($_GET, 'from'), input_date($_GET, 'to'));
['from' => $from, 'to' => $to] = $period;
$topSort = ($_GET['top'] ?? '') === 'qty' ? 'qty' : 'revenue';

$totals   = Reports::totals($from, $to);
$prev     = Reports::totals($period['prev_from'], $period['prev_to']);
$voided   = Reports::voided($from, $to);
$series   = Reports::series($from, $to, $period['group']);
$top      = Reports::topItems($from, $to, $topSort, 10);
$cats     = Reports::byCategory($from, $to);
$payments = Reports::byPayment($from, $to);
$cashiers = Reports::byCashier($from, $to);

// ---------------------------------------------------------------------
// CSV export (same period) — opens in Excel
// ---------------------------------------------------------------------
if (($_GET['export'] ?? '') === 'csv') {
    // Text cells go through csv_cell() (formula guard for Excel).
    $num  = static fn ($v): string => number_format((float) $v, 2, '.', '');

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="execom-sales-' . $from . '-to-' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads it correctly
    fputcsv($out, ['EXECOM Logistics sales report', $from . ' to ' . $to, csv_cell(Branch::label())]);
    fputcsv($out, []);
    fputcsv($out, ['Transactions', 'Net sales (incl. VAT)', 'Average sale', 'Items sold', 'Discounts', 'VAT']);
    fputcsv($out, [(int) $totals['transactions'], $num($totals['net']), $num($totals['average']), (int) $totals['items'], $num($totals['discounts']), $num($totals['vat'])]);
    fputcsv($out, []);
    fputcsv($out, [$period['group'] === 'month' ? 'Month' : 'Date', 'Transactions', 'Net sales']);
    foreach ($series as $p) {
        fputcsv($out, [$p['key'], $p['count'], from_cents($p['cents'])]);
    }
    fputcsv($out, []);
    fputcsv($out, ['Top items', 'Code', 'Qty sold', 'Item sales (before discount & VAT)']);
    foreach ($top as $t) {
        fputcsv($out, [csv_cell($t['name']), csv_cell($t['code']), (int) $t['qty'], $num($t['revenue'])]);
    }
    fclose($out);
    exit;
}

// ---------------------------------------------------------------------
// View helpers
// ---------------------------------------------------------------------
$today = new DateTimeImmutable('today');
$presets = [
    'Today'        => [$today, $today],
    'Last 7 days'  => [$today->modify('-6 days'), $today],
    'Last 30 days' => [$today->modify('-29 days'), $today],
    'This month'   => [$today->modify('first day of this month'), $today],
    'Last month'   => [$today->modify('first day of last month'), $today->modify('last day of last month')],
    'This year'    => [$today->modify('first day of january this year'), $today],
];
$link = static function (array $q): string {
    return url('pages/reports.php') . '?' . http_build_query($q);
};
$baseQuery = ['from' => $from, 'to' => $to] + ($topSort === 'qty' ? ['top' => 'qty'] : []);
$fmtRange = static function (string $a, string $b): string {
    if ($a === $b) {
        return date('M j, Y', strtotime($a));
    }
    $sameYear = substr($a, 0, 4) === substr($b, 0, 4);
    return date($sameYear ? 'M j' : 'M j, Y', strtotime($a)) . ' – ' . date('M j, Y', strtotime($b));
};
$periodLabel = $fmtRange($from, $to) . ' · ' . $period['days'] . ' ' . ($period['days'] === 1 ? 'day' : 'days');
$prevLabel   = $fmtRange($period['prev_from'], $period['prev_to']);

/** Change vs the previous period: [css modifier, icon, text]. Up is good for every KPI here. */
$delta = static function (float $cur, float $prevValue): array {
    if ($prevValue == 0.0) {
        return $cur > 0.0 ? ['up', 'trend-up', 'New'] : ['flat', 'minus', 'No change'];
    }
    $pct = ($cur - $prevValue) / $prevValue * 100;
    if (abs($pct) < 0.05) {
        return ['flat', 'minus', '0.0%'];
    }
    return [$pct > 0 ? 'up' : 'down', $pct > 0 ? 'trend-up' : 'trend-down', ($pct > 0 ? '+' : '−') . number_format(abs($pct), 1) . '%'];
};
$kpis = [
    ['Net Sales',    'wallet',  money($totals['net']),                     (float) $totals['net'],          (float) $prev['net'],          money($prev['net'])],
    ['Transactions', 'receipt', number_format((int) $totals['transactions']), (float) $totals['transactions'], (float) $prev['transactions'], number_format((int) $prev['transactions'])],
    ['Average Sale', 'chart',   money($totals['average']),                 (float) $totals['average'],      (float) $prev['average'],      money($prev['average'])],
    ['Items Sold',   'box',     number_format((int) $totals['items']),     (float) $totals['items'],        (float) $prev['items'],        number_format((int) $prev['items'])],
];

/** Share of a total as "42.5%". */
$share = static fn (float $part, float $whole): string => $whole > 0 ? number_format($part / $whole * 100, 1) . '%' : '0%';

$itemsTotal = array_sum(array_map(static fn ($r) => (float) $r['revenue'], $cats));
$topMax     = $top ? max(array_map(static fn ($r) => (float) ($topSort === 'qty' ? $r['qty'] : $r['revenue']), $top)) : 0;
$catMax     = $cats ? max(array_map(static fn ($r) => (float) $r['revenue'], $cats)) : 0;
$payMax     = max(array_map(static fn ($r) => (float) $r['total'], $payments) ?: [0]);
$cashMax    = $cashiers ? max(array_map(static fn ($r) => (float) $r['total'], $cashiers)) : 0;

$stock      = Products::summary();
$stockCats  = Reports::stockByCategory();
$stockMax   = $stockCats ? max(array_map(static fn ($r) => (float) $r['value'], $stockCats)) : 0;
$lowStock   = Reports::lowStock(20);

$chartData = [
    'currency' => config('app.currency'),
    'group'    => $period['group'],
    'points'   => $series,
];
$bestPoint = null;
foreach ($series as $p) {
    if ($p['cents'] > 0 && ($bestPoint === null || $p['cents'] > $bestPoint['cents'])) {
        $bestPoint = $p;
    }
}

$pageStyles  = ['css/reports.css'];
$pageScripts = ['js/reports.js'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Reports</h1>
        <p class="muted">Completed sales for the period (voided sales are left out) and today's inventory · <strong id="reportScope"><?= e(Branch::label()) ?></strong>.</p>
    </div>
    <div class="page-actions no-print">
        <a class="btn btn--light" href="<?= e($link($baseQuery + ['export' => 'csv'])) ?>" id="exportCsv"><?= icon('download') ?> Export CSV</a>
        <button type="button" class="btn btn--light" id="printReport"><?= icon('printer') ?> Print</button>
    </div>
</div>

<?php $reportTab = 'sales'; require ROOT_PATH . '/includes/reports-nav.php'; ?>

<!-- One filter row scopes every sales figure below -->
<section class="card report-filters no-print" aria-label="Report period">
    <nav class="quick-ranges" aria-label="Quick periods">
        <?php foreach ($presets as $label => [$pf, $pt]): ?>
            <?php $active = $from === $pf->format('Y-m-d') && $to === $pt->format('Y-m-d'); ?>
            <a class="chip<?= $active ? ' is-active' : '' ?>"<?= $active ? ' aria-current="true"' : '' ?>
               href="<?= e($link(['from' => $pf->format('Y-m-d'), 'to' => $pt->format('Y-m-d')] + ($topSort === 'qty' ? ['top' => 'qty'] : []))) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>
    <form class="toolbar report-range" method="get" action="<?= e(url('pages/reports.php')) ?>">
        <label class="date-field"><span>From</span>
            <input class="form-input" type="date" name="from" value="<?= e($from) ?>" max="<?= e($today->format('Y-m-d')) ?>" required>
        </label>
        <label class="date-field"><span>To</span>
            <input class="form-input" type="date" name="to" value="<?= e($to) ?>" max="<?= e($today->format('Y-m-d')) ?>" required>
        </label>
        <?php if ($topSort === 'qty'): ?><input type="hidden" name="top" value="qty"><?php endif; ?>
        <button type="submit" class="btn btn--primary">Apply</button>
    </form>
</section>

<p class="report-period"><?= icon('calendar') ?> <strong id="periodLabel"><?= e($periodLabel) ?></strong>
    <span class="muted">compared with <?= e($prevLabel) ?></span></p>

<section class="stats kpis" aria-label="Sales summary">
    <?php foreach ($kpis as [$label, $icon, $value, $cur, $prevValue, $prevText]): ?>
        <?php [$dir, $dIcon, $dText] = $delta($cur, $prevValue); ?>
        <div class="stat kpi">
            <span class="stat__icon"><?= icon($icon) ?></span>
            <div>
                <p class="stat__label"><?= e($label) ?></p>
                <p class="stat__value"><?= e($value) ?></p>
                <p class="kpi__delta kpi__delta--<?= e($dir) ?>" title="Previous period: <?= e($prevText) ?>">
                    <?= icon($dIcon) ?> <span><?= e($dText) ?></span> <small>vs previous</small>
                </p>
            </div>
        </div>
    <?php endforeach; ?>
</section>

<!-- ============ Sales over time ============ -->
<section class="card report-card">
    <header class="card__head">
        <h2><?= icon('chart') ?> <?= $period['group'] === 'month' ? 'Monthly' : 'Daily' ?> Net Sales</h2>
        <span class="muted">
            <?= number_format((int) $totals['transactions']) ?> <?= (int) $totals['transactions'] === 1 ? 'sale' : 'sales' ?>
            · VAT <?= e(money($totals['vat'])) ?> · Discounts <?= e(money($totals['discounts'])) ?>
        </span>
    </header>
    <div class="chart-box">
        <?php if ((int) $totals['transactions'] === 0): ?>
            <p class="chart-empty"><?= icon('chart') ?> No sales in this period.</p>
        <?php else: ?>
            <div class="column-chart" id="salesChart"
                 aria-label="<?= e(($period['group'] === 'month' ? 'Monthly' : 'Daily') . ' net sales, ' . $periodLabel . '. Highest: ' . ($bestPoint ? $bestPoint['long'] . ', ' . money(from_cents($bestPoint['cents'])) : 'none') . '.') ?>"></div>
            <script type="application/json" id="salesSeries"><?= json_encode($chartData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
        <?php endif; ?>
    </div>
    <?php if ((int) $voided['count'] > 0): ?>
        <p class="report-note"><?= icon('info') ?> <?= (int) $voided['count'] ?> voided <?= (int) $voided['count'] === 1 ? 'sale' : 'sales' ?>
            (<?= e(money($voided['total'])) ?>) in this period <?= (int) $voided['count'] === 1 ? 'is' : 'are' ?> not included.</p>
    <?php endif; ?>
    <details class="table-view">
        <summary>Show as table</summary>
        <div class="table-wrap">
            <table class="table" id="seriesTable">
                <thead><tr><th><?= $period['group'] === 'month' ? 'Month' : 'Date' ?></th><th class="num">Transactions</th><th class="num">Net Sales</th></tr></thead>
                <tbody>
                <?php foreach (array_reverse($series) as $p): ?>
                    <tr class="<?= $p['count'] ? '' : 'is-zero' ?>">
                        <td><?= e($p['long']) ?></td>
                        <td class="num"><?= (int) $p['count'] ?></td>
                        <td class="num"><?= e(money(from_cents($p['cents']))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </details>
</section>

<?php
/**
 * One row of a horizontal bar list: label, bar (SVG, % width — no inline styles under our CSP), value.
 * Every value is printed, so the tooltip only adds detail.
 */
$barRow = static function (string $label, ?string $sub, string $value, float $fraction, string $tipNote): void {
    $pct   = max(0.0, min(1.0, $fraction)) * 100;
    $width = $pct > 0 ? max($pct, 0.6) : 0;
    ?>
    <li class="barlist__row" tabindex="0" data-tip-title="<?= e($label) ?>" data-tip-value="<?= e($value) ?>" data-tip-note="<?= e($tipNote) ?>">
        <span class="barlist__label"><?= e($label) ?><?php if ($sub !== null && $sub !== ''): ?> <small><?= e($sub) ?></small><?php endif; ?></span>
        <svg class="barlist__bar" width="100%" height="14" aria-hidden="true" focusable="false">
            <?php if ($width > 0): ?>
                <rect class="bar" width="<?= e(number_format($width, 2, '.', '')) ?>%" height="14" rx="4"/>
                <?php if ($width >= 3): ?><rect class="bar" width="4" height="14"/><?php endif; /* square end at the baseline */ ?>
            <?php endif; ?>
        </svg>
        <span class="barlist__value"><?= e($value) ?></span>
    </li>
    <?php
};
?>

<div class="report-grid">
    <!-- ============ Top items ============ -->
    <section class="card report-card">
        <header class="card__head">
            <h2><?= icon('tag') ?> Top 10 Items</h2>
            <nav class="segmented-links no-print" aria-label="Rank top items by">
                <a href="<?= e($link(['from' => $from, 'to' => $to])) ?>" class="<?= $topSort === 'revenue' ? 'is-active' : '' ?>"<?= $topSort === 'revenue' ? ' aria-current="true"' : '' ?>>By sales</a>
                <a href="<?= e($link(['from' => $from, 'to' => $to, 'top' => 'qty'])) ?>" class="<?= $topSort === 'qty' ? 'is-active' : '' ?>"<?= $topSort === 'qty' ? ' aria-current="true"' : '' ?>>By quantity</a>
            </nav>
        </header>
        <p class="card__sub muted">Item sales before discount &amp; VAT<?= $topSort === 'qty' ? ', ranked by units sold' : '' ?>.</p>
        <?php if ($top): ?>
            <ol class="barlist" id="topItems">
                <?php foreach ($top as $t): ?>
                    <?php
                    $metric = $topSort === 'qty' ? (float) $t['qty'] : (float) $t['revenue'];
                    $barRow(
                        $t['name'], $t['code'],
                        $topSort === 'qty' ? number_format((int) $t['qty']) . ' sold' : money($t['revenue']),
                        $topMax > 0 ? $metric / $topMax : 0,
                        $topSort === 'qty'
                            ? money($t['revenue']) . ' · in ' . (int) $t['sales'] . ' ' . ((int) $t['sales'] === 1 ? 'sale' : 'sales')
                            : number_format((int) $t['qty']) . ' sold · ' . $share((float) $t['revenue'], $itemsTotal) . ' of item sales'
                    );
                    ?>
                <?php endforeach; ?>
            </ol>
        <?php else: ?>
            <p class="chart-empty">No items sold in this period.</p>
        <?php endif; ?>
    </section>

    <!-- ============ By category ============ -->
    <section class="card report-card">
        <header class="card__head"><h2><?= icon('grid') ?> Sales by Category</h2></header>
        <p class="card__sub muted">Item sales before discount &amp; VAT, with share of the total.</p>
        <?php if ($cats): ?>
            <ul class="barlist" id="categorySales">
                <?php foreach ($cats as $c): ?>
                    <?php $barRow($c['name'], $share((float) $c['revenue'], $itemsTotal), money($c['revenue']),
                        $catMax > 0 ? (float) $c['revenue'] / $catMax : 0,
                        number_format((int) $c['qty']) . ' units · ' . $share((float) $c['revenue'], $itemsTotal) . ' of item sales'); ?>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="chart-empty">No items sold in this period.</p>
        <?php endif; ?>
    </section>

    <!-- ============ By payment ============ -->
    <section class="card report-card">
        <header class="card__head"><h2><?= icon('wallet') ?> Payment Types</h2></header>
        <p class="card__sub muted">Net sales (incl. VAT) per payment type.</p>
        <ul class="barlist" id="paymentSales">
            <?php foreach ($payments as $p): ?>
                <?php $barRow($p['name'], $p['count'] . ' ' . ($p['count'] === 1 ? 'sale' : 'sales'), money($p['total']),
                    $payMax > 0 ? (float) $p['total'] / $payMax : 0,
                    $share((float) $p['total'], (float) $totals['net']) . ' of net sales'); ?>
            <?php endforeach; ?>
        </ul>
    </section>

    <!-- ============ By cashier ============ -->
    <section class="card report-card">
        <header class="card__head"><h2><?= icon('user') ?> Sales by Cashier</h2></header>
        <p class="card__sub muted">Net sales (incl. VAT) rung up by each user.</p>
        <?php if ($cashiers): ?>
            <ul class="barlist" id="cashierSales">
                <?php foreach ($cashiers as $c): ?>
                    <?php $barRow($c['name'], (int) $c['count'] . ' ' . ((int) $c['count'] === 1 ? 'sale' : 'sales'), money($c['total']),
                        $cashMax > 0 ? (float) $c['total'] / $cashMax : 0,
                        $share((float) $c['total'], (float) $totals['net']) . ' of net sales · average ' . money((float) $c['total'] / max(1, (int) $c['count']))); ?>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="chart-empty">No sales in this period.</p>
        <?php endif; ?>
    </section>
</div>

<!-- ============ Inventory snapshot ============ -->
<h2 class="report-section">Inventory <span class="muted">as of <?= e(date('M j, Y g:i A')) ?></span></h2>

<section class="stats" aria-label="Inventory summary">
    <div class="stat">
        <span class="stat__icon"><?= icon('wallet') ?></span>
        <div><p class="stat__label">Stock Value</p><p class="stat__value" id="stockValue"><?= e(money($stock['stock_value'])) ?></p></div>
    </div>
    <div class="stat">
        <span class="stat__icon"><?= icon('box') ?></span>
        <div><p class="stat__label">Active Products</p><p class="stat__value"><?= (int) $stock['items'] ?></p></div>
    </div>
    <a class="stat stat--link<?= (int) $stock['low'] > 0 ? ' stat--warn' : '' ?>" href="<?= e(url('pages/inventory.php?status=low')) ?>">
        <span class="stat__icon"><?= icon('alert') ?></span>
        <div><p class="stat__label">Low Stock</p><p class="stat__value"><?= (int) $stock['low'] ?></p></div>
    </a>
    <a class="stat stat--link<?= (int) $stock['out_of_stock'] > 0 ? ' stat--danger' : '' ?>" href="<?= e(url('pages/inventory.php?status=out')) ?>">
        <span class="stat__icon"><?= icon('x') ?></span>
        <div><p class="stat__label">Out of Stock</p><p class="stat__value"><?= (int) $stock['out_of_stock'] ?></p></div>
    </a>
</section>

<div class="report-grid">
    <section class="card report-card">
        <header class="card__head"><h2><?= icon('box') ?> Stock Value by Category</h2></header>
        <p class="card__sub muted">Selling price × units on hand, active products.</p>
        <ul class="barlist" id="stockByCategory">
            <?php foreach ($stockCats as $c): ?>
                <?php $barRow($c['name'], number_format((int) $c['units']) . ' units', money($c['value']),
                    $stockMax > 0 ? (float) $c['value'] / $stockMax : 0,
                    (int) $c['products'] . ' ' . ((int) $c['products'] === 1 ? 'product' : 'products') . ' · ' . $share((float) $c['value'], (float) $stock['stock_value']) . ' of stock value'); ?>
            <?php endforeach; ?>
        </ul>
    </section>

    <section class="card report-card">
        <header class="card__head">
            <h2><?= icon('alert') ?> Reorder List</h2>
            <a class="btn btn--sm btn--light no-print" href="<?= e(url('pages/inventory.php?status=low')) ?>">Open in Inventory</a>
        </header>
        <div class="table-wrap">
            <table class="table" id="lowStockTable">
                <thead><tr><th>Product</th><th class="num">On hand</th><th class="num">Low at</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($lowStock as $p): ?>
                    <?php $out = (int) $p['stock'] === 0; ?>
                    <tr>
                        <td>
                            <a class="item-cell__name" href="<?= e(url('pages/product-form.php?id=' . (int) $p['id'])) ?>"><?= e($p['name']) ?></a>
                            <small class="muted block"><?= e($p['code']) ?> · <?= e($p['category']) ?></small>
                        </td>
                        <td class="num"><?= (int) $p['stock'] ?></td>
                        <td class="num"><?= (int) $p['reorder_level'] ?></td>
                        <td><span class="badge <?= $out ? 'badge--danger' : 'badge--warning' ?> status-badge"><?= icon($out ? 'x' : 'alert') ?> <?= $out ? 'Out of stock' : 'Low stock' ?></span></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$lowStock): ?>
                    <tr><td colspan="4" class="empty"><?= icon('check') ?> Every product is above its low-stock level.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<div class="chart-tip" id="chartTip" role="status" hidden>
    <strong class="chart-tip__value"></strong>
    <span class="chart-tip__title"></span>
    <small class="chart-tip__note"></small>
</div>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
