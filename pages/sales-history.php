<?php
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('sales-history');

// ---------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------
$statuses = ['all' => 'All statuses'] + Sales::STATUSES;
$filters = [
    'q'       => input_string($_GET, 'search', 100),
    'from'    => input_date($_GET, 'from'),
    'to'      => input_date($_GET, 'to'),
    'status'  => is_string($_GET['status'] ?? null) && array_key_exists($_GET['status'], $statuses) ? $_GET['status'] : 'all',
    'payment' => is_string($_GET['payment'] ?? null) && array_key_exists($_GET['payment'], Sales::PAYMENT_TYPES) ? $_GET['payment'] : '',
    'cashier' => input_int($_GET, 'cashier', 1),
];
if ($filters['from'] !== null && $filters['to'] !== null && $filters['from'] > $filters['to']) {
    [$filters['from'], $filters['to']] = [$filters['to'], $filters['from']];
}
$pgQuery = array_filter([
    'search'  => $filters['q'],
    'from'    => $filters['from'],
    'to'      => $filters['to'],
    'status'  => $filters['status'] !== 'all' ? $filters['status'] : null,
    'payment' => $filters['payment'],
    'cashier' => $filters['cashier'],
], static fn ($v) => $v !== '' && $v !== null);

$pg       = paginate(Sales::count($filters), 20);
$sales    = Sales::search($filters, $pg['per_page'], $pg['offset']);
$summary  = Sales::summary($filters);
$cashiers = Sales::cashiers();
$showBranch = Branch::current() === Branch::ALL; // several branches in the list
$returnTo = 'sales-history.php' . (($pgQuery || $pg['page'] > 1) ? '?' . http_build_query($pgQuery + ['page' => $pg['page']]) : '');
$pgPath   = 'pages/sales-history.php';

// Quick date ranges (keep the other filters)
$today  = new DateTimeImmutable('today');
$ranges = [
    'Today'        => [$today, $today],
    'Yesterday'    => [$today->modify('-1 day'), $today->modify('-1 day')],
    'Last 7 days'  => [$today->modify('-6 days'), $today],
    'This month'   => [$today->modify('first day of this month'), $today],
];
$rangeUrl = static function (?DateTimeImmutable $from, ?DateTimeImmutable $to) use ($pgQuery): string {
    $q = array_filter(
        ['from' => $from?->format('Y-m-d'), 'to' => $to?->format('Y-m-d')] + $pgQuery,
        static fn ($v) => $v !== null
    );
    if ($from === null) {
        unset($q['from'], $q['to']);
    }
    return url('pages/sales-history.php') . ($q ? '?' . http_build_query($q) : '');
};
$period = match (true) {
    $filters['from'] !== null && $filters['from'] === $filters['to'] => date('M j, Y', strtotime($filters['from'])),
    $filters['from'] !== null && $filters['to'] !== null => date('M j', strtotime($filters['from'])) . ' – ' . date('M j, Y', strtotime($filters['to'])),
    $filters['from'] !== null => 'Since ' . date('M j, Y', strtotime($filters['from'])),
    $filters['to'] !== null   => 'Until ' . date('M j, Y', strtotime($filters['to'])),
    default                   => 'All time',
};

