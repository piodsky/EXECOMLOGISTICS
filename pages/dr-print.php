<?php
/**
 * Printable delivery receipt (A4) on the EXECOM letterhead: DR no. / date, customer, place of delivery, customer PO,
 * items with serial numbers, "nothing follows", Released / Delivered / Received by (with date). Same access as
 * dr-view.php. pages/dr-print.php?id=5
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
require_page('customer-orders');
$id = input_int($_GET, 'id', 1);
$d  = $id !== null ? CustomerDeliveries::find($id) : null;
if ($d === null) {
    abort(404, 'Delivery receipt not found.');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($d['dr_no']) ?> · Delivery Receipt</title>
    <link rel="icon" href="<?= e(asset('img/favicon.png')) ?>" type="image/png">
    <link rel="stylesheet" href="<?= e(asset('css/print-doc.css')) ?>">
</head>
<body class="doc-page">

<div class="doc-toolbar no-print">
    <button type="button" id="printBtn">Print / Save as PDF</button>
    <a href="<?= e(url('pages/dr-view.php?id=' . (int) $d['id'])) ?>">Back to <?= e($d['dr_no']) ?></a>
</div>

<article class="doc" id="drDoc">
    <?php require ROOT_PATH . '/includes/letterhead.php'; ?>

    <h1 class="doc__title">Delivery Receipt</h1>
    <?php if ($d['status'] === 'cancelled'): ?><p class="doc__stamp">CANCELLED</p><?php endif; ?>

    <div class="doc__meta">
        <dl>
            <div><dt>D.R. No.</dt><dd class="doc__no" id="drNo"><?= e($d['dr_no']) ?></dd></div>
            <div><dt>Delivered to</dt><dd class="doc__strong"><?= e($d['customer_name']) ?></dd></div>
            <?php if ($d['end_user']): ?><div><dt>End-user</dt><dd><?= e($d['end_user']) ?></dd></div><?php endif; ?>
            <div><dt>Address</dt><dd><?= e($d['place_of_delivery'] ?? $d['customer_address'] ?? '—') ?></dd></div>
            <?php if ($d['customer_tin']): ?><div><dt>TIN</dt><dd><?= e($d['customer_tin']) ?></dd></div><?php endif; ?>
        </dl>
        <dl>
            <div><dt>Date</dt><dd><?= e(date('m/d/Y', strtotime($d['released_at']))) ?></dd></div>
            <div><dt>Your PO</dt><dd class="doc__strong"><?= e($d['customer_po_no']) ?></dd></div>
            <?php if ($d['customer_po_date']): ?><div><dt>PO date</dt><dd><?= e(date('m/d/Y', strtotime($d['customer_po_date']))) ?></dd></div><?php endif; ?>
            <div><dt>Our ref.</dt><dd><?= e((string) $d['order_no']) ?></dd></div>
        </dl>
    </div>

    <table class="doc__table">
        <thead>
        <tr><th class="c">Item No.</th><th class="num">Qty</th><th>Unit</th><th class="w">Description</th></tr>
        </thead>
        <tbody>
        <?php foreach ($d['lines'] as $i => $l): ?>
            <tr>
                <td class="c"><?= $i + 1 ?></td>
                <td class="num"><?= number_format((int) $l['qty']) ?></td>
                <td><?= e(strtolower((string) ($l['unit_code'] ?? ''))) ?></td>
                <td class="w"><strong><?= e($l['product_name']) ?></strong><small><?= e($l['product_code']) ?><?= $l['serials'] ? ' · S/N: ' . e(implode(', ', $l['serials'])) : '' ?></small></td>
            </tr>
        <?php endforeach; ?>
        <tr class="doc__nf"><td colspan="4">*** Nothing follows ***</td></tr>
        </tbody>
    </table>

    <div class="doc__info">
        <p><span>Total quantity:</span> <?= number_format((int) $d['total_qty']) ?></p>
        <?php if ($d['award_ref']): ?><p><span>Award / BAC reference:</span> <?= e($d['award_ref']) ?></p><?php endif; ?>
        <?php if ($d['notes']): ?><p><span>Notes:</span> <?= e($d['notes']) ?></p><?php endif; ?>
        <p>Received the above items in good order and condition.</p>
    </div>

    <div class="doc__signs">
        <div><span>Released By:</span><strong><?= e($d['released_by_name']) ?></strong><small>Signature over printed name</small></div>
        <div><span>Delivered By:</span><strong><?= e((string) $d['delivered_by']) ?></strong><small>Signature over printed name</small></div>
        <div><span>Received By:</span><strong><?= e((string) $d['received_by']) ?></strong><small>Printed name, signature &amp; date<?= $d['received_date'] ? ': ' . e(date('m/d/Y', strtotime($d['received_date']))) : '' ?></small></div>
    </div>

    <footer class="doc__foot">
        <span><?= e(setting('shop_name', 'EXECOM')) ?> · <?= e($d['dr_no']) ?><?= $d['acceptance_ref'] ? ' · IAR ' . e($d['acceptance_ref']) : '' ?></span>
        <span>Printed <?= e(date('M j, Y g:i A')) ?></span>
    </footer>
</article>

<script src="<?= e(asset('js/receipt.js')) ?>" defer></script>
</body>
</html>
