<?php
/**
 * Purchasing → Purchase Requests (any of PurchaseRequests::VIEW_PERMISSIONS): requests in the branch scope,
 * work lists (to approve / approved, not yet on a PO), "New Request" (purchasing.request, concrete branch).
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('purchasing');

$concrete = Branch::isConcrete();
$filters = [
    'search' => input_string($_GET, 'search', 100),
    'status' => is_string($_GET['status'] ?? null) && isset(PurchaseRequests::STATUSES[$_GET['status']]) ? $_GET['status'] : '',
];
$pgQuery = array_filter($filters, static fn ($v) => $v !== '');

$pg       = paginate(PurchaseRequests::count($filters), 20);
$requests = PurchaseRequests::search($filters, $pg['per_page'], $pg['offset']);
$pgPath   = 'pages/purchase-requests.php';
$returnTo = 'purchase-requests.php' . (($pgQuery || $pg['page'] > 1) ? '?' . http_build_query($pgQuery + ['page' => $pg['page']]) : '');
$showBranch = Branch::current() === Branch::ALL;
$work = PurchaseRequests::workCounts();
$cols = 7 + ($showBranch ? 1 : 0);

$listUrl = static fn (array $q): string => url('pages/purchase-requests.php?' . http_build_query($q));
$tiles = [
    ['To Approve', 'Requests waiting for a decision', 'check', $work['approve'], ['status' => 'requested'], Auth::can('purchasing.approve')],
    ['To Order',   'Approved, not yet on a PO',       'cart',  $work['order'],   ['status' => 'approved'],  PurchaseOrders::canManage()],
];
$tiles = array_values(array_filter($tiles, static fn (array $t): bool => $t[5]));

$purchasingTab = 'requests';
$page['title'] = 'Purchase Requests';
$pageStyles  = ['css/sales.css', 'css/stock-docs.css', 'css/transfers.css', 'css/reports.css', 'css/purchasing.css'];
$pageScripts = ['js/purchasing.js'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Purchasing</h1>
        <p class="muted">Ask for items the branch needs, approve the requests, then order them from suppliers with a purchase order.</p>
    </div>
    <div class="page-actions">
        <span class="badge badge--period badge--branch"><?= icon('store') ?> <?= e(Branch::label()) ?></span>
        <?php if ($concrete && Auth::can('purchasing.request')): ?>
            <a class="btn btn--primary" href="<?= e(url('pages/pr-form.php?return=' . rawurlencode($returnTo))) ?>" id="newPrBtn"><?= icon('plus') ?> New Request</a>
        <?php endif; ?>
    </div>
</div>

<?php require ROOT_PATH . '/includes/purchasing-nav.php'; ?>

<?php if (!$concrete): ?>
    <div class="alert alert--info" role="status">
        <?= icon('info') ?>
        <span>Showing the requests of all branches. Choose a branch in the top bar to request or approve.</span>
    </div>
<?php elseif ($tiles): ?>
    <nav class="op-tiles bt-tiles" aria-label="Work lists">
        <?php foreach ($tiles as [$label, $hint, $ic, $count, $q]): ?>
            <a class="op-tile<?= $count > 0 ? ' has-work' : '' ?>" href="<?= e($listUrl($q)) ?>">
                <span class="op-tile__icon"><?= icon($ic) ?></span>
                <span><strong><?= e($label) ?> <span class="bt-count" data-work="<?= e(strtolower(str_replace(' ', '-', $label))) ?>"><?= $count ?></span></strong><small><?= e($hint) ?></small></span>
            </a>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>

<section class="card">
    <form class="toolbar" method="get" action="<?= e(url('pages/purchase-requests.php')) ?>" role="search">
        <label class="toolbar__search">
            <?= icon('search') ?>
            <input class="form-input" type="search" name="search" maxlength="100" placeholder="PR no., purpose, end-user, item code or name"
                   value="<?= e($filters['search']) ?>" aria-label="Search purchase requests">
        </label>
        <select class="form-input" name="status" aria-label="Status">
            <option value="">All statuses</option>
            <?php foreach (PurchaseRequests::STATUSES as $value => $label): ?>
                <option value="<?= e($value) ?>"<?= $filters['status'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn--primary">Filter</button>
        <?php if ($pgQuery): ?>
            <a class="btn btn--light" href="<?= e(url('pages/purchase-requests.php')) ?>">Reset</a>
        <?php endif; ?>
    </form>

    <div class="table-wrap">
        <table class="table table--list pu-table" id="prTable">
            <thead>
            <tr>
                <th>Request</th>
                <th>Requested</th>
                <?php if ($showBranch): ?><th class="col-opt">Branch</th><?php endif; ?>
                <th>Needed by</th>
                <th class="col-opt">Purpose</th>
                <th class="num col-opt">Lines</th>
                <th class="num">Qty</th>
                <th>Status</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($requests as $r): ?>
                <?php $viewUrl = url('pages/pr-view.php?id=' . (int) $r['id'] . '&return=' . rawurlencode($returnTo)); ?>
                <tr class="<?= in_array($r['status'], ['rejected', 'cancelled'], true) ? 'is-void' : '' ?>" data-pr="<?= e($r['pr_no']) ?>">
                    <td>
                        <a class="item-cell__name doc-no" href="<?= e($viewUrl) ?>"><?= e($r['pr_no']) ?></a>
                        <small class="muted block"><?= e($r['requested_by_name']) ?></small>
                    </td>
                    <td class="nowrap"><?= e(date('M j, Y', strtotime($r['requested_at']))) ?><small class="muted block"><?= e(date('g:i A', strtotime($r['requested_at']))) ?></small></td>
                    <?php if ($showBranch): ?><td class="col-opt"><span class="badge badge--branch" title="<?= e($r['branch_name']) ?>"><?= e($r['branch_code']) ?></span></td><?php endif; ?>
                    <td class="nowrap<?= $r['needed_by'] !== null && $r['needed_by'] < date('Y-m-d') && in_array($r['status'], ['requested', 'approved'], true) ? ' text-danger' : '' ?>"><?= $r['needed_by'] !== null ? e(date('M j, Y', strtotime($r['needed_by']))) : '<span class="muted">—</span>' ?></td>
                    <td class="col-opt">
                        <?= $r['purpose'] !== null ? '<small class="doc-reason">' . e($r['purpose']) . '</small>' : '' ?>
                        <?= $r['job_no'] !== null ? '<small class="muted block">' . icon('wrench') . ' ' . e($r['job_no']) . '</small>' : '' ?>
                        <?= $r['purpose'] === null && $r['job_no'] === null ? '<span class="muted">—</span>' : '' ?>
                    </td>
                    <td class="num col-opt"><?= number_format((int) $r['line_count']) ?></td>
                    <td class="num"><?= number_format((int) $r['total_qty']) ?></td>
                    <td><span class="badge <?= e(PurchaseRequests::BADGES[$r['status']] ?? '') ?>"><?= e(PurchaseRequests::STATUSES[$r['status']] ?? $r['status']) ?></span></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$requests): ?>
                <tr><td colspan="<?= $cols ?>" class="empty">No purchase requests found<?= $pgQuery ? ' for these filters' : ' yet' ?>.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php require ROOT_PATH . '/includes/pagination.php'; ?>
    <p class="doc-foot muted"><?= icon('info') ?> <span>A request does not order anything by itself: once approved, it is put on a purchase order (PO Internal) to a supplier.
        The items enter stock when the delivery is received.</span></p>
</section>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
