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
$canInventory = can_open_menu(config('menu')['inventory']);
$canTransfers = can_open_menu(config('menu')['transfers']);
$activity = Dashboard::recentActivity(8);
$jobMax   = max($jobs ?: [0]);
$canJobs  = Auth::canAny(...JobOrders::VIEW_PERMISSIONS);
// More at a glance: money position, sales pace + target, coming up, sales mix, stock health, service, people.
$money    = Dashboard::money();
$pace     = Dashboard::pace();
$upcoming = Dashboard::upcoming();
$topItems = Reports::topItems($month, $today, 'revenue', 5);
$topCats  = array_slice(Reports::byCategory($month, $today), 0, 5);
$payToday = Reports::byPayment($today, $today);
$cashiers = Reports::byCashier($today, $today);
$slow     = Dashboard::slowMovers(60, 8);
$stockCat = Reports::stockByCategory();
$service  = Dashboard::service();
$topCust  = Dashboard::topCustomers(5);
$myNotes  = Notifications::forUser((int) Auth::id(), true, 5);
$maxOf    = static fn (array $rows, string $k): float => max(array_map(static fn (array $r): float => (float) $r[$k], $rows) ?: [0]);
$agingBar = static function (array $aging): void { // stacked bar: not due · 1–30 · 31–60 · 61–90 · 90+
    $x = 0.0;
    ?>
    <svg class="dash-aging" width="100%" height="12" aria-hidden="true" focusable="false">
        <?php foreach ($aging['buckets'] as $k => $b): ?>
            <?php $w = $aging['cents'] > 0 ? $b['cents'] / $aging['cents'] * 100 : 0; ?>
            <?php if ($w > 0): ?><rect class="dash-aging__<?= e($k) ?>" x="<?= e(number_format($x, 2, '.', '')) ?>%" width="<?= e(number_format($w, 2, '.', '')) ?>%" height="12"/><?php endif; ?>
            <?php $x += $w; ?>
        <?php endforeach; ?>
    </svg>
    <ul class="dash-aging-legend">
        <?php foreach ($aging['buckets'] as $k => $b): ?>
            <li><span class="dash-dot dash-aging__<?= e($k) ?>"></span><?= e(Collections::AGING[$k][0]) ?> <strong><?= e(money(from_cents($b['cents']))) ?></strong></li>
        <?php endforeach; ?>
    </ul>
    <?php
};

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

