<?php
/**
 * Printable purchase request (A4 / Letter) on the EXECOM letterhead: PR no., branch, dates, purpose, job order,
 * Item No. / End-User / Qty requested / approved / Unit / Items, Requested / Approved By. Same access as pr-view.php.
 * pages/pr-print.php?id=5
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
require_page('purchasing');
$id = input_int($_GET, 'id', 1);
$r  = $id !== null ? PurchaseRequests::find($id) : null;
if ($r === null) {
    abort(404, 'Purchase request not found.');
}
$label    = PurchaseRequests::label($r);
$approved = in_array($r['status'], ['approved', 'ordered'], true);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($label) ?> · Purchase Request</title>
    <link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('css/print-doc.css')) ?>">
</head>
<body class="doc-page">

<div class="doc-toolbar no-print">
    <button type="button" id="printBtn">Print / Save as PDF</button>
    <a href="<?= e(url('pages/pr-view.php?id=' . (int) $r['id'])) ?>">Back to <?= e($label) ?></a>
</div>

<article class="doc" id="prDoc">
    <?php require ROOT_PATH . '/includes/letterhead.php'; ?>

    <h1 class="doc__title">Purchase Request</h1>
    <?php if (!$approved): ?>
        <p class="doc__stamp"><?= e(strtoupper(PurchaseRequests::STATUSES[$r['status']] ?? $r['status'])) ?></p>
    <?php endif; ?>

    <div class="doc__meta">
        <dl>
            <div><dt>P.R. No.</dt><dd class="doc__no"><?= e($r['pr_no']) ?></dd></div>
            <div><dt>Branch</dt><dd class="doc__strong"><?= e($r['branch_name']) ?></dd></div>
            <?php if ($r['purpose']): ?><div><dt>Purpose</dt><dd><?= e($r['purpose']) ?></dd></div><?php endif; ?>
            <?php if ($r['job_no']): ?><div><dt>Job order</dt><dd><?= e($r['job_no']) ?></dd></div><?php endif; ?>
        </dl>
        <dl>
            <div><dt>Date</dt><dd><?= e(date('m/d/Y', strtotime($r['requested_at']))) ?></dd></div>
            <div><dt>Needed by</dt><dd><?= $r['needed_by'] ? e(date('m/d/Y', strtotime($r['needed_by']))) : '—' ?></dd></div>
            <div><dt>Status</dt><dd><?= e(PurchaseRequests::STATUSES[$r['status']] ?? $r['status']) ?></dd></div>
        </dl>
    </div>

    <table class="doc__table">
        <thead>
        <tr>
            <th class="c">Item No.</th>
            <th>End-User</th>
            <th class="num">Qty Requested</th>
            <th class="num">Qty Approved</th>
            <th>Unit</th>
            <th class="w">Items</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($r['lines'] as $i => $l): ?>
            <tr>
                <td class="c"><?= $i + 1 ?></td>
                <td><?= e($l['end_user'] ?? '') ?></td>
                <td class="num"><?= number_format((int) $l['qty_requested']) ?></td>
                <td class="num"><?= $l['qty_approved'] === null ? '' : number_format((int) $l['qty_approved']) ?></td>
                <td><?= e(strtolower((string) ($l['unit_code'] ?? ''))) ?></td>
                <td class="w"><strong><?= e($l['product_name']) ?></strong><small><?= e($l['product_code']) ?></small></td>
            </tr>
        <?php endforeach; ?>
        <tr class="doc__nf"><td colspan="6">*** Nothing follows ***</td></tr>
        </tbody>
    </table>

    <?php if ($r['decision_note']): ?>
        <div class="doc__info"><p><span><?= $r['status'] === 'rejected' ? 'Reason for rejecting:' : 'Approval note:' ?></span> <?= e($r['decision_note']) ?></p></div>
    <?php endif; ?>

    <div class="doc__signs">
        <div><span>Requested By:</span><strong><?= e($r['requested_by_name']) ?></strong><small>Signature over printed name</small></div>
        <div><span>Approved By:</span><strong><?= e($approved ? (string) $r['decided_by_name'] : '') ?></strong><small>Signature over printed name</small></div>
    </div>

    <footer class="doc__foot">
        <span><?= e(setting('shop_name', 'EXECOM')) ?> · <?= e($label) ?></span>
        <span>Printed <?= e(date('M j, Y g:i A')) ?></span>
    </footer>
</article>

<script src="<?= e(asset('js/receipt.js')) ?>" defer></script>
</body>
</html>
