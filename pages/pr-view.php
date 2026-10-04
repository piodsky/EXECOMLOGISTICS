<?php
/**
 * One purchase request: lines (requested / approved / ordered / still to order), purchase orders, Print.
 * Actions follow PurchaseRequests::actions():
 *   requested -> Approve (approved qty per line, 0 = not approved) / Reject (note), never by the requester
 *   requested / approved with nothing ordered -> Cancel (reason)
 *   approved  -> Create PO (purchasing.order + products.cost) = po-form.php?pr[]=ID
 * pages/pr-view.php?id=5&return=purchase-requests.php
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('purchasing');

$id = input_int($_GET, 'id', 1) ?? throw new HttpException(404, 'Purchase request not found.');
$r  = PurchaseRequests::find($id) ?? throw new HttpException(404, 'Purchase request not found.');

$returnTo = safe_return($_POST['return'] ?? $_GET['return'] ?? null, 'purchase-requests.php');
$self     = 'pr-view.php?' . http_build_query(['id' => $id, 'return' => $returnTo]);
$label    = PurchaseRequests::label($r);

if (is_post()) {
    Csrf::verifyRequest();
    $action = input_string($_POST, 'action', 10);
    $uid    = (int) Auth::id();
    $qty    = is_array($_POST['qty'] ?? null) ? array_filter($_POST['qty'], 'is_string') : [];
    $note   = input_string($_POST, 'note', 300);
    try {
        switch ($action) {
            case 'approve':
                $no = PurchaseRequests::approve($id, $qty, $note, $uid);
                flash('success', "{$no} was approved. It can now be put on a purchase order.");
                break;
            case 'reject':
                $no = PurchaseRequests::reject($id, $note, $uid);
                flash('success', "{$no} was rejected.");
                break;
            case 'cancel':
                $no = PurchaseRequests::cancel($id, input_string($_POST, 'reason', 300), $uid);
                flash('success', "{$no} was cancelled.");
                break;
            default:
                throw new HttpException(400, 'Unknown action.');
        }
    } catch (HttpException $e) {
        if (is_array($e->details['errors'] ?? null)) {
            flash_errors($e->details['errors']);
        }
        flash_old(['action' => (string) $action, 'qty' => $qty, 'note' => $note]);
        flash('error', $e->getMessage());
    }
    redirect('pages/' . $self);
}

$status  = $r['status'];
$actions = PurchaseRequests::actions($r);
$lines   = $r['lines'];
$page['title'] = $label;
$old     = has_old() ? old_input() : [];
$oldQty  = is_array($old['qty'] ?? null) ? $old['qty'] : null;
$when    = static fn (?string $ts): string => $ts ? date('M j, Y g:i A', strtotime($ts)) : '—';
$num     = static fn ($v): string => $v === null ? '—' : number_format((int) $v);
$showOrdered = in_array($status, ['approved', 'ordered'], true);
$poView  = PurchaseOrders::canView();

$pageStyles  = ['css/sales.css', 'css/receiving.css', 'css/stock-docs.css', 'css/transfers.css', 'css/purchasing.css'];
$pageScripts = ['js/purchasing.js'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <a class="back-link" href="<?= e(url('pages/' . $returnTo)) ?>"><?= icon('arrow-left') ?> Purchase Requests</a>
        <h1 class="sale-title">
            <span id="prTitle"><?= e($label) ?></span>
            <span class="badge <?= e(PurchaseRequests::BADGES[$status] ?? '') ?>" id="prStatus"><?= e(PurchaseRequests::STATUSES[$status] ?? $status) ?></span>
        </h1>
        <p class="muted"><?= e($r['branch_code'] . ' · ' . $r['branch_name']) ?> · requested by <?= e($r['requested_by_name']) ?> on <?= e($when($r['requested_at'])) ?></p>
    </div>
    <div class="page-actions">
        <a class="btn btn--light" href="<?= e(url('pages/pr-print.php?id=' . $id)) ?>" target="_blank" rel="noopener" id="prPrint"><?= icon('printer') ?> Print</a>
        <?php if ($actions['order']): ?>
            <a class="btn btn--primary" href="<?= e(url('pages/po-form.php?' . http_build_query(['pr' => [$id], 'return' => 'purchase-orders.php']))) ?>" id="prCreatePo"><?= icon('cart') ?> Create PO</a>
        <?php endif; ?>
        <?php if ($actions['reject']): ?>
            <button type="button" class="btn btn--light" data-open="rejectDialog" id="prRejectBtn"><?= icon('x') ?> Reject</button>
        <?php endif; ?>
        <?php if ($actions['cancel']): ?>
            <button type="button" class="btn btn--danger" data-open="cancelDialog" id="prCancelBtn"><?= icon('x') ?> Cancel Request</button>
        <?php endif; ?>
    </div>
</div>

<?php if ($status === 'cancelled'): ?>
    <div class="void-box" role="note"><?= icon('alert') ?>
        <div><strong>This request was cancelled</strong><?= e(($r['cancelled_at'] ? ' on ' . $when($r['cancelled_at']) : '') . ($r['cancelled_by_name'] ? ' by ' . $r['cancelled_by_name'] : '')) ?>.
            <?php if ($r['cancel_reason']): ?><p class="void-box__reason">Reason: <?= e($r['cancel_reason']) ?></p><?php endif; ?></div>
    </div>
<?php elseif ($status === 'rejected'): ?>
    <div class="void-box" role="note"><?= icon('alert') ?>
        <div><strong>This request was rejected</strong><?= e(($r['decided_at'] ? ' on ' . $when($r['decided_at']) : '') . ($r['decided_by_name'] ? ' by ' . $r['decided_by_name'] : '')) ?>.
            <?php if ($r['decision_note']): ?><p class="void-box__reason">Reason: <?= e($r['decision_note']) ?></p><?php endif; ?></div>
    </div>
<?php elseif ($actions['approve']): ?>
    <div class="alert alert--info doc-note" role="note"><?= icon('info') ?>
        <span>Approve what may be ordered (0 = not approved), or reject the request. Approval does not order anything yet.</span></div>
<?php elseif ($status === 'requested'): ?>
    <div class="alert alert--warning doc-note" role="note"><?= icon('clock') ?>
        <span>Waiting for a branch admin to approve<?= (int) Auth::id() === (int) $r['requested_by'] ? ' (someone other than you)' : '' ?>.</span></div>
<?php elseif ($status === 'approved'): ?>
    <div class="alert alert--warning doc-note" role="note"><?= icon('cart') ?>
        <span>Approved<?= $r['decided_by_name'] ? ' by ' . e($r['decided_by_name']) : '' ?>. Waiting to be put on a purchase order.</span></div>
<?php elseif ($status === 'ordered'): ?>
    <div class="alert alert--success doc-note" role="note"><?= icon('check') ?>
        <span>Everything approved is on a purchase order. Track the delivery on the purchase order.</span></div>
<?php endif; ?>

<div class="sale-layout">
    <section class="card">
        <header class="card__head">
            <h2><?= icon('box') ?> Items</h2>
            <span class="muted"><?= count($lines) ?> <?= count($lines) === 1 ? 'item' : 'items' ?></span>
        </header>

        <?php if ($actions['approve']): ?>
            <form method="post" action="<?= e(url('pages/' . $self)) ?>" id="prApproveForm" novalidate>
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="return" value="<?= e($returnTo) ?>">
        <?php endif; ?>

        <div class="table-wrap">
            <table class="table doc-lines bt-lines" id="prLines">
                <thead>
                <tr>
                    <th>#</th>
                    <th>Item</th>
                    <th class="col-opt">End-user</th>
                    <th class="num">Requested</th>
                    <th class="num">Approved</th>
                    <?php if ($showOrdered): ?><th class="num">On PO</th><th class="num">To order</th><?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($lines as $i => $l): ?>
                    <?php $lid = (int) $l['id']; ?>
                    <tr data-line="<?= $lid ?>">
                        <td class="muted"><?= $i + 1 ?></td>
                        <td>
                            <strong class="block"><?= e($l['product_name']) ?></strong>
                            <small class="muted"><?= e($l['product_code']) ?><?= (int) $l['track_serial'] === 1 ? ' · S/N' : '' ?></small>
                            <?= field_error('qty.' . $lid) ?>
                        </td>
                        <td class="col-opt"><?= $l['end_user'] !== null ? e($l['end_user']) : '<span class="muted">—</span>' ?></td>
                        <td class="num"><?= $num($l['qty_requested']) ?> <small class="muted"><?= e($l['unit_code'] ?? '') ?></small></td>
                        <td class="num">
                            <?php if ($actions['approve']): ?>
                                <?php $v = $oldQty !== null ? (string) ($oldQty[$lid] ?? $oldQty[(string) $lid] ?? '') : (string) (int) $l['qty_requested']; ?>
                                <input class="form-input num bt-qty" type="number" name="qty[<?= $lid ?>]" min="0" max="<?= (int) $l['qty_requested'] ?>" step="1"
                                       inputmode="numeric" aria-label="Approved quantity of <?= e($l['product_name']) ?>" value="<?= e($v) ?>"<?= invalid('qty.' . $lid) ?>>
                            <?php else: ?>
                                <?= $num($l['qty_approved']) ?>
                            <?php endif; ?>
                        </td>
                        <?php if ($showOrdered): ?>
                            <td class="num"><?= $num($l['qty_ordered']) ?></td>
                            <td class="num<?= $l['remaining'] > 0 ? ' pu-due' : '' ?>"><?= $num($l['remaining']) ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($actions['approve']): ?>
                <div class="form-actions form-actions--inline bt-actions">
                    <label class="form-field bt-note">
                        <span class="form-label">Note <small class="muted">(optional)</small></span>
                        <input class="form-input" name="note" maxlength="255" value="<?= e(($old['action'] ?? '') === 'approve' ? (string) ($old['note'] ?? '') : '') ?>"
                               placeholder="e.g. Order from the usual supplier">
                    </label>
                    <button type="submit" class="btn btn--primary" id="prApproveBtn" data-confirm-submit="Approve <?= e($label) ?>?"><?= icon('check') ?> Approve</button>
                </div>
            </form>
        <?php endif; ?>

        <dl class="sale-totals<?= in_array($status, ['rejected', 'cancelled'], true) ? ' is-void' : '' ?>">
            <div><dt>Units requested</dt><dd><?= number_format((int) $r['total_qty']) ?></dd></div>
            <?php if ($showOrdered): ?>
                <div><dt>Units approved</dt><dd><?= number_format(array_sum(array_map(static fn (array $l): int => (int) $l['qty_approved'], $lines))) ?></dd></div>
                <div class="sale-totals__grand"><dt>Still to order</dt><dd id="prRemaining"><?= number_format(array_sum(array_column($lines, 'remaining'))) ?></dd></div>
            <?php endif; ?>
        </dl>
    </section>

    <aside class="form-side">
        <section class="card card--pad">
            <h2 class="card__title">Details</h2>
            <dl class="detail-list doc-details">
                <div><dt>Branch</dt><dd><?= e($r['branch_code'] . ' · ' . $r['branch_name']) ?></dd></div>
                <div><dt>Requested by</dt><dd><?= e($r['requested_by_name']) ?><small class="muted block"><?= e($when($r['requested_at'])) ?></small></dd></div>
                <div><dt>Needed by</dt><dd><?= $r['needed_by'] !== null ? e(date('M j, Y', strtotime($r['needed_by']))) : '—' ?></dd></div>
                <?php if ($r['job_no'] !== null): ?>
                    <div><dt>Job order</dt><dd id="prJob">
                        <?php if (Auth::canAny(...JobOrders::VIEW_PERMISSIONS)): ?>
                            <a href="<?= e(url('pages/job-view.php?id=' . (int) $r['job_order_id'])) ?>"><?= e($r['job_no']) ?></a>
                        <?php else: ?><?= e($r['job_no']) ?><?php endif; ?>
                    </dd></div>
                <?php endif; ?>
                <?php if ($r['decided_at'] && $status !== 'rejected'): ?>
                    <div><dt>Approved by</dt><dd><?= e($r['decided_by_name'] ?? '—') ?><small class="muted block"><?= e($when($r['decided_at'])) ?></small></dd></div>
                <?php endif; ?>
            </dl>
            <?php if ($r['purpose']): ?><p class="rr-notes"><span class="form-label">Purpose</span><?= e($r['purpose']) ?></p><?php endif; ?>
            <?php if ($r['decision_note'] && $status !== 'rejected'): ?><p class="rr-notes"><span class="form-label">Approval note</span><?= e($r['decision_note']) ?></p><?php endif; ?>
        </section>
        <?php if ($r['orders']): ?>
            <section class="card card--pad" id="prOrders">
                <h2 class="card__title">Purchase orders</h2>
                <ul class="pu-doc-list">
                    <?php foreach ($r['orders'] as $o): ?>
                        <li>
                            <?php $oLabel = PurchaseOrders::label($o); ?>
                            <?php if ($poView): ?><a class="doc-no" href="<?= e(url('pages/po-view.php?id=' . (int) $o['id'])) ?>"><?= e($oLabel) ?></a><?php else: ?><span class="doc-no"><?= e($oLabel) ?></span><?php endif; ?>
                            <span class="badge <?= e(PurchaseOrders::BADGES[$o['status']] ?? '') ?>"><?= e(PurchaseOrders::STATUSES[$o['status']] ?? $o['status']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>
    </aside>
</div>

<?php if ($actions['reject']): ?>
    <dialog class="modal" id="rejectDialog" aria-labelledby="rejectTitle"<?= ($old['action'] ?? '') === 'reject' ? ' data-reopen' : '' ?>>
        <form class="modal__body" method="post" action="<?= e(url('pages/' . $self)) ?>">
            <header class="modal__head">
                <h2 id="rejectTitle">Reject <?= e($label) ?>?</h2>
                <button type="button" class="modal__close" data-close aria-label="Close"><?= icon('x') ?></button>
            </header>
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="return" value="<?= e($returnTo) ?>">
            <label class="form-field">
                <span class="form-label">Reason *</span>
                <textarea class="form-input" name="note" id="rejectNote" required minlength="3" maxlength="255"
                          placeholder="e.g. Enough stock at the warehouse"<?= invalid('note') ?>></textarea>
                <?= field_error('note') ?>
            </label>
            <footer class="modal__foot">
                <button type="button" class="btn btn--light" data-close>Keep Request</button>
                <button type="submit" class="btn btn--danger"><?= icon('x') ?> Reject</button>
            </footer>
        </form>
    </dialog>
<?php endif; ?>

<?php if ($actions['cancel']): ?>
    <dialog class="modal" id="cancelDialog" aria-labelledby="cancelTitle"<?= ($old['action'] ?? '') === 'cancel' ? ' data-reopen' : '' ?>>
        <form class="modal__body" method="post" action="<?= e(url('pages/' . $self)) ?>" data-confirm="Cancel <?= e($label) ?>? This cannot be undone.">
            <header class="modal__head">
                <h2 id="cancelTitle">Cancel <?= e($label) ?>?</h2>
                <button type="button" class="modal__close" data-close aria-label="Close"><?= icon('x') ?></button>
            </header>
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="cancel">
            <input type="hidden" name="return" value="<?= e($returnTo) ?>">
            <label class="form-field">
                <span class="form-label">Reason *</span>
                <textarea class="form-input" name="reason" id="cancelReason" required minlength="3" maxlength="255"
                          placeholder="e.g. No longer needed"<?= invalid('reason') ?>></textarea>
                <?= field_error('reason') ?>
            </label>
            <footer class="modal__foot">
                <button type="button" class="btn btn--light" data-close>Keep Request</button>
                <button type="submit" class="btn btn--danger"><?= icon('x') ?> Cancel Request</button>
            </footer>
        </form>
    </dialog>
<?php endif; ?>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
