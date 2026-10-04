<?php
/**
 * Printable disbursement voucher (A4 / Letter) on the EXECOM letterhead: payee, DV no. / date, payment (check no.,
 * bank, check date), invoices paid (AP no., supplier invoice no., RR, cash, EWT), particulars, amount in words,
 * Prepared / Checked / Approved / Received Payment signatures. Cancelled vouchers print with a stamp.
 * Same access as dv-view.php. pages/dv-print.php?id=5
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
require_page('payables');
Payables::requireView();
$id = input_int($_GET, 'id', 1);
$d  = $id !== null ? Payables::findDv($id) : null;
if ($d === null) {
    abort(404, 'Disbursement not found.');
}
$fmt = static fn (?string $x): string => $x ? date('m/d/Y', strtotime($x)) : '—';

/** Pesos in words, e.g. "Twelve Thousand Five Hundred Pesos and 50/100". */
$words = static function (string $amount): string {
    $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen',
             'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    $chunk = static function (int $n) use ($ones, $tens): string {
        $out = [];
        if ($n >= 100) {
            $out[] = $ones[intdiv($n, 100)] . ' Hundred';
            $n %= 100;
        }
        if ($n >= 20) {
            $out[] = $tens[intdiv($n, 10)] . ($n % 10 ? '-' . $ones[$n % 10] : '');
        } elseif ($n > 0) {
            $out[] = $ones[$n];
        }
        return implode(' ', $out);
    };
    $cents = to_cents($amount);
    $pesos = intdiv($cents, 100);
    $parts = [];
    foreach ([1000000 => 'Million', 1000 => 'Thousand', 1 => ''] as $unit => $name) {
        if ($pesos >= $unit) {
            $parts[] = trim($chunk(intdiv($pesos, $unit)) . ' ' . $name);
            $pesos %= $unit;
        }
    }
    return ($parts ? implode(' ', $parts) : 'Zero') . ' Pesos and ' . sprintf('%02d', $cents % 100) . '/100';
};
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($d['dv_no']) ?> · Disbursement Voucher</title>
    <link rel="icon" href="<?= e(asset('img/favicon.png')) ?>" type="image/png">
    <link rel="stylesheet" href="<?= e(asset('css/print-doc.css')) ?>">
</head>
<body class="doc-page">

<div class="doc-toolbar no-print">
    <button type="button" id="printBtn">Print / Save as PDF</button>
    <a href="<?= e(url('pages/dv-view.php?id=' . (int) $d['id'])) ?>">Back to <?= e($d['dv_no']) ?></a>
</div>

<article class="doc" id="dvDoc">
    <?php require ROOT_PATH . '/includes/letterhead.php'; ?>

    <h1 class="doc__title">Disbursement Voucher</h1>
    <?php if ($d['status'] === 'cancelled'): ?><p class="doc__stamp">CANCELLED</p><?php endif; ?>

    <div class="doc__meta">
        <dl>
            <div><dt>Payee</dt><dd class="doc__strong"><?= e($d['supplier_name']) ?></dd></div>
            <div><dt>Address</dt><dd><?= e($d['supplier_address'] ?? '—') ?></dd></div>
            <?php if ($d['supplier_tin']): ?><div><dt>TIN</dt><dd><?= e($d['supplier_tin']) ?></dd></div><?php endif; ?>
        </dl>
        <dl>
            <div><dt>DV No.</dt><dd class="doc__no" id="dvNo"><?= e($d['dv_no']) ?></dd></div>
            <div><dt>Date</dt><dd><?= e($fmt($d['payment_date'])) ?></dd></div>
            <div><dt>Payment</dt><dd><?= e(Collections::METHODS[$d['method']] ?? $d['method']) ?><?= $d['reference'] ? ' · ' . e($d['reference']) : '' ?></dd></div>
            <?php if ($d['bank_name']): ?><div><dt>Bank</dt><dd><?= e($d['bank_name']) ?><?= $d['check_date'] ? ' · ' . e($fmt($d['check_date'])) : '' ?></dd></div><?php endif; ?>
        </dl>
    </div>

    <table class="doc__table">
        <thead><tr><th class="c">No.</th><th class="w">Invoice</th><th>Due</th><th class="num">Cash Paid</th><th class="num">EWT</th><th class="num">Settled</th></tr></thead>
        <tbody>
        <?php foreach ($d['lines'] as $n => $l): ?>
            <tr>
                <td class="c"><?= $n + 1 ?></td>
                <td class="w"><strong><?= e($l['ap_no']) ?> · Inv. <?= e($l['invoice_no']) ?></strong><small><?= e($fmt($l['invoice_date'])) ?> · <?= e($l['rr_no']) ?></small></td>
                <td><?= e($fmt($l['due_date'])) ?></td>
                <td class="num"><?= e(number_format((float) $l['amount'], 2)) ?></td>
                <td class="num"><?= e(number_format((float) $l['ewt_amount'], 2)) ?></td>
                <td class="num"><?= e(number_format((float) $l['amount'] + (float) $l['ewt_amount'], 2)) ?></td>
            </tr>
        <?php endforeach; ?>
        <tr class="doc__nf"><td colspan="6">*** Nothing follows ***</td></tr>
        </tbody>
        <tfoot>
        <tr class="doc__sum"><td colspan="4"></td><th class="num">EWT withheld</th><td class="num"><?= e(number_format((float) $d['ewt_total'], 2)) ?></td></tr>
        <tr class="doc__total"><td colspan="4"></td><th class="num">AMOUNT PAID</th><td class="num" id="dvPrintAmount"><?= e(money($d['amount_paid'])) ?></td></tr>
        </tfoot>
    </table>

    <div class="doc__info">
        <p><span>Amount in words:</span> <?= e($words((string) $d['amount_paid'])) ?></p>
        <?php if ($d['particulars']): ?><p><span>Particulars:</span> <?= e($d['particulars']) ?></p><?php endif; ?>
        <?php if ((float) $d['ewt_total'] > 0): ?><p><span>Note:</span> BIR Form 2307 to be issued to the payee for the tax withheld.</p><?php endif; ?>
    </div>

    <div class="doc__signs doc__signs--4">
        <div><span>Prepared By:</span><strong><?= e($d['created_by_name']) ?></strong><small>Signature over printed name</small></div>
        <div><span>Checked By:</span><strong></strong><small>Signature over printed name</small></div>
        <div><span>Approved By:</span><strong></strong><small>Signature over printed name</small></div>
        <div><span>Received Payment:</span><strong></strong><small>Payee's signature / date</small></div>
    </div>

    <footer class="doc__foot">
        <span><?= e(setting('shop_name', 'EXECOM')) ?> · <?= e($d['dv_no']) ?></span>
        <span>Printed <?= e(date('M j, Y g:i A')) ?></span>
    </footer>
</article>

<script src="<?= e(asset('js/receipt.js')) ?>" defer></script>
</body>
</html>