<?php if ($money['receivable'] !== null || $money['payable'] !== null): ?>
    <section class="card report-card" id="dashMoney" aria-label="Money position">
        <header class="card__head"><h2><?= icon('wallet') ?> Money Position</h2><span class="muted"><?= e(Branch::label()) ?></span></header>
        <div class="dash-money">
            <?php if (($r = $money['receivable']) !== null): ?>
                <a class="dash-money__box" href="<?= e(url('pages/collections.php')) ?>" id="dashReceivable">
                    <span class="dash-money__label">Customers owe us</span>
                    <strong class="dash-money__value"><?= e(money(from_cents($r['aging']['cents']))) ?></strong>
                    <span class="dash-money__sub<?= $r['overdue_cents'] > 0 ? ' text-danger' : '' ?>"><?= $r['aging']['count'] ?> unpaid bills · <?= e(money(from_cents($r['overdue_cents']))) ?> overdue</span>
                    <?php $agingBar($r['aging']); ?>
                </a>
            <?php endif; ?>
            <?php if (($p = $money['payable']) !== null): ?>
                <a class="dash-money__box" href="<?= e(url('pages/payables.php')) ?>" id="dashPayable">
                    <span class="dash-money__label">We owe suppliers</span>
                    <strong class="dash-money__value"><?= e(money(from_cents($p['aging']['cents']))) ?></strong>
                    <span class="dash-money__sub<?= $p['overdue_cents'] > 0 ? ' text-danger' : '' ?>"><?= $p['aging']['count'] ?> open invoices · <?= e(money(from_cents($p['overdue_cents']))) ?> overdue</span>
                    <span class="dash-money__sub"><?= $p['due_week'] ?> due in 7 days · <?= e(money(from_cents($p['due_week_cents']))) ?></span>
                    <?php $agingBar($p['aging']); ?>
                </a>
            <?php endif; ?>
            <div class="dash-money__box dash-money__box--plain" id="dashChecks">
                <span class="dash-money__label">Checks not cleared</span>
                <?php if ($r !== null): ?>
                    <a class="dash-money__line" href="<?= e(url('pages/checks.php?check=on_hand')) ?>"><span>From customers</span><strong><?= $r['checks'] ?> · <?= e(money(from_cents($r['checks_cents']))) ?></strong></a>
                    <?php if ($r['deposited'] > 0): ?><small class="muted"><?= $r['deposited'] ?> deposited, waiting to clear</small><?php endif; ?>
                <?php endif; ?>
                <?php if ($p !== null): ?>
                    <a class="dash-money__line" href="<?= e(url('pages/disbursements.php?checks=issued')) ?>"><span>Issued to suppliers</span><strong><?= $p['checks'] ?> · <?= e(money(from_cents($p['checks_cents']))) ?></strong></a>
                <?php endif; ?>
            </div>
            <div class="dash-money__box dash-money__box--plain" id="dashCollected">
                <?php if ($r !== null): ?>
                    <span class="dash-money__label">Collected</span>
                    <div class="dash-money__line"><span>This week</span><strong><?= e(money(from_cents($r['week_cents']))) ?></strong></div>
                    <div class="dash-money__line"><span>This month</span><strong><?= e(money(from_cents($r['month_cents']))) ?></strong></div>
                    <?php if ($r['methods']): ?>
                        <small class="muted"><?= e(implode(' · ', array_map(static fn (string $m, string $v): string => (Collections::METHODS[$m] ?? $m) . ' ' . money($v), array_keys($r['methods']), $r['methods']))) ?></small>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($p !== null): ?>
                    <div class="dash-money__line"><span>Paid to suppliers (month)</span><strong><?= e(money(from_cents($p['paid_month_cents']))) ?></strong></div>
                <?php endif; ?>
            </div>
        </div>
    </section>
<?php endif; ?>

