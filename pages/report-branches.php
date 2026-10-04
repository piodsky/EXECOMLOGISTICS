<?php
/**
 * Branch comparison (reports.view + access to all branches): every active branch side by side for the period —
 * sales, net, gross profit (products.cost), job bills, stock value now (at cost with products.cost, else at price),
 * low stock, open jobs, jobs released, transfers sent. CSV export.
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('reports');
if (!Branch::canSeeAll()) {
    throw new HttpException(403, 'Only users with access to all branches can compare branches.');
}
$page['title'] = 'Branch Comparison';

$reportPath = 'pages/report-branches.php';
require ROOT_PATH . '/includes/report-kit.php';

$rows    = Reports::branchComparison($from, $to);
$canCost = Auth::can('products.cost');
$sum = static fn (string $k): float => array_sum(array_map(static fn (array $r): float => (float) $r[$k], $rows));

if (($_GET['export'] ?? '') === 'csv') {
    $out = $csvStart('branches', 'branch comparison');
    fputcsv($out, array_merge(['Branch', 'Sales', 'Net sales'], $canCost ? ['Gross profit'] : [], ['Job bills', 'Job revenue', 'Units on hand',
        $canCost ? 'Stock value (cost)' : 'Stock value (price)', 'Low stock', 'Open jobs', 'Jobs released', 'Transfers sent']));
    foreach ($rows as $r) {
        fputcsv($out, array_merge([csv_cell($r['code'] . ' ' . $r['name']), $r['sales'], $r['net']], $canCost ? [$r['profit']] : [],
            [$r['job_bills'], $r['job_revenue'], $r['units'], $r['stock_value'], $r['low'], $r['open_jobs'], $r['released'], $r['transfers_sent']]));
    }
    fclose($out);
    exit;
}

$netMax = max(array_map(static fn (array $r): float => (float) $r['net'], $rows) ?: [0]);
$netAll = $sum('net');

$pageStyles  = ['css/reports.css'];
$pageScripts = ['js/reports.js'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Reports</h1>
        <p class="muted">Every branch side by side. Sales and jobs follow the period; stock is as of now.</p>
    </div>
    <div class="page-actions no-print">
        <a class="btn btn--light" href="<?= e($reportLink(['from' => $from, 'to' => $to, 'export' => 'csv'])) ?>" id="exportCsv"><?= icon('download') ?> Export CSV</a>
        <button type="button" class="btn btn--light" id="printReport"><?= icon('printer') ?> Print</button>
    </div>
</div>

<?php $reportTab = 'branches'; require ROOT_PATH . '/includes/reports-nav.php'; ?>
<?php require ROOT_PATH . '/includes/report-filter.php'; ?>

<section class="card report-card">
    <header class="card__head"><h2><?= icon('store') ?> Branches</h2><span class="muted"><?= count($rows) ?> active</span></header>
    <div class="table-wrap">
        <table class="table report-table" id="branchTable">
            <thead>
            <tr>
                <th>Branch</th><th class="num">Sales</th><th class="num">Net sales</th>
                <?php if ($canCost): ?><th class="num">Gross profit</th><?php endif; ?>
                <th class="num col-opt">Job revenue</th><th class="num">Stock value</th><th class="num col-opt">Units</th>
                <th class="num">Low stock</th><th class="num">Open jobs</th><th class="num col-opt">Released</th><th class="num col-opt">Transfers sent</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr data-branch="<?= e($r['code']) ?>">
                    <td><span class="badge badge--branch"><?= e($r['code']) ?></span> <?= e($r['name']) ?></td>
                    <td class="num"><?= number_format($r['sales']) ?></td><td class="num"><?= e(money($r['net'])) ?></td>
                    <?php if ($canCost): ?><td class="num<?= (float) $r['profit'] < 0 ? ' is-neg' : '' ?>"><?= e(money($r['profit'])) ?></td><?php endif; ?>
                    <td class="num col-opt"><?= e(money($r['job_revenue'])) ?></td><td class="num"><?= e(money($r['stock_value'])) ?></td>
                    <td class="num col-opt"><?= number_format($r['units']) ?></td>
                    <td class="num<?= $r['low'] > 0 ? ' is-neg' : '' ?>"><?= $r['low'] ?></td><td class="num"><?= $r['open_jobs'] ?></td>
                    <td class="num col-opt"><?= $r['released'] ?></td><td class="num col-opt"><?= $r['transfers_sent'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
            <tr>
                <th>All branches</th><td class="num"><?= number_format((int) $sum('sales')) ?></td><td class="num" id="branchNetTotal"><?= e(money($netAll)) ?></td>
                <?php if ($canCost): ?><td class="num"><?= e(money($sum('profit'))) ?></td><?php endif; ?>
                <td class="num col-opt"><?= e(money($sum('job_revenue'))) ?></td><td class="num"><?= e(money($sum('stock_value'))) ?></td>
                <td class="num col-opt"><?= number_format((int) $sum('units')) ?></td><td class="num"><?= (int) $sum('low') ?></td><td class="num"><?= (int) $sum('open_jobs') ?></td>
                <td class="num col-opt"><?= (int) $sum('released') ?></td><td class="num col-opt"><?= (int) $sum('transfers_sent') ?></td>
            </tr>
            </tfoot>
        </table>
    </div>
    <p class="card__sub muted">Stock value at <?= $canCost ? 'branch average cost' : 'selling price' ?>. Low stock = active items at or below their low-stock level at that branch.</p>
</section>

<section class="card report-card">
    <header class="card__head"><h2><?= icon('chart') ?> Net Sales by Branch</h2></header>
    <ul class="barlist" id="branchSales">
        <?php foreach ($rows as $r): ?>
            <?php $barRow($r['name'], $r['code'], money($r['net']), $netMax > 0 ? (float) $r['net'] / $netMax : 0,
                $share((float) $r['net'], $netAll) . ' of all net sales · ' . number_format($r['sales']) . ' sales'); ?>
        <?php endforeach; ?>
    </ul>
</section>

<div class="chart-tip" id="chartTip" role="status" hidden>
    <strong class="chart-tip__value"></strong>
    <span class="chart-tip__title"></span>
    <small class="chart-tip__note"></small>
</div>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
