<?php
/**
 * Price overrides & discounts report (reports.view): every completed sale line sold below the suggested price
 * (who, approver, reason, amount given away) and every sale discount, with totals per cashier. CSV export.
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('reports');
$page['title'] = 'Price Overrides Report';

$reportPath = 'pages/report-pricing.php';
require ROOT_PATH . '/includes/report-kit.php';

$summary   = Reports::overrideSummary($from, $to);
$tot       = $summary['totals'];
$lines     = Reports::priceOverrides($from, $to, 200);
$discounts = Reports::discounts($from, $to, 200);
$canSales  = Auth::can('sales.view');
$saleLink  = static fn (array $r): string => $canSales
    ? '<a class="doc-no" href="' . e(url('pages/sale-view.php?id=' . (int) $r['sale_id'])) . '">' . e($r['sale_no']) . '</a>'
    : '<span class="doc-no">' . e($r['sale_no']) . '</span>';

if (($_GET['export'] ?? '') === 'csv') {
    $out = $csvStart('price-overrides', 'price overrides & discounts');
    fputcsv($out, ['Lower prices', 'Given away (prices)', 'With approver', 'Discounts', 'Discount amount', 'Discounts approved by someone else']);
    fputcsv($out, [$tot['lines'], $tot['given'], $tot['line_approved'], $tot['discounts'], $tot['discount_total'], $tot['discount_approved']]);
    fputcsv($out, []);
    fputcsv($out, ['Date', 'Sale', 'Branch', 'Item', 'Qty', 'Suggested', 'Sold at', 'Given away', 'Reason', 'Cashier', 'Approved by']);
    foreach ($lines as $r) {
        fputcsv($out, [$r['created_at'], csv_cell($r['sale_no']), csv_cell($r['branch_code']), csv_cell($r['product_code'] . ' ' . $r['product_name']), (int) $r['quantity'],
            $r['suggested_price'], $r['unit_price'], number_format((float) $r['given'], 2, '.', ''), csv_cell((string) $r['price_reason']), csv_cell($r['cashier']), csv_cell((string) $r['approver'])]);
    }
    fputcsv($out, []);
    fputcsv($out, ['Date', 'Sale', 'Branch', 'Discount %', 'Discount', 'Total', 'Cashier', 'Approved by']);
    foreach ($discounts as $r) {
        fputcsv($out, [$r['created_at'], csv_cell($r['sale_no']), csv_cell($r['branch_code']), $r['discount_percent'], $r['discount_amount'], $r['total'], csv_cell($r['cashier']), csv_cell((string) $r['approver'])]);
    }
    fclose($out);
    exit;
}

$givenAll = from_cents(to_cents($tot['given']) + to_cents($tot['discount_total']));
$when = static fn (string $ts): string => date('M j, g:i A', strtotime($ts));

$pageStyles  = ['css/reports.css'];
$pageScripts = ['js/reports.js'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Reports</h1>
        <p class="muted">Prices lowered below the suggested price and sale discounts: who gave them, who approved and why.</p>
    </div>
    <div class="page-actions no-print">
        <a class="btn btn--light" href="<?= e($reportLink(['from' => $from, 'to' => $to, 'export' => 'csv'])) ?>" id="exportCsv"><?= icon('download') ?> Export CSV</a>
        <button type="button" class="btn btn--light" id="printReport"><?= icon('printer') ?> Print</button>
    </div>
</div>

<?php $reportTab = 'pricing'; require ROOT_PATH . '/includes/reports-nav.php'; ?>
<?php require ROOT_PATH . '/includes/report-filter.php'; ?>

<section class="stats kpis" aria-label="Override summary">
    <div class="stat kpi"><span class="stat__icon"><?= icon('tag') ?></span>
        <div><p class="stat__label">Lower Prices</p><p class="stat__value" id="ovLines"><?= number_format($tot['lines']) ?></p><p class="kpi__sub"><?= $tot['line_approved'] ?> with an approver</p></div></div>
    <div class="stat kpi"><span class="stat__icon"><?= icon('trend-down') ?></span>
        <div><p class="stat__label">Given on Prices</p><p class="stat__value" id="ovGiven"><?= e(money($tot['given'])) ?></p><p class="kpi__sub">below the suggested price</p></div></div>
    <div class="stat kpi"><span class="stat__icon"><?= icon('wallet') ?></span>
        <div><p class="stat__label">Discounts</p><p class="stat__value" id="ovDiscounts"><?= e(money($tot['discount_total'])) ?></p><p class="kpi__sub"><?= $tot['discounts'] ?> sales · <?= $tot['discount_approved'] ?> approved by someone else</p></div></div>
    <div class="stat kpi"><span class="stat__icon"><?= icon('alert') ?></span>
        <div><p class="stat__label">Total Given Away</p><p class="stat__value" id="ovTotal"><?= e(money($givenAll)) ?></p><p class="kpi__sub">prices + discounts, before VAT</p></div></div>
</section>

<section class="card report-card">
    <header class="card__head"><h2><?= icon('user') ?> By Cashier</h2></header>
    <div class="table-wrap">
        <table class="table report-table" id="ovCashiers">
            <thead><tr><th>Cashier</th><th class="num">Lower prices</th><th class="num">Given on prices</th><th class="num">Discounts</th><th class="num">Discount amount</th></tr></thead>
            <tbody>
            <?php foreach ($summary['cashiers'] as $c): ?>
                <tr><td><?= e($c['name']) ?></td><td class="num"><?= (int) $c['lines'] ?></td><td class="num"><?= e(money($c['given'])) ?></td>
                    <td class="num"><?= (int) $c['discounts'] ?></td><td class="num"><?= e(money($c['discount_total'])) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$summary['cashiers']): ?><tr><td colspan="5" class="empty"><?= icon('check') ?> No lowered prices or discounts in this period.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card report-card">
    <header class="card__head"><h2><?= icon('tag') ?> Prices Below Suggested</h2><span class="muted"><?= count($lines) ?><?= count($lines) >= 200 ? '+ (latest 200)' : '' ?></span></header>
    <div class="table-wrap">
        <table class="table report-table" id="ovLinesTable">
            <thead><tr><th>Sale</th><th>Item</th><th class="num">Suggested</th><th class="num">Sold at</th><th class="num">Given</th><th class="col-opt">Reason</th><th>Cashier / approver</th></tr></thead>
            <tbody>
            <?php foreach ($lines as $r): ?>
                <tr><td><?= $saleLink($r) ?><small class="muted block"><?= e($when($r['created_at'])) ?> · <?= e($r['branch_code']) ?></small></td>
                    <td><?= e($r['product_name']) ?><small class="muted block"><?= e($r['product_code']) ?> · ×<?= (int) $r['quantity'] ?></small></td>
                    <td class="num"><?= e(money($r['suggested_price'])) ?></td><td class="num"><?= e(money($r['unit_price'])) ?></td>
                    <td class="num is-neg"><?= e(money($r['given'])) ?></td>
                    <td class="col-opt"><small class="doc-reason"><?= e((string) $r['price_reason']) ?></small></td>
                    <td><?= e($r['cashier']) ?><?php if ($r['approver'] !== null): ?><small class="muted block">approved by <?= e($r['approver']) ?></small><?php endif; ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$lines): ?><tr><td colspan="7" class="empty">No sale lines below the suggested price in this period.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card report-card">
    <header class="card__head"><h2><?= icon('wallet') ?> Discounts</h2><span class="muted"><?= count($discounts) ?><?= count($discounts) >= 200 ? '+ (latest 200)' : '' ?></span></header>
    <div class="table-wrap">
        <table class="table report-table" id="ovDiscountTable">
            <thead><tr><th>Sale</th><th class="num">Discount</th><th class="num">Amount</th><th class="num col-opt">Sale total</th><th>Cashier / approver</th></tr></thead>
            <tbody>
            <?php foreach ($discounts as $r): ?>
                <tr><td><?= $saleLink($r) ?><small class="muted block"><?= e($when($r['created_at'])) ?> · <?= e($r['branch_code']) ?><?= $r['job_order_id'] !== null ? ' · job bill' : '' ?></small></td>
                    <td class="num"><?= e(rtrim(rtrim($r['discount_percent'], '0'), '.')) ?>%</td><td class="num is-neg"><?= e(money($r['discount_amount'])) ?></td>
                    <td class="num col-opt"><?= e(money($r['total'])) ?></td>
                    <td><?= e($r['cashier']) ?><?php if ($r['approver'] !== null): ?><small class="muted block">approved by <?= e($r['approver']) ?></small><?php endif; ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$discounts): ?><tr><td colspan="5" class="empty">No discounts in this period.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
