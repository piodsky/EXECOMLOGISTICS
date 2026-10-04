<?php
/**
 * Printable receipt (80mm thermal layout).
 * pages/receipt.php?id=5            — view with a Print button
 * pages/receipt.php?id=5&autoprint=1 — used by the POS hidden iframe
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
Auth::requirePermission('sales.view', 'pos.access');
allow_same_origin_framing();

$id   = input_int($_GET, 'id', 1);
$sale = $id !== null ? Sales::find($id) : null; // null when outside the user's branch scope
if ($sale === null || $sale['status'] === 'held') {
    abort(404, 'Receipt not found.');
}
// Branch address/contact when filled in, else the company's.
$rcptAddress = trim((string) $sale['branch_address']) !== '' ? $sale['branch_address'] : setting('shop_address');
$rcptPhone   = trim((string) $sale['branch_contact']) !== '' ? $sale['branch_contact'] : setting('shop_phone');

$autoPrint = ($_GET['autoprint'] ?? '') === '1';
$isVoid    = $sale['status'] === 'cancelled';
$itemCount = array_sum(array_map('intval', array_column($sale['items'], 'quantity')));
$vatLabel  = rtrim(rtrim((string) $sale['vat_rate'], '0'), '.');
$discLabel = rtrim(rtrim((string) $sale['discount_percent'], '0'), '.');
$date      = new DateTimeImmutable($sale['completed_at'] ?? $sale['created_at']);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Receipt <?= e($sale['sale_no']) ?> · <?= e(setting('shop_name', 'EXECOM Logistics')) ?></title>
    <link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('css/receipt.css')) ?>">
</head>
<body class="receipt-page"<?= $autoPrint ? ' data-autoprint="1"' : '' ?>>

<div class="receipt-toolbar no-print">
    <button type="button" id="printBtn">Print receipt</button>
    <?php if (Auth::can('pos.access')): ?>
        <a href="<?= e(url('pages/pos.php')) ?>">Back to POS</a>
    <?php endif; ?>
</div>

<article class="receipt">
    <header class="receipt__head">
        <h1><?= e(setting('shop_name', 'EXECOM Logistics')) ?></h1>
        <p class="receipt__branch" id="receiptBranch"><?= e($sale['branch_name']) ?> Branch</p>
        <p><?= e($rcptAddress) ?></p>
        <p>Tel: <?= e($rcptPhone) ?></p>
        <?php if (setting('shop_tin') !== ''): ?>
            <p>VAT Reg TIN: <?= e(setting('shop_tin')) ?></p>
        <?php endif; ?>
    </header>

    <?php if ($isVoid): ?>
        <p class="void-stamp">VOID</p>
        <?php if ($sale['voided_at']): ?>
            <p class="void-note">Voided <?= e(date('M j, Y g:i A', strtotime($sale['voided_at']))) ?><?= $sale['void_reason'] ? ': ' . e($sale['void_reason']) : '' ?></p>
        <?php endif; ?>
    <?php endif; ?>

    <hr>
    <dl class="receipt__meta">
        <div><dt>Sale No.</dt><dd><?= e($sale['sale_no']) ?></dd></div>
        <div><dt>Date</dt><dd><?= e($date->format('M j, Y g:i A')) ?></dd></div>
        <div><dt>Cashier</dt><dd><?= e($sale['cashier_name']) ?></dd></div>
        <div><dt>Customer</dt><dd><?= e($sale['customer_name']) ?></dd></div>
        <?php if ($sale['job_no'] !== null): ?><div><dt>Job Order</dt><dd id="receiptJob"><?= e($sale['job_no']) ?></dd></div><?php endif; ?>
    </dl>
    <hr>

    <div class="receipt__items">
        <?php foreach ($sale['items'] as $item): ?>
            <div class="item">
                <div class="item__name"><?= e($item['product_name']) ?></div>
                <div class="row">
                    <span><?= (int) $item['quantity'] ?> x <?= e(number_format((float) $item['unit_price'], 2)) ?></span>
                    <span><?= e(number_format((float) $item['line_total'], 2)) ?></span>
                </div>
                <?php if (!empty($item['serials'])): ?>
                    <div class="item__sn">S/N: <?= e(implode(', ', $item['serials'])) ?></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <hr>

    <div class="receipt__totals">
        <div class="row"><span>Items</span><span><?= (int) $itemCount ?></span></div>
        <div class="row"><span>Subtotal</span><span><?= e(number_format((float) $sale['subtotal'], 2)) ?></span></div>
        <?php if ((float) $sale['discount_amount'] > 0): ?>
            <div class="row"><span>Discount (<?= e($discLabel) ?>%)</span><span>-<?= e(number_format((float) $sale['discount_amount'], 2)) ?></span></div>
        <?php endif; ?>
        <div class="row"><span>VAT (<?= e($vatLabel) ?>%)</span><span><?= e(number_format((float) $sale['vat_amount'], 2)) ?></span></div>
        <div class="row grand"><span>TOTAL</span><span><?= e(money($sale['total'])) ?></span></div>
        <hr>
        <div class="row"><span><?= e(Sales::PAYMENT_TYPES[$sale['payment_type']] ?? $sale['payment_type']) ?></span><span><?= e(number_format((float) $sale['amount_paid'], 2)) ?></span></div>
        <div class="row"><span>Change</span><span><?= e(number_format((float) $sale['change_amount'], 2)) ?></span></div>
    </div>
    <hr>

    <footer class="receipt__foot">
        <p><?= e(setting('receipt_footer', 'Thank you!')) ?></p>
        <p class="small">Printed <?= e(date('M j, Y g:i A')) ?></p>
    </footer>
</article>

<script src="<?= e(asset('js/receipt.js')) ?>" defer></script>
</body>
</html>
