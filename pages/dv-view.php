<?php
/**
 * One disbursement voucher: payment details, invoices paid (cash + EWT), print. Actions (Payables::dvActions, working
 * in the branch): Check Cleared (payables.manage, date), Cancel (payables.cancel, reason: the invoices are open again).
 * pages/dv-view.php?id=5
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('payables');
Payables::requireView();

$id = input_int($_GET, 'id', 1) ?? throw new HttpException(404, 'Disbursement not found.');
$d  = Payables::findDv($id) ?? throw new HttpException(404, 'Disbursement not found.');
$self = 'dv-view.php?id=' . $id;

if (is_post()) {
    Csrf::verifyRequest();
    $action = input_string($_POST, 'action', 10);
    try {
        if ($action === 'cancel') {
            Payables::cancelDv($id, input_string($_POST, 'reason', 300), (int) Auth::id());
            flash('success', "{$d['dv_no']} was cancelled. Its invoices are open again.");
        } elseif ($action === 'clear') {
            Payables::clearCheck($id, input_string($_POST, 'cleared_at', 10), (int) Auth::id());
            flash('success', "The check of {$d['dv_no']} cleared.");
        } else {
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

$actions = Payables::dvActions($d);
$old     = has_old() ? old_input() : [];
$day     = static fn (?string $x): string => $x ? date('M j, Y', strtotime($x)) : '—';
$page['title'] = $d['dv_no'];
$pageStyles  = ['css/sales.css', 'css/receiving.css', 'css/stock-docs.css', 'css/transfers.css', 'css/purchasing.css'];
$pageScripts = ['js/purchasing.js'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <a class="back-link" href="<?= e(url('pages/disbursements.php')) ?>"><?= icon('arrow-left') ?> Disbursements</a>
        <h1 class="sale-title"><span id="dvTitle"><?= e($d['dv_no']) ?></span>
            <span class="badge <?= e(Payables::DV_BADGES[$d['status']] ?? '') ?>" id="dvStatus"><?= e(Payables::DV_STATUSES[$d['status']] ?? $d['status']) ?></span></h1>
        <p class="muted"><?= e($d['supplier_name']) ?> · <?= e($day($d['payment_date'])) ?> · <?= e($d['branch_code'] . ' · ' . $d['branch_name']) ?></p>
    </div>
    <div class="page-actions">
        <a class="btn btn--light" href="<?= e(url('pages/dv-print.php?id=' . $id)) ?>" target="_blank" rel="noopener" id="dvPrint"><?= icon('printer') ?> Print Voucher</a>
        <?php if ($actions['clear']): ?><button type="button" class="btn btn--primary" data-open="clearDialog" id="dvClearBtn"><?= icon('check') ?> Check Cleared</button><?php endif; ?>
        <?php if ($actions['cancel']): ?><button type="button" class="btn btn--danger" data-open="cancelDialog" id="dvCancelBtn"><?= icon('x') ?> Cancel Voucher</button><?php endif; ?>
    </div>
</div>

<?php if ($d['status'] === 'cancelled'): ?>
    <div class="void-box" role="note"><?= icon('alert') ?><div><strong>This voucher was cancelled</strong><?= e(' on ' . $day($d['cancelled_at']) . ($d['cancelled_by_name'] ? ' by ' . $d['cancelled_by_name'] : '')) ?>. Its invoices are open again.
        <?php if ($d['cancel_reason']): ?><p class="void-box__reason">Reason: <?= e($d['cancel_reason']) ?></p><?php endif; ?></div></div>
<?php elseif ($d['check_status'] === 'issued'): ?>
    <div class="alert alert--info doc-note" role="note"><?= icon('clock') ?><span>Check <?= e((string) $d['reference']) ?> is issued; mark it cleared when it is debited from the bank.</span></div>
<?php endif; ?>

<div class="sale-layout">
    <section class="card">
        <header class="card__head"><h2><?= icon('clipboard') ?> Invoices paid</h2></header>
        <div class="table-wrap">
            <table class="table doc-lines" id="dvLinesView">
                <thead><tr><th>Invoice</th><th class="num">Cash paid</th><th class="num">EWT</th><th class="num col-opt">Invoice balance now</th></tr></thead>
                <tbody>
                <?php foreach ($d['lines'] as $l): ?>
                    <tr>
                        <td><a class="doc-no" href="<?= e(url('pages/ap-view.php?id=' . (int) $l['invoice_id'])) ?>"><?= e($l['ap_no']) ?></a>
                            <small class="muted block">Inv. <?= e($l['invoice_no']) ?> · <?= e($l['rr_no']) ?> · due <?= e($day($l['due_date'])) ?></small></td>
                        <td class="num"><?= e(money($l['amount'])) ?></td>
                        <td class="num"><?= (float) $l['ewt_amount'] > 0 ? e(money($l['ewt_amount'])) : '<span class="muted">—</span>' ?></td>
                        <td class="num col-opt"><?= $l['invoice_status'] === 'cancelled' ? '—' : e(money(from_cents(to_cents($l['invoice_amount']) - to_cents($l['paid_amount'])))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <dl class="sale-totals<?= $d['status'] === 'cancelled' ? ' is-void' : '' ?>">
            <div><dt>EWT withheld</dt><dd><?= e(money($d['ewt_total'])) ?></dd></div>
            <div><dt>Invoices settled</dt><dd><?= e(money($d['total_settled'])) ?></dd></div>
            <div class="sale-totals__grand"><dt>Amount paid</dt><dd id="dvAmount"><?= e(money($d['amount_paid'])) ?></dd></div>
        </dl>
    </section>
    <aside class="form-side">
        <section class="card card--pad">
            <h2 class="card__title">Payment</h2>
            <dl class="detail-list doc-details">
                <div><dt>Payee</dt><dd><?= e($d['supplier_name']) ?><?= $d['supplier_tin'] ? '<small class="muted block">TIN ' . e($d['supplier_tin']) . '</small>' : '' ?></dd></div>
                <div><dt>Date</dt><dd><?= e($day($d['payment_date'])) ?></dd></div>
                <div><dt>Method</dt><dd><?= e(Collections::METHODS[$d['method']] ?? $d['method']) ?></dd></div>
                <?php if ($d['reference']): ?><div><dt><?= $d['method'] === 'check' ? 'Check no.' : 'Reference' ?></dt><dd class="doc-no"><?= e($d['reference']) ?></dd></div><?php endif; ?>
                <?php if ($d['bank_name']): ?><div><dt>Bank</dt><dd><?= e($d['bank_name']) ?></dd></div><?php endif; ?>
                <?php if ($d['check_date']): ?><div><dt>Check date</dt><dd><?= e($day($d['check_date'])) ?></dd></div><?php endif; ?>
                <?php if ($d['check_status'] !== 'none'): ?><div><dt>Check</dt><dd id="dvCheck"><?= e(Payables::CHECK_STATUSES[$d['check_status']]) ?><?= $d['cleared_at'] ? ' ' . e($day($d['cleared_at'])) : '' ?></dd></div><?php endif; ?>
                <div><dt>Prepared by</dt><dd><?= e($d['created_by_name']) ?><small class="muted block"><?= e(date('M j, Y g:i A', strtotime($d['created_at']))) ?></small></dd></div>
            </dl>
            <?php if ($d['particulars']): ?><p class="rr-notes"><span class="form-label">Particulars</span><?= e($d['particulars']) ?></p><?php endif; ?>
        </section>
    </aside>
</div>

<?php if ($actions['clear']): ?>
    <dialog class="modal" id="clearDialog" aria-labelledby="clearTitle"<?= ($old['action'] ?? '') === 'clear' ? ' data-reopen' : '' ?>>
        <form class="modal__body" method="post" action="<?= e(url('pages/' . $self)) ?>">
            <header class="modal__head"><h2 id="clearTitle">Check <?= e((string) $d['reference']) ?> cleared</h2>
                <button type="button" class="modal__close" data-close aria-label="Close"><?= icon('x') ?></button></header>
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="clear">
            <label class="form-field"><span class="form-label">Date cleared *</span>
                <input class="form-input" type="date" name="cleared_at" max="<?= e(date('Y-m-d')) ?>" value="<?= e(date('Y-m-d')) ?>"<?= invalid('cleared_at') ?>>
                <?= field_error('cleared_at') ?></label>
            <footer class="modal__foot"><button type="button" class="btn btn--light" data-close>Not Now</button>
                <button type="submit" class="btn btn--primary"><?= icon('check') ?> Mark Cleared</button></footer>
        </form>
    </dialog>
<?php endif; ?>

<?php if ($actions['cancel']): ?>
    <dialog class="modal" id="cancelDialog" aria-labelledby="cancelTitle"<?= ($old['action'] ?? '') === 'cancel' ? ' data-reopen' : '' ?>>
        <form class="modal__body" method="post" action="<?= e(url('pages/' . $self)) ?>" data-confirm="Cancel <?= e($d['dv_no']) ?>? The invoices it paid are open again.">
            <header class="modal__head"><h2 id="cancelTitle">Cancel <?= e($d['dv_no']) ?>?</h2>
                <button type="button" class="modal__close" data-close aria-label="Close"><?= icon('x') ?></button></header>
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="cancel">
            <p class="void-warning"><?= icon('alert') ?><span>Use this for a stopped / spoiled check or a payment entered by mistake.</span></p>
            <label class="form-field"><span class="form-label">Reason *</span>
                <textarea class="form-input" name="reason" id="dvCancelReason" required minlength="3" maxlength="255" placeholder="e.g. Check spoiled; reissued"<?= invalid('reason') ?>></textarea>
                <?= field_error('reason') ?></label>
            <footer class="modal__foot"><button type="button" class="btn btn--light" data-close>Keep It</button>
                <button type="submit" class="btn btn--danger"><?= icon('x') ?> Cancel Voucher</button></footer>
        </form>
    </dialog>
<?php endif; ?>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