<div class="report-grid">
    <section class="card report-card" id="dashPace">
        <header class="card__head"><h2><?= icon('trend-up') ?> Sales Pace</h2><span class="muted">Day <?= $pace['day'] ?> of <?= $pace['days'] ?></span></header>
        <?php
        $delta = $pace['prev_cents'] > 0 ? ($pace['now_cents'] - $pace['prev_cents']) / $pace['prev_cents'] * 100 : null;
        $targetPct = $pace['target_cents'] ? $pace['now_cents'] / $pace['target_cents'] * 100 : null;
        $expected  = $pace['day'] / $pace['days'] * 100;
        ?>
        <dl class="detail-list dash-pace">
            <div><dt>This month so far</dt><dd><strong><?= e(money(from_cents($pace['now_cents']))) ?></strong></dd></div>
            <div><dt>Same days last month (<?= e($pace['prev_label']) ?>)</dt><dd><?= e(money(from_cents($pace['prev_cents']))) ?>
                <?php if ($delta !== null): ?><small class="block <?= $delta >= 0 ? 'text-success' : 'text-danger' ?>" id="dashPaceDelta"><?= $delta >= 0 ? '▲' : '▼' ?> <?= e(number_format(abs($delta), 1)) ?>%</small><?php endif; ?></dd></div>
        </dl>
        <?php if ($targetPct !== null): ?>
            <div class="dash-target" id="dashTarget">
                <div class="dash-target__head"><span>Monthly target <?= e(money(from_cents((int) $pace['target_cents']))) ?></span><strong><?= e(number_format($targetPct, 1)) ?>%</strong></div>
                <svg class="dash-target__bar" width="100%" height="12" aria-hidden="true" focusable="false">
                    <rect class="dash-target__track" width="100%" height="12" rx="6"/>
                    <rect class="dash-target__fill<?= $targetPct + 0.01 < $expected ? ' is-behind' : '' ?>" width="<?= e(number_format(min(100, $targetPct), 2, '.', '')) ?>%" height="12" rx="6"/>
                    <rect class="dash-target__mark" x="<?= e(number_format(min(99.6, $expected), 2, '.', '')) ?>%" width="2" height="12"/>
                </svg>
                <small class="muted"><?= $targetPct + 0.01 < $expected ? 'Behind the pace for day ' . $pace['day'] . ' (' . number_format($expected, 0) . '% of the month).' : 'On or ahead of pace.' ?>
                    <?= e(money(from_cents(max(0, (int) $pace['target_cents'] - $pace['now_cents'])))) ?> to go.</small>
            </div>
        <?php elseif (Auth::can('branches.manage')): ?>
            <p class="muted dash-note">No monthly target yet. Set one per branch in <a href="<?= e(url('pages/branches.php')) ?>">Settings → Branches</a>.</p>
        <?php endif; ?>
    </section>

    <section class="card report-card" id="dashUpcoming">
        <header class="card__head"><h2><?= icon('calendar') ?> Coming Up (7 days)</h2></header>
        <?php if ($upcoming['late']): ?>
            <ul class="dash-late">
                <?php foreach ($upcoming['late'] as [$lbl, $n, $href]): ?>
                    <li><a href="<?= e(url($href)) ?>"><?= icon('alert') ?> <?= e($lbl) ?> <strong><?= $n ?></strong></a></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if ($upcoming['items']): ?>
            <ul class="dash-upcoming">
                <?php foreach ($upcoming['items'] as $u): ?>
                    <li><span class="dash-upcoming__date"><?= e($u['date'] === $today ? 'Today' : date('D j', strtotime($u['date']))) ?></span>
                        <a href="<?= e(url($u['url'])) ?>"><span class="dash-upcoming__kind"><?= icon($u['icon']) ?> <?= e($u['kind']) ?></span>
                            <span><strong><?= e($u['ref']) ?></strong> · <?= e($u['who']) ?></span></a></li>
                <?php endforeach; ?>
            </ul>
            <?php if ($upcoming['more'] > 0): ?><p class="muted dash-note">+<?= $upcoming['more'] ?> more this week</p><?php endif; ?>
        <?php else: ?>
            <p class="chart-empty"><?= icon('check') ?> Nothing due in the next 7 days.</p>
        <?php endif; ?>
    </section>

    <section class="card report-card" id="dashTopItems">
        <header class="card__head"><h2><?= icon('box') ?> Top Items (month)</h2><a class="btn btn--sm btn--light no-print" href="<?= e(url('pages/reports.php?' . http_build_query(['from' => $month, 'to' => $today]))) ?>">Sales report</a></header>
        <?php if ($topItems): ?>
            <?php $mx = $maxOf($topItems, 'revenue'); ?>
            <ul class="barlist">
                <?php foreach ($topItems as $ti): ?>
                    <?php $barRow($ti['name'], (int) $ti['qty'] . ' sold', money($ti['revenue']), $mx > 0 ? (float) $ti['revenue'] / $mx : 0, 'before discount & VAT'); ?>
                <?php endforeach; ?>
            </ul>
            <h3 class="dash-sub">Top categories</h3>
            <?php $mx = $maxOf($topCats, 'revenue'); ?>
            <ul class="barlist">
                <?php foreach ($topCats as $tc): ?>
                    <?php $barRow($tc['name'], (int) $tc['qty'] . ' units', money($tc['revenue']), $mx > 0 ? (float) $tc['revenue'] / $mx : 0, 'before discount & VAT'); ?>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="chart-empty">No sales this month yet.</p>
        <?php endif; ?>
    </section>

    <section class="card report-card" id="dashToday2">
        <header class="card__head"><h2><?= icon('receipt') ?> Sales Today</h2></header>
        <h3 class="dash-sub dash-sub--first">By payment</h3>
        <?php $mx = $maxOf($payToday, 'total'); ?>
        <ul class="barlist" id="dashPayMix">
            <?php foreach ($payToday as $pm): ?>
                <?php $barRow($pm['name'], $pm['count'] . ' ' . ($pm['count'] === 1 ? 'sale' : 'sales'), money($pm['total']), $mx > 0 ? (float) $pm['total'] / $mx : 0, 'incl. VAT'); ?>
            <?php endforeach; ?>
        </ul>
        <h3 class="dash-sub">By cashier</h3>
        <?php if ($cashiers): ?>
            <?php $mx = $maxOf($cashiers, 'total'); ?>
            <ul class="barlist" id="dashCashiers">
                <?php foreach ($cashiers as $cs): ?>
                    <?php $barRow($cs['name'], (int) $cs['count'] . ' ' . ((int) $cs['count'] === 1 ? 'sale' : 'sales'), money($cs['total']), $mx > 0 ? (float) $cs['total'] / $mx : 0, 'incl. VAT'); ?>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="chart-empty">No sales yet today.</p>
        <?php endif; ?>
    </section>
