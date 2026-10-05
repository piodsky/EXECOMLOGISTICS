<?php
/**
 * One delivery receipt: items (+ serials; cost only with products.cost), order / customer, Print DR.
 * released -> Mark Delivered (received by, date, acceptance / IAR ref.) or Return to Stock (not billed; reason).
 * pages/dr-view.php?id=5[&return=deliveries.php]
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('customer-orders');

$id = input_int($_GET, 'id', 1) ?? throw new HttpException(404, 'Delivery receipt not found.');
$d  = CustomerDeliveries::find($id) ?? throw new HttpException(404, 'Delivery receipt not found.');

$returnTo = safe_return($_POST['return'] ?? $_GET['return'] ?? null, 'deliveries.php');
$self     = 'dr-view.php?' . http_build_query(['id' => $id, 'return' => $returnTo]);

if (is_post()) {
    Csrf::verifyRequest();
    $action = input_string($_POST, 'action', 10);
    try {
        if ($action === 'deliver') {
            $no = CustomerDeliveries::markDelivered($id, $_POST, (int) Auth::id());
            flash('success', "{$no} was recorded as delivered.");
        } elseif ($action === 'cancel') {
            $no = CustomerDeliveries::cancel($id, input_string($_POST, 'reason', 300), (int) Auth::id());
            flash('success', "{$no} was cancelled: the items are back in stock and due on the order again.");
        } else {
            throw new HttpException(400, 'Unknown action.');
        }
    } catch (HttpException $e) {
        if (is_array($e->details['errors'] ?? null)) {
            flash_errors($e->details['errors']);
        }
        flash_old(['action' => (string) $action, 'received_by' => input_string($_POST, 'received_by', 151),
                   'received_date' => input_string($_POST, 'received_date', 10), 'acceptance_ref' => input_string($_POST, 'acceptance_ref', 61)]);
        flash('error', $e->getMessage());
    }
    redirect('pages/' . $self);
}

$status  = $d['status'];
$actions = CustomerDeliveries::actions($d);
$canCost = Auth::can('products.cost');
$old     = has_old() ? old_input() : [];
$page['title'] = $d['dr_no'];
$when = static fn (?string $ts): string => $ts ? date('M j, Y g:i A', strtotime($ts)) : '—';

$pageStyles  = ['css/sales.css', 'css/receiving.css', 'css/stock-docs.css', 'css/transfers.css', 'css/purchasing.css'];
$pageScripts = ['js/purchasing.js'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <a class="back-link" href="<?= e(url('pages/' . $returnTo)) ?>"><?= icon('arrow-left') ?> Delivery Receipts</a>
        <h1 class="sale-title">
            <span id="drTitle"><?= e($d['dr_no']) ?></span>
            <span class="badge <?= e(CustomerDeliveries::BADGES[$status] ?? '') ?>" id="drStatus"><?= e(CustomerDeliveries::STATUSES[$status] ?? $status) ?></span>
        </h1>
        <p class="muted"><?= e($d['customer_name']) ?> · PO <?= e($d['customer_po_no']) ?> · released <?= e($when($d['released_at'])) ?></p>
    </div>
    <div class="page-actions">
        <a class="btn btn--light" href="<?= e(url('pages/dr-print.php?id=' . $id)) ?>" target="_blank" rel="noopener" id="drPrint"><?= icon('printer') ?> Print DR</a>
        <?php if ($actions['deliver']): ?>
            <button type="button" class="btn btn--primary" data-open="deliveredDialog" id="drDeliveredBtn"><?= icon('check') ?> Mark Delivered</button>
        <?php endif; ?>
        <?php if ($actions['cancel']): ?>
            <button type="button" class="btn btn--danger" data-open="cancelDialog" id="drCancelBtn"><?= icon('x') ?> Return to Stock</button>
        <?php endif; ?>
    </div>
</div>

<?php if ($status === 'cancelled'): ?>
    <div class="void-box" role="note"><?= icon('alert') ?>
        <div><strong>This delivery receipt was cancelled</strong><?= e(($d['cancelled_at'] ? ' on ' . $when($d['cancelled_at']) : '') . ($d['cancelled_by_name'] ? ' by ' . $d['cancelled_by_name'] : '')) ?>. The items went back to stock.
            <?php if ($d['cancel_reason']): ?><p class="void-box__reason">Reason: <?= e($d['cancel_reason']) ?></p><?php endif; ?></div>
    </div>
<?php elseif ($status === 'released'): ?>
    <div class="alert alert--warning doc-note" role="note"><?= icon('truck') ?><span>Out for delivery. Record who received it (and the inspection / acceptance report no.) when it arrives.</span></div>
<?php else: ?>
    <div class="alert alert--success doc-note" role="note"><?= icon('check') ?>
        <span>Delivered: received by <?= e((string) $d['received_by']) ?> on <?= e(date('M j, Y', strtotime((string) $d['received_date']))) ?><?= $d['acceptance_ref'] ? ' · ' . e($d['acceptance_ref']) : '' ?>.</span></div>
<?php endif; ?>

<?php $chain = DocChain::of('dr', $id); require ROOT_PATH . '/includes/doc-chain.php'; ?>

<div class="sale-layout">
    <section class="card">
        <header class="card__head"><h2><?= icon('box') ?> Items</h2><span class="muted"><?= number_format((int) $d['total_qty']) ?> units</span></header>
        <div class="table-wrap">
            <table class="table doc-lines" id="drItems">
                <thead><tr><th>#</th><th>Item</th><th class="num">Qty</th><?php if ($canCost): ?><th class="num col-opt">Unit Cost</th><?php endif; ?></tr></thead>
                <tbody>
                <?php foreach ($d['lines'] as $i => $l): ?>
                    <tr>
                        <td class="muted"><?= $i + 1 ?></td>
                        <td>
                            <strong class="block"><?= e($l['product_name']) ?></strong><small class="muted"><?= e($l['product_code']) ?></small>
                            <?php if ($l['serials']): ?><ul class="sn-list" aria-label="Serial numbers"><?php foreach ($l['serials'] as $sn): ?><li><?= e($sn) ?></li><?php endforeach; ?></ul><?php endif; ?>
                        </td>
                        <td class="num"><?= number_format((int) $l['qty']) ?> <small class="muted"><?= e($l['unit_code'] ?? '') ?></small></td>
                        <?php if ($canCost): ?><td class="num col-opt"><?= ($l['unit_cost'] ?? null) !== null ? e(money($l['unit_cost'])) : '—' ?></td><?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($canCost && ($d['total_cost'] ?? null) !== null): ?>
            <dl class="sale-totals"><div class="sale-totals__grand"><dt>Value at cost</dt><dd><?= e(money($d['total_cost'])) ?></dd></div></dl>
        <?php endif; ?>
    </section>
    <aside class="form-side">
        <section class="card card--pad">
            <h2 class="card__title">Details</h2>
            <dl class="detail-list doc-details">
                <div><dt>Order</dt><dd id="drOrder"><a href="<?= e(url('pages/co-view.php?id=' . (int) $d['order_id'])) ?>"><?= e((string) $d['order_no']) ?></a></dd></div>
                <div><dt>Customer PO</dt><dd><?= e($d['customer_po_no']) ?></dd></div>
                <div><dt>From</dt><dd><?= e($d['branch_code'] . ' · ' . $d['warehouse_code'] . ' / ' . $d['location_code']) ?></dd></div>
                <div><dt>Released by</dt><dd><?= e($d['released_by_name']) ?><small class="muted block"><?= e($when($d['released_at'])) ?></small></dd></div>
                <?php if ($d['delivered_by']): ?><div><dt>Delivered by</dt><dd><?= e($d['delivered_by']) ?></dd></div><?php endif; ?>
                <?php if ($d['sale_no']): ?><div><dt>Billed</dt><dd id="drBill">
                    <?php if (Auth::can('sales.view')): ?><a href="<?= e(url('pages/sale-view.php?id=' . (int) $d['sale_id'])) ?>">No. <?= e($d['sale_no']) ?></a><?php else: ?>No. <?= e($d['sale_no']) ?><?php endif; ?>
                </dd></div><?php endif; ?>
            </dl>
            <?php if ($d['place_of_delivery']): ?><p class="rr-notes"><span class="form-label">Place of delivery</span><?= e($d['place_of_delivery']) ?></p><?php endif; ?>
            <?php if ($d['notes']): ?><p class="rr-notes"><span class="form-label">Notes</span><?= e($d['notes']) ?></p><?php endif; ?>
        </section>
    </aside>
</div>

<?php if ($actions['deliver']): ?>
    <dialog class="modal" id="deliveredDialog" aria-labelledby="deliveredTitle"<?= ($old['action'] ?? '') === 'deliver' ? ' data-reopen' : '' ?>>
        <form class="modal__body" method="post" action="<?= e(url('pages/' . $self)) ?>">
            <header class="modal__head">
                <h2 id="deliveredTitle">Record delivery of <?= e($d['dr_no']) ?></h2>
                <button type="button" class="modal__close" data-close aria-label="Close"><?= icon('x') ?></button>
            </header>
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="deliver">
            <input type="hidden" name="return" value="<?= e($returnTo) ?>">
            <label class="form-field">
                <span class="form-label">Received by *</span>
                <input class="form-input" name="received_by" maxlength="150" required placeholder="Name and position" value="<?= e((string) ($old['received_by'] ?? '')) ?>"<?= invalid('received_by') ?>>
                <?= field_error('received_by') ?>
            </label>
            <label class="form-field">
                <span class="form-label">Date received *</span>
                <input class="form-input" type="date" name="received_date" max="<?= e(date('Y-m-d')) ?>" value="<?= e((string) ($old['received_date'] ?? date('Y-m-d'))) ?>"<?= invalid('received_date') ?>>
                <?= field_error('received_date') ?>
            </label>
            <label class="form-field">
                <span class="form-label">Acceptance / IAR no. <small class="muted">(optional)</small></span>
                <input class="form-input" name="acceptance_ref" maxlength="60" value="<?= e((string) ($old['acceptance_ref'] ?? '')) ?>"<?= invalid('acceptance_ref') ?>>
                <?= field_error('acceptance_ref') ?>
            </label>
            <footer class="modal__foot">
                <button type="button" class="btn btn--light" data-close>Not Yet</button>
                <button type="submit" class="btn btn--primary"><?= icon('check') ?> Mark Delivered</button>
            </footer>
        </form>
    </dialog>
<?php endif; ?>

<?php if ($actions['cancel']): ?>
    <dialog class="modal" id="cancelDialog" aria-labelledby="cancelTitle"<?= ($old['action'] ?? '') === 'cancel' ? ' data-reopen' : '' ?>>
        <form class="modal__body" method="post" action="<?= e(url('pages/' . $self)) ?>" data-confirm="Return <?= e($d['dr_no']) ?> to stock? This cannot be undone.">
            <header class="modal__head">
                <h2 id="cancelTitle">Return <?= e($d['dr_no']) ?> to stock?</h2>
                <button type="button" class="modal__close" data-close aria-label="Close"><?= icon('x') ?></button>
            </header>
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="cancel">
            <input type="hidden" name="return" value="<?= e($returnTo) ?>">
            <p class="void-warning"><?= icon('alert') ?><span>The items come back into the branch stock and are due (reserved) on the order again.</span></p>
            <label class="form-field">
                <span class="form-label">Reason *</span>
                <textarea class="form-input" name="reason" id="drCancelReason" required minlength="3" maxlength="255" placeholder="e.g. Customer refused the delivery"<?= invalid('reason') ?>></textarea>
                <?= field_error('reason') ?>
            </label>
            <footer class="modal__foot">
                <button type="button" class="btn btn--light" data-close>Keep Delivery</button>
                <button type="submit" class="btn btn--danger"><?= icon('x') ?> Return to Stock</button>
            </footer>
        </form>
    </dialog>
<?php endif; ?>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
