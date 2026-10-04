<?php
/**
 * One customer order (PO Outgoing): lines with ordered / delivered / billed / to deliver, delivery receipts, bills.
 * Actions follow CustomerOrders::actions():
 *   draft    -> Edit, Send for Confirmation (customer_orders.manage)
 *   pending  -> Confirm (customer_orders.approve, not the preparer: reserves the stock) / Return to draft (note)
 *   confirmed / partial -> New Delivery Receipt (customer_orders.deliver) = dr-form.php?order=ID
 *   delivery receipts not billed -> Bill (customer_orders.bill): dialog with the receipts, payment (cash / GCash /
 *   card / on account) and the amount received for cash
 *   partial -> Close (reason)     confirmed, no receipt -> Cancel (reason)
 * pages/co-view.php?id=5&return=customer-orders.php
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('customer-orders');

$id = input_int($_GET, 'id', 1) ?? throw new HttpException(404, 'Customer order not found.');
$o  = CustomerOrders::find($id) ?? throw new HttpException(404, 'Customer order not found.');

$returnTo = safe_return($_POST['return'] ?? $_GET['return'] ?? null, 'customer-orders.php');
$self     = 'co-view.php?' . http_build_query(['id' => $id, 'return' => $returnTo]);
$label    = CustomerOrders::label($o);

if (is_post()) {
    Csrf::verifyRequest();
    $action = input_string($_POST, 'action', 10);
    $uid    = (int) Auth::id();
    $text   = input_string($_POST, 'reason', 300);
    $drIds  = is_array($_POST['deliveries'] ?? null) ? array_values(array_filter($_POST['deliveries'], 'is_string')) : [];
    try {
        switch ($action) {
            case 'submit':
                CustomerOrders::submit($id, $uid);
                flash('success', "{$label} was sent for confirmation.");
                break;
            case 'confirm':
                $no = CustomerOrders::confirm($id, $uid);
                flash('success', "Confirmed as {$no}. Its items are reserved at the branch until they are delivered.");
                break;
            case 'return':
                CustomerOrders::returnToDraft($id, input_string($_POST, 'note', 300), $uid);
                flash('success', "{$label} was returned to draft.");
                break;
            case 'close':
                CustomerOrders::close($id, $text, $uid);
                flash('success', "{$label} was closed. The undelivered items are no longer reserved.");
                break;
            case 'cancel':
                CustomerOrders::cancel($id, $text, $uid);
                flash('success', "{$label} was cancelled. Its items are no longer reserved.");
                break;
            case 'bill':
                $res = CustomerOrders::bill($id, $drIds, $_POST, $uid);
                flash('success', "Billed on sale No. {$res['sale_no']} (" . money(from_cents($res['total_cents'])) . ')'
                    . ($res['change_cents'] > 0 ? '. Change: ' . money(from_cents($res['change_cents'])) : '') . '.');
                break;
            default:
                throw new HttpException(400, 'Unknown action.');
        }
    } catch (HttpException $e) {
        if (is_array($e->details['errors'] ?? null)) {
            flash_errors($e->details['errors']);
        }
        flash_old(['action' => (string) $action, 'payment_type' => input_string($_POST, 'payment_type', 10),
                   'amount_paid' => input_string($_POST, 'amount_paid', 20), 'deliveries' => $drIds]);
        flash('error', $e->getMessage());
    }
    redirect('pages/' . $self);
}

$status   = $o['status'];
$actions  = CustomerOrders::actions($o);
$lines    = $o['lines'];
$page['title'] = $label;
$old      = has_old() ? old_input() : [];
$when     = static fn (?string $ts): string => $ts ? date('M j, Y g:i A', strtotime($ts)) : '—';
$day      = static fn (?string $d): string => $d ? date('M j, Y', strtotime($d)) : '—';
$ordered  = array_sum(array_map(static fn (array $l): int => (int) $l['qty_ordered'], $lines));
$delivered = array_sum(array_map(static fn (array $l): int => (int) $l['qty_delivered'], $lines));
$billedQty = array_sum(array_map(static fn (array $l): int => (int) $l['qty_billed'], $lines));
$tracking = !in_array($status, ['draft', 'pending'], true);
$late     = $o['due_date'] !== null && $o['due_date'] < date('Y-m-d') && in_array($status, CustomerOrders::OPEN, true);
$vatRate  = (float) setting('vat_rate', '12');
$subCents = to_cents($o['subtotal']);
$vatCents = (int) round($subCents * $vatRate / 100);
$unbilled = array_values(array_filter($o['deliveries'], static fn (array $d): bool => $d['status'] !== 'cancelled' && $d['sale_id'] === null));
$short    = !$tracking ? array_filter($lines, static fn (array $l): bool => (int) $l['free'] < (int) $l['qty_ordered']) : [];
$oldDr    = is_array($old['deliveries'] ?? null) ? array_flip(array_map('intval', $old['deliveries'])) : null;

$pageStyles  = ['css/sales.css', 'css/receiving.css', 'css/stock-docs.css', 'css/transfers.css', 'css/purchasing.css'];
$pageScripts = ['js/purchasing.js'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <a class="back-link" href="<?= e(url('pages/' . $returnTo)) ?>"><?= icon('arrow-left') ?> Customer Orders</a>
        <h1 class="sale-title">
            <span id="coTitle"><?= e($label) ?></span>
            <span class="badge <?= e(CustomerOrders::BADGES[$status] ?? '') ?>" id="coStatus"><?= e(CustomerOrders::STATUSES[$status] ?? $status) ?></span>
        </h1>
        <p class="muted"><?= e($o['customer_name']) ?> · PO <?= e($o['customer_po_no']) ?> · <?= e($o['branch_code'] . ' · ' . $o['branch_name']) ?></p>
    </div>
    <div class="page-actions">
        <?php if ($actions['edit']): ?>
            <a class="btn btn--light" href="<?= e(url('pages/co-form.php?' . http_build_query(['id' => $id, 'return' => $returnTo]))) ?>" id="coEdit"><?= icon('edit') ?> Edit Draft</a>
        <?php endif; ?>
        <?php if ($actions['submit']): ?>
            <form method="post" action="<?= e(url('pages/' . $self)) ?>" class="inline-form" data-confirm="Send <?= e($label) ?> for confirmation?">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="submit">
                <input type="hidden" name="return" value="<?= e($returnTo) ?>">
                <button type="submit" class="btn btn--primary" id="coSubmit"><?= icon('check') ?> Send for Confirmation</button>
            </form>
        <?php endif; ?>
        <?php if ($actions['return']): ?>
            <button type="button" class="btn btn--light" data-open="returnDialog" id="coReturnBtn"><?= icon('arrow-left') ?> Return to Draft</button>
        <?php endif; ?>
        <?php if ($actions['confirm']): ?>
            <form method="post" action="<?= e(url('pages/' . $self)) ?>" class="inline-form" data-confirm="Confirm <?= e($label) ?>? Its items are reserved at the branch now.">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="confirm">
                <input type="hidden" name="return" value="<?= e($returnTo) ?>">
                <button type="submit" class="btn btn--primary" id="coConfirm"><?= icon('check') ?> Confirm &amp; Reserve</button>
            </form>
        <?php endif; ?>
        <?php if ($actions['deliver']): ?>
            <a class="btn btn--primary" href="<?= e(url('pages/dr-form.php?' . http_build_query(['order' => $id, 'return' => $returnTo]))) ?>" id="coDeliver"><?= icon('truck') ?> New Delivery Receipt</a>
        <?php endif; ?>
        <?php if ($actions['bill']): ?>
            <button type="button" class="btn btn--primary" data-open="billDialog" id="coBillBtn"><?= icon('receipt') ?> Bill</button>
        <?php endif; ?>
        <?php if ($actions['close']): ?>
            <button type="button" class="btn btn--light" data-open="closeDialog" id="coCloseBtn"><?= icon('lock') ?> Close Order</button>
        <?php endif; ?>
        <?php if ($actions['cancel']): ?>
            <button type="button" class="btn btn--danger" data-open="cancelDialog" id="coCancelBtn"><?= icon('x') ?> Cancel Order</button>
        <?php endif; ?>
    </div>
</div>

<?php if ($status === 'cancelled' || $status === 'closed'): ?>
    <div class="void-box<?= $status === 'closed' ? ' pu-closed' : '' ?>" role="note"><?= icon($status === 'closed' ? 'lock' : 'alert') ?>
        <div><strong>This order was <?= $status === 'closed' ? 'closed' : 'cancelled' ?></strong><?= e(($o['closed_at'] ? ' on ' . $when($o['closed_at']) : '') . ($o['closed_by_name'] ? ' by ' . $o['closed_by_name'] : '')) ?>.
            Nothing is reserved for it any more.
            <?php if ($o['close_reason']): ?><p class="void-box__reason">Reason: <?= e($o['close_reason']) ?></p><?php endif; ?></div>
    </div>
<?php elseif ($status === 'draft'): ?>
    <div class="alert alert--info doc-note" role="note"><?= icon('info') ?>
        <span>Draft: nothing is reserved yet. <?= $o['return_note'] ? 'Returned for changes: ' . e($o['return_note']) : '' ?></span></div>
<?php elseif ($status === 'pending'): ?>
    <div class="alert alert--warning doc-note" role="note"><?= icon('clock') ?>
        <span>Waiting for confirmation<?= (int) Auth::id() === (int) $o['created_by'] ? ' by a branch admin other than you' : '' ?>. Confirming reserves the items.</span></div>
<?php elseif (in_array($status, CustomerOrders::OPEN, true)): ?>
    <div class="alert <?= $late ? 'alert--warning' : 'alert--info' ?> doc-note" role="note" id="coReservedNote"><?= icon($late ? 'clock' : 'lock') ?>
        <span><?= $late ? 'Overdue: the delivery deadline was ' . e($day($o['due_date'])) . '. ' : '' ?><?= number_format($ordered - $delivered) ?> undelivered
            <?= $ordered - $delivered === 1 ? 'unit is' : 'units are' ?> reserved at the branch: the POS and other stock-outs can't use them.</span></div>
<?php elseif ($status === 'delivered'): ?>
    <div class="alert alert--warning doc-note" role="note"><?= icon('receipt') ?><span>Everything was delivered. Bill the remaining delivery receipts.</span></div>
<?php elseif ($status === 'completed'): ?>
    <div class="alert alert--success doc-note" role="note"><?= icon('check') ?><span>Completed: everything was delivered and billed.</span></div>
<?php endif; ?>
<?php if ($short && $status === 'pending'): ?>
    <div class="alert alert--warning doc-note" role="note" id="coShortNote"><?= icon('alert') ?>
        <span>Not enough free stock for: <?= e(implode(', ', array_map(static fn (array $l): string => $l['product_name'] . ' (' . $l['free'] . ' free, ' . $l['qty_ordered'] . ' ordered)', $short))) ?>.
            Receive or transfer stock in before confirming.</span></div>
<?php endif; ?>

<div class="sale-layout">
    <section class="card">
        <header class="card__head">
            <h2><?= icon('box') ?> Items</h2>
            <?php if ($tracking): ?>
                <span class="pu-head-progress" id="coProgress">
                    <span class="pu-progress" aria-hidden="true"><svg viewBox="0 0 100 6" preserveAspectRatio="none"><rect class="pu-progress__track" width="100" height="6" rx="3"></rect><rect class="pu-progress__bar" width="<?= $ordered > 0 ? (int) floor($delivered * 100 / $ordered) : 0 ?>" height="6" rx="3"></rect></svg></span>
                    <span class="muted"><?= number_format($delivered) ?> of <?= number_format($ordered) ?> delivered · <?= number_format($billedQty) ?> billed</span>
                </span>
            <?php endif; ?>
        </header>
        <div class="table-wrap">
            <table class="table doc-lines bt-lines" id="coLines">
                <thead>
                <tr>
                    <th>#</th>
                    <th>Item</th>
                    <th class="num">Ordered</th>
                    <?php if ($tracking): ?><th class="num">Delivered</th><th class="num">Billed</th><?php else: ?><th class="num">Free now</th><?php endif; ?>
                    <th class="num">Unit Price</th>
                    <th class="num">Total</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($lines as $i => $l): ?>
                    <tr data-line="<?= (int) $l['id'] ?>">
                        <td class="muted"><?= $i + 1 ?></td>
                        <td>
                            <strong class="block"><?= e($l['product_name']) ?></strong>
                            <small class="muted"><?= e($l['product_code']) ?><?= (int) $l['track_serial'] === 1 ? ' · S/N' : '' ?></small>
                            <?php if ($l['price_reason'] !== null): ?>
                                <small class="muted block">Suggested <s><?= e(money($l['suggested_price'])) ?></s> · <?= e($l['price_reason']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="num"><?= number_format((int) $l['qty_ordered']) ?> <small class="muted"><?= e($l['unit_code'] ?? '') ?></small></td>
                        <?php if ($tracking): ?>
                            <td class="num<?= $l['to_deliver'] > 0 && in_array($status, CustomerOrders::OPEN, true) ? ' pu-due' : '' ?>"><?= number_format((int) $l['qty_delivered']) ?></td>
                            <td class="num"><?= number_format((int) $l['qty_billed']) ?></td>
                        <?php else: ?>
                            <td class="num<?= (int) $l['free'] < (int) $l['qty_ordered'] ? ' text-danger' : '' ?>"><?= number_format((int) $l['free']) ?></td>
                        <?php endif; ?>
                        <td class="num"><?= e(money($l['unit_price'])) ?></td>
                        <td class="num"><?= e(money($l['line_total'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <dl class="sale-totals<?= $status === 'cancelled' ? ' is-void' : '' ?>">
            <div><dt>Subtotal</dt><dd><?= e(money($o['subtotal'])) ?></dd></div>
            <div><dt>VAT (<?= e(rtrim(rtrim(number_format($vatRate, 2), '0'), '.')) ?>%)</dt><dd><?= e(money(from_cents($vatCents))) ?></dd></div>
            <div class="sale-totals__grand"><dt>Order total</dt><dd id="coTotal"><?= e(money(from_cents($subCents + $vatCents))) ?></dd></div>
        </dl>
    </section>

    <aside class="form-side">
        <section class="card card--pad">
            <h2 class="card__title">Customer PO</h2>
            <dl class="detail-list doc-details">
                <div><dt>Customer</dt><dd>
                    <?php if (Auth::can('customers.view')): ?><a href="<?= e(url('pages/customer-form.php?id=' . (int) $o['customer_id'])) ?>"><?= e($o['customer_name']) ?></a><?php else: ?><?= e($o['customer_name']) ?><?php endif; ?>
                    <?php if ($o['customer_type']): ?><small class="muted block"><?= e($o['customer_type']) ?></small><?php endif; ?>
                </dd></div>
                <div><dt>PO no.</dt><dd class="doc-no" id="coPoNo"><?= e($o['customer_po_no']) ?></dd></div>
                <div><dt>PO date</dt><dd><?= e($day($o['customer_po_date'])) ?></dd></div>
                <?php if ($o['end_user']): ?><div><dt>End-user</dt><dd><?= e($o['end_user']) ?></dd></div><?php endif; ?>
                <?php if ($o['procurement_mode']): ?><div><dt>Procurement</dt><dd><?= e($o['procurement_mode']) ?></dd></div><?php endif; ?>
                <?php if ($o['award_ref']): ?><div><dt>Award / BAC</dt><dd><?= e($o['award_ref']) ?></dd></div><?php endif; ?>
                <div><dt>Deadline</dt><dd class="<?= $late ? 'text-danger' : '' ?>"><?= e($day($o['due_date'])) ?><?= $o['delivery_term'] ? '<small class="muted block">' . e($o['delivery_term']) . '</small>' : '' ?></dd></div>
                <div><dt>Payment term</dt><dd><?= e($o['payment_term'] ?? '—') ?></dd></div>
                <div><dt>Prepared by</dt><dd><?= e($o['created_by_name']) ?><small class="muted block"><?= e($when($o['created_at'])) ?></small></dd></div>
                <?php if ($o['confirmed_at']): ?><div><dt>Confirmed by</dt><dd><?= e($o['confirmed_by_name'] ?? '—') ?><small class="muted block"><?= e($when($o['confirmed_at'])) ?></small></dd></div><?php endif; ?>
            </dl>
            <?php if ($o['place_of_delivery']): ?><p class="rr-notes"><span class="form-label">Place of delivery</span><?= e($o['place_of_delivery']) ?></p><?php endif; ?>
            <?php if ($o['notes']): ?><p class="rr-notes"><span class="form-label">Notes</span><?= e($o['notes']) ?></p><?php endif; ?>
        </section>

        <?php if ($tracking): ?>
            <section class="card card--pad" id="coDeliveries">
                <h2 class="card__title">Delivery receipts</h2>
                <?php if ($o['deliveries']): ?>
                    <ul class="pu-doc-list">
                        <?php foreach ($o['deliveries'] as $d): ?>
                            <li class="<?= $d['status'] === 'cancelled' ? 'is-void' : '' ?>">
                                <span>
                                    <a class="doc-no" href="<?= e(url('pages/dr-view.php?id=' . (int) $d['id'])) ?>"><?= e($d['dr_no']) ?></a>
                                    <small class="muted block"><?= e($day($d['released_at'])) ?> · <?= number_format((int) $d['total_qty']) ?> units<?= $d['sale_no'] ? ' · billed No. ' . e($d['sale_no']) : '' ?></small>
                                </span>
                                <span class="badge <?= e(CustomerDeliveries::BADGES[$d['status']] ?? '') ?>"><?= e(CustomerDeliveries::STATUSES[$d['status']] ?? $d['status']) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="muted pu-empty">Nothing delivered yet.</p>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if ($o['bills']): ?>
            <section class="card card--pad" id="coBills">
                <h2 class="card__title">Bills</h2>
                <ul class="pu-doc-list">
                    <?php foreach ($o['bills'] as $b): ?>
                        <li class="<?= $b['status'] === 'cancelled' ? 'is-void' : '' ?>">
                            <span>
                                <?php if (Auth::can('sales.view')): ?><a class="doc-no" href="<?= e(url('pages/sale-view.php?id=' . (int) $b['id'])) ?>">No. <?= e($b['sale_no']) ?></a><?php else: ?><span class="doc-no">No. <?= e($b['sale_no']) ?></span><?php endif; ?>
                                <small class="muted block"><?= e(money($b['total'])) ?> · <?= e(Sales::ALL_PAYMENT_TYPES[$b['payment_type']] ?? $b['payment_type']) ?></small>
                            </span>
                            <a class="btn btn--light btn--sm" href="<?= e(url('pages/bill-print.php?id=' . (int) $b['id'])) ?>" target="_blank" rel="noopener"><?= icon('printer') ?> Statement</a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>
    </aside>
</div>

<?php if ($actions['bill']): ?>
    <dialog class="modal" id="billDialog" aria-labelledby="billTitle"<?= ($old['action'] ?? '') === 'bill' ? ' data-reopen' : '' ?>>
        <form class="modal__body" method="post" action="<?= e(url('pages/' . $self)) ?>" id="billForm" data-confirm="Bill the ticked delivery receipts of <?= e($label) ?>?">
            <header class="modal__head">
                <h2 id="billTitle">Bill <?= e($label) ?></h2>
                <button type="button" class="modal__close" data-close aria-label="Close"><?= icon('x') ?></button>
            </header>
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="bill">
            <input type="hidden" name="return" value="<?= e($returnTo) ?>">
            <fieldset class="sn-pick">
                <legend class="form-label">Delivery receipts to bill</legend>
                <div class="pu-picker__list">
                    <?php foreach ($unbilled as $d): ?>
                        <label class="sn-check pu-pick"><input type="checkbox" name="deliveries[]" value="<?= (int) $d['id'] ?>"<?= $oldDr === null || isset($oldDr[(int) $d['id']]) ? ' checked' : '' ?>>
                            <span><strong class="doc-no"><?= e($d['dr_no']) ?></strong><small class="muted block"><?= number_format((int) $d['total_qty']) ?> units · <?= e(CustomerDeliveries::STATUSES[$d['status']]) ?></small></span></label>
                    <?php endforeach; ?>
                </div>
                <?= field_error('deliveries') ?>
            </fieldset>
            <div class="form-grid">
                <label class="form-field">
                    <span class="form-label">Payment *</span>
                    <select class="form-input" name="payment_type" id="billPayment"<?= invalid('payment_type') ?>>
                        <?php foreach (Sales::ALL_PAYMENT_TYPES as $k => $lbl): ?>
                            <option value="<?= e($k) ?>"<?= (string) ($old['payment_type'] ?? 'charge') === $k ? ' selected' : '' ?>><?= e($lbl) ?><?= $k === 'charge' ? ' (collect later)' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= field_error('payment_type') ?>
                </label>
                <label class="form-field" data-cash-only>
                    <span class="form-label">Amount received</span>
                    <input class="form-input num" name="amount_paid" inputmode="decimal" maxlength="15" value="<?= e((string) ($old['amount_paid'] ?? '')) ?>"<?= invalid('amount_paid') ?>>
                    <?= field_error('amount_paid') ?>
                </label>
            </div>
            <p class="form-hint">The bill uses the order prices with VAT on top. The items already left stock on the delivery receipts.</p>
            <footer class="modal__foot">
                <button type="button" class="btn btn--light" data-close>Not Now</button>
                <button type="submit" class="btn btn--primary" id="billSubmit"><?= icon('receipt') ?> Bill</button>
            </footer>
        </form>
    </dialog>
<?php endif; ?>

<?php if ($actions['return']): ?>
    <dialog class="modal" id="returnDialog" aria-labelledby="returnTitle"<?= ($old['action'] ?? '') === 'return' ? ' data-reopen' : '' ?>>
        <form class="modal__body" method="post" action="<?= e(url('pages/' . $self)) ?>">
            <header class="modal__head">
                <h2 id="returnTitle">Return <?= e($label) ?> to draft?</h2>
                <button type="button" class="modal__close" data-close aria-label="Close"><?= icon('x') ?></button>
            </header>
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="return">
            <input type="hidden" name="return" value="<?= e($returnTo) ?>">
            <label class="form-field">
                <span class="form-label">What should be changed? *</span>
                <textarea class="form-input" name="note" id="returnNote" required minlength="3" maxlength="255" placeholder="e.g. Check the price of item 2 against the PO"<?= invalid('note') ?>></textarea>
                <?= field_error('note') ?>
            </label>
            <footer class="modal__foot">
                <button type="button" class="btn btn--light" data-close>Keep Waiting</button>
                <button type="submit" class="btn btn--primary"><?= icon('arrow-left') ?> Return to Draft</button>
            </footer>
        </form>
    </dialog>
<?php endif; ?>

<?php foreach (['close' => ['closeDialog', 'Close', 'The undelivered items are no longer reserved and will not be delivered.', 'e.g. Customer reduced the order'],
                'cancel' => ['cancelDialog', 'Cancel', 'Nothing was delivered. The reserved items are free again.', 'e.g. Customer cancelled the PO']] as $act => [$dlgId, $verb, $warn, $hint]): ?>
    <?php if (!$actions[$act]) continue; ?>
    <dialog class="modal" id="<?= e($dlgId) ?>" aria-labelledby="<?= e($dlgId) ?>Title"<?= ($old['action'] ?? '') === $act ? ' data-reopen' : '' ?>>
        <form class="modal__body" method="post" action="<?= e(url('pages/' . $self)) ?>" data-confirm="<?= e($verb . ' ' . $label) ?>? This cannot be undone.">
            <header class="modal__head">
                <h2 id="<?= e($dlgId) ?>Title"><?= e($verb . ' ' . $label) ?>?</h2>
                <button type="button" class="modal__close" data-close aria-label="Close"><?= icon('x') ?></button>
            </header>
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="<?= e($act) ?>">
            <input type="hidden" name="return" value="<?= e($returnTo) ?>">
            <p class="void-warning"><?= icon('alert') ?><span><?= e($warn) ?></span></p>
            <label class="form-field">
                <span class="form-label">Reason *</span>
                <textarea class="form-input" name="reason" required minlength="3" maxlength="255" placeholder="<?= e($hint) ?>"<?= invalid('reason') ?>></textarea>
                <?= field_error('reason') ?>
            </label>
            <footer class="modal__foot">
                <button type="button" class="btn btn--light" data-close>Keep Order</button>
                <button type="submit" class="btn btn--danger"><?= icon('x') ?> <?= e($verb) ?> Order</button>
            </footer>
        </form>
    </dialog>
<?php endforeach; ?>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
