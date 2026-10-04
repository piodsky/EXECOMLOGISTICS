<?php
/**
 * Printable collection receipt (acknowledgement of payment, A4 / Letter) on the EXECOM letterhead: received from,
 * date, method / check / bank, the bills paid with cash applied and taxes withheld, totals, received by.
 * Not an official receipt (the BIR OR / CR is issued from the registered booklet). Cancelled receipts print with a
 * stamp. Same access as collection-view.php. pages/collection-print.php?id=5
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
require_page('collections');
$id = input_int($_GET, 'id', 1);
$c  = $id !== null ? Collections::find($id) : null;
if ($c === null) {
    abort(404, 'Collection not found.');
}
$fmtDate = static fn (?string $d): string => $d ? date('m/d/Y', strtotime($d)) : '—';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($c['collection_no']) ?> · Collection Receipt</title>
    <link rel="icon" href="<?= e(asset('img/favicon.png')) ?>" type="image/png">
    <link rel="stylesheet" href="<?= e(asset('css/print-doc.css')) ?>">
</head>
<body class="doc-page">

<div class="doc-toolbar no-print">
    <button type="button" id="printBtn">Print / Save as PDF</button>
    <a href="<?= e(url('pages/collection-view.php?id=' . (int) $c['id'])) ?>">Back to <?= e($c['collection_no']) ?></a>
</div>

<article class="doc" id="crDoc">
    <?php require ROOT_PATH . '/includes/letterhead.php'; ?>

    <h1 class="doc__title">Collection Receipt</h1>
    <?php if ($c['status'] === 'cancelled'): ?><p class="doc__stamp">CANCELLED</p><?php endif; ?>

    <div class="doc__meta">
        <dl>
            <div><dt>Received from</dt><dd class="doc__strong"><?= e($c['customer_name']) ?></dd></div>
            <div><dt>Address</dt><dd><?= e($c['customer_address'] ?? '—') ?></dd></div>
            <?php if ($c['customer_tin']): ?><div><dt>TIN</dt><dd><?= e($c['customer_tin']) ?></dd></div><?php endif; ?>
        </dl>
        <dl>
            <div><dt>Receipt No.</dt><dd class="doc__no" id="crNo"><?= e($c['collection_no']) ?></dd></div>
            <div><dt>Date</dt><dd><?= e($fmtDate($c['collection_date'])) ?></dd></div>
            <div><dt>Payment</dt><dd><?= e(Collections::METHODS[$c['method']] ?? $c['method']) ?><?= $c['reference'] ? ' · ' . e($c['reference']) : '' ?></dd></div>
            <?php if ($c['bank_name']): ?><div><dt>Bank</dt><dd><?= e($c['bank_name']) ?><?= $c['check_date'] ? ' · ' . e($fmtDate($c['check_date'])) : '' ?></dd></div><?php endif; ?>
        </dl>
    </div>

    <table class="doc__table">
        <thead>
        <tr><th class="c">No.</th><th class="w">Bill</th><th class="num">Cash Applied</th><th class="num">EWT</th><th class="num">VAT W/H</th><th class="num">Credited</th></tr>
        </thead>
        <tbody>
        <?php foreach ($c['lines'] as $i => $l): ?>
            <tr>
                <td class="c"><?= $i + 1 ?></td>
                <td class="w"><strong>Bill No. <?= e($l['sale_no']) ?></strong><small><?= e($fmtDate($l['billed_at'])) ?><?= $l['order_no'] ? ' · ' . e($l['order_no']) : '' ?><?= $l['customer_po_no'] ? ' · PO ' . e($l['customer_po_no']) : '' ?></small></td>
                <td class="num"><?= e(number_format((float) $l['amount'], 2)) ?></td>
                <td class="num"><?= e(number_format((float) $l['ewt_amount'], 2)) ?></td>
                <td class="num"><?= e(number_format((float) $l['vat_withheld'], 2)) ?></td>
                <td class="num"><?= e(number_format((float) $l['amount'] + (float) $l['ewt_amount'] + (float) $l['vat_withheld'], 2)) ?></td>
            </tr>
        <?php endforeach; ?>
        <tr class="doc__nf"><td colspan="6">*** Nothing follows ***</td></tr>
        </tbody>
        <tfoot>
        <tr class="doc__sum"><td colspan="4"></td><th class="num">Taxes withheld</th><td class="num"><?= e(number_format((float) $c['ewt_total'] + (float) $c['vat_withheld_total'], 2)) ?></td></tr>
        <tr class="doc__total"><td colspan="4"></td><th class="num">AMOUNT RECEIVED</th><td class="num" id="crPrintReceived"><?= e(money($c['amount_received'])) ?></td></tr>
        </tfoot>
    </table>

    <div class="doc__info">
        <?php if ($c['notes']): ?><p><span>Notes:</span> <?= e($c['notes']) ?></p><?php endif; ?>
        <?php if ($c['form_2307'] !== 'none'): ?><p><span>BIR 2307 / 2306:</span> <?= $c['form_2307'] === 'received' ? 'received ' . e($fmtDate($c['form_2307_received_at'])) : 'to be submitted by the customer' ?></p><?php endif; ?>
        <?php if ($c['method'] === 'check'): ?><p><span>Note:</span> payment by check is valid only when the check is cleared.</p><?php endif; ?>
    </div>

    <div class="doc__signs">
        <div><span>Received By:</span><strong><?= e($c['created_by_name']) ?></strong><small>Signature over printed name</small></div>
        <div><span>Paid By:</span><strong></strong><small>Customer's representative / date</small></div>
    </div>

    <footer class="doc__foot">
        <span><?= e(setting('shop_name', 'EXECOM')) ?> · <?= e($c['collection_no']) ?> · not an official receipt</span>
        <span>Printed <?= e(date('M j, Y g:i A')) ?></span>
    </footer>
</article>

<script src="<?= e(asset('js/receipt.js')) ?>" defer></script>
</body>
</html>
