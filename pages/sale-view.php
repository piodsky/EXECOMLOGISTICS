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
        flash('success', $sale['job_no'] !== null
            ? "Sale No. {$saleNo} was voided. Job order {$sale['job_no']} is back to Completed (not released); the parts stay installed."
            : sprintf('Sale No. %s was voided. %d %s returned to stock.', $saleNo, $units, $units === 1 ? 'item was' : 'items were'));
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

// Cost / margin only with products.cost (Sales::find never carries cost). Null cost = sold before costing.
$costs    = Auth::can('products.cost') ? Sales::costs($id) : null;
$showCost = $costs !== null;
$lineCost = static function (array $item) use ($costs): ?int { // cents
    $unit = $costs['items'][(int) $item['id']] ?? null;
    return $unit === null ? null : Costing::lineCents((int) $item['quantity'], $unit);
};

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
                <tr><th>#</th><th>Item</th><th class="num">Unit Price</th><th class="num">Qty</th><th class="num">Total</th>
                    <?php if ($showCost): ?><th class="num">Cost</th><th class="num" title="Line total minus cost, before discount and VAT">Margin</th><?php endif; ?></tr>
                </thead>
                <tbody>
                <?php foreach ($sale['items'] as $i => $item): ?>
                    <tr>
                        <td class="muted"><?= $i + 1 ?></td>
                        <td>
                            <strong class="block"><?= e($item['product_name']) ?></strong>
                            <small class="muted"><?= e($item['product_code']) ?></small>
                            <?php if ($item['price_reason'] !== null || $item['price_approved_by_name'] !== null): ?>
                                <small class="price-note block"><?= e(trim(($item['price_reason'] !== null ? 'Price: ' . $item['price_reason'] : 'Price') . ($item['price_approved_by_name'] !== null ? ' · approved by ' . $item['price_approved_by_name'] : ''))) ?></small>
                            <?php endif; ?>
                            <?php if (!empty($item['serials'])): ?>
                                <ul class="sn-list" aria-label="Serial numbers">
                                    <?php foreach ($item['serials'] as $sn): ?><li>S/N <?= e($sn) ?></li><?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </td>
                        <td class="num">
                            <?= e(money($item['unit_price'])) ?>
                            <?php if ($item['suggested_price'] !== null && to_cents($item['suggested_price']) !== to_cents($item['unit_price'])): ?>
                                <small class="price-was block" title="Suggested price"><?= e(money($item['suggested_price'])) ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="num"><?= (int) $item['quantity'] ?></td>
                        <td class="num"><?= e(money($item['line_total'])) ?></td>
                        <?php if ($showCost): ?>
                            <?php $c = $lineCost($item); ?>
                            <td class="num cost-cell"><?= $c === null ? '—' : e(money(from_cents($c))) ?></td>
                            <td class="num cost-cell"><?= $c === null ? '—' : e(money(from_cents(to_cents($item['line_total']) - $c))) ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <dl class="sale-totals<?= $isVoid ? ' is-void' : '' ?>">
            <div><dt>Sub Total</dt><dd><?= e(money($sale['subtotal'])) ?></dd></div>
            <?php if ((float) $sale['discount_amount'] > 0): ?>
                <div><dt>Discount (<?= e($discLabel) ?>%)<?= $sale['discount_approved_by_name'] !== null ? ' <small>approved by ' . e($sale['discount_approved_by_name']) . '</small>' : '' ?></dt><dd>− <?= e(money($sale['discount_amount'])) ?></dd></div>
            <?php endif; ?>
            <div><dt>VAT (<?= e($vatLabel) ?>%)</dt><dd><?= e(money($sale['vat_amount'])) ?></dd></div>
            <div class="sale-totals__grand"><dt>Total Amount</dt><dd id="saleTotal"><?= e(money($sale['total'])) ?></dd></div>
            <?php if ($showCost): ?>
                <?php $costTotal = $costs['cost_total']; ?>
                <div class="sale-totals__cost"><dt>Cost of items</dt><dd id="saleCost"><?= $costTotal === null ? '—' : e(money($costTotal)) ?></dd></div>
                <div class="sale-totals__cost"><dt>Gross margin <small>(after discount, before VAT)</small></dt>
                    <dd><?= $costTotal === null ? '—' : e(money(from_cents(to_cents($sale['subtotal']) - to_cents($sale['discount_amount']) - to_cents($costTotal)))) ?></dd></div>
            <?php endif; ?>
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
                <?php if ($sale['job_no'] !== null): ?>
                    <div><dt>Job order</dt><dd id="saleJob"><?php if (Auth::canAny(...JobOrders::VIEW_PERMISSIONS)): ?><a href="<?= e(url('pages/job-view.php?id=' . (int) $sale['job_order_id'])) ?>"><?= e($sale['job_no']) ?></a><?php else: ?><?= e($sale['job_no']) ?><?php endif; ?>
                        <small class="muted block">Parts were taken from stock when issued to the job.</small></dd></div>
                <?php endif; ?>
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
