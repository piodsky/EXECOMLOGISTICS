<?php
/**
 * Billing statement (A4) for the bill of a customer order: bill to (customer, TIN, address), customer PO, delivery
 * receipts, items at the order prices, subtotal / VAT / total, amount paid, balance due (on account). Sales of
 * customer orders only. Access: sales.view or any customer orders permission (Sales::find keeps the branch scope).
 * pages/bill-print.php?id=<sale id>
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
Auth::requirePermission('sales.view', ...CustomerOrders::VIEW_PERMISSIONS);
$id   = input_int($_GET, 'id', 1);
$sale = $id !== null ? Sales::find($id) : null;
if ($sale === null || $sale['customer_order_id'] === null) {
    abort(404, 'Bill not found.');
}
$stmt = db()->prepare(
    'SELECT o.customer_name, o.customer_address, o.customer_po_no, o.customer_po_date, o.payment_term, o.end_user, c.tin
       FROM customer_orders o JOIN customers c ON c.id = o.customer_id WHERE o.id = ?'
);
$stmt->execute([(int) $sale['customer_order_id']]);
$o = $stmt->fetch();
$stmt = db()->prepare('SELECT dr_no FROM customer_deliveries WHERE sale_id = ? ORDER BY id');
$stmt->execute([$id]);
$drs   = $stmt->fetchAll(PDO::FETCH_COLUMN);
$isVoid = $sale['status'] === 'cancelled';
$due    = $sale['payment_type'] === 'charge' && !$isVoid ? (float) $sale['total'] - (float) $sale['amount_paid'] : 0.0;
$vat    = rtrim(rtrim((string) $sale['vat_rate'], '0'), '.');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Billing No. <?= e($sale['sale_no']) ?> · Billing Statement</title>
    <link rel="icon" href="<?= e(asset('img/favicon.png')) ?>" type="image/png">
    <link rel="stylesheet" href="<?= e(asset('css/print-doc.css')) ?>">
</head>
<body class="doc-page">

<div class="doc-toolbar no-print">
    <button type="button" id="printBtn">Print / Save as PDF</button>
    <?php if (Auth::canAny(...CustomerOrders::VIEW_PERMISSIONS)): ?>
        <a href="<?= e(url('pages/co-view.php?id=' . (int) $sale['customer_order_id'])) ?>">Back to <?= e((string) $sale['order_no']) ?></a>
    <?php endif; ?>
</div>

<article class="doc" id="billDoc">
    <?php require ROOT_PATH . '/includes/letterhead.php'; ?>

    <h1 class="doc__title">Billing Statement</h1>
    <?php if ($isVoid): ?><p class="doc__stamp">VOID</p><?php endif; ?>

    <div class="doc__meta">
        <dl>
            <div><dt>Bill to</dt><dd class="doc__strong"><?= e($o['customer_name']) ?></dd></div>
            <?php if ($o['end_user']): ?><div><dt>End-user</dt><dd><?= e($o['end_user']) ?></dd></div><?php endif; ?>
            <div><dt>Address</dt><dd><?= e($o['customer_address'] ?? '—') ?></dd></div>
            <?php if ($o['tin']): ?><div><dt>TIN</dt><dd><?= e($o['tin']) ?></dd></div><?php endif; ?>
            <div><dt>Your PO</dt><dd class="doc__strong"><?= e($o['customer_po_no']) ?><?= $o['customer_po_date'] ? ' · ' . e(date('m/d/Y', strtotime($o['customer_po_date']))) : '' ?></dd></div>
        </dl>
        <dl>
            <div><dt>Bill No.</dt><dd class="doc__no" id="billNo"><?= e($sale['sale_no']) ?></dd></div>
            <div><dt>Date</dt><dd><?= e(date('m/d/Y', strtotime($sale['completed_at'] ?? $sale['created_at']))) ?></dd></div>
            <div><dt>Our ref.</dt><dd><?= e((string) $sale['order_no']) ?></dd></div>
            <div><dt>D.R.</dt><dd><?= e(implode(', ', $drs) ?: '—') ?></dd></div>
            <?php if ($o['payment_term']): ?><div><dt>Terms</dt><dd><?= e($o['payment_term']) ?></dd></div><?php endif; ?>
        </dl>
    </div>

    <table class="doc__table">
        <thead>
        <tr><th class="c">Item No.</th><th class="num">Qty</th><th class="w">Description</th><th class="num">Unit Price</th><th class="num">Amount</th></tr>
        </thead>
        <tbody>
        <?php foreach ($sale['items'] as $i => $it): ?>
            <tr>
                <td class="c"><?= $i + 1 ?></td>
                <td class="num"><?= number_format((int) $it['quantity']) ?></td>
                <td class="w"><strong><?= e($it['product_name']) ?></strong><small><?= e($it['product_code']) ?><?= !empty($it['serials']) ? ' · S/N: ' . e(implode(', ', $it['serials'])) : '' ?></small></td>
                <td class="num"><?= e(number_format((float) $it['unit_price'], 2)) ?></td>
                <td class="num"><?= e(number_format((float) $it['line_total'], 2)) ?></td>
            </tr>
        <?php endforeach; ?>
        <tr class="doc__nf"><td colspan="5">*** Nothing follows ***</td></tr>
        </tbody>
        <tfoot>
        <tr class="doc__sum"><td colspan="3"></td><th class="num">Subtotal</th><td class="num"><?= e(number_format((float) $sale['subtotal'], 2)) ?></td></tr>
        <tr class="doc__sum"><td colspan="3"></td><th class="num">VAT <?= e($vat) ?>%</th><td class="num"><?= e(number_format((float) $sale['vat_amount'], 2)) ?></td></tr>
        <tr class="doc__total"><td colspan="3"></td><th class="num">TOTAL</th><td class="num" id="billTotal"><?= e(money($sale['total'])) ?></td></tr>
        </tfoot>
    </table>

    <div class="doc__info">
        <p><span>Payment:</span> <?= e(Sales::ALL_PAYMENT_TYPES[$sale['payment_type']] ?? $sale['payment_type']) ?><?= $sale['payment_type'] !== 'charge' ? ' · ' . e(money($sale['amount_paid'])) . ' received' : '' ?></p>
        <?php if ($due > 0): ?><p id="billDue"><span>Amount due:</span> <?= e(money($due)) ?></p><?php endif; ?>
    </div>

    <div class="doc__signs">
        <div><span>Prepared By:</span><strong><?= e($sale['cashier_name']) ?></strong><small>Signature over printed name</small></div>
        <div><span>Received By:</span><strong></strong><small>Printed name, signature &amp; date</small></div>
    </div>

    <footer class="doc__foot">
        <span><?= e(setting('shop_name', 'EXECOM')) ?> · Billing No. <?= e($sale['sale_no']) ?> · not an official receipt</span>
        <span>Printed <?= e(date('M j, Y g:i A')) ?></span>
    </footer>
</article>

<script src="<?= e(asset('js/receipt.js')) ?>" defer></script>
</body>
</html>
