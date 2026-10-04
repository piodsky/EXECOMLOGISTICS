<?php
/**
 * Customer Orders → Order Tracking: everything still moving, in one place.
 *   Outgoing: customer orders for confirmation / to deliver / to bill (delivery + billing progress, deadline).
 *   Incoming: PO Internal waiting for approval or for the supplier (only with PurchaseOrders::canView()).
 * Both in the current branch scope, most urgent (oldest deadline) first, max 100 each.
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('customer-orders');

$today = date('Y-m-d');
$out   = CustomerOrders::search(['status' => 'active'], 100, 0);
usort($out, static fn (array $a, array $b): int => [$a['due_date'] === null, $a['due_date']] <=> [$b['due_date'] === null, $b['due_date']]);
$in = PurchaseOrders::canView() ? PurchaseOrders::search(['status' => 'active'], 100, 0) : null;
if ($in !== null) {
    usort($in, static fn (array $a, array $b): int => [$a['expected_date'] === null, $a['expected_date']] <=> [$b['expected_date'] === null, $b['expected_date']]);
}
$showBranch = Branch::current() === Branch::ALL;
$bar = static fn (int $done, int $all): string =>
    '<span class="pu-progress" aria-hidden="true"><svg viewBox="0 0 100 6" preserveAspectRatio="none"><rect class="pu-progress__track" width="100" height="6" rx="3"></rect>'
    . '<rect class="pu-progress__bar" width="' . ($all > 0 ? (int) floor($done * 100 / $all) : 0) . '" height="6" rx="3"></rect></svg></span>';

$ordersTab = 'tracking';
$page['title'] = 'Order Tracking';
$pageStyles  = ['css/sales.css', 'css/stock-docs.css', 'css/transfers.css', 'css/reports.css', 'css/purchasing.css'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Customer Orders</h1>
        <p class="muted">Order tracking: customer orders still to confirm, deliver or bill<?= $in !== null ? ', and purchase orders still coming from suppliers' : '' ?>.</p>
    </div>
    <div class="page-actions"><span class="badge badge--period badge--branch"><?= icon('store') ?> <?= e(Branch::label()) ?></span></div>
</div>

<?php require ROOT_PATH . '/includes/orders-nav.php'; ?>

<section class="card" id="trackOut">
    <header class="card__head"><h2><?= icon('truck') ?> Outgoing: customer orders</h2><span class="muted"><?= count($out) ?> active</span></header>
    <div class="table-wrap">
        <table class="table table--list pu-table">
            <thead><tr><th>Order</th><th>Customer / PO</th><?php if ($showBranch): ?><th class="col-opt">Branch</th><?php endif; ?><th>Deadline</th><th>Delivered</th><th>Billed</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($out as $o): ?>
                <?php $late = $o['due_date'] !== null && $o['due_date'] < $today && in_array($o['status'], CustomerOrders::OPEN, true); ?>
                <tr data-track-co="<?= e(CustomerOrders::label($o)) ?>">
                    <td><a class="item-cell__name doc-no" href="<?= e(url('pages/co-view.php?id=' . (int) $o['id'] . '&return=order-tracking.php')) ?>"><?= e(CustomerOrders::label($o)) ?></a></td>
                    <td><?= e($o['customer_name']) ?><small class="muted block">PO <?= e($o['customer_po_no']) ?></small></td>
                    <?php if ($showBranch): ?><td class="col-opt"><span class="badge badge--branch"><?= e($o['branch_code']) ?></span></td><?php endif; ?>
                    <td class="nowrap<?= $late ? ' text-danger' : '' ?>"><?= $o['due_date'] !== null ? e(date('M j, Y', strtotime($o['due_date']))) . ($late ? ' <small>(late)</small>' : '') : '<span class="muted">—</span>' ?></td>
                    <td class="pu-progress-cell"><?= $bar((int) $o['qty_delivered'], (int) $o['total_qty']) ?><small><?= number_format((int) $o['qty_delivered']) ?> of <?= number_format((int) $o['total_qty']) ?></small></td>
                    <td class="pu-progress-cell"><?= $bar((int) $o['qty_billed'], (int) $o['total_qty']) ?><small><?= number_format((int) $o['qty_billed']) ?> of <?= number_format((int) $o['total_qty']) ?></small></td>
                    <td><span class="badge <?= e(CustomerOrders::BADGES[$o['status']] ?? '') ?>"><?= e(CustomerOrders::STATUSES[$o['status']] ?? $o['status']) ?></span></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$out): ?><tr><td colspan="<?= 6 + ($showBranch ? 1 : 0) ?>" class="empty">No active customer orders.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php if ($in !== null): ?>
    <section class="card pu-track-in" id="trackIn">
        <header class="card__head"><h2><?= icon('cart') ?> Incoming: PO Internal (from suppliers)</h2><span class="muted"><?= count($in) ?> active</span></header>
        <div class="table-wrap">
            <table class="table table--list pu-table">
                <thead><tr><th>PO</th><th>Supplier</th><?php if ($showBranch): ?><th class="col-opt">Branch</th><?php endif; ?><th>Expected</th><th>Received</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($in as $p): ?>
                    <?php $late = $p['expected_date'] !== null && $p['expected_date'] < $today && in_array($p['status'], PurchaseOrders::OPEN, true); ?>
                    <tr data-track-po="<?= e(PurchaseOrders::label($p)) ?>">
                        <td><a class="item-cell__name doc-no" href="<?= e(url('pages/po-view.php?id=' . (int) $p['id'])) ?>"><?= e(PurchaseOrders::label($p)) ?></a></td>
                        <td><?= e($p['supplier_name']) ?></td>
                        <?php if ($showBranch): ?><td class="col-opt"><span class="badge badge--branch"><?= e($p['branch_code']) ?></span></td><?php endif; ?>
                        <td class="nowrap<?= $late ? ' text-danger' : '' ?>"><?= $p['expected_date'] !== null ? e(date('M j, Y', strtotime($p['expected_date']))) . ($late ? ' <small>(late)</small>' : '') : '<span class="muted">—</span>' ?></td>
                        <td class="pu-progress-cell"><?= $bar((int) $p['qty_received'], (int) $p['total_qty']) ?><small><?= number_format((int) $p['qty_received']) ?> of <?= number_format((int) $p['total_qty']) ?></small></td>
                        <td><span class="badge <?= e(PurchaseOrders::BADGES[$p['status']] ?? '') ?>"><?= e(PurchaseOrders::STATUSES[$p['status']] ?? $p['status']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$in): ?><tr><td colspan="<?= 5 + ($showBranch ? 1 : 0) ?>" class="empty">No purchase orders waiting.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
