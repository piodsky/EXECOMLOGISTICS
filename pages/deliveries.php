<?php
/**
 * Customer Orders → Delivery Receipts (any of CustomerOrders::VIEW_PERMISSIONS): DRs in the branch scope with their
 * order, receiver / acceptance and bill. Filters: search, status, billed yes / no.
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('customer-orders');

$filters = [
    'search' => input_string($_GET, 'search', 100),
    'status' => is_string($_GET['status'] ?? null) && isset(CustomerDeliveries::STATUSES[$_GET['status']]) ? $_GET['status'] : '',
    'billed' => is_string($_GET['billed'] ?? null) && in_array($_GET['billed'], ['yes', 'no'], true) ? $_GET['billed'] : '',
];
$pgQuery = array_filter($filters, static fn ($v) => $v !== '');
$pg      = paginate(CustomerDeliveries::count($filters), 20);
$rows    = CustomerDeliveries::search($filters, $pg['per_page'], $pg['offset']);
$pgPath  = 'pages/deliveries.php';
$returnTo = 'deliveries.php' . (($pgQuery || $pg['page'] > 1) ? '?' . http_build_query($pgQuery + ['page' => $pg['page']]) : '');
$showBranch = Branch::current() === Branch::ALL;
$cols = 6 + ($showBranch ? 1 : 0);

$ordersTab = 'deliveries';
$page['title'] = 'Delivery Receipts';
$pageStyles  = ['css/sales.css', 'css/stock-docs.css', 'css/transfers.css', 'css/reports.css', 'css/purchasing.css'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Customer Orders</h1>
        <p class="muted">Delivery receipts: what left the branch for customer orders, who received it, and whether it is billed.</p>
    </div>
    <div class="page-actions"><span class="badge badge--period badge--branch"><?= icon('store') ?> <?= e(Branch::label()) ?></span></div>
</div>

<?php require ROOT_PATH . '/includes/orders-nav.php'; ?>

<section class="card">
    <form class="toolbar" method="get" action="<?= e(url('pages/deliveries.php')) ?>" role="search">
        <label class="toolbar__search">
            <?= icon('search') ?>
            <input class="form-input" type="search" name="search" maxlength="100" placeholder="DR no., order no., customer PO, customer, receiver, IAR"
                   value="<?= e($filters['search']) ?>" aria-label="Search delivery receipts">
        </label>
        <select class="form-input" name="status" aria-label="Status">
            <option value="">All statuses</option>
            <?php foreach (CustomerDeliveries::STATUSES as $value => $label): ?>
                <option value="<?= e($value) ?>"<?= $filters['status'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <select class="form-input" name="billed" aria-label="Billing">
            <option value="">Billed and not billed</option>
            <option value="no"<?= $filters['billed'] === 'no' ? ' selected' : '' ?>>Not billed yet</option>
            <option value="yes"<?= $filters['billed'] === 'yes' ? ' selected' : '' ?>>Billed</option>
        </select>
        <button type="submit" class="btn btn--primary">Filter</button>
        <?php if ($pgQuery): ?><a class="btn btn--light" href="<?= e(url('pages/deliveries.php')) ?>">Reset</a><?php endif; ?>
    </form>

    <div class="table-wrap">
        <table class="table table--list pu-table" id="drTable">
            <thead>
            <tr>
                <th>DR</th>
                <th>Customer / Order</th>
                <?php if ($showBranch): ?><th class="col-opt">Branch</th><?php endif; ?>
                <th class="num">Qty</th>
                <th class="col-opt">Received</th>
                <th>Billed</th>
                <th>Status</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $d): ?>
                <tr class="<?= $d['status'] === 'cancelled' ? 'is-void' : '' ?>" data-dr="<?= e($d['dr_no']) ?>">
                    <td>
                        <a class="item-cell__name doc-no" href="<?= e(url('pages/dr-view.php?id=' . (int) $d['id'] . '&return=' . rawurlencode($returnTo))) ?>"><?= e($d['dr_no']) ?></a>
                        <small class="muted block"><?= e(date('M j, Y', strtotime($d['released_at']))) ?> · <?= e($d['released_by_name']) ?></small>
                    </td>
                    <td><?= e($d['customer_name']) ?><small class="muted block"><?= e((string) $d['order_no']) ?> · PO <?= e($d['customer_po_no']) ?></small></td>
                    <?php if ($showBranch): ?><td class="col-opt"><span class="badge badge--branch" title="<?= e($d['branch_name']) ?>"><?= e($d['branch_code']) ?></span></td><?php endif; ?>
                    <td class="num"><?= number_format((int) $d['total_qty']) ?></td>
                    <td class="col-opt"><?= $d['received_by'] ? e($d['received_by']) . '<small class="muted block">' . e(date('M j, Y', strtotime($d['received_date']))) . ($d['acceptance_ref'] ? ' · ' . e($d['acceptance_ref']) : '') . '</small>' : '<span class="muted">—</span>' ?></td>
                    <td><?= $d['sale_no'] ? 'No. ' . e($d['sale_no']) : ($d['status'] === 'cancelled' ? '<span class="muted">—</span>' : '<span class="badge badge--warning">Not billed</span>') ?></td>
                    <td><span class="badge <?= e(CustomerDeliveries::BADGES[$d['status']] ?? '') ?>"><?= e(CustomerDeliveries::STATUSES[$d['status']] ?? $d['status']) ?></span></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="<?= $cols ?>" class="empty">No delivery receipts found<?= $pgQuery ? ' for these filters' : ' yet' ?>.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php require ROOT_PATH . '/includes/pagination.php'; ?>
</section>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
