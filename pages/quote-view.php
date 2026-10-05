<?php
/**
 * One quotation: lines, customer / RFQ details, print. Actions follow Quotations::actions() (customer_orders.manage,
 * working in the branch):
 *   draft -> Edit, Mark as Sent        sent -> Create Customer PO (co-form.php?quote=ID), Revise (back to draft), Lost (reason)
 *   draft / sent -> Cancel (reason)    won -> link to the customer order
 * pages/quote-view.php?id=5&return=quotations.php
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('customer-orders');

$id = input_int($_GET, 'id', 1) ?? throw new HttpException(404, 'Quotation not found.');
$q  = Quotations::find($id) ?? throw new HttpException(404, 'Quotation not found.');

$returnTo = safe_return($_POST['return'] ?? $_GET['return'] ?? null, 'quotations.php');
$self     = 'quote-view.php?' . http_build_query(['id' => $id, 'return' => $returnTo]);
$label    = (string) $q['quote_no'];

if (is_post()) {
    Csrf::verifyRequest();
    $action = input_string($_POST, 'action', 10);
    $uid    = (int) Auth::id();
    try {
        switch ($action) {
            case 'send':
                Quotations::send($id, $uid);
                flash('success', "{$label} was marked as sent.");
                break;
            case 'revise':
                Quotations::revise($id, $uid);
                flash('success', "{$label} is a draft again: edit it, then mark it as sent.");
                break;
            case 'lost':
            case 'cancel':
                Quotations::close($id, $action === 'lost' ? 'lost' : 'cancelled', input_string($_POST, 'reason', 300), $uid);
                flash('success', $action === 'lost' ? "{$label} was marked as lost." : "{$label} was cancelled.");
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

$status  = $q['status'];
$actions = Quotations::actions($q);
$page['title'] = $label;
$old     = has_old() ? old_input() : [];
$when    = static fn (?string $ts): string => $ts ? date('M j, Y g:i A', strtotime($ts)) : '—';
$day     = static fn (?string $d): string => $d ? date('M j, Y', strtotime($d)) : '—';
$vatRate = (float) setting('vat_rate', '12');
$subCents = to_cents($q['subtotal']);
$vatCents = (int) round($subCents * $vatRate / 100);

$pageStyles  = ['css/sales.css', 'css/receiving.css', 'css/stock-docs.css', 'css/transfers.css', 'css/purchasing.css'];
$pageScripts = ['js/purchasing.js'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <a class="back-link" href="<?= e(url('pages/' . $returnTo)) ?>"><?= icon('arrow-left') ?> Quotations</a>
        <h1 class="sale-title">
            <span id="quoteTitle"><?= e($label) ?></span>
            <span class="badge <?= e(Quotations::BADGES[$status] ?? '') ?>" id="quoteStatus"><?= e(Quotations::STATUSES[$status] ?? $status) ?></span>
        </h1>
        <p class="muted"><?= e($q['customer_name']) ?><?= $q['rfq_no'] ? ' · RFQ ' . e($q['rfq_no']) : '' ?> · <?= e($q['branch_code'] . ' · ' . $q['branch_name']) ?></p>
    </div>
    <div class="page-actions">
        <a class="btn btn--light" href="<?= e(url('pages/quote-print.php?id=' . $id)) ?>" target="_blank" rel="noopener" id="quotePrint"><?= icon('printer') ?> Print</a>
        <?php if ($actions['edit']): ?>
            <a class="btn btn--light" href="<?= e(url('pages/quote-form.php?' . http_build_query(['id' => $id, 'return' => $returnTo]))) ?>" id="quoteEdit"><?= icon('edit') ?> Edit</a>
        <?php endif; ?>
        <?php if ($actions['revise']): ?>
            <form method="post" action="<?= e(url('pages/' . $self)) ?>" class="inline-form" data-confirm="Put <?= e($label) ?> back to draft to change it?">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="revise">
                <input type="hidden" name="return" value="<?= e($returnTo) ?>">
                <button type="submit" class="btn btn--light" id="quoteRevise"><?= icon('edit') ?> Revise</button>
            </form>
        <?php endif; ?>
        <?php if ($actions['lost']): ?>
            <button type="button" class="btn btn--light" data-open="lostDialog" id="quoteLostBtn"><?= icon('trend-down') ?> Lost</button>
        <?php endif; ?>
        <?php if ($actions['cancel']): ?>
            <button type="button" class="btn btn--danger" data-open="cancelDialog" id="quoteCancelBtn"><?= icon('x') ?> Cancel</button>
        <?php endif; ?>
        <?php if ($actions['send']): ?>
            <form method="post" action="<?= e(url('pages/' . $self)) ?>" class="inline-form" data-confirm="Mark <?= e($label) ?> as sent to the customer?">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="send">
                <input type="hidden" name="return" value="<?= e($returnTo) ?>">
                <button type="submit" class="btn btn--primary" id="quoteSend"><?= icon('check') ?> Mark as Sent</button>
            </form>
        <?php endif; ?>
        <?php if ($actions['order']): ?>
            <a class="btn btn--primary" href="<?= e(url('pages/co-form.php?' . http_build_query(['quote' => $id, 'return' => 'customer-orders.php']))) ?>" id="quoteOrder"><?= icon('file') ?> Create Customer PO</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($status === 'won'): ?>
    <div class="alert alert--success doc-note" role="note" id="quoteWonNote"><?= icon('check') ?>
        <span>Won: the customer PO is <a href="<?= e(url('pages/co-view.php?id=' . (int) $q['order_id'])) ?>"><?= e($q['order_no'] ?? 'Draft Order #' . $q['order_id']) ?></a>.</span></div>
<?php elseif ($status === 'lost' || $status === 'cancelled'): ?>
    <div class="void-box" role="note"><?= icon('alert') ?>
        <div><strong>This quotation was <?= $status === 'lost' ? 'lost' : 'cancelled' ?></strong><?= e(($q['closed_at'] ? ' on ' . $when($q['closed_at']) : '') . ($q['closed_by_name'] ? ' by ' . $q['closed_by_name'] : '')) ?>.
            <?php if ($q['close_reason']): ?><p class="void-box__reason">Reason: <?= e($q['close_reason']) ?></p><?php endif; ?></div>
    </div>
<?php elseif ($q['expired']): ?>
    <div class="alert alert--warning doc-note" role="note"><?= icon('clock') ?>
        <span>Expired on <?= e($day($q['valid_until'])) ?>. Revise it with new prices and a new date, or mark it as lost.</span></div>
<?php elseif ($status === 'sent'): ?>
    <div class="alert alert--info doc-note" role="note"><?= icon('info') ?>
        <span>Waiting for the customer. When they award it, choose "Create Customer PO" and enter their PO number.</span></div>
<?php else: ?>
    <div class="alert alert--info doc-note" role="note"><?= icon('info') ?><span>Draft: print it or mark it as sent when it goes to the customer.</span></div>
<?php endif; ?>

<?php $chain = DocChain::of('qt', $id); require ROOT_PATH . '/includes/doc-chain.php'; ?>

<div class="sale-layout">
    <section class="card">
        <header class="card__head"><h2><?= icon('box') ?> Items</h2></header>
        <div class="table-wrap">
            <table class="table doc-lines bt-lines" id="quoteLines">
                <thead>
                <tr><th>#</th><th>Item</th><th class="num">Qty</th><th class="num">Unit Price</th><th class="num">Total</th></tr>
                </thead>
                <tbody>
                <?php foreach ($q['lines'] as $i => $l): ?>
                    <tr>
                        <td class="muted"><?= $i + 1 ?></td>
                        <td>
                            <strong class="block"><?= e($l['product_name']) ?></strong>
                            <small class="muted"><?= e($l['product_code']) ?><?= $l['brand_name'] ? ' · ' . e($l['brand_name']) : '' ?></small>
                            <?php if ($l['price_reason'] !== null): ?>
                                <small class="muted block">Suggested <s><?= e(money($l['suggested_price'])) ?></s> · <?= e($l['price_reason']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="num"><?= number_format((int) $l['quantity']) ?> <small class="muted"><?= e($l['unit_code'] ?? '') ?></small></td>
                        <td class="num"><?= e(money($l['unit_price'])) ?></td>
                        <td class="num"><?= e(money($l['line_total'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <dl class="sale-totals<?= $status === 'cancelled' ? ' is-void' : '' ?>">
            <div><dt>Subtotal</dt><dd><?= e(money($q['subtotal'])) ?></dd></div>
            <div><dt>VAT (<?= e(rtrim(rtrim(number_format($vatRate, 2), '0'), '.')) ?>%)</dt><dd><?= e(money(from_cents($vatCents))) ?></dd></div>
            <div class="sale-totals__grand"><dt>Total</dt><dd id="quoteTotal"><?= e(money(from_cents($subCents + $vatCents))) ?></dd></div>
        </dl>
    </section>

    <aside class="form-side">
        <section class="card card--pad">
            <h2 class="card__title">Quotation</h2>
            <dl class="detail-list doc-details">
                <div><dt>Customer</dt><dd>
                    <?php if (Auth::can('customers.view')): ?><a href="<?= e(url('pages/customer-form.php?id=' . (int) $q['customer_id'])) ?>"><?= e($q['customer_name']) ?></a><?php else: ?><?= e($q['customer_name']) ?><?php endif; ?>
                    <?php if ($q['customer_type']): ?><small class="muted block"><?= e($q['customer_type']) ?></small><?php endif; ?>
                </dd></div>
                <?php if ($q['attention']): ?><div><dt>Attention</dt><dd><?= e($q['attention']) ?></dd></div><?php endif; ?>
                <?php if ($q['end_user']): ?><div><dt>End-user</dt><dd><?= e($q['end_user']) ?></dd></div><?php endif; ?>
                <div><dt>RFQ</dt><dd><?= e($q['rfq_no'] ?? '—') ?><?= $q['rfq_date'] ? '<small class="muted block">' . e($day($q['rfq_date'])) . '</small>' : '' ?></dd></div>
                <div><dt>Date</dt><dd><?= e($day($q['quote_date'])) ?></dd></div>
                <div><dt>Valid until</dt><dd class="<?= $q['expired'] ? 'text-danger' : '' ?>"><?= e($day($q['valid_until'])) ?></dd></div>
                <?php if ($q['delivery_term']): ?><div><dt>Delivery</dt><dd><?= e($q['delivery_term']) ?></dd></div><?php endif; ?>
                <?php if ($q['payment_term']): ?><div><dt>Payment</dt><dd><?= e($q['payment_term']) ?></dd></div><?php endif; ?>
                <?php if ($q['warranty']): ?><div><dt>Warranty</dt><dd><?= e($q['warranty']) ?></dd></div><?php endif; ?>
                <div><dt>Prepared by</dt><dd><?= e($q['created_by_name']) ?><small class="muted block"><?= e($when($q['created_at'])) ?></small></dd></div>
                <?php if ($q['sent_at']): ?><div><dt>Sent</dt><dd><?= e($q['sent_by_name'] ?? '—') ?><small class="muted block"><?= e($when($q['sent_at'])) ?></small></dd></div><?php endif; ?>
            </dl>
            <?php if ($q['notes']): ?><p class="rr-notes"><span class="form-label">Notes</span><?= e($q['notes']) ?></p><?php endif; ?>
        </section>
    </aside>
</div>

<?php foreach (['lost' => ['lostDialog', 'Mark as lost', 'The customer chose another supplier or did not push through.', 'e.g. Awarded to a lower bidder', 'Lost'],
                'cancel' => ['cancelDialog', 'Cancel', 'The quotation is withdrawn.', 'e.g. Prices changed; replaced by a new quotation', 'Cancel Quotation']] as $act => [$dlgId, $verb, $warn, $hint, $btn]): ?>
    <?php if (!$actions[$act]) continue; ?>
    <dialog class="modal" id="<?= e($dlgId) ?>" aria-labelledby="<?= e($dlgId) ?>Title"<?= ($old['action'] ?? '') === $act ? ' data-reopen' : '' ?>>
        <form class="modal__body" method="post" action="<?= e(url('pages/' . $self)) ?>">
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
                <button type="button" class="btn btn--light" data-close>Keep It</button>
                <button type="submit" class="btn btn--danger"><?= icon('x') ?> <?= e($btn) ?></button>
            </footer>
        </form>
    </dialog>
<?php endforeach; ?>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
