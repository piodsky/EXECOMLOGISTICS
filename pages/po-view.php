<?php
/**
 * One PO Internal: delivery tracking per line (ordered / received / still due), deliveries (receiving reports),
 * purchase requests it orders, Print. Actions follow PurchaseOrders::actions():
 *   draft    -> Edit, Send for Approval, Delete (purchasing.order)
 *   pending  -> Approve (purchasing.approve, not its creator; numbers it) / Return to draft with a note
 *   approved / partial -> Receive Delivery (receiving.manage) = receiving-form.php?po=ID
 *   partial  -> Close (the rest will not come; reason)    approved, nothing received -> Cancel (reason)
 * pages/po-view.php?id=5&return=purchase-orders.php
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('purchasing');
if (!PurchaseOrders::canView()) {
    abort(403, 'You do not have permission to view purchase orders.');
}

$id = input_int($_GET, 'id', 1) ?? throw new HttpException(404, 'Purchase order not found.');
$po = PurchaseOrders::find($id) ?? throw new HttpException(404, 'Purchase order not found.');

$returnTo = safe_return($_POST['return'] ?? $_GET['return'] ?? null, 'purchase-orders.php');
$self     = 'po-view.php?' . http_build_query(['id' => $id, 'return' => $returnTo]);
$label    = PurchaseOrders::label($po);

if (is_post()) {
    Csrf::verifyRequest();
    $action = input_string($_POST, 'action', 10);
    $uid    = (int) Auth::id();
    $text   = input_string($_POST, 'reason', 300);
    try {
        switch ($action) {
            case 'submit':
                PurchaseOrders::submit($id, $uid);
                flash('success', "{$label} was sent for approval.");
                break;
            case 'approve':
                $no = PurchaseOrders::approve($id, $uid);
                flash('success', "Approved as {$no}. Print it and send it to the supplier.");
                break;
            case 'return':
                PurchaseOrders::returnToDraft($id, input_string($_POST, 'note', 300), $uid);
                flash('success', "{$label} was returned to draft.");
                break;
            case 'close':
                PurchaseOrders::close($id, $text, $uid);
                flash('success', "{$label} was closed. The rest will not be received.");
                break;
            case 'cancel':
                PurchaseOrders::cancel($id, $text, $uid);
                flash('success', "{$label} was cancelled. Its purchase requests can be ordered again.");
                break;
            default:
                throw new HttpException(400, 'Unknown action.');
        }
    } catch (HttpException $e) {
        if (is_array($e->details['errors'] ?? null)) {
            flash_errors($e->details['errors']);
        }
        flash_old(['action' => (string) $action]);
        flash('error', $e->getMessage());
    }
    redirect('pages/' . $self);
}

$status   = $po['status'];
$actions  = PurchaseOrders::actions($po);
$lines    = $po['lines'];
$page['title'] = $label;
$old      = has_old() ? old_input() : [];
$when     = static fn (?string $ts): string => $ts ? date('M j, Y g:i A', strtotime($ts)) : '—';
$day      = static fn (?string $d): string => $d ? date('M j, Y', strtotime($d)) : '—';
$ordered  = array_sum(array_map(static fn (array $l): int => (int) $l['qty_ordered'], $lines));
$received = array_sum(array_map(static fn (array $l): int => (int) $l['qty_received'], $lines));
$tracking = !in_array($status, ['draft', 'pending'], true);
$late     = $po['expected_date'] !== null && $po['expected_date'] < date('Y-m-d') && in_array($status, PurchaseOrders::OPEN, true);
$costText = static function (string $v): string {
    $s = preg_match('/^(\d+)\.(\d{2})(\d*)$/', $v, $m) ? $m[2] . rtrim($m[3], '0') : '00';
    return number_format((float) $v, strlen($s));
};
$requests = [];
foreach ($lines as $l) {
    foreach ($l['requests'] as $x) {
        $requests[$x['request_id']] = $x['pr_no'];
    }
}

$pageStyles  = ['css/sales.css', 'css/receiving.css', 'css/stock-docs.css', 'css/transfers.css', 'css/purchasing.css'];
$pageScripts = ['js/purchasing.js'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <a class="back-link" href="<?= e(url('pages/' . $returnTo)) ?>"><?= icon('arrow-left') ?> PO Internal</a>
        <h1 class="sale-title">
            <span id="poTitle"><?= e($label) ?></span>
            <span class="badge <?= e(PurchaseOrders::BADGES[$status] ?? '') ?>" id="poStatus"><?= e(PurchaseOrders::STATUSES[$status] ?? $status) ?></span>
        </h1>
        <p class="muted"><?= e($po['supplier_name']) ?> · <?= e($po['branch_code'] . ' · ' . $po['branch_name']) ?> · PO date <?= e($day($po['order_date'])) ?></p>
    </div>
    <div class="page-actions">
        <a class="btn btn--light" href="<?= e(url('pages/po-print.php?id=' . $id)) ?>" target="_blank" rel="noopener" id="poPrint"><?= icon('printer') ?> Print PO</a>
        <?php if ($actions['edit']): ?>
            <a class="btn btn--light" href="<?= e(url('pages/po-form.php?' . http_build_query(['id' => $id, 'return' => $returnTo]))) ?>" id="poEdit"><?= icon('edit') ?> Edit Draft</a>
        <?php endif; ?>
        <?php if ($actions['submit']): ?>
            <form method="post" action="<?= e(url('pages/' . $self)) ?>" class="inline-form" data-confirm="Send <?= e($label) ?> for approval?">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="submit">
                <input type="hidden" name="return" value="<?= e($returnTo) ?>">
                <button type="submit" class="btn btn--primary" id="poSubmit"><?= icon('check') ?> Send for Approval</button>
            </form>
        <?php endif; ?>
        <?php if ($actions['return']): ?>
            <button type="button" class="btn btn--light" data-open="returnDialog" id="poReturnBtn"><?= icon('arrow-left') ?> Return to Draft</button>
        <?php endif; ?>
        <?php if ($actions['approve']): ?>
            <form method="post" action="<?= e(url('pages/' . $self)) ?>" class="inline-form" data-confirm="Approve <?= e($label) ?> for <?= e(money($po['total_amount'])) ?>? It gets its PO number now.">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="return" value="<?= e($returnTo) ?>">
                <button type="submit" class="btn btn--primary" id="poApprove"><?= icon('check') ?> Approve</button>
            </form>
        <?php endif; ?>
        <?php if ($actions['receive']): ?>
            <a class="btn btn--primary" href="<?= e(url('pages/receiving-form.php?' . http_build_query(['po' => $id, 'return' => 'receiving.php']))) ?>" id="poReceive"><?= icon('truck') ?> Receive Delivery</a>
        <?php endif; ?>
        <?php if ($actions['close']): ?>
            <button type="button" class="btn btn--light" data-open="closeDialog" id="poCloseBtn"><?= icon('lock') ?> Close PO</button>
        <?php endif; ?>
        <?php if ($actions['cancel']): ?>
            <button type="button" class="btn btn--danger" data-open="cancelDialog" id="poCancelBtn"><?= icon('x') ?> Cancel PO</button>
        <?php endif; ?>
    </div>
</div>

<?php if ($status === 'cancelled' || $status === 'closed'): ?>
    <div class="void-box<?= $status === 'closed' ? ' pu-closed' : '' ?>" role="note"><?= icon($status === 'closed' ? 'lock' : 'alert') ?>
        <div><strong>This purchase order was <?= $status === 'closed' ? 'closed' : 'cancelled' ?></strong><?= e(($po['closed_at'] ? ' on ' . $when($po['closed_at']) : '') . ($po['closed_by_name'] ? ' by ' . $po['closed_by_name'] : '')) ?>.
            <?= $status === 'closed' ? 'The remaining quantities will not be received.' : 'Nothing was received.' ?>
            <?php if ($po['close_reason']): ?><p class="void-box__reason">Reason: <?= e($po['close_reason']) ?></p><?php endif; ?></div>
    </div>
<?php elseif ($status === 'draft'): ?>
    <div class="alert alert--info doc-note" role="note"><?= icon('info') ?>
        <span>Draft: send it for approval when it is complete. <?= $po['return_note'] ? 'Returned for changes: ' . e($po['return_note']) : '' ?></span></div>
<?php elseif ($status === 'pending'): ?>
    <div class="alert alert--warning doc-note" role="note"><?= icon('clock') ?>
        <span>Waiting for approval<?= (int) Auth::id() === (int) $po['created_by'] ? ' by a branch admin other than you' : '' ?>. It gets its PO number when approved.</span></div>
<?php elseif ($status === 'received'): ?>
    <div class="alert alert--success doc-note" role="note"><?= icon('check') ?><span>Delivery complete: everything ordered was received.</span></div>
<?php elseif ($late): ?>
    <div class="alert alert--warning doc-note" role="note"><?= icon('clock') ?><span>Overdue: the delivery was expected on <?= e($day($po['expected_date'])) ?>.</span></div>
<?php endif; ?>

<?php $chain = DocChain::of('po', $id); require ROOT_PATH . '/includes/doc-chain.php'; ?>

<div class="sale-layout">
    <section class="card">
        <header class="card__head">
            <h2><?= icon('box') ?> Items</h2>
            <?php if ($tracking): ?>
                <span class="pu-head-progress" id="poProgress">
                    <span class="pu-progress" aria-hidden="true"><svg viewBox="0 0 100 6" preserveAspectRatio="none"><rect class="pu-progress__track" width="100" height="6" rx="3"></rect><rect class="pu-progress__bar" width="<?= $ordered > 0 ? (int) floor($received * 100 / $ordered) : 0 ?>" height="6" rx="3"></rect></svg></span>
                    <span class="muted"><?= number_format($received) ?> of <?= number_format($ordered) ?> received</span>
                </span>
            <?php else: ?>
                <span class="muted"><?= count($lines) ?> <?= count($lines) === 1 ? 'item' : 'items' ?></span>
            <?php endif; ?>
        </header>
        <div class="table-wrap">
            <table class="table doc-lines bt-lines" id="poLines">
                <thead>
                <tr>
                    <th>#</th>
                    <th>Item</th>
                    <th class="col-opt">End-user</th>
                    <th class="num">Ordered</th>
                    <?php if ($tracking): ?><th class="num">Received</th><th class="num">Still due</th><?php endif; ?>
                    <th class="num col-opt">Unit Cost</th>
                    <th class="num">Total</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($lines as $i => $l): ?>
                    <tr data-line="<?= (int) $l['id'] ?>">
                        <td class="muted"><?= $i + 1 ?></td>
                        <td>
                            <strong class="block"><?= e($l['product_name']) ?></strong>
                            <small class="muted"><?= e($l['product_code']) ?><?= (int) $l['track_serial'] === 1 ? ' · S/N' : '' ?><?= (int) $l['product_active'] === 1 ? '' : ' · inactive' ?></small>
                            <?php if ($l['requests']): ?>
                                <small class="pu-from muted block"><?= icon('clipboard') ?> For <?= e(implode(', ', array_map(static fn (array $x): string => $x['pr_no'] . ' (' . $x['qty'] . ')', $l['requests']))) ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="col-opt"><?= $l['end_user'] !== null ? e($l['end_user']) : '<span class="muted">—</span>' ?></td>
                        <td class="num"><?= number_format((int) $l['qty_ordered']) ?> <small class="muted"><?= e($l['unit_code'] ?? '') ?></small></td>
                        <?php if ($tracking): ?>
                            <td class="num"><?= number_format((int) $l['qty_received']) ?></td>
                            <td class="num<?= $l['remaining'] > 0 && in_array($status, PurchaseOrders::OPEN, true) ? ' pu-due' : '' ?>"><?= $l['remaining'] > 0 ? number_format($l['remaining']) : '<span class="pu-done">' . icon('check') . '</span>' ?></td>
                        <?php endif; ?>
                        <td class="num col-opt"><?= e($costText((string) $l['unit_cost'])) ?></td>
                        <td class="num"><?= e(money($l['line_total'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <dl class="sale-totals<?= $status === 'cancelled' ? ' is-void' : '' ?>">
            <div><dt>Units ordered</dt><dd><?= number_format($ordered) ?></dd></div>
            <div class="sale-totals__grand"><dt>Total amount</dt><dd id="poTotal"><?= e(money($po['total_amount'])) ?></dd></div>
        </dl>
    </section>

    <aside class="form-side">
        <section class="card card--pad">
            <h2 class="card__title">Details</h2>
            <dl class="detail-list doc-details">
                <div><dt>Supplier</dt><dd>
                    <?php if (Auth::can('suppliers.view')): ?>
                        <a href="<?= e(url('pages/supplier-form.php?id=' . (int) $po['supplier_id'])) ?>"><?= e($po['supplier_name']) ?></a>
                    <?php else: ?><?= e($po['supplier_name']) ?><?php endif; ?>
                </dd></div>
                <div><dt>Payment terms</dt><dd><?= e($po['payment_terms'] ?? '—') ?></dd></div>
                <div><dt>PO date</dt><dd><?= e($day($po['order_date'])) ?></dd></div>
                <div><dt>Expected</dt><dd class="<?= $late ? 'text-danger' : '' ?>"><?= e($day($po['expected_date'])) ?></dd></div>
                <div><dt>Deliver to</dt><dd><?= e($po['branch_code'] . ' · ' . $po['warehouse_code'] . ' / ' . $po['location_code']) ?></dd></div>
                <?php if ($po['forwarder']): ?><div><dt>Forwarder</dt><dd><?= e($po['forwarder']) ?></dd></div><?php endif; ?>
                <?php if ($po['contact_person'] || $po['contact_number']): ?>
                    <div><dt>Contact</dt><dd><?= e(trim(($po['contact_person'] ?? '') . ' ' . ($po['contact_number'] ?? ''))) ?></dd></div>
                <?php endif; ?>
                <div><dt>Prepared by</dt><dd><?= e($po['created_by_name']) ?><small class="muted block"><?= e($when($po['created_at'])) ?></small></dd></div>
                <?php if ($po['approved_at']): ?><div><dt>Approved by</dt><dd><?= e($po['approved_by_name'] ?? '—') ?><small class="muted block"><?= e($when($po['approved_at'])) ?></small></dd></div><?php endif; ?>
            </dl>
            <?php if ($po['ship_to']): ?><p class="rr-notes"><span class="form-label">Ship to</span><?= e($po['ship_to']) ?></p><?php endif; ?>
            <?php if ($po['notes']): ?><p class="rr-notes"><span class="form-label">Notes</span><?= e($po['notes']) ?></p><?php endif; ?>
        </section>

        <?php if ($tracking): ?>
            <section class="card card--pad" id="poDeliveries">
                <h2 class="card__title">Deliveries</h2>
                <?php if ($po['receipts']): ?>
                    <ul class="pu-doc-list">
                        <?php foreach ($po['receipts'] as $rr): ?>
                            <li class="<?= $rr['status'] === 'cancelled' ? 'is-void' : '' ?>">
                                <span>
                                    <?php if (Auth::can('receiving.view')): ?>
                                        <a class="doc-no" href="<?= e(url('pages/receiving-view.php?id=' . (int) $rr['id'])) ?>"><?= e($rr['rr_no'] ?? 'Draft #' . $rr['id']) ?></a>
                                    <?php else: ?><span class="doc-no"><?= e($rr['rr_no'] ?? 'Draft #' . $rr['id']) ?></span><?php endif; ?>
                                    <small class="muted block"><?= e($day($rr['received_date'])) ?> · <?= number_format((int) $rr['total_qty']) ?> units<?= $rr['reference_no'] ? ' · ' . e($rr['reference_no']) : '' ?></small>
                                </span>
                                <span class="badge <?= e(['draft' => 'badge--info', 'posted' => 'badge--success', 'cancelled' => 'badge--danger'][$rr['status']] ?? '') ?>"><?= e(Receiving::STATUSES[$rr['status']] ?? $rr['status']) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="muted pu-empty">Nothing received yet.</p>
                <?php endif; ?>
            </section>
            <?php if (Payables::canView()): ?>
                <?php $payCard = PaymentStatus::poCard($id); require ROOT_PATH . '/includes/payment-card.php'; ?>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($requests): ?>
            <section class="card card--pad" id="poRequests">
                <h2 class="card__title">Purchase requests</h2>
                <ul class="pu-doc-list">
                    <?php foreach ($requests as $rid => $prNo): ?>
                        <li><a class="doc-no" href="<?= e(url('pages/pr-view.php?id=' . (int) $rid)) ?>"><?= e($prNo) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>
        <?php [$attType, $attId, $attReturn] = ['purchase_order', $id, $self]; require ROOT_PATH . '/includes/attachments-card.php'; ?>
    </aside>
</div>

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
                <textarea class="form-input" name="note" id="returnNote" required minlength="3" maxlength="255"
                          placeholder="e.g. Ask the supplier for a better price"<?= invalid('note') ?>></textarea>
                <?= field_error('note') ?>
            </label>
            <footer class="modal__foot">
                <button type="button" class="btn btn--light" data-close>Keep Waiting</button>
                <button type="submit" class="btn btn--primary"><?= icon('arrow-left') ?> Return to Draft</button>
            </footer>
        </form>
    </dialog>
<?php endif; ?>

<?php foreach (['close' => ['closeDialog', 'Close', 'The remaining quantities will not be received. What was received stays in stock.', 'e.g. Supplier is out of stock'],
                'cancel' => ['cancelDialog', 'Cancel', 'Nothing was received. The purchase requests on it can be ordered again.', 'e.g. Supplier cannot deliver']] as $act => [$dlgId, $verb, $warn, $hint]): ?>
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
                <button type="button" class="btn btn--light" data-close>Keep PO</button>
                <button type="submit" class="btn btn--danger"><?= icon('x') ?> <?= e($verb) ?> PO</button>
            </footer>
        </form>
    </dialog>
<?php endforeach; ?>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