$pageStyles = ['css/sales.css'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Sales History</h1>
        <p class="muted">Every completed and voided sale. Open a sale to reprint its receipt<?= Auth::can('sales.cancel') ? ' or void it' : '' ?>.</p>
    </div>
    <div class="page-actions">
        <span class="badge badge--period badge--branch" id="scopeBranch"><?= icon('store') ?> <?= e(Branch::label()) ?></span>
        <span class="badge badge--period"><?= icon('calendar') ?> <?= e($period) ?></span>
    </div>
</div>

<section class="stats" aria-label="Sales summary">
    <div class="stat">
        <span class="stat__icon"><?= icon('receipt') ?></span>
        <div><p class="stat__label">Completed Sales</p><p class="stat__value"><?= number_format((int) $summary['sales']) ?></p></div>
    </div>
    <div class="stat">
        <span class="stat__icon"><?= icon('wallet') ?></span>
        <div><p class="stat__label">Total Sales</p><p class="stat__value"><?= e(money($summary['revenue'])) ?></p></div>
    </div>
    <div class="stat">
        <span class="stat__icon"><?= icon('chart') ?></span>
        <div><p class="stat__label">Average Sale</p><p class="stat__value"><?= e(money($summary['average'])) ?></p></div>
    </div>
    <div class="stat<?= (int) $summary['voided'] > 0 ? ' stat--danger' : '' ?>">
        <span class="stat__icon"><?= icon('x') ?></span>
        <div>
            <p class="stat__label">Voided</p>
            <p class="stat__value"><?= number_format((int) $summary['voided']) ?>
                <?php if ((int) $summary['voided'] > 0): ?><small><?= e(money($summary['voided_total'])) ?></small><?php endif; ?>
            </p>
        </div>
    </div>
</section>

<section class="card">
    <form class="toolbar" method="get" action="<?= e(url('pages/sales-history.php')) ?>" role="search">
        <label class="toolbar__search">
            <?= icon('search') ?>
            <input class="form-input" type="search" name="search" maxlength="100" placeholder="Sale no., customer or item"
                   value="<?= e($filters['q']) ?>" aria-label="Search sales">
        </label>
        <label class="date-field">
            <span>From</span>
            <input class="form-input" type="date" name="from" value="<?= e($filters['from'] ?? '') ?>" max="<?= e($today->format('Y-m-d')) ?>">
        </label>
        <label class="date-field">
            <span>To</span>
            <input class="form-input" type="date" name="to" value="<?= e($filters['to'] ?? '') ?>" max="<?= e($today->format('Y-m-d')) ?>">
        </label>
        <select class="form-input" name="status" aria-label="Status">
            <?php foreach ($statuses as $value => $label): ?>
                <option value="<?= e($value) ?>"<?= $filters['status'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <select class="form-input" name="payment" aria-label="Payment type">
            <option value="">All payments</option>
            <?php foreach (Sales::PAYMENT_TYPES as $value => $label): ?>
                <option value="<?= e($value) ?>"<?= $filters['payment'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <select class="form-input" name="cashier" aria-label="Cashier">
            <option value="">All cashiers</option>
            <?php foreach ($cashiers as $u): ?>
                <option value="<?= (int) $u['id'] ?>"<?= $filters['cashier'] === (int) $u['id'] ? ' selected' : '' ?>><?= e($u['full_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn--primary">Filter</button>
        <?php if ($pgQuery): ?>
            <a class="btn btn--light" href="<?= e(url('pages/sales-history.php')) ?>">Reset</a>
        <?php endif; ?>
    </form>

    <nav class="quick-ranges" aria-label="Quick date ranges">
        <?php foreach ($ranges as $label => [$from, $to]): ?>
            <?php $active = $filters['from'] === $from->format('Y-m-d') && $filters['to'] === $to->format('Y-m-d'); ?>
            <a href="<?= e($rangeUrl($from, $to)) ?>" class="chip<?= $active ? ' is-active' : '' ?>"<?= $active ? ' aria-current="true"' : '' ?>><?= e($label) ?></a>
        <?php endforeach; ?>
        <?php $allTime = $filters['from'] === null && $filters['to'] === null; ?>
        <a href="<?= e($rangeUrl(null, null)) ?>" class="chip<?= $allTime ? ' is-active' : '' ?>"<?= $allTime ? ' aria-current="true"' : '' ?>>All time</a>
    </nav>

    <div class="table-wrap">
        <table class="table table--list sales-table">
            <thead>
            <tr>
                <th>Sale No.</th>
                <th>Date</th>
                <th>Customer</th>
                <?php if ($showBranch): ?><th class="col-opt">Branch</th><?php endif; ?>
                <th class="col-opt">Cashier</th>
                <th class="num col-opt">Items</th>
                <th>Payment</th>
                <th class="num">Total</th>
                <th>Status</th>
                <th class="actions-col">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($sales as $s): ?>
                <?php
                $void    = $s['status'] === 'cancelled';
                $viewUrl = url('pages/sale-view.php?id=' . (int) $s['id'] . '&return=' . rawurlencode($returnTo));
                ?>
                <tr class="<?= $void ? 'is-void' : '' ?>">
                    <td><a class="item-cell__name sale-no" href="<?= e($viewUrl) ?>"><?= e($s['sale_no']) ?></a></td>
                    <td class="nowrap"><?= e(date('M j, Y', strtotime($s['created_at']))) ?> <small class="muted sale-time"><?= e(date('g:i A', strtotime($s['created_at']))) ?></small></td>
                    <td><?= e($s['customer_name']) ?></td>
                    <?php if ($showBranch): ?><td class="col-opt"><span class="badge badge--branch" title="<?= e($s['branch_name']) ?>"><?= e($s['branch_code']) ?></span></td><?php endif; ?>
                    <td class="col-opt"><?= e($s['cashier_name']) ?></td>
                    <td class="num col-opt"><?= (int) $s['items'] ?></td>
                    <td><span class="badge"><?= e(Sales::PAYMENT_TYPES[$s['payment_type']] ?? $s['payment_type']) ?></span></td>
                    <td class="num sale-total"><?= e(money($s['total'])) ?></td>
                    <td><span class="badge <?= $void ? 'badge--danger' : 'badge--success' ?>"><?= e(Sales::STATUSES[$s['status']]) ?></span></td>
                    <td class="actions-col">
                        <div class="row-actions">
                            <a class="icon-btn" href="<?= e($viewUrl) ?>" title="View details" aria-label="View sale <?= e($s['sale_no']) ?>"><?= icon('eye') ?></a>
                            <a class="icon-btn" href="<?= e(url('pages/receipt.php?id=' . (int) $s['id'] . '&autoprint=1')) ?>" target="_blank" rel="noopener"
                               title="Reprint receipt" aria-label="Reprint receipt <?= e($s['sale_no']) ?>"><?= icon('printer') ?></a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$sales): ?>
                <tr><td colspan="<?= $showBranch ? 10 : 9 ?>" class="empty">No sales found<?= $pgQuery ? ' for these filters' : ' yet' ?>.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php require ROOT_PATH . '/includes/pagination.php'; ?>
</section>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
