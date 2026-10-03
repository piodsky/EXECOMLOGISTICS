<?php
/**
 * One sale: items, totals, reprint, and Void (sales.cancel). Sales outside the branch scope are 404.
 * pages/sale-view.php?id=5&return=sales-history.php%3Fpage%3D2
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('sales-history');

$id   = input_int($_GET, 'id', 1) ?? throw new HttpException(404, 'Sale not found.');
$sale = Sales::find($id);
if ($sale === null || $sale['status'] === 'held') {
    throw new HttpException(404, 'Sale not found.');
}
$canVoid  = Auth::can('sales.cancel');
$returnTo = safe_return($_POST['return'] ?? $_GET['return'] ?? null, 'sales-history.php');
$self     = 'sale-view.php?' . http_build_query(['id' => $id, 'return' => $returnTo]);

// ---------------------------------------------------------------------
// Void (POST → redirect back here)
// ---------------------------------------------------------------------
if (is_post()) {
    Csrf::verifyRequest();
    if (!$canVoid) {
        abort(403, 'You do not have permission to void sales.');
    }
    try {
        if (input_string($_POST, 'action', 20) !== 'void') {
            throw new HttpException(400, 'Unknown action.');
        }
        [$saleNo, $units] = Sales::void($id, (int) Auth::id(), input_string($_POST, 'reason', 255));
        flash('success', sprintf('Sale No. %s was voided. %d %s returned to stock.', $saleNo, $units, $units === 1 ? 'item was' : 'items were'));
    } catch (HttpException $e) {
        flash('error', $e->getMessage());
    }
    redirect('pages/' . $self);
}

$page['title'] = 'Sale No. ' . $sale['sale_no'];
$isVoid    = $sale['status'] === 'cancelled';
$itemCount = array_sum(array_map('intval', array_column($sale['items'], 'quantity')));
$vatLabel  = rtrim(rtrim((string) $sale['vat_rate'], '0'), '.');
$discLabel = rtrim(rtrim((string) $sale['discount_percent'], '0'), '.');
$date      = new DateTimeImmutable($sale['completed_at'] ?? $sale['created_at']);
$printUrl  = url('pages/receipt.php?id=' . $id . '&autoprint=1');

$pageStyles = ['css/sales.css'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <a class="back-link" href="<?= e(url('pages/' . $returnTo)) ?>"><?= icon('arrow-left') ?> Sales History</a>
        <h1 class="sale-title">
            Sale No. <?= e($sale['sale_no']) ?>
            <span class="badge <?= $isVoid ? 'badge--danger' : 'badge--success' ?>"><?= e(Sales::STATUSES[$sale['status']] ?? $sale['status']) ?></span>
        </h1>
        <p class="muted"><?= e($date->format('l, M j, Y · g:i A')) ?></p>
    </div>
    <div class="page-actions">
        <a class="btn btn--light" href="<?= e($printUrl) ?>" target="_blank" rel="noopener" id="reprintBtn">
            <?= icon('printer') ?> Reprint Receipt
        </a>
        <?php if ($canVoid && !$isVoid): ?>
            <button type="button" class="btn btn--danger" data-open="voidDialog" id="voidBtn"><?= icon('x') ?> Void Sale</button>
        <?php endif; ?>
    </div>
</div>

<?php if ($isVoid): ?>
    <div class="void-box" role="note">
        <?= icon('alert') ?>
        <div>
            <?php
            $voidLine = ($sale['voided_at'] ? ' on ' . date('M j, Y g:i A', strtotime($sale['voided_at'])) : '')
                . ($sale['voided_by_name'] ? ' by ' . $sale['voided_by_name'] : '');
            ?>
            <strong>This sale was voided</strong><?= e($voidLine) ?>. All items were returned to stock.
            <?php if ($sale['void_reason']): ?>
                <p class="void-box__reason">Reason: <?= e($sale['void_reason']) ?></p>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<div class="sale-layout">
    <section class="card">
        <header class="card__head">
            <h2><?= icon('cart') ?> Items</h2>
            <span class="muted"><?= (int) $itemCount ?> <?= $itemCount === 1 ? 'item' : 'items' ?></span>
        </header>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr><th>#</th><th>Item</th><th class="num">Unit Price</th><th class="num">Qty</th><th class="num">Total</th></tr>
                </thead>
                <tbody>
                <?php foreach ($sale['items'] as $i => $item): ?>
                    <tr>
                        <td class="muted"><?= $i + 1 ?></td>
                        <td>
                            <strong class="block"><?= e($item['product_name']) ?></strong>
                            <small class="muted"><?= e($item['product_code']) ?></small>
                        </td>
                        <td class="num"><?= e(money($item['unit_price'])) ?></td>
                        <td class="num"><?= (int) $item['quantity'] ?></td>
                        <td class="num"><?= e(money($item['line_total'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <dl class="sale-totals<?= $isVoid ? ' is-void' : '' ?>">
            <div><dt>Sub Total</dt><dd><?= e(money($sale['subtotal'])) ?></dd></div>
            <?php if ((float) $sale['discount_amount'] > 0): ?>
                <div><dt>Discount (<?= e($discLabel) ?>%)</dt><dd>− <?= e(money($sale['discount_amount'])) ?></dd></div>
            <?php endif; ?>
            <div><dt>VAT (<?= e($vatLabel) ?>%)</dt><dd><?= e(money($sale['vat_amount'])) ?></dd></div>
            <div class="sale-totals__grand"><dt>Total Amount</dt><dd id="saleTotal"><?= e(money($sale['total'])) ?></dd></div>
        </dl>
    </section>

    <aside class="form-side">
        <section class="card card--pad">
            <h2 class="card__title">Details</h2>
            <dl class="detail-list">
                <div><dt>Customer</dt><dd>
                    <?php if ($sale['customer_id'] !== null && Auth::can('customers.view')): ?>
                        <a href="<?= e(url('pages/customer-form.php?id=' . (int) $sale['customer_id'])) ?>"><?= e($sale['customer_name']) ?></a>
                    <?php else: ?>
                        <?= e($sale['customer_name']) ?>
                    <?php endif; ?>
                </dd></div>
                <div><dt>Branch</dt><dd id="saleBranch"><?= e($sale['branch_code'] . ' · ' . $sale['branch_name']) ?></dd></div>
                <div><dt>Cashier</dt><dd><?= e($sale['cashier_name']) ?></dd></div>
                <div><dt>Payment</dt><dd><span class="badge"><?= e(Sales::PAYMENT_TYPES[$sale['payment_type']] ?? $sale['payment_type']) ?></span></dd></div>
                <div><dt>Amount Paid</dt><dd><?= e(money($sale['amount_paid'])) ?></dd></div>
                <div><dt>Change</dt><dd><?= e(money($sale['change_amount'])) ?></dd></div>
            </dl>
        </section>
    </aside>
</div>

<?php if ($canVoid && !$isVoid): ?>
    <dialog class="modal" id="voidDialog" aria-labelledby="voidTitle">
        <form class="modal__body" method="post" action="<?= e(url('pages/' . $self)) ?>">
            <header class="modal__head">
                <h2 id="voidTitle">Void Sale No. <?= e($sale['sale_no']) ?>?</h2>
                <button type="button" class="modal__close" data-close aria-label="Close"><?= icon('x') ?></button>
            </header>
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="void">
            <input type="hidden" name="return" value="<?= e($returnTo) ?>">
            <p class="void-warning">
                <?= icon('alert') ?>
                <span>The sale total of <strong><?= e(money($sale['total'])) ?></strong> is removed from sales and all
                    <?= (int) $itemCount ?> <?= $itemCount === 1 ? 'item goes' : 'items go' ?> back to stock. This cannot be undone.</span>
            </p>
            <label class="form-field">
                <span class="form-label">Reason *</span>
                <textarea class="form-input" name="reason" id="voidReason" required minlength="3" maxlength="255"
                          placeholder="e.g. Customer returned the items, wrong item scanned"></textarea>
            </label>
            <footer class="modal__foot">
                <button type="button" class="btn btn--light" data-close>Keep Sale</button>
                <button type="submit" class="btn btn--danger"><?= icon('x') ?> Void Sale</button>
            </footer>
        </form>
    </dialog>
<?php endif; ?>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
