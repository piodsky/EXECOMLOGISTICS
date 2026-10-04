<?php
/**
 * One receiving report: header, lines + serials, totals (cost only with products.cost),
 * Post (draft; receiving.post + products.cost), Cancel with reason (posted; receiving.cancel), Print.
 * Reports outside the branch scope are 404 (Receiving::find -> Branch::assertAccess).
 * pages/receiving-view.php?id=5&return=receiving.php%3Fpage%3D2
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('receiving');

$id = input_int($_GET, 'id', 1) ?? throw new HttpException(404, 'Receiving report not found.');
$rr = Receiving::find($id) ?? throw new HttpException(404, 'Receiving report not found.');

$canCost   = Auth::can('products.cost');
$canPost   = Auth::can('receiving.post') && $canCost;
$canCancel = Auth::can('receiving.cancel');
$canManage = Auth::can('receiving.manage');
$returnTo  = safe_return($_POST['return'] ?? $_GET['return'] ?? null, 'receiving.php');
$self      = 'receiving-view.php?' . http_build_query(['id' => $id, 'return' => $returnTo]);

// ---------------------------------------------------------------------
// Post / Cancel (POST -> redirect back here)
// ---------------------------------------------------------------------
if (is_post()) {
    Csrf::verifyRequest();
    $action = input_string($_POST, 'action', 10);
    try {
        if ($action === 'post') {
            if (!$canPost) {
                abort(403, 'You do not have permission to post receiving reports.');
            }
            $rrNo = Receiving::post($id, (int) Auth::id());
            flash('success', "{$rrNo} was posted. The items were added to stock.");
        } elseif ($action === 'cancel') {
            if (!$canCancel) {
                abort(403, 'You do not have permission to cancel receiving reports.');
            }
            Receiving::cancel($id, (int) Auth::id(), input_string($_POST, 'reason', 255));
            flash('success', Receiving::label($rr) . ' was cancelled. Its stock was removed and the cost restored.');
        } else {
            throw new HttpException(400, 'Unknown action.');
        }
    } catch (HttpException $e) {
        if ($e->status === 403) {
            throw $e;
        }
        flash('error', $e->getMessage());
    }
    redirect('pages/' . $self);
}

$status   = $rr['status'];
$isDraft  = $status === 'draft';
$label    = Receiving::label($rr);
$page['title'] = $label;
$statusBadge = ['draft' => 'badge--info', 'posted' => 'badge--success', 'cancelled' => 'badge--danger'];

// Totals; for a draft the cost total is what posting would give (lines without a cost make it incomplete).
$totalQty    = 0;
$costCents   = 0;
$costMissing = false;
$warnings    = 0;
foreach ($rr['items'] as $item) {
    $totalQty += (int) $item['quantity'];
    if ($canCost) {
        if (($item['unit_cost'] ?? null) === null) {
            $costMissing = true;
        } else {
            $costCents += Costing::lineCents((int) $item['quantity'], (string) $item['unit_cost']);
        }
    }
}
$costText = static function (?string $v): string {
    if ($v === null) {
        return '—';
    }
    $s = preg_match('/^(\d+)\.(\d{2})(\d*)$/', $v, $m) ? $m[2] . rtrim($m[3], '0') : '00';
    return config('app.currency') . ' ' . number_format((float) $v, strlen($s));
};

$pageStyles  = ['css/sales.css', 'css/receiving.css'];
$pageScripts = ['js/receiving.js'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <a class="back-link" href="<?= e(url('pages/' . $returnTo)) ?>"><?= icon('arrow-left') ?> Receiving</a>
        <h1 class="sale-title">
            <span id="rrTitle"><?= e($label) ?></span>
            <span class="badge <?= e($statusBadge[$status] ?? '') ?>" id="rrStatus"><?= e(Receiving::STATUSES[$status] ?? $status) ?></span>
        </h1>
        <p class="muted">Received <?= e(date('l, M j, Y', strtotime($rr['received_date']))) ?> from <?= e($rr['supplier_name']) ?></p>
    </div>
    <div class="page-actions">
        <button type="button" class="btn btn--light" data-print><?= icon('printer') ?> Print</button>
        <?php if ($isDraft && $canManage): ?>
            <a class="btn btn--light" href="<?= e(url('pages/receiving-form.php?' . http_build_query(['id' => $id, 'return' => $returnTo]))) ?>" id="rrEdit"><?= icon('edit') ?> Edit Draft</a>
        <?php endif; ?>
        <?php if ($isDraft && $canPost): ?>
            <form method="post" action="<?= e(url('pages/' . $self)) ?>" class="inline-form"
                  data-confirm="Post <?= e($label) ?>? The items are added to stock and the average cost is updated.">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="post">
                <input type="hidden" name="return" value="<?= e($returnTo) ?>">
                <button type="submit" class="btn btn--primary" id="rrPost"><?= icon('check') ?> Post</button>
            </form>
        <?php endif; ?>
        <?php if ($status === 'posted' && $canCancel): ?>
            <button type="button" class="btn btn--danger" data-open="cancelDialog" id="rrCancel"><?= icon('x') ?> Cancel RR</button>
        <?php endif; ?>
    </div>
</div>

<div class="print-head" aria-hidden="true">
    <strong><?= e(setting('shop_name', 'EXECOM Logistics')) ?></strong>
    <span>Receiving Report · <?= e($rr['branch_code'] . ' · ' . $rr['branch_name']) ?></span>
</div>

<?php if ($status === 'cancelled'): ?>
    <div class="void-box" role="note">
        <?= icon('alert') ?>
        <div>
            <?php
            $line = ($rr['cancelled_at'] ? ' on ' . date('M j, Y g:i A', strtotime($rr['cancelled_at'])) : '')
                . ($rr['cancelled_by_name'] ? ' by ' . $rr['cancelled_by_name'] : '');
            ?>
            <strong>This receiving report was cancelled</strong><?= e($line) ?>. Its stock was removed and the cost restored.
            <?php if ($rr['cancel_reason']): ?>
                <p class="void-box__reason">Reason: <?= e($rr['cancel_reason']) ?></p>
            <?php endif; ?>
        </div>
    </div>
<?php elseif ($isDraft): ?>
    <div class="alert alert--info rr-draft-note" role="note">
        <?= icon('info') ?>
        <span>This is a draft: nothing is added to stock until it is posted<?= $canPost ? '' : ' by a user who can post receiving reports' ?>.</span>
    </div>
<?php endif; ?>

<div class="sale-layout">
    <section class="card">
        <header class="card__head">
            <h2><?= icon('box') ?> Items</h2>
            <span class="muted"><?= count($rr['items']) ?> <?= count($rr['items']) === 1 ? 'line' : 'lines' ?> · <?= number_format($totalQty) ?> <?= $totalQty === 1 ? 'unit' : 'units' ?></span>
        </header>
        <div class="table-wrap">
            <table class="table rr-view-table">
                <thead>
                <tr>
                    <th>#</th><th>Item</th><th class="num">Qty</th>
                    <?php if ($canCost): ?><th class="num">Unit Cost</th><th class="num">Line Total</th><?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rr['items'] as $i => $item): ?>
                    <?php
                    $serials = $item['serials'];
                    $short   = $isDraft && (int) $item['track_serial'] === 1 && count($serials) !== (int) $item['quantity'];
                    $lineCost = null;
                    if ($canCost && ($item['unit_cost'] ?? null) !== null) {
                        $lineCost = $item['line_total'] ?? from_cents(Costing::lineCents((int) $item['quantity'], (string) $item['unit_cost']));
                    }
                    ?>
                    <tr>
                        <td class="muted"><?= $i + 1 ?></td>
                        <td>
                            <strong class="block"><?= e($item['product_name']) ?></strong>
                            <small class="muted"><?= e($item['product_code']) ?><?= (int) $item['product_active'] === 1 ? '' : ' · inactive' ?></small>
                            <?php if ($serials): ?>
                                <ul class="sn-list" aria-label="Serial numbers">
                                    <?php foreach ($serials as $sn): ?><li><?= e($sn) ?></li><?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                            <?php if ($short): ?>
                                <p class="form-hint rr-warn"><?= icon('alert') ?> <?= count($serials) ?> of <?= (int) $item['quantity'] ?> serial numbers entered</p>
                            <?php endif; ?>
                        </td>
                        <td class="num"><?= number_format((int) $item['quantity']) ?> <small class="muted"><?= e($item['unit_code'] ?? '') ?></small></td>
                        <?php if ($canCost): ?>
                            <td class="num"><?= $item['unit_cost'] === null ? '<span class="rr-warn">Not set</span>' : e($costText((string) $item['unit_cost'])) ?></td>
                            <td class="num"><?= $lineCost === null ? '—' : e(money($lineCost)) ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rr['items']): ?>
                    <tr><td colspan="<?= $canCost ? 5 : 3 ?>" class="empty">No items on this report yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <dl class="sale-totals<?= $status === 'cancelled' ? ' is-void' : '' ?>">
            <div><dt>Total quantity</dt><dd><?= number_format($totalQty) ?></dd></div>
            <?php if ($canCost): ?>
                <div class="sale-totals__grand">
                    <dt>Total Cost</dt>
                    <dd id="rrTotalCost"><?= $isDraft
                        ? ($costMissing ? 'Incomplete' : e(money(from_cents($costCents))))
                        : e(money($rr['total_cost'] ?? 0)) ?></dd>
                </div>
            <?php endif; ?>
        </dl>
    </section>

    <aside class="form-side">
        <section class="card card--pad">
            <h2 class="card__title">Details</h2>
            <dl class="detail-list">
                <div><dt>Supplier</dt><dd>
                    <?php if (Auth::can('suppliers.view')): ?>
                        <a href="<?= e(url('pages/supplier-form.php?id=' . (int) $rr['supplier_id'])) ?>"><?= e($rr['supplier_name']) ?></a>
                    <?php else: ?>
                        <?= e($rr['supplier_name']) ?>
                    <?php endif; ?>
                </dd></div>
                <?php if ($rr['po_no'] !== null): ?>
                    <div><dt>Purchase order</dt><dd id="rrPo">
                        <?php if (PurchaseOrders::canView()): ?>
                            <a href="<?= e(url('pages/po-view.php?id=' . (int) $rr['po_id'])) ?>"><?= e($rr['po_no']) ?></a>
                        <?php else: ?>
                            <?= e($rr['po_no']) ?>
                        <?php endif; ?>
                    </dd></div>
                <?php endif; ?>
                <div><dt>Reference / DR</dt><dd><?= e($rr['reference_no'] ?? '—') ?></dd></div>
                <div><dt>Received</dt><dd><?= e(date('M j, Y', strtotime($rr['received_date']))) ?></dd></div>
                <div><dt>Branch</dt><dd><?= e($rr['branch_code'] . ' · ' . $rr['branch_name']) ?></dd></div>
                <div><dt>Location</dt><dd><?= e($rr['warehouse_code'] . ' / ' . $rr['location_code']) ?></dd></div>
                <div><dt>Created by</dt><dd><?= e($rr['created_by_name']) ?></dd></div>
                <div><dt>Created</dt><dd><?= e(date('M j, Y g:i A', strtotime($rr['created_at']))) ?></dd></div>
                <?php if ($rr['posted_at']): ?>
                    <div><dt>Posted by</dt><dd><?= e($rr['posted_by_name'] ?? '—') ?></dd></div>
                    <div><dt>Posted</dt><dd><?= e(date('M j, Y g:i A', strtotime($rr['posted_at']))) ?></dd></div>
                <?php endif; ?>
            </dl>
            <?php if ($rr['notes']): ?>
                <p class="rr-notes"><span class="form-label">Notes</span><?= e($rr['notes']) ?></p>
            <?php endif; ?>
        </section>
    </aside>
</div>

<?php if ($status === 'posted' && $canCancel): ?>
    <dialog class="modal" id="cancelDialog" aria-labelledby="cancelTitle">
        <form class="modal__body" method="post" action="<?= e(url('pages/' . $self)) ?>"
              data-confirm="Cancel <?= e($label) ?>? This cannot be undone.">
            <header class="modal__head">
                <h2 id="cancelTitle">Cancel <?= e($label) ?>?</h2>
                <button type="button" class="modal__close" data-close aria-label="Close"><?= icon('x') ?></button>
            </header>
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="cancel">
            <input type="hidden" name="return" value="<?= e($returnTo) ?>">
            <p class="void-warning">
                <?= icon('alert') ?>
                <span>All <?= number_format($totalQty) ?> <?= $totalQty === 1 ? 'unit is' : 'units are' ?> removed from stock, the average cost goes back
                    to what it was and the serial numbers are removed. This only works while none of the items have been sold or moved since posting.</span>
            </p>
            <label class="form-field">
                <span class="form-label">Reason *</span>
                <textarea class="form-input" name="reason" id="cancelReason" required minlength="3" maxlength="255"
                          placeholder="e.g. Wrong supplier, delivery returned"></textarea>
            </label>
            <footer class="modal__foot">
                <button type="button" class="btn btn--light" data-close>Keep RR</button>
                <button type="submit" class="btn btn--danger"><?= icon('x') ?> Cancel RR</button>
            </footer>
        </form>
    </dialog>
<?php endif; ?>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
