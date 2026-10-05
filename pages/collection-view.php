<?php
/**
 * One collection receipt: payment details, the bills it paid (cash / EWT / VAT withheld), certificate status, print.
 * Actions (Collections::actions, working in the branch): Cancel (collections.cancel, reason: the bills are open
 * again), 2307 Received (collections.manage, date). pages/collection-view.php?id=5[&return=collection-receipts.php]
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('collections');

$id = input_int($_GET, 'id', 1) ?? throw new HttpException(404, 'Collection not found.');
$c  = Collections::find($id) ?? throw new HttpException(404, 'Collection not found.');
$returnTo = safe_return($_POST['return'] ?? $_GET['return'] ?? null, 'collection-receipts.php');
$self     = 'collection-view.php?' . http_build_query(['id' => $id, 'return' => $returnTo]);
$label    = (string) $c['collection_no'];

if (is_post()) {
    Csrf::verifyRequest();
    $action = input_string($_POST, 'action', 10);
    try {
        if ($action === 'cancel') {
            Collections::cancel($id, input_string($_POST, 'reason', 300), (int) Auth::id());
            flash('success', "{$label} was cancelled. Its bills are open again.");
        } elseif ($action === 'form') {
            Collections::receiveForm($id, input_string($_POST, 'received_at', 10), (int) Auth::id());
            flash('success', "The withholding certificate for {$label} was recorded as received.");
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

$actions = Collections::actions($c);
$old     = has_old() ? old_input() : [];
$when    = static fn (?string $ts): string => $ts ? date('M j, Y g:i A', strtotime($ts)) : '—';
$day     = static fn (?string $d): string => $d ? date('M j, Y', strtotime($d)) : '—';
$canSale = Auth::can('sales.view');
$page['title'] = $label;
$pageStyles  = ['css/sales.css', 'css/receiving.css', 'css/stock-docs.css', 'css/transfers.css', 'css/purchasing.css'];
$pageScripts = ['js/purchasing.js'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <a class="back-link" href="<?= e(url('pages/' . $returnTo)) ?>"><?= icon('arrow-left') ?> Collection Receipts</a>
        <h1 class="sale-title">
            <span id="crTitle"><?= e($label) ?></span>
            <span class="badge <?= e(Collections::BADGES[$c['status']] ?? '') ?>" id="crStatus"><?= e(Collections::STATUSES[$c['status']] ?? $c['status']) ?></span>
        </h1>
        <p class="muted"><?= e($c['customer_name']) ?> · <?= e($day($c['collection_date'])) ?> · <?= e($c['branch_code'] . ' · ' . $c['branch_name']) ?></p>
    </div>
    <div class="page-actions">
        <a class="btn btn--light" href="<?= e(url('pages/collection-print.php?id=' . $id)) ?>" target="_blank" rel="noopener" id="crPrint"><?= icon('printer') ?> Print Receipt</a>
        <?php if ($actions['form']): ?>
            <button type="button" class="btn btn--primary" data-open="formDialog" id="crFormBtn"><?= icon('check') ?> 2307 Received</button>
        <?php endif; ?>
        <?php if ($actions['cancel']): ?>
            <button type="button" class="btn btn--danger" data-open="cancelDialog" id="crCancelBtn"><?= icon('x') ?> Cancel Receipt</button>
        <?php endif; ?>
    </div>
</div>

<?php if ($c['status'] === 'cancelled'): ?>
    <div class="void-box" role="note"><?= icon('alert') ?>
        <div><strong>This collection was cancelled</strong><?= e(' on ' . $when($c['cancelled_at']) . ($c['cancelled_by_name'] ? ' by ' . $c['cancelled_by_name'] : '')) ?>. Its bills are open again.
            <?php if ($c['cancel_reason']): ?><p class="void-box__reason">Reason: <?= e($c['cancel_reason']) ?></p><?php endif; ?></div>
    </div>
<?php elseif ($c['form_2307'] === 'pending'): ?>
    <div class="alert alert--warning doc-note" role="note" id="crFormNote"><?= icon('clock') ?>
        <span>Waiting for the customer's withholding certificate (BIR 2307<?= (float) $c['vat_withheld_total'] > 0 ? ' / 2306' : '' ?>) for <?= e(money(from_cents(to_cents($c['ewt_total']) + to_cents($c['vat_withheld_total'])))) ?> withheld.</span></div>
<?php endif; ?>

<?php $chain = DocChain::of('cr', $id); require ROOT_PATH . '/includes/doc-chain.php'; ?>

<div class="sale-layout">
    <section class="card">
        <header class="card__head"><h2><?= icon('receipt') ?> Bills paid</h2></header>
        <div class="table-wrap">
            <table class="table doc-lines" id="crLinesView">
                <thead><tr><th>Bill</th><th class="num">Cash applied</th><th class="num">EWT</th><th class="num">VAT w/h</th><th class="num">Credited</th><th class="num col-opt">Bill balance now</th></tr></thead>
                <tbody>
                <?php foreach ($c['lines'] as $l): ?>
                    <tr>
                        <td>
                            <?php if ($canSale): ?><a class="doc-no" href="<?= e(url('pages/sale-view.php?id=' . (int) $l['sale_id'])) ?>">No. <?= e($l['sale_no']) ?></a><?php else: ?><span class="doc-no">No. <?= e($l['sale_no']) ?></span><?php endif; ?>
                            <small class="muted block"><?= e($day($l['billed_at'])) ?> · total <?= e(money($l['total'])) ?><?= $l['order_no'] ? ' · ' . e($l['order_no']) : '' ?></small>
                        </td>
                        <td class="num"><?= e(money($l['amount'])) ?></td>
                        <td class="num"><?= (float) $l['ewt_amount'] > 0 ? e(money($l['ewt_amount'])) : '<span class="muted">—</span>' ?></td>
                        <td class="num"><?= (float) $l['vat_withheld'] > 0 ? e(money($l['vat_withheld'])) : '<span class="muted">—</span>' ?></td>
                        <td class="num"><?= e(money(from_cents(to_cents($l['amount']) + to_cents($l['ewt_amount']) + to_cents($l['vat_withheld'])))) ?></td>
                        <td class="num col-opt"><?= e(money(from_cents(to_cents($l['total']) - to_cents($l['settled_amount'])))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <dl class="sale-totals<?= $c['status'] === 'cancelled' ? ' is-void' : '' ?>">
            <div><dt>Cash / check received</dt><dd id="crReceived"><?= e(money($c['amount_received'])) ?></dd></div>
            <div><dt>EWT withheld (2307)</dt><dd><?= e(money($c['ewt_total'])) ?></dd></div>
            <div><dt>VAT withheld (2306)</dt><dd><?= e(money($c['vat_withheld_total'])) ?></dd></div>
            <div class="sale-totals__grand"><dt>Total credited</dt><dd id="crCreditedView"><?= e(money($c['total_credited'])) ?></dd></div>
        </dl>
    </section>

    <aside class="form-side">
        <section class="card card--pad">
            <h2 class="card__title">Payment</h2>
            <dl class="detail-list doc-details">
                <div><dt>Customer</dt><dd><?php if (Auth::can('customers.view')): ?><a href="<?= e(url('pages/customer-form.php?id=' . (int) $c['customer_id'])) ?>"><?= e($c['customer_name']) ?></a><?php else: ?><?= e($c['customer_name']) ?><?php endif; ?></dd></div>
                <div><dt>Date</dt><dd><?= e($day($c['collection_date'])) ?></dd></div>
                <div><dt>Method</dt><dd><?= e(Collections::METHODS[$c['method']] ?? $c['method']) ?></dd></div>
                <?php if ($c['reference']): ?><div><dt><?= $c['method'] === 'check' ? 'Check no.' : 'Reference' ?></dt><dd class="doc-no"><?= e($c['reference']) ?></dd></div><?php endif; ?>
                <?php if ($c['bank_name']): ?><div><dt>Bank</dt><dd><?= e($c['bank_name']) ?></dd></div><?php endif; ?>
                <?php if ($c['check_date']): ?><div><dt>Check date</dt><dd><?= e($day($c['check_date'])) ?></dd></div><?php endif; ?>
                <div><dt>2307 / 2306</dt><dd id="crForm2307"><?= e(Collections::FORM_2307[$c['form_2307']]) ?><?= $c['form_2307_received_at'] ? '<small class="muted block">' . e($day($c['form_2307_received_at'])) . ($c['form_2307_by_name'] ? ' · ' . e($c['form_2307_by_name']) : '') . '</small>' : '' ?></dd></div>
                <div><dt>Recorded by</dt><dd><?= e($c['created_by_name']) ?><small class="muted block"><?= e($when($c['created_at'])) ?></small></dd></div>
            </dl>
            <?php if ($c['notes']): ?><p class="rr-notes"><span class="form-label">Notes</span><?= e($c['notes']) ?></p><?php endif; ?>
        </section>
        <?php [$attType, $attId, $attReturn] = ['collection', $id, $self]; require ROOT_PATH . '/includes/attachments-card.php'; ?>
    </aside>
</div>

<?php if ($actions['form']): ?>
    <dialog class="modal" id="formDialog" aria-labelledby="formTitle"<?= ($old['action'] ?? '') === 'form' ? ' data-reopen' : '' ?>>
        <form class="modal__body" method="post" action="<?= e(url('pages/' . $self)) ?>">
            <header class="modal__head">
                <h2 id="formTitle">Certificate received for <?= e($label) ?></h2>
                <button type="button" class="modal__close" data-close aria-label="Close"><?= icon('x') ?></button>
            </header>
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="form">
            <input type="hidden" name="return" value="<?= e($returnTo) ?>">
            <label class="form-field">
                <span class="form-label">Date received *</span>
                <input class="form-input" type="date" name="received_at" id="crFormDate" max="<?= e(date('Y-m-d')) ?>" value="<?= e(date('Y-m-d')) ?>"<?= invalid('received_at') ?>>
                <?= field_error('received_at') ?>
            </label>
            <p class="form-hint">Keep the original BIR 2307 / 2306 with the accounting files: it is claimed as a tax credit.</p>
            <footer class="modal__foot">
                <button type="button" class="btn btn--light" data-close>Not Now</button>
                <button type="submit" class="btn btn--primary"><?= icon('check') ?> Mark Received</button>
            </footer>
        </form>
    </dialog>
<?php endif; ?>

<?php if ($actions['cancel']): ?>
    <dialog class="modal" id="cancelDialog" aria-labelledby="cancelTitle"<?= ($old['action'] ?? '') === 'cancel' ? ' data-reopen' : '' ?>>
        <form class="modal__body" method="post" action="<?= e(url('pages/' . $self)) ?>" data-confirm="Cancel <?= e($label) ?>? The bills it paid are open again.">
            <header class="modal__head">
                <h2 id="cancelTitle">Cancel <?= e($label) ?>?</h2>
                <button type="button" class="modal__close" data-close aria-label="Close"><?= icon('x') ?></button>
            </header>
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="cancel">
            <input type="hidden" name="return" value="<?= e($returnTo) ?>">
            <p class="void-warning"><?= icon('alert') ?><span>Use this for a bounced check or a payment entered by mistake. The bills are open again.</span></p>
            <label class="form-field">
                <span class="form-label">Reason *</span>
                <textarea class="form-input" name="reason" id="crCancelReason" required minlength="3" maxlength="255" placeholder="e.g. Check bounced (DAIF)"<?= invalid('reason') ?>></textarea>
                <?= field_error('reason') ?>
            </label>
            <footer class="modal__foot">
                <button type="button" class="btn btn--light" data-close>Keep It</button>
                <button type="submit" class="btn btn--danger"><?= icon('x') ?> Cancel Receipt</button>
            </footer>
        </form>
    </dialog>
<?php endif; ?>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