</div>

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
                    <tr><td><?php if ($canInventory): ?><a class="item-cell__name" href="<?= e(url('pages/product-form.php?id=' . (int) $p['id'])) ?>"><?= e($p['name']) ?></a><?php else: ?><?= e($p['name']) ?><?php endif; ?><small class="muted block"><?= e($p['code']) ?></small></td>
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
            <div><dt>Transfers in transit</dt><dd id="dashTransit"><?php if ($canTransfers && $transit['transfers'] > 0): ?><a href="<?= e(url('pages/transfers.php?status=released')) ?>"><?= $transit['transfers'] ?> (<?= number_format($transit['units']) ?> units)</a><?php else: ?><?= $transit['transfers'] ?> (<?= number_format($transit['units']) ?> units)<?php endif; ?><?= $transit['late'] > 0 ? ' · <span class="text-danger">' . $transit['late'] . ' over 3 days</span>' : '' ?></dd></div>
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

<div class="report-grid">
    <section class="card report-card" id="dashSlow">
        <header class="card__head"><h2><?= icon('clock') ?> Slow-moving Stock</h2><span class="muted">No sale in <?= $slow['days'] ?>+ days</span></header>
        <div class="table-wrap">
            <table class="table report-table">
                <thead><tr><th>Product</th><th class="num">On hand</th><th class="num">Value <?= $slow['at_cost'] ? '(cost)' : '(price)' ?></th><th class="num col-opt">Last sold</th></tr></thead>
                <tbody>
                <?php foreach ($slow['rows'] as $sm): ?>
                    <tr><td><?php if ($canInventory): ?><a class="item-cell__name" href="<?= e(url('pages/product-form.php?id=' . (int) $sm['id'])) ?>"><?= e($sm['name']) ?></a><?php else: ?><?= e($sm['name']) ?><?php endif; ?><small class="muted block"><?= e($sm['code']) ?></small></td>
                        <td class="num"><?= (int) $sm['qty'] ?></td><td class="num"><?= e(money($sm['value'])) ?></td>
                        <td class="num col-opt"><?= $sm['last_sold'] ? e(date('M j, Y', strtotime($sm['last_sold']))) : '<span class="muted">Never</span>' ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$slow['rows']): ?><tr><td colspan="4" class="empty"><?= icon('check') ?> Everything in stock sold within <?= $slow['days'] ?> days.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card report-card" id="dashStockCat">
        <header class="card__head"><h2><?= icon('layers') ?> Stock by Category</h2><span class="muted">at selling price</span></header>
        <?php $mx = $maxOf($stockCat, 'value'); ?>
        <ul class="barlist">
            <?php foreach ($stockCat as $sc): ?>
                <?php $barRow($sc['name'], number_format((int) $sc['units']) . ' units', money($sc['value']), $mx > 0 ? (float) $sc['value'] / $mx : 0, (int) $sc['products'] . ' products'); ?>
            <?php endforeach; ?>
        </ul>
        <p class="muted dash-note"><?= (int) $stock['out_of_stock'] ?> out of stock · <?= (int) $stock['low'] ?> low · <a href="<?= e(url('pages/inventory.php?status=out')) ?>">see out of stock</a></p>
    </section>

    <?php if ($service['jobs'] !== null || $service['quotes'] !== null): ?>
        <section class="card report-card" id="dashService">
            <header class="card__head"><h2><?= icon('wrench') ?> Service &amp; Quotations (month)</h2></header>
            <dl class="detail-list">
                <?php if (($sj = $service['jobs']) !== null): ?>
                    <div><dt>Average turnaround</dt><dd><?= $sj['avg_days'] !== null ? e(number_format($sj['avg_days'], 1)) . ' days' : '—' ?><small class="muted block"><?= $sj['completed'] ?> jobs completed</small></dd></div>
                    <div><dt>Back-jobs</dt><dd class="<?= $sj['back_jobs'] > 0 ? 'text-danger' : '' ?>"><?= $sj['back_jobs'] ?><?= $sj['back_rate'] !== null ? ' · ' . e(number_format($sj['back_rate'], 1)) . '% of released' : '' ?></dd></div>
                <?php endif; ?>
                <?php if (($sq = $service['quotes']) !== null): ?>
                    <div><dt>Quotation win rate</dt><dd><?= $sq['rate'] !== null ? e(number_format($sq['rate'], 0)) . '%' : '—' ?><small class="muted block"><?= $sq['won'] ?> won · <?= $sq['lost'] ?> lost</small></dd></div>
                    <div><dt>Quotes still open</dt><dd><a href="<?= e(url('pages/quotations.php?status=sent')) ?>"><?= $sq['open'] ?> · <?= e(money(from_cents($sq['open_cents']))) ?></a><small class="muted block">before VAT</small></dd></div>
                <?php endif; ?>
            </dl>
        </section>
    <?php endif; ?>

    <section class="card report-card" id="dashCustomers">
        <header class="card__head"><h2><?= icon('user') ?> Top Customers (month)</h2></header>
        <?php if ($topCust): ?>
            <?php $mx = $maxOf($topCust, 'total'); ?>
            <ul class="barlist">
                <?php foreach ($topCust as $tcu): ?>
                    <?php $barRow($tcu['name'], (int) $tcu['sales'] . ' ' . ((int) $tcu['sales'] === 1 ? 'sale' : 'sales'), money($tcu['total']), $mx > 0 ? (float) $tcu['total'] / $mx : 0, 'incl. VAT'); ?>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="chart-empty">No sales to registered customers this month.</p>
        <?php endif; ?>
    </section>

    <section class="card report-card" id="dashNotes">
        <header class="card__head"><h2><?= icon('bell') ?> My Notifications</h2><a class="btn btn--sm btn--light no-print" href="<?= e(url('pages/notifications.php')) ?>">See all</a></header>
        <?php if ($myNotes): ?>
            <ul class="notif-list">
                <?php foreach ($myNotes as $row): ?>
                    <?php $nItem = Notifications::present($row); ?>
                    <li><a class="notif-item is-unread" href="<?= e($nItem['url']) ?>">
                        <span class="notif-icon notif-icon--<?= e($nItem['tone']) ?>"><?= icon($nItem['icon']) ?></span>
                        <span class="notif-body"><span class="notif-text"><?= str_replace(e($nItem['ref']), '<strong>' . e($nItem['ref']) . '</strong>', e($nItem['message'])) ?></span>
                            <small class="notif-meta"><?= e($nItem['ago']) ?><?= $nItem['actor'] !== '' ? ' · ' . e($nItem['actor']) : '' ?></small></span>
                        <span class="notif-dot" aria-label="Unread"></span></a></li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="chart-empty"><?= icon('check') ?> No unread notifications.</p>
        <?php endif; ?>
    </section>
</div>

<div class="chart-tip" id="chartTip" role="status" hidden>
    <strong class="chart-tip__value"></strong>
    <span class="chart-tip__title"></span>
    <small class="chart-tip__note"></small>
</div>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
