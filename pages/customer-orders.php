<?php
/**
 * Customer Orders → PO Outgoing (any of CustomerOrders::VIEW_PERMISSIONS): customer purchase orders in the branch
 * scope with delivery / billing progress, work lists (to confirm / to deliver / to bill / overdue),
 * "New Customer PO" (customer_orders.manage, concrete branch).
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('customer-orders');

$concrete = Branch::isConcrete();
$statuses = CustomerOrders::STATUSES + ['open' => 'Awaiting delivery', 'to_bill' => 'Delivered, not billed', 'overdue' => 'Overdue'];
$filters = [
    'search'   => input_string($_GET, 'search', 100),
    'status'   => is_string($_GET['status'] ?? null) && isset($statuses[$_GET['status']]) ? $_GET['status'] : '',
    'customer' => input_int($_GET, 'customer', 1),
    'payment'  => is_string($_GET['payment'] ?? null) && isset(PaymentStatus::FILTERS[$_GET['payment']]) ? $_GET['payment'] : '',
];
$pgQuery = array_filter($filters, static fn ($v) => $v !== '' && $v !== null);

$pg       = paginate(CustomerOrders::count($filters), 20);
$orders   = CustomerOrders::search($filters, $pg['per_page'], $pg['offset']);
$pgPath   = 'pages/customer-orders.php';
$returnTo = 'customer-orders.php' . (($pgQuery || $pg['page'] > 1) ? '?' . http_build_query($pgQuery + ['page' => $pg['page']]) : '');
$showBranch = Branch::current() === Branch::ALL;
$work  = CustomerOrders::workCounts();
$cols  = 8 + ($showBranch ? 1 : 0);
$today = date('Y-m-d');

$listUrl = static fn (array $q): string => url('pages/customer-orders.php?' . http_build_query($q));
$tiles = [
    ['To Confirm', 'Orders waiting for a branch admin', 'check', $work['confirm'], ['status' => 'pending'], Auth::can('customer_orders.approve')],
    ['To Deliver', 'Confirmed, stock reserved',         'truck', $work['deliver'], ['status' => 'open'],    Auth::can('customer_orders.deliver')],
    ['To Bill',    'Delivered, not billed yet',         'receipt', $work['bill'],  ['status' => 'to_bill'], Auth::can('customer_orders.bill')],
    ['Overdue',    'Past the delivery deadline',        'clock', $work['overdue'], ['status' => 'overdue'], true],
];
$tiles = array_values(array_filter($tiles, static fn (array $t): bool => $t[5]));

$ordersTab = 'orders';
$page['title'] = 'Customer Orders';
$pageStyles  = ['css/sales.css', 'css/stock-docs.css', 'css/transfers.css', 'css/reports.css', 'css/purchasing.css'];
$pageScripts = ['js/purchasing.js'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Customer Orders</h1>
        <p class="muted">Purchase orders from government offices, companies and schools: confirm (stock is reserved), deliver, then bill.</p>
    </div>
    <div class="page-actions">
        <span class="badge badge--period badge--branch"><?= icon('store') ?> <?= e(Branch::label()) ?></span>
        <?php if ($concrete && Auth::can('customer_orders.manage')): ?>
            <a class="btn btn--primary" href="<?= e(url('pages/co-form.php?return=' . rawurlencode($returnTo))) ?>" id="newCoBtn"><?= icon('plus') ?> New Customer PO</a>
        <?php endif; ?>
    </div>
</div>

<?php require ROOT_PATH . '/includes/orders-nav.php'; ?>

<?php if (!$concrete): ?>
    <div class="alert alert--info" role="status">
        <?= icon('info') ?>
        <span>Showing the orders of all branches. Choose a branch in the top bar to enter, confirm, deliver or bill.</span>
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
    <form class="toolbar" method="get" action="<?= e(url('pages/customer-orders.php')) ?>" role="search">
        <label class="toolbar__search">
            <?= icon('search') ?>
            <input class="form-input" type="search" name="search" maxlength="100" placeholder="Order no., customer PO no., customer, end-user, item"
                   value="<?= e($filters['search']) ?>" aria-label="Search customer orders">
        </label>
        <select class="form-input" name="status" aria-label="Status">
            <option value="">All statuses</option>
            <?php foreach ($statuses as $value => $label): ?>
                <option value="<?= e($value) ?>"<?= $filters['status'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <select class="form-input" name="payment" aria-label="Payment">
            <option value="">Payment: any</option>
            <?php foreach (PaymentStatus::FILTERS as $value => $label): ?>
                <option value="<?= e($value) ?>"<?= $filters['payment'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn--primary">Filter</button>
        <?php if ($pgQuery): ?>
            <a class="btn btn--light" href="<?= e(url('pages/customer-orders.php')) ?>">Reset</a>
        <?php endif; ?>
    </form>

    <div class="table-wrap">
        <table class="table table--list pu-table" id="coTable">
            <thead>
            <tr>
                <th>Order</th>
                <th>Customer / PO</th>
                <?php if ($showBranch): ?><th class="col-opt">Branch</th><?php endif; ?>
                <th class="col-opt">Deadline</th>
                <th>Delivered</th>
                <th class="col-opt">Billed</th>
                <th class="num">Amount</th>
                <th>Status</th>
                <th>Payment</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($orders as $o): ?>
                <?php
                $viewUrl   = url('pages/co-view.php?id=' . (int) $o['id'] . '&return=' . rawurlencode($returnTo));
                $ordered   = (int) $o['total_qty'];
                $delivered = (int) $o['qty_delivered'];
                $late      = $o['due_date'] !== null && $o['due_date'] < $today && in_array($o['status'], CustomerOrders::OPEN, true);
                $tracking  = !in_array($o['status'], ['draft', 'pending', 'cancelled'], true);
                ?>
                <tr class="<?= $o['status'] === 'cancelled' ? 'is-void' : '' ?>" data-co="<?= e(CustomerOrders::label($o)) ?>">
                    <td>
                        <a class="item-cell__name doc-no" href="<?= e($viewUrl) ?>"><?= e(CustomerOrders::label($o)) ?></a>
                        <small class="muted block"><?= e(date('M j, Y', strtotime($o['created_at']))) ?> · <?= e($o['created_by_name']) ?></small>
                    </td>
                    <td><?= e($o['customer_name']) ?><small class="muted block">PO <?= e($o['customer_po_no']) ?><?= $o['customer_type'] ? ' · ' . e($o['customer_type']) : '' ?></small></td>
                    <?php if ($showBranch): ?><td class="col-opt"><span class="badge badge--branch" title="<?= e($o['branch_name']) ?>"><?= e($o['branch_code']) ?></span></td><?php endif; ?>
                    <td class="col-opt nowrap<?= $late ? ' text-danger' : '' ?>"><?= $o['due_date'] !== null ? e(date('M j, Y', strtotime($o['due_date']))) . ($late ? ' <small>(late)</small>' : '') : '<span class="muted">—</span>' ?></td>
                    <td class="pu-progress-cell">
                        <?php if ($tracking): ?>
                            <span class="pu-progress" aria-hidden="true"><svg viewBox="0 0 100 6" preserveAspectRatio="none"><rect class="pu-progress__track" width="100" height="6" rx="3"></rect><rect class="pu-progress__bar" width="<?= $ordered > 0 ? (int) floor($delivered * 100 / $ordered) : 0 ?>" height="6" rx="3"></rect></svg></span>
                            <small><?= number_format($delivered) ?> of <?= number_format($ordered) ?></small>
                        <?php else: ?>
                            <span class="muted"><?= number_format($ordered) ?> ordered</span>
                        <?php endif; ?>
                    </td>
                    <td class="col-opt"><?= $tracking ? number_format((int) $o['qty_billed']) . ' of ' . number_format($delivered) : '<span class="muted">—</span>' ?></td>
                    <td class="num doc-value"><?= e(money($o['subtotal'])) ?></td>
                    <td><span class="badge <?= e(CustomerOrders::BADGES[$o['status']] ?? '') ?>"><?= e(CustomerOrders::STATUSES[$o['status']] ?? $o['status']) ?></span></td>
                    <?php $pay = PaymentStatus::order($o); ?>
                    <td class="pay-cell" data-payment="<?= e($pay['key']) ?>"><?php if ($pay['key'] === 'none'): ?><span class="muted">—</span><?php else: ?><span class="badge <?= e($pay['badge']) ?>"><?= e($pay['label']) ?></span><?php endif; ?>
                        <?php foreach ($pay['notes'] as $n): ?><small><?= e($n) ?></small><?php endforeach; ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$orders): ?>
                <tr><td colspan="<?= $cols ?>" class="empty">No customer orders found<?= $pgQuery ? ' for these filters' : ' yet' ?>.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php require ROOT_PATH . '/includes/pagination.php'; ?>
    <p class="doc-foot muted"><?= icon('info') ?> <span>Amounts are the agreed prices before VAT. Confirming an order reserves its items at the branch:
        the POS and other stock-outs can't use reserved units. Stock leaves on the delivery receipts.</span></p>
</section>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
