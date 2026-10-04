<?php
/**
 * Printable statement of account (A4 / Letter) on the EXECOM letterhead: customer, as-of date, open bills on account
 * in the branch scope (bill no., date, order / PO, due date, days overdue, amount, paid, balance), aging summary,
 * payments received in the last 90 days, total due. Same access as soa.php. pages/soa-print.php?customer=ID
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
require_page('collections');
$cid = input_int($_GET, 'customer', 1);
$c   = $cid !== null ? Collections::statement($cid) : null;
if ($c === null) {
    abort(404, 'Customer not found.');
}
$aging = array_map(static fn (): int => 0, Collections::AGING);
$total = 0;
foreach ($c['bills'] as $b) {
    $bal = to_cents($b['balance']);
    $aging[Collections::bucket((int) $b['days'])] += $bal;
    $total += $bal;
}
$fmt = static fn (?string $d): string => $d ? date('m/d/Y', strtotime($d)) : '—';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($c['name']) ?> · Statement of Account</title>
    <link rel="icon" href="<?= e(asset('img/favicon.png')) ?>" type="image/png">
    <link rel="stylesheet" href="<?= e(asset('css/print-doc.css')) ?>">
</head>
<body class="doc-page">

<div class="doc-toolbar no-print">
    <button type="button" id="printBtn">Print / Save as PDF</button>
    <a href="<?= e(url('pages/soa.php')) ?>">Back to Statement of Account</a>
</div>

<article class="doc" id="soaDoc">
    <?php require ROOT_PATH . '/includes/letterhead.php'; ?>

    <h1 class="doc__title">Statement of Account</h1>

    <div class="doc__meta">
        <dl>
            <div><dt>Customer</dt><dd class="doc__strong" id="soaCustomer"><?= e($c['name']) ?></dd></div>
            <div><dt>Address</dt><dd><?= e($c['address'] ?? '—') ?></dd></div>
            <?php if ($c['tin']): ?><div><dt>TIN</dt><dd><?= e($c['tin']) ?></dd></div><?php endif; ?>
        </dl>
        <dl>
            <div><dt>As of</dt><dd><?= e(date('m/d/Y')) ?></dd></div>
            <div><dt>Terms</dt><dd><?= (int) $c['credit_days'] > 0 ? (int) $c['credit_days'] . ' days' : '—' ?></dd></div>
            <div><dt>Branch</dt><dd><?= e(Branch::label()) ?></dd></div>
        </dl>
    </div>

    <table class="doc__table">
        <thead>
        <tr><th>Bill No.</th><th>Date</th><th class="w">Reference</th><th>Due</th><th class="num">Days Overdue</th><th class="num">Amount</th><th class="num">Paid</th><th class="num">Balance</th></tr>
        </thead>
        <tbody>
        <?php foreach ($c['bills'] as $b): ?>
            <tr>
                <td><?= e($b['sale_no']) ?></td>
                <td><?= e($fmt($b['created_at'])) ?></td>
                <td class="w"><?= $b['order_no'] ? e($b['order_no']) . ($b['customer_po_no'] ? ' · PO ' . e($b['customer_po_no']) : '') : 'Sale' ?></td>
                <td><?= e($fmt($b['due_date'])) ?></td>
                <td class="num"><?= (int) $b['days'] > 0 ? number_format((int) $b['days']) : '—' ?></td>
                <td class="num"><?= e(number_format((float) $b['total'], 2)) ?></td>
                <td class="num"><?= e(number_format((float) $b['settled_amount'], 2)) ?></td>
                <td class="num"><?= e(number_format((float) $b['balance'], 2)) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$c['bills']): ?><tr><td colspan="8" class="c">No open bills: thank you for your payments.</td></tr><?php endif; ?>
        <tr class="doc__nf"><td colspan="8">*** Nothing follows ***</td></tr>
        </tbody>
        <tfoot>
        <tr class="doc__total"><td colspan="6"></td><th class="num">TOTAL DUE</th><td class="num" id="soaDue"><?= e(money(from_cents($total))) ?></td></tr>
        </tfoot>
    </table>

    <table class="doc__table doc__aging">
        <thead><tr><?php foreach (Collections::AGING as [$label]): ?><th class="num"><?= e($label) ?></th><?php endforeach; ?></tr></thead>
        <tbody><tr><?php foreach ($aging as $cents): ?><td class="num"><?= e(number_format($cents / 100, 2)) ?></td><?php endforeach; ?></tr></tbody>
    </table>

    <?php if ($c['payments']): ?>
        <div class="doc__info">
            <p><span>Payments received (last 90 days):</span></p>
            <?php foreach ($c['payments'] as $p): ?>
                <p><?= e($fmt($p['collection_date'])) ?> · <?= e($p['collection_no']) ?> · <?= e(Collections::METHODS[$p['method']] ?? $p['method']) ?><?= $p['reference'] ? ' ' . e($p['reference']) : '' ?>
                    · <?= e(money($p['total_credited'])) ?><?= (float) $p['ewt_total'] + (float) $p['vat_withheld_total'] > 0 ? ' (incl. ' . e(money(from_cents(to_cents($p['ewt_total']) + to_cents($p['vat_withheld_total'])))) . ' taxes withheld)' : '' ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="doc__info">
        <p>Please settle the amounts past due. If payment has been made, kindly disregard this statement and send us a copy
            of your payment and BIR Form 2307 / 2306 for the taxes withheld.</p>
    </div>

    <div class="doc__signs">
        <div><span>Prepared By:</span><strong><?= e((string) (Auth::user()['full_name'] ?? '')) ?></strong><small>Signature over printed name</small></div>
        <div><span>Received By:</span><strong></strong><small>Customer's representative / date</small></div>
    </div>

    <footer class="doc__foot">
        <span><?= e(setting('shop_name', 'EXECOM')) ?> · Statement of Account · <?= e($c['name']) ?></span>
        <span>Printed <?= e(date('M j, Y g:i A')) ?></span>
    </footer>
</article>

<script src="<?= e(asset('js/receipt.js')) ?>" defer></script>
</body>
</html>
