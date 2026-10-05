<?php
/**
 * One supplier invoice: supplier, receiving report / PO, amounts, payments (disbursement vouchers). Actions
 * (Payables::invoiceActions, working in the branch): Pay (dv-form.php?supplier=ID), Cancel (payables.cancel, nothing
 * paid, reason). pages/ap-view.php?id=5
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('payables');
Payables::requireView();

$id = input_int($_GET, 'id', 1) ?? throw new HttpException(404, 'Supplier invoice not found.');
$i  = Payables::find($id) ?? throw new HttpException(404, 'Supplier invoice not found.');
$self = 'ap-view.php?id=' . $id;

if (is_post()) {
    Csrf::verifyRequest();
    try {
        if (input_string($_POST, 'action', 10) !== 'cancel') {
            throw new HttpException(400, 'Unknown action.');
        }
        Payables::cancelInvoice($id, input_string($_POST, 'reason', 300), (int) Auth::id());
        flash('success', "{$i['ap_no']} was cancelled. Its receiving report can be invoiced again.");
    } catch (HttpException $e) {
        if (is_array($e->details['errors'] ?? null)) {
            flash_errors($e->details['errors']);
        }
        flash_old(['action' => 'cancel']);
        flash('error', $e->getMessage());
    }
    redirect('pages/' . $self);
}

$actions = Payables::invoiceActions($i);
$old     = has_old() ? old_input() : [];
$day     = static fn (?string $d): string => $d ? date('M j, Y', strtotime($d)) : '—';
$balance = to_cents($i['amount']) - to_cents($i['paid_amount']);
$late    = $i['status'] === 'open' && $i['due_date'] < date('Y-m-d');
$status  = $i['status'] === 'open' && to_cents($i['paid_amount']) > 0 ? 'Partial' : (Payables::STATUSES[$i['status']] ?? $i['status']);
$page['title'] = $i['ap_no'];
$pageStyles  = ['css/sales.css', 'css/receiving.css', 'css/stock-docs.css', 'css/transfers.css', 'css/purchasing.css'];
$pageScripts = ['js/purchasing.js'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <a class="back-link" href="<?= e(url('pages/payables.php')) ?>"><?= icon('arrow-left') ?> Payables</a>
        <h1 class="sale-title"><span id="apTitle"><?= e($i['ap_no']) ?></span>
            <span class="badge <?= e($status === 'Partial' ? 'badge--info' : (Payables::BADGES[$i['status']] ?? '')) ?>" id="apStatus"><?= e($status) ?></span></h1>
        <p class="muted"><?= e($i['supplier_name']) ?> · invoice <?= e($i['invoice_no']) ?> · <?= e($i['branch_code'] . ' · ' . $i['branch_name']) ?></p>
    </div>
    <div class="page-actions">
        <?php if ($actions['pay']): ?>
            <a class="btn btn--primary" href="<?= e(url('pages/dv-form.php?supplier=' . (int) $i['supplier_id'])) ?>" id="apPay"><?= icon('wallet') ?> Pay</a>
        <?php endif; ?>
        <?php if ($actions['cancel']): ?>
            <button type="button" class="btn btn--danger" data-open="cancelDialog" id="apCancelBtn"><?= icon('x') ?> Cancel Invoice</button>
        <?php endif; ?>
    </div>
</div>

<?php if ($i['status'] === 'cancelled'): ?>
    <div class="void-box" role="note"><?= icon('alert') ?><div><strong>This invoice was cancelled</strong><?= e(' on ' . $day($i['cancelled_at']) . ($i['cancelled_by_name'] ? ' by ' . $i['cancelled_by_name'] : '')) ?>.
        <?php if ($i['cancel_reason']): ?><p class="void-box__reason">Reason: <?= e($i['cancel_reason']) ?></p><?php endif; ?></div></div>
<?php elseif ($late): ?>
    <div class="alert alert--warning doc-note" role="note"><?= icon('clock') ?><span>Overdue since <?= e($day($i['due_date'])) ?>.</span></div>
<?php endif; ?>

<?php $chain = DocChain::of('ap', $id); require ROOT_PATH . '/includes/doc-chain.php'; ?>

<div class="sale-layout">
    <section class="card">
        <header class="card__head"><h2><?= icon('wallet') ?> Payments</h2></header>
        <div class="table-wrap">
            <table class="table doc-lines" id="apPayments">
                <thead><tr><th>Voucher</th><th>Date</th><th>Method</th><th class="num">Paid</th><th class="num">EWT</th></tr></thead>
                <tbody>
                <?php foreach ($i['payments'] as $p): ?>
                    <tr class="<?= $p['status'] === 'cancelled' ? 'is-void' : '' ?>">
                        <td><a class="doc-no" href="<?= e(url('pages/dv-view.php?id=' . (int) $p['id'])) ?>"><?= e($p['dv_no']) ?></a><?= $p['status'] === 'cancelled' ? ' <small class="muted">cancelled</small>' : '' ?></td>
                        <td><?= e($day($p['payment_date'])) ?></td>
                        <td><?= e(Collections::METHODS[$p['method']] ?? $p['method']) ?><?= $p['reference'] ? ' <small class="muted">' . e($p['reference']) . '</small>' : '' ?></td>
                        <td class="num"><?= e(money($p['amount'])) ?></td>
                        <td class="num"><?= (float) $p['ewt_amount'] > 0 ? e(money($p['ewt_amount'])) : '<span class="muted">—</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$i['payments']): ?><tr><td colspan="5" class="empty">Nothing paid yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <dl class="sale-totals<?= $i['status'] === 'cancelled' ? ' is-void' : '' ?>">
            <div><dt>Invoice amount</dt><dd><?= e(money($i['amount'])) ?></dd></div>
            <div><dt>Paid (incl. EWT)</dt><dd><?= e(money($i['paid_amount'])) ?></dd></div>
            <div class="sale-totals__grand"><dt>Balance</dt><dd id="apBalance"><?= e(money(from_cents($i['status'] === 'cancelled' ? 0 : $balance))) ?></dd></div>
        </dl>
    </section>

    <aside class="form-side">
        <section class="card card--pad">
            <h2 class="card__title">Invoice</h2>
            <dl class="detail-list doc-details">
                <div><dt>Supplier</dt><dd><?= e($i['supplier_name']) ?><?= $i['supplier_tin'] ? '<small class="muted block">TIN ' . e($i['supplier_tin']) . '</small>' : '' ?></dd></div>
                <div><dt>Invoice no.</dt><dd class="doc-no"><?= e($i['invoice_no']) ?></dd></div>
                <div><dt>Invoice date</dt><dd><?= e($day($i['invoice_date'])) ?></dd></div>
                <div><dt>Due</dt><dd class="<?= $late ? 'text-danger' : '' ?>"><?= e($day($i['due_date'])) ?></dd></div>
                <div><dt>Receiving</dt><dd><a class="doc-no" href="<?= e(url('pages/receiving-view.php?id=' . (int) $i['receiving_id'])) ?>"><?= e($i['rr_no']) ?></a><small class="muted block">received <?= e($day($i['received_date'])) ?> · <?= e(money($i['rr_total'] ?? 0)) ?></small></dd></div>
                <?php if ($i['po_no']): ?><div><dt>PO</dt><dd><a class="doc-no" href="<?= e(url('pages/po-view.php?id=' . (int) $i['po_id'])) ?>"><?= e($i['po_no']) ?></a></dd></div><?php endif; ?>
                <div><dt>Recorded by</dt><dd><?= e($i['created_by_name']) ?><small class="muted block"><?= e(date('M j, Y g:i A', strtotime($i['created_at']))) ?></small></dd></div>
            </dl>
            <?php if ($i['notes']): ?><p class="rr-notes"><span class="form-label">Notes</span><?= e($i['notes']) ?></p><?php endif; ?>
        </section>
    </aside>
</div>

<?php if ($actions['cancel']): ?>
    <dialog class="modal" id="cancelDialog" aria-labelledby="cancelTitle"<?= ($old['action'] ?? '') === 'cancel' ? ' data-reopen' : '' ?>>
        <form class="modal__body" method="post" action="<?= e(url('pages/' . $self)) ?>" data-confirm="Cancel <?= e($i['ap_no']) ?>?">
            <header class="modal__head"><h2 id="cancelTitle">Cancel <?= e($i['ap_no']) ?>?</h2>
                <button type="button" class="modal__close" data-close aria-label="Close"><?= icon('x') ?></button></header>
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="cancel">
            <label class="form-field">
                <span class="form-label">Reason *</span>
                <textarea class="form-input" name="reason" id="apCancelReason" required minlength="3" maxlength="255" placeholder="e.g. Wrong invoice number; re-encode"<?= invalid('reason') ?>></textarea>
                <?= field_error('reason') ?>
            </label>
            <footer class="modal__foot"><button type="button" class="btn btn--light" data-close>Keep It</button>
                <button type="submit" class="btn btn--danger"><?= icon('x') ?> Cancel Invoice</button></footer>
        </form>
    </dialog>
<?php endif; ?>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
