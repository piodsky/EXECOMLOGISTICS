<?php
/**
 * Printable purchase order (A4 / Letter): EXECOM's PO form (letterhead, PO no. / date, supplier, Item No. /
 * End-User / Qty / Unit / Items / Unit Cost / Total Amount, TOTAL, "nothing follows", contact / ship-to /
 * forwarder box, Prepared / Approved / Received By). Drafts and POs waiting for approval print with a
 * "NOT YET APPROVED" stamp. Same access as po-view.php. pages/po-print.php?id=5
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
require_page('purchasing');
if (!PurchaseOrders::canView()) {
    abort(403, 'You do not have permission to view purchase orders.');
}
$id = input_int($_GET, 'id', 1);
$po = $id !== null ? PurchaseOrders::find($id) : null;
if ($po === null) {
    abort(404, 'Purchase order not found.');
}
$label     = PurchaseOrders::label($po);
$valid     = !in_array($po['status'], ['draft', 'pending', 'cancelled'], true);
$costText  = static function (string $v): string {
    $s = preg_match('/^(\d+)\.(\d{2})(\d*)$/', $v, $m) ? $m[2] . rtrim($m[3], '0') : '00';
    return number_format((float) $v, strlen($s));
};
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($label) ?> · Purchase Order</title>
    <link rel="icon" href="<?= e(asset('img/favicon.png')) ?>" type="image/png">
    <link rel="stylesheet" href="<?= e(asset('css/print-doc.css')) ?>">
</head>
<body class="doc-page">

<div class="doc-toolbar no-print">
    <button type="button" id="printBtn">Print / Save as PDF</button>
    <a href="<?= e(url('pages/po-view.php?id=' . (int) $po['id'])) ?>">Back to <?= e($label) ?></a>
</div>

<article class="doc" id="poDoc">
    <?php require ROOT_PATH . '/includes/letterhead.php'; ?>

    <h1 class="doc__title">Purchase Order</h1>
    <?php if (!$valid): ?>
        <p class="doc__stamp" id="poStamp"><?= $po['status'] === 'cancelled' ? 'CANCELLED' : 'NOT YET APPROVED' ?></p>
    <?php endif; ?>

    <div class="doc__meta">
        <dl>
            <div><dt>P.O. No.</dt><dd class="doc__no" id="poNo"><?= e($po['po_no'] ?? 'Draft #' . $po['id']) ?></dd></div>
            <div><dt>Supplier</dt><dd class="doc__strong"><?= e($po['supplier_name']) ?></dd></div>
            <div><dt>Address</dt><dd><?= e($po['supplier_address'] ?? '—') ?></dd></div>
            <?php if ($po['supplier_tin']): ?><div><dt>TIN</dt><dd><?= e($po['supplier_tin']) ?></dd></div><?php endif; ?>
        </dl>
        <dl>
            <div><dt>Date</dt><dd><?= e(date('m/d/Y', strtotime($po['order_date']))) ?></dd></div>
            <?php if ($po['payment_terms']): ?><div><dt>Terms</dt><dd><?= e($po['payment_terms']) ?></dd></div><?php endif; ?>
            <?php if ($po['expected_date']): ?><div><dt>Delivery</dt><dd><?= e(date('m/d/Y', strtotime($po['expected_date']))) ?></dd></div><?php endif; ?>
            <div><dt>Branch</dt><dd><?= e($po['branch_name']) ?></dd></div>
        </dl>
    </div>

    <table class="doc__table">
        <thead>
        <tr>
            <th class="c">Item No.</th>
            <th>End-User</th>
            <th class="num">Qty</th>
            <th>Unit</th>
            <th class="w">Items</th>
            <th class="num">Unit Cost</th>
            <th class="num">Total Amount</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($po['lines'] as $i => $l): ?>
            <tr>
                <td class="c"><?= $i + 1 ?></td>
                <td><?= e($l['end_user'] ?? '') ?></td>
                <td class="num"><?= number_format((int) $l['qty_ordered']) ?></td>
                <td><?= e(strtolower((string) ($l['unit_code'] ?? ''))) ?></td>
                <td class="w"><strong><?= e($l['product_name']) ?></strong><small><?= e($l['product_code']) ?></small></td>
                <td class="num"><?= e($costText((string) $l['unit_cost'])) ?></td>
                <td class="num"><?= e(number_format((float) $l['line_total'], 2)) ?></td>
            </tr>
        <?php endforeach; ?>
        <tr class="doc__nf"><td colspan="7">*** Nothing follows ***</td></tr>
        </tbody>
        <tfoot>
        <tr class="doc__total">
            <td colspan="5"></td>
            <th class="num">TOTAL</th>
            <td class="num" id="poPrintTotal"><?= e(money($po['total_amount'])) ?></td>
        </tr>
        </tfoot>
    </table>

    <div class="doc__info">
        <?php if ($po['contact_person']): ?><p><span>Contact person:</span> <?= e($po['contact_person']) ?></p><?php endif; ?>
        <?php if ($po['contact_number']): ?><p><span>Contact number:</span> <?= e($po['contact_number']) ?></p><?php endif; ?>
        <?php if ($po['ship_to']): ?><p><span>Ship to address:</span> <?= e($po['ship_to']) ?></p><?php endif; ?>
        <?php if ($po['forwarder']): ?><p><span>Forwarder:</span> <?= e($po['forwarder']) ?></p><?php endif; ?>
        <?php if ($po['notes']): ?><p><span>Notes:</span> <?= e($po['notes']) ?></p><?php endif; ?>
    </div>

    <div class="doc__signs">
        <div><span>Prepared By:</span><strong><?= e($po['created_by_name']) ?></strong><small>Signature over printed name</small></div>
        <div><span>Approved By:</span><strong><?= e($po['approved_by_name'] ?? '') ?></strong><small>Signature over printed name</small></div>
        <div><span>Received By:</span><strong></strong><small>Printed name &amp; signature / date</small></div>
    </div>

    <footer class="doc__foot">
        <span><?= e(setting('shop_name', 'EXECOM')) ?> · <?= e($label) ?></span>
        <span>Printed <?= e(date('M j, Y g:i A')) ?></span>
    </footer>
</article>

<script src="<?= e(asset('js/receipt.js')) ?>" defer></script>
</body>
</html>
