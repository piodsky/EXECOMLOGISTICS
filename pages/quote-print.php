<?php
/**
 * Printable price quotation (A4 / Letter) on the EXECOM letterhead: customer, attention, RFQ reference, Item No. /
 * Qty / Unit / Description / Unit Price / Amount, subtotal / VAT / total, "nothing follows", terms (validity,
 * delivery, payment, warranty), Prepared / Conforme signatures. Cancelled / lost quotations print with a stamp.
 * Same access as quote-view.php. pages/quote-print.php?id=5
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
require_page('customer-orders');
$id = input_int($_GET, 'id', 1);
$q  = $id !== null ? Quotations::find($id) : null;
if ($q === null) {
    abort(404, 'Quotation not found.');
}
$vatRate  = (float) setting('vat_rate', '12');
$subCents = to_cents($q['subtotal']);
$vatCents = (int) round($subCents * $vatRate / 100);
$vat      = rtrim(rtrim(number_format($vatRate, 2), '0'), '.');
$stamp    = match ($q['status']) { 'cancelled' => 'CANCELLED', 'lost' => 'NOT AWARDED', default => $q['expired'] ? 'EXPIRED' : null };
$fmtDate  = static fn (?string $d): string => $d ? date('m/d/Y', strtotime($d)) : '—';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($q['quote_no']) ?> · Quotation</title>
    <link rel="icon" href="<?= e(asset('img/favicon.png')) ?>" type="image/png">
    <link rel="stylesheet" href="<?= e(asset('css/print-doc.css')) ?>">
</head>
<body class="doc-page">

<div class="doc-toolbar no-print">
    <button type="button" id="printBtn">Print / Save as PDF</button>
    <a href="<?= e(url('pages/quote-view.php?id=' . (int) $q['id'])) ?>">Back to <?= e($q['quote_no']) ?></a>
</div>

<article class="doc" id="quoteDoc">
    <?php require ROOT_PATH . '/includes/letterhead.php'; ?>

    <h1 class="doc__title">Price Quotation</h1>
    <?php if ($stamp !== null): ?><p class="doc__stamp" id="quoteStamp"><?= e($stamp) ?></p><?php endif; ?>

    <div class="doc__meta">
        <dl>
            <div><dt>To</dt><dd class="doc__strong"><?= e($q['customer_name']) ?></dd></div>
            <div><dt>Address</dt><dd><?= e($q['customer_address'] ?? '—') ?></dd></div>
            <?php if ($q['attention']): ?><div><dt>Attention</dt><dd><?= e($q['attention']) ?></dd></div><?php endif; ?>
            <?php if ($q['end_user']): ?><div><dt>End-user</dt><dd><?= e($q['end_user']) ?></dd></div><?php endif; ?>
        </dl>
        <dl>
            <div><dt>Quotation No.</dt><dd class="doc__no" id="quoteNo"><?= e($q['quote_no']) ?></dd></div>
            <div><dt>Date</dt><dd><?= e($fmtDate($q['quote_date'])) ?></dd></div>
            <?php if ($q['rfq_no']): ?><div><dt>Your RFQ</dt><dd><?= e($q['rfq_no']) ?><?= $q['rfq_date'] ? ' · ' . e($fmtDate($q['rfq_date'])) : '' ?></dd></div><?php endif; ?>
            <?php if ($q['valid_until']): ?><div><dt>Valid until</dt><dd><?= e($fmtDate($q['valid_until'])) ?></dd></div><?php endif; ?>
        </dl>
    </div>

    <p class="doc__lead">We are pleased to submit our price quotation for the following items:</p>

    <table class="doc__table">
        <thead>
        <tr><th class="c">Item No.</th><th class="num">Qty</th><th>Unit</th><th class="w">Description</th><th class="num">Unit Price</th><th class="num">Amount</th></tr>
        </thead>
        <tbody>
        <?php foreach ($q['lines'] as $i => $l): ?>
            <tr>
                <td class="c"><?= $i + 1 ?></td>
                <td class="num"><?= number_format((int) $l['quantity']) ?></td>
                <td><?= e(strtolower((string) ($l['unit_code'] ?? ''))) ?></td>
                <td class="w"><strong><?= e(trim(($l['brand_name'] ? $l['brand_name'] . ' ' : '') . $l['product_name'])) ?></strong><small><?= e($l['product_code']) ?><?= $l['specs'] ? ' · ' . e($l['specs']) : '' ?></small></td>
                <td class="num"><?= e(number_format((float) $l['unit_price'], 2)) ?></td>
                <td class="num"><?= e(number_format((float) $l['line_total'], 2)) ?></td>
            </tr>
        <?php endforeach; ?>
        <tr class="doc__nf"><td colspan="6">*** Nothing follows ***</td></tr>
        </tbody>
        <tfoot>
        <tr class="doc__sum"><td colspan="4"></td><th class="num">Subtotal</th><td class="num"><?= e(number_format($subCents / 100, 2)) ?></td></tr>
        <tr class="doc__sum"><td colspan="4"></td><th class="num">VAT <?= e($vat) ?>%</th><td class="num"><?= e(number_format($vatCents / 100, 2)) ?></td></tr>
        <tr class="doc__total"><td colspan="4"></td><th class="num">TOTAL</th><td class="num" id="quotePrintTotal"><?= e(money(from_cents($subCents + $vatCents))) ?></td></tr>
        </tfoot>
    </table>

    <div class="doc__info">
        <p><span>Prices:</span> in Philippine pesos, VAT <?= e($vat) ?>% shown separately.</p>
        <?php if ($q['valid_until']): ?><p><span>Validity:</span> until <?= e(date('F j, Y', strtotime($q['valid_until']))) ?></p><?php endif; ?>
        <?php if ($q['delivery_term']): ?><p><span>Delivery:</span> <?= e($q['delivery_term']) ?></p><?php endif; ?>
        <?php if ($q['payment_term']): ?><p><span>Payment:</span> <?= e($q['payment_term']) ?></p><?php endif; ?>
        <?php if ($q['warranty']): ?><p><span>Warranty:</span> <?= e($q['warranty']) ?></p><?php endif; ?>
        <?php if ($q['notes']): ?><p><span>Notes:</span> <?= e($q['notes']) ?></p><?php endif; ?>
    </div>

    <div class="doc__signs">
        <div><span>Prepared By:</span><strong><?= e($q['created_by_name']) ?></strong><small>Signature over printed name</small></div>
        <div><span>Conforme:</span><strong></strong><small>Customer's signature over printed name / date</small></div>
    </div>

    <footer class="doc__foot">
        <span><?= e(setting('shop_name', 'EXECOM')) ?> · <?= e($q['quote_no']) ?></span>
        <span>Printed <?= e(date('M j, Y g:i A')) ?></span>
    </footer>
</article>

<script src="<?= e(asset('js/receipt.js')) ?>" defer></script>
</body>
</html>
