<?php
/**
 * Purchasing → PO Internal (PurchaseOrders::canView(): purchasing.order / .approve + products.cost): purchase orders
 * to suppliers in the branch scope with delivery progress, work lists (drafts / for approval / awaiting delivery /
 * overdue), "New PO" (purchasing.order, concrete branch).
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('purchasing');
if (!PurchaseOrders::canView()) {
    abort(403, 'You do not have permission to view purchase orders.');
}

$concrete = Branch::isConcrete();
$statuses = PurchaseOrders::STATUSES + ['open' => 'Awaiting delivery', 'overdue' => 'Overdue'];
$filters = [
    'search'   => input_string($_GET, 'search', 100),
    'status'   => is_string($_GET['status'] ?? null) && isset($statuses[$_GET['status']]) ? $_GET['status'] : '',
    'supplier' => input_int($_GET, 'supplier', 1),
];
$pgQuery = array_filter($filters, static fn ($v) => $v !== '' && $v !== null);

$pg       = paginate(PurchaseOrders::count($filters), 20);
$orders   = PurchaseOrders::search($filters, $pg['per_page'], $pg['offset']);
$pgPath   = 'pages/purchase-orders.php';
$returnTo = 'purchase-orders.php' . (($pgQuery || $pg['page'] > 1) ? '?' . http_build_query($pgQuery + ['page' => $pg['page']]) : '');
$showBranch = Branch::current() === Branch::ALL;
$work = PurchaseOrders::workCounts();
$cols = 7 + ($showBranch ? 1 : 0);
$today = date('Y-m-d');

$stmt = db()->prepare('SELECT id, name, is_active FROM suppliers ORDER BY name');
$stmt->execute();
$suppliers = $stmt->fetchAll();

$listUrl = static fn (array $q): string => url('pages/purchase-orders.php?' . http_build_query($q));
$tiles = [
    ['Drafts',            'Not sent for approval yet',      'edit',  $work['draft'],   ['status' => 'draft'],   PurchaseOrders::canManage()],
    ['For Approval',      'Purchase orders to approve',     'check', $work['approve'], ['status' => 'pending'], Auth::can('purchasing.approve')],
    ['Awaiting Delivery', 'Approved, not fully received',   'truck', $work['open'],    ['status' => 'open'],    true],
    ['Overdue',           'Past the expected delivery date', 'clock', $work['overdue'], ['status' => 'overdue'], true],
];
$tiles = array_values(array_filter($tiles, static fn (array $t): bool => $t[5]));

$purchasingTab = 'orders';
$page['title'] = 'PO Internal';
$pageStyles  = ['css/sales.css', 'css/stock-docs.css', 'css/transfers.css', 'css/reports.css', 'css/purchasing.css'];
$pageScripts = ['js/purchasing.js'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Purchasing</h1>
        <p class="muted">Purchase orders to suppliers. Deliveries are received with a receiving report made from the PO.</p>
    </div>
    <div class="page-actions">
        <span class="badge badge--period badge--branch"><?= icon('store') ?> <?= e(Branch::label()) ?></span>
        <?php if ($concrete && PurchaseOrders::canManage()): ?>
            <a class="btn btn--primary" href="<?= e(url('pages/po-form.php?return=' . rawurlencode($returnTo))) ?>" id="newPoBtn"><?= icon('plus') ?> New PO</a>
        <?php endif; ?>
    </div>
</div>

<?php require ROOT_PATH . '/includes/purchasing-nav.php'; ?>

<?php if (!$concrete): ?>
    <div class="alert alert--info" role="status">
        <?= icon('info') ?>
        <span>Showing the purchase orders of all branches. Choose a branch in the top bar to prepare, approve or receive.</span>
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
    <form class="toolbar" method="get" action="<?= e(url('pages/purchase-orders.php')) ?>" role="search">
        <label class="toolbar__search">
            <?= icon('search') ?>
            <input class="form-input" type="search" name="search" maxlength="100" placeholder="PO no., supplier, end-user, item code or name"
                   value="<?= e($filters['search']) ?>" aria-label="Search purchase orders">
        </label>
        <select class="form-input" name="status" aria-label="Status">
            <option value="">All statuses</option>
            <?php foreach ($statuses as $value => $label): ?>
                <option value="<?= e($value) ?>"<?= $filters['status'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <select class="form-input" name="supplier" aria-label="Supplier">
            <option value="">All suppliers</option>
            <?php foreach ($suppliers as $s): ?>
                <option value="<?= (int) $s['id'] ?>"<?= $filters['supplier'] === (int) $s['id'] ? ' selected' : '' ?>><?= e($s['name']) ?><?= (int) $s['is_active'] === 1 ? '' : ' (inactive)' ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn--primary">Filter</button>
        <?php if ($pgQuery): ?>
            <a class="btn btn--light" href="<?= e(url('pages/purchase-orders.php')) ?>">Reset</a>
        <?php endif; ?>
    </form>

    <div class="table-wrap">
        <table class="table table--list pu-table" id="poTable">
            <thead>
            <tr>
                <th>PO</th>
                <th>Date</th>
                <th>Supplier</th>
                <?php if ($showBranch): ?><th class="col-opt">Branch</th><?php endif; ?>
                <th class="col-opt">Expected</th>
                <th>Delivery</th>
                <th class="num">Amount</th>
                <th>Status</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($orders as $o): ?>
                <?php
                $viewUrl  = url('pages/po-view.php?id=' . (int) $o['id'] . '&return=' . rawurlencode($returnTo));
                $ordered  = (int) $o['total_qty'];
                $received = (int) $o['qty_received'];
                $pct      = $ordered > 0 ? (int) floor($received * 100 / $ordered) : 0;
                $late     = $o['expected_date'] !== null && $o['expected_date'] < $today && in_array($o['status'], PurchaseOrders::OPEN, true);
                ?>
                <tr class="<?= $o['status'] === 'cancelled' ? 'is-void' : '' ?>" data-po="<?= e(PurchaseOrders::label($o)) ?>">
                    <td>
                        <a class="item-cell__name doc-no" href="<?= e($viewUrl) ?>"><?= e(PurchaseOrders::label($o)) ?></a>
                        <small class="muted block"><?= e($o['created_by_name']) ?></small>
                    </td>
                    <td class="nowrap"><?= e(date('M j, Y', strtotime($o['order_date']))) ?></td>
                    <td><?= e($o['supplier_name']) ?><small class="muted block"><?= e($o['supplier_code']) ?></small></td>
                    <?php if ($showBranch): ?><td class="col-opt"><span class="badge badge--branch" title="<?= e($o['branch_name']) ?>"><?= e($o['branch_code']) ?></span></td><?php endif; ?>
                    <td class="col-opt nowrap<?= $late ? ' text-danger' : '' ?>"><?= $o['expected_date'] !== null ? e(date('M j, Y', strtotime($o['expected_date']))) . ($late ? ' <small>(late)</small>' : '') : '<span class="muted">—</span>' ?></td>
                    <td class="pu-progress-cell">
                        <?php if (in_array($o['status'], ['draft', 'pending', 'cancelled'], true)): ?>
                            <span class="muted"><?= number_format($ordered) ?> ordered</span>
                        <?php else: ?>
                            <span class="pu-progress" aria-hidden="true"><svg viewBox="0 0 100 6" preserveAspectRatio="none"><rect class="pu-progress__track" width="100" height="6" rx="3"></rect><rect class="pu-progress__bar" width="<?= $pct ?>" height="6" rx="3"></rect></svg></span>
                            <small><?= number_format($received) ?> of <?= number_format($ordered) ?> received</small>
                        <?php endif; ?>
                    </td>
                    <td class="num doc-value"><?= e(money($o['total_amount'])) ?></td>
                    <td><span class="badge <?= e(PurchaseOrders::BADGES[$o['status']] ?? '') ?>"><?= e(PurchaseOrders::STATUSES[$o['status']] ?? $o['status']) ?></span></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$orders): ?>
                <tr><td colspan="<?= $cols ?>" class="empty">No purchase orders found<?= $pgQuery ? ' for these filters' : ' yet' ?>.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php require ROOT_PATH . '/includes/pagination.php'; ?>
    <p class="doc-foot muted"><?= icon('info') ?> <span>Amounts are the agreed supplier cost (VAT as quoted by the supplier). A PO does not change stock:
        the items enter the branch when the receiving report made from it is posted.</span></p>
</section>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
