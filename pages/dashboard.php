<?php
/**
 * Dashboard (reports.view = super / branch admins; first menu item, so admins land here after sign-in).
 * Current branch scope: sales today / this month, gross profit (products.cost), stock value, open jobs, "Needs you"
 * work lists (concrete branch only, per permission), branches side by side (All branches), last 7 days, jobs by
 * status + technician workload, price overrides today, transfers in transit, low stock, recent activity
 * (audit_logs.view). Loaded on open; Refresh reloads.
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('dashboard');

$today    = date('Y-m-d');
$month    = date('Y-m-01');
$canCost  = Auth::can('products.cost');
$salesDay = Dashboard::sales($today, $today);
$salesMon = Dashboard::sales($month, $today);
$profit   = $canCost ? Reports::profitTotals($month, $today) : null;
$stock    = Products::summary();
$stockVal = $canCost ? Reports::stockValueAtCost() : (string) $stock['stock_value'];
$jobs     = Dashboard::jobsByStatus();
$openJobs = array_sum(array_intersect_key($jobs, array_flip(JobOrders::OPEN)));
$needs    = Dashboard::needsYou();
$branches = Dashboard::branchRows();
$week     = Reports::series(date('Y-m-d', strtotime('-6 days')), $today, 'day');
$weekMax  = max(array_map(static fn (array $p): int => $p['cents'], $week) ?: [0]);
$workload = Dashboard::workload();
$override = Dashboard::overridesToday();
$transit  = Dashboard::inTransit();
$low      = Reports::lowStock(8);
$activity = Dashboard::recentActivity(8);
$jobMax   = max($jobs ?: [0]);
$canJobs  = Auth::canAny(...JobOrders::VIEW_PERMISSIONS);

// Reuse the report bar rows (tooltips from reports.js).
$reportPath = 'pages/dashboard.php';
require ROOT_PATH . '/includes/report-kit.php';

$pageStyles  = ['css/reports.css', 'css/stock-docs.css', 'css/transfers.css', 'css/dashboard.css'];
$pageScripts = ['js/reports.js'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Dashboard</h1>
        <p class="muted">Today at a glance · <strong id="dashScope"><?= e(Branch::label()) ?></strong> · as of <?= e(date('g:i A')) ?></p>
    </div>
    <div class="page-actions no-print">
        <a class="btn btn--light" href="<?= e(url('pages/dashboard.php')) ?>" id="refreshDash"><?= icon('clock') ?> Refresh</a>
        <a class="btn btn--primary" href="<?= e(url('pages/reports.php')) ?>"><?= icon('chart') ?> Reports</a>
    </div>
</div>

<section class="stats kpis kpis--5" aria-label="Summary">
    <div class="stat kpi"><span class="stat__icon"><?= icon('wallet') ?></span>
        <div><p class="stat__label">Sales Today</p><p class="stat__value" id="dashToday"><?= e(money($salesDay['net'])) ?></p>
            <p class="kpi__sub"><?= number_format($salesDay['count']) ?> <?= $salesDay['count'] === 1 ? 'sale' : 'sales' ?></p></div></div>
    <div class="stat kpi"><span class="stat__icon"><?= icon('calendar') ?></span>
        <div><p class="stat__label">This Month</p><p class="stat__value" id="dashMonth"><?= e(money($salesMon['net'])) ?></p>
            <p class="kpi__sub"><?= number_format($salesMon['count']) ?> sales · incl. VAT</p></div></div>
    <?php if ($profit !== null): ?>
        <a class="stat kpi stat--link" href="<?= e(url('pages/report-profit.php?' . http_build_query(['from' => $month, 'to' => $today]))) ?>"><span class="stat__icon"><?= icon('trend-up') ?></span>
            <div><p class="stat__label">Gross Profit (Month)</p><p class="stat__value" id="dashProfit"><?= e(money($profit['profit'])) ?></p>
                <p class="kpi__sub"><?= e($pct($profit['margin'])) ?> margin</p></div></a>
    <?php endif; ?>
    <a class="stat kpi stat--link<?= (int) $stock['low'] + (int) $stock['out_of_stock'] > 0 ? ' stat--warn' : '' ?>" href="<?= e(url('pages/inventory.php?status=low')) ?>"><span class="stat__icon"><?= icon('box') ?></span>
        <div><p class="stat__label">Stock Value<?= $canCost ? ' (cost)' : '' ?></p><p class="stat__value" id="dashStock"><?= e(money($stockVal)) ?></p>
            <p class="kpi__sub"><?= (int) $stock['low'] ?> low · <?= (int) $stock['out_of_stock'] ?> out of stock</p></div></a>
    <?php if ($canJobs): ?>
        <a class="stat kpi stat--link" href="<?= e(url('pages/job-orders.php')) ?>"><span class="stat__icon"><?= icon('wrench') ?></span>
            <div><p class="stat__label">Open Jobs</p><p class="stat__value" id="dashJobs"><?= number_format($openJobs) ?></p>
                <p class="kpi__sub"><?= $jobs['completed'] ?> ready to release</p></div></a>
    <?php endif; ?>
</section>

<?php if (Branch::isConcrete()): ?>
    <section class="card report-card" aria-label="Needs you">
        <header class="card__head"><h2><?= icon('alert') ?> Needs You</h2><span class="muted"><?= e(Branch::label()) ?></span></header>
        <?php if ($needs): ?>
            <nav class="op-tiles bt-tiles dash-needs" id="needsYou">
                <?php foreach ($needs as [$label, $hint, $count, $href, $ic]): ?>
                    <a class="op-tile has-work" href="<?= e($href) ?>" data-need="<?= e(strtolower(str_replace(' ', '-', $label))) ?>">
                        <span class="op-tile__icon"><?= icon($ic) ?></span>
                        <span><strong><?= e($label) ?> <span class="bt-count"><?= $count ?></span></strong><small><?= e($hint) ?></small></span>
                    </a>
                <?php endforeach; ?>
            </nav>
        <?php else: ?>
            <p class="chart-empty" id="needsYou"><?= icon('check') ?> Nothing is waiting for you.</p>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($branches): ?>
    <section class="card report-card">
        <header class="card__head"><h2><?= icon('store') ?> Branches</h2><a class="btn btn--sm btn--light no-print" href="<?= e(url('pages/report-branches.php')) ?>">Compare</a></header>
        <div class="table-wrap">
            <table class="table report-table" id="dashBranches">
                <thead><tr><th>Branch</th><th class="num">Today</th><th class="num">This month</th><?php if ($canCost): ?><th class="num col-opt">Profit (month)</th><?php endif; ?>
                    <th class="num">Low stock</th><th class="num">Open jobs</th><th class="num col-opt">Ready</th></tr></thead>
                <tbody>
                <?php foreach ($branches as $b): ?>
                    <tr data-branch="<?= e($b['code']) ?>"><td><span class="badge badge--branch"><?= e($b['code']) ?></span> <?= e($b['name']) ?></td>
                        <td class="num"><?= e(money($b['today_net'])) ?><small class="muted block"><?= (int) $b['today_sales'] ?> sales</small></td>
                        <td class="num"><?= e(money($b['net'])) ?></td>
                        <?php if ($canCost): ?><td class="num col-opt"><?= e(money($b['profit'])) ?></td><?php endif; ?>
                        <td class="num<?= $b['low'] > 0 ? ' is-neg' : '' ?>"><?= $b['low'] ?></td><td class="num"><?= $b['open_jobs'] ?></td>
                        <td class="num col-opt"><?= (int) ($b['ready'] ?? 0) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<div class="report-grid">
    <section class="card report-card">
        <header class="card__head"><h2><?= icon('chart') ?> Last 7 Days</h2><a class="btn btn--sm btn--light no-print" href="<?= e(url('pages/reports.php?' . http_build_query(['from' => date('Y-m-d', strtotime('-6 days')), 'to' => $today]))) ?>">Sales report</a></header>
        <ul class="barlist" id="dashWeek">
            <?php foreach (array_reverse($week) as $p): ?>
                <?php $barRow($p['long'], null, money(from_cents($p['cents'])), $weekMax > 0 ? $p['cents'] / $weekMax : 0, $p['count'] . ' ' . ($p['count'] === 1 ? 'sale' : 'sales')); ?>
            <?php endforeach; ?>
        </ul>
    </section>

    <?php if ($canJobs): ?>
        <section class="card report-card">
            <header class="card__head"><h2><?= icon('wrench') ?> Job Orders</h2><a class="btn btn--sm btn--light no-print" href="<?= e(url('pages/report-jobs.php')) ?>">Jobs report</a></header>
            <ul class="barlist" id="dashJobStatus">
                <?php foreach ($jobs as $s => $n): ?>
                    <?php $barRow(JobOrders::STATUSES[$s], null, (string) $n, $jobMax > 0 ? $n / $jobMax : 0, $s === 'completed' ? 'ready to bill and release' : 'open'); ?>
                <?php endforeach; ?>
            </ul>
            <?php if ($workload): ?>
                <table class="table report-table dash-workload" id="dashWorkload">
                    <thead><tr><th>Technician</th><th class="num">Open jobs</th><th class="num">Overdue</th></tr></thead>
                    <tbody>
                    <?php foreach ($workload as $w): ?>
                        <tr><td><?= e($w['name']) ?></td><td class="num"><?= (int) $w['open_jobs'] ?></td><td class="num<?= (int) $w['overdue'] > 0 ? ' is-neg' : '' ?>"><?= (int) $w['overdue'] ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="card report-card">
        <header class="card__head"><h2><?= icon('alert') ?> Low Stock</h2><a class="btn btn--sm btn--light no-print" href="<?= e(url('pages/inventory.php?status=low')) ?>">Inventory</a></header>
        <div class="table-wrap">
            <table class="table report-table" id="dashLow">
                <thead><tr><th>Product</th><th class="num">On hand</th><th class="num">Low at</th></tr></thead>
                <tbody>
                <?php foreach ($low as $p): ?>
                    <tr><td><?= e($p['name']) ?><small class="muted block"><?= e($p['code']) ?></small></td>
                        <td class="num<?= (int) $p['stock'] === 0 ? ' is-neg' : '' ?>"><?= (int) $p['stock'] ?></td><td class="num"><?= (int) $p['reorder_level'] ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$low): ?><tr><td colspan="3" class="empty"><?= icon('check') ?> Every product is above its low-stock level.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card report-card">
        <header class="card__head"><h2><?= icon('tag') ?> Today</h2></header>
        <dl class="detail-list dash-today">
            <div><dt>Lower prices &amp; discounts</dt><dd id="dashOverrides"><a href="<?= e(url('pages/report-pricing.php?' . http_build_query(['from' => $today, 'to' => $today]))) ?>"><?= $override['count'] ?> · <?= e(money($override['amount'])) ?> given</a></dd></div>
            <div><dt>Transfers in transit</dt><dd id="dashTransit"><?= $transit['transfers'] ?> (<?= number_format($transit['units']) ?> units)<?= $transit['late'] > 0 ? ' · <span class="text-danger">' . $transit['late'] . ' over 3 days</span>' : '' ?></dd></div>
        </dl>
        <?php if ($activity): ?>
            <h3 class="dash-sub">Recent activity</h3>
            <ul class="dash-activity" id="dashActivity">
                <?php foreach ($activity as $a): ?>
                    <li><span class="badge"><?= e(Audit::MODULES[$a['module']] ?? $a['module']) ?></span>
                        <span><?= e(str_replace('_', ' ', $a['action'])) ?><?= $a['entity_ref'] !== null ? ' · ' . e($a['entity_ref']) : '' ?></span>
                        <small class="muted"><?= e((string) $a['username']) ?> · <?= e(date('M j, g:i A', strtotime($a['occurred_at']))) ?></small></li>
                <?php endforeach; ?>
            </ul>
            <a class="btn btn--sm btn--light no-print" href="<?= e(url('pages/audit-log.php')) ?>">Audit log</a>
        <?php endif; ?>
    </section>
</div>

<div class="chart-tip" id="chartTip" role="status" hidden>
    <strong class="chart-tip__value"></strong>
    <span class="chart-tip__title"></span>
    <small class="chart-tip__note"></small>
</div>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
