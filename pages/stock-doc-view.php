<?php
/**
 * One stock document: header, lines + serial numbers, Print. Cost columns only with products.cost.
 * Stock counts also: count sheet (open; counts.create): counted qty per item / found serials, Save,
 * Submit; Approve dialog with the variances (submitted; counts.approve, not the creator / counter);
 * Cancel with reason. Buttons follow InventoryDocs::actions(); every action re-checks everything.
 * Documents outside the branch scope are 404 (InventoryDocs::find -> Branch::assertAccess).
 * pages/stock-doc-view.php?id=5&return=stock-docs.php%3Fpage%3D2
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('stock-docs');

$id  = input_int($_GET, 'id', 1) ?? throw new HttpException(404, 'Stock document not found.');
$doc = InventoryDocs::find($id) ?? throw new HttpException(404, 'Stock document not found.');

$returnTo = safe_return($_POST['return'] ?? $_GET['return'] ?? null, 'stock-docs.php');
$self     = 'stock-doc-view.php?' . http_build_query(['id' => $id, 'return' => $returnTo]);
$label    = InventoryDocs::label($doc);
$isCount  = $doc['doc_type'] === 'count';

// ---------------------------------------------------------------------
// Count actions: save / submit / approve / cancel (POST -> redirect back here)
// ---------------------------------------------------------------------
if (is_post()) {
    Csrf::verifyRequest();
    if (!$isCount) {
        throw new HttpException(400, 'Unknown action.');
    }
    $action = input_string($_POST, 'action', 10);
    $uid    = (int) Auth::id();
    $counts = is_array($_POST['counts'] ?? null) ? array_filter($_POST['counts'], 'is_string') : [];
    $found  = is_array($_POST['found'] ?? null) ? array_values(array_filter($_POST['found'], 'is_string')) : [];
    try {
        switch ($action) {
            case 'save':
            case 'submit':
                try {
                    InventoryDocs::saveCounts($id, $counts, $found, $uid);
                } catch (HttpException $e) {
                    flash_old(['counts' => $counts, 'found' => $found]);
                    if (is_array($e->details['errors'] ?? null)) {
                        flash_errors($e->details['errors']);
                    }
                    throw $e;
                }
                if ($action === 'save') {
                    flash('success', 'The counted quantities were saved.');
                    break;
                }
                InventoryDocs::submit($id, $uid);
                flash('success', "{$label} was submitted for approval.");
                break;

            case 'approve':
                $res = InventoryDocs::approve($id, $uid);
                flash('success', $res['total_qty'] > 0
                    ? "{$res['doc_no']} was approved and posted: " . number_format($res['total_qty']) . ' ' . ($res['total_qty'] === 1 ? 'unit' : 'units') . ' adjusted.'
                    : "{$res['doc_no']} was approved and posted. No differences: stock is unchanged.");
                break;

            case 'cancel':
                InventoryDocs::cancel($id, input_string($_POST, 'reason', 300), $uid);
                flash('success', "{$label} was cancelled. Stock is unchanged.");
                break;

            default:
                throw new HttpException(400, 'Unknown action.');
        }
    } catch (HttpException $e) {
        flash('error', $e->getMessage());
    }
    redirect('pages/' . $self);
}

// ---------------------------------------------------------------------
// View data
// ---------------------------------------------------------------------
$status   = $doc['status'];
$canCost  = Auth::can('products.cost');
$actions  = InventoryDocs::actions($doc);
$editable = $isCount && $actions['save'];
$page['title'] = $label;

$statusBadge = ['open' => 'badge--info', 'submitted' => 'badge--warning', 'posted' => 'badge--success', 'cancelled' => 'badge--danger'];
$kindLabel   = static fn (?string $k, ?string $name): string => $k !== null && $k !== 'stock' && (Warehouses::KINDS[$k] ?? $k) !== $name ? ' (' . (Warehouses::KINDS[$k] ?? $k) . ')' : '';
$from = $doc['from_warehouse_code'] . ' / ' . $doc['from_location_code'] . ' - ' . $doc['from_location_name'];
$to   = $doc['to_location_code'] !== null ? $doc['to_warehouse_code'] . ' / ' . $doc['to_location_code'] . ' - ' . $doc['to_location_name'] : null;
$when = static fn (?string $ts): string => $ts ? date('M j, Y g:i A', strtotime($ts)) : '—';

// Re-display after a failed save: the values the user typed.
$old       = has_old() ? old_input() : [];
$oldCounts = is_array($old['counts'] ?? null) ? $old['counts'] : null;
$oldFound  = is_array($old['found'] ?? null) ? array_flip(array_map('intval', $old['found'])) : null;
$errors    = form_errors();

// Count figures
$lines      = $doc['lines'];
$counted    = 0;
$withDiff   = [];
$totalUnits = 0;
foreach ($lines as $line) {
    $totalUnits += (int) ($line['quantity'] ?? 0);
    if ($isCount && $line['counted_qty'] !== null) {
        $counted++;
        if ((int) $line['variance'] !== 0) {
            $withDiff[] = $line;
        }
    }
}

// Estimated value of the variances before posting (approval dialog): branch average, read only.
$estCost = [];
if ($isCount && $canCost && $status === 'submitted' && $withDiff) {
    $ids  = array_map(static fn (array $l): int => (int) $l['product_id'], $withDiff);
    $in_  = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare(
        "SELECT p.id, COALESCE(pb.avg_cost, p.unit_cost) AS cost
           FROM products p LEFT JOIN product_branches pb ON pb.product_id = p.id AND pb.branch_id = ?
          WHERE p.id IN ({$in_})"
    );
    $stmt->execute([(int) $doc['branch_id'], ...$ids]);
    foreach ($stmt->fetchAll() as $r) {
        $estCost[(int) $r['id']] = (string) $r['cost'];
    }
}
$estValue = static function (array $line) use ($estCost): ?int {
    $cost = $estCost[(int) $line['product_id']] ?? null;
    if ($cost === null || $line['variance'] === null) {
        return null;
    }
    $v = (int) $line['variance'];
    return ($v < 0 ? -1 : 1) * Costing::lineCents(abs($v), $cost);
};
$signed = static fn (?int $n): string => $n === null ? '—' : ($n > 0 ? '+' : ($n < 0 ? '−' : '')) . number_format(abs($n));
$varClass = static fn (?int $n): string => $n === null || $n === 0 ? '' : ($n < 0 ? 'text-danger' : 'text-success');

$pageStyles  = ['css/sales.css', 'css/receiving.css', 'css/stock-docs.css'];
$pageScripts = ['js/stock-docs.js'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <a class="back-link" href="<?= e(url('pages/' . $returnTo)) ?>"><?= icon('arrow-left') ?> Stock Operations</a>
        <h1 class="sale-title">
            <span id="docTitle"><?= e($label) ?></span>
            <span class="badge <?= e($statusBadge[$status] ?? '') ?>" id="docStatus"><?= e(InventoryDocs::STATUSES[$status] ?? $status) ?></span>
        </h1>
        <p class="muted"><?= e(InventoryDocs::typeLabel($doc)) ?> · <?= e($doc['branch_code'] . ' · ' . $doc['branch_name']) ?> · <?= e($when($doc['created_at'])) ?></p>
    </div>
    <div class="page-actions">
        <button type="button" class="btn btn--light" data-print><?= icon('printer') ?> Print</button>
        <?php if ($actions['approve']): ?>
            <button type="button" class="btn btn--primary" data-open="approveDialog" id="approveBtn"><?= icon('check') ?> Approve</button>
        <?php endif; ?>
        <?php if ($actions['cancel']): ?>
            <button type="button" class="btn btn--danger" data-open="cancelDialog" id="cancelCountBtn"><?= icon('x') ?> Cancel Count</button>
        <?php endif; ?>
    </div>
</div>

<div class="print-head" aria-hidden="true">
    <strong><?= e(setting('shop_name', 'EXECOM Logistics')) ?></strong>
    <span><?= e(InventoryDocs::typeLabel($doc)) ?> · <?= e($doc['branch_code'] . ' · ' . $doc['branch_name']) ?></span>
</div>

<?php if ($status === 'cancelled'): ?>
    <div class="void-box" role="note">
        <?= icon('alert') ?>
        <div>
            <strong>This stock count was cancelled</strong><?= e(($doc['cancelled_at'] ? ' on ' . $when($doc['cancelled_at']) : '') . ($doc['cancelled_by_name'] ? ' by ' . $doc['cancelled_by_name'] : '')) ?>. Stock was not changed.
            <?php if ($doc['cancel_reason']): ?><p class="void-box__reason">Reason: <?= e($doc['cancel_reason']) ?></p><?php endif; ?>
        </div>
    </div>
<?php elseif ($isCount && in_array($status, ['open', 'submitted'], true) && Branch::current() !== (int) $doc['branch_id']): ?>
    <div class="alert alert--info doc-note" role="note">
        <?= icon('info') ?>
        <span>Switch to <?= e($doc['branch_name']) ?> in the top bar to work on this count.</span>
    </div>
<?php elseif ($isCount && $status === 'open'): ?>
    <div class="alert alert--info doc-note" role="note">
        <?= icon('info') ?>
        <span>Count every item at <?= e($doc['from_warehouse_code'] . ' / ' . $doc['from_location_code']) ?>, save as you go, then submit.
            The quantities shown were frozen when the count was created; stock changes only when the count is approved.</span>
    </div>
<?php elseif ($isCount && $status === 'submitted'): ?>
    <div class="alert alert--warning doc-note" role="note">
        <?= icon('clock') ?>
        <span>Waiting for approval by someone who neither created nor counted it.
            <?= count($withDiff) ?> of <?= count($lines) ?> <?= count($lines) === 1 ? 'item differs' : 'items differ' ?> from the system quantity.</span>
    </div>
<?php endif; ?>

<div class="sale-layout">
    <section class="card">
        <header class="card__head">
            <h2><?= icon($isCount ? 'clipboard' : 'box') ?> <?= $isCount ? 'Count Sheet' : 'Items' ?></h2>
            <span class="muted"><?= count($lines) ?> <?= count($lines) === 1 ? 'item' : 'items' ?><?= $isCount ? ' · ' . $counted . ' counted' : ' · ' . number_format($totalUnits) . ($totalUnits === 1 ? ' unit' : ' units') ?></span>
        </header>

        <?php if ($editable): ?>
            <form method="post" action="<?= e(url('pages/' . $self)) ?>" id="countForm" novalidate>
                <?= Csrf::field() ?>
                <input type="hidden" name="return" value="<?= e($returnTo) ?>">
        <?php endif; ?>

        <div class="table-wrap">
            <table class="table doc-lines<?= $isCount ? ' count-table' : '' ?>" id="docLinesView">
                <thead>
                <tr>
                    <th>#</th>
                    <th>Item</th>
                    <?php if ($isCount): ?>
                        <th class="num">System</th>
                        <th class="num">Counted</th>
                        <th class="num">Difference</th>
                        <?php if ($canCost && $status === 'posted'): ?><th class="num col-opt">Value</th><?php endif; ?>
                    <?php else: ?>
                        <th class="num">Qty</th>
                        <?php if ($canCost && $doc['doc_type'] !== 'transfer'): ?><th class="num">Unit Cost</th><th class="num">Value</th><?php endif; ?>
                    <?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($lines as $i => $line): ?>
                    <?php
                    $lineId  = (int) $line['id'];
                    $track   = (int) $line['track_serial'] === 1;
                    $serials = $line['serials'];
                    ?>
                    <tr data-count-line="<?= $lineId ?>"<?= $isCount ? ' data-system="' . (int) $line['system_qty'] . '"' : '' ?>>
                        <td class="muted"><?= $i + 1 ?></td>
                        <td>
                            <strong class="block"><?= e($line['product_name']) ?></strong>
                            <small class="muted"><?= e($line['product_code']) ?><?= $track ? ' · S/N' : '' ?><?= (int) $line['product_active'] === 1 ? '' : ' · inactive' ?></small>
                            <?php if ($serials && $editable && $track): ?>
                                <fieldset class="sn-pick sn-pick--count">
                                    <legend class="visually-hidden">Serial numbers found of <?= e($line['product_name']) ?></legend>
                                    <div class="sn-pick__list">
                                        <?php foreach ($serials as $s): ?>
                                            <?php $isFound = $oldFound !== null ? isset($oldFound[$s['id']]) : (int) $s['found'] === 1; ?>
                                            <label class="sn-check"><input type="checkbox" name="found[]" value="<?= (int) $s['id'] ?>" data-found<?= $isFound ? ' checked' : '' ?>>
                                                <span class="serial-cell"><?= e($s['serial_no']) ?></span></label>
                                        <?php endforeach; ?>
                                    </div>
                                    <p class="form-hint">Tick every serial number you found.</p>
                                </fieldset>
                            <?php elseif ($serials): ?>
                                <ul class="sn-list sn-list--status" aria-label="Serial numbers">
                                    <?php foreach ($serials as $s): ?>
                                        <?php $missing = $isCount && (int) $s['found'] === 0 && $status !== 'open'; ?>
                                        <li class="<?= $missing ? 'is-missing' : '' ?>"><?= e($s['serial_no']) ?><?= $missing ? ' <span class="badge badge--danger">Not found</span>' : '' ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                            <?= $isCount ? field_error('counts.' . $lineId) : '' ?>
                        </td>
                        <?php if ($isCount): ?>
                            <td class="num"><?= number_format((int) $line['system_qty']) ?></td>
                            <td class="num count-cell">
                                <?php if ($editable && !$track): ?>
                                    <?php $value = $oldCounts !== null ? (string) ($oldCounts[$lineId] ?? $oldCounts[(string) $lineId] ?? '') : ($line['counted_qty'] === null ? '' : (string) (int) $line['counted_qty']); ?>
                                    <input class="form-input num count-input" type="number" name="counts[<?= $lineId ?>]" min="0" max="<?= Products::MAX_STOCK ?>" step="1"
                                           inputmode="numeric" aria-label="Counted quantity of <?= e($line['product_name']) ?>" data-counted
                                           value="<?= e($value) ?>"<?= invalid('counts.' . $lineId) ?>>
                                <?php elseif ($editable): ?>
                                    <strong data-found-count><?= $line['counted_qty'] === null ? '—' : (int) $line['counted_qty'] ?></strong>
                                <?php else: ?>
                                    <?= $line['counted_qty'] === null ? '<span class="muted">—</span>' : number_format((int) $line['counted_qty']) ?>
                                <?php endif; ?>
                            </td>
                            <td class="num <?= e($varClass($line['variance'])) ?>" data-variance><?= e($signed($line['variance'])) ?></td>
                            <?php if ($canCost && $status === 'posted'): ?>
                                <td class="num col-opt <?= e($varClass($line['variance'])) ?>"><?= ($line['line_value'] ?? null) !== null && (int) $line['variance'] !== 0 ? e(money($line['line_value'])) : '<span class="muted">—</span>' ?></td>
                            <?php endif; ?>
                        <?php else: ?>
                            <td class="num"><?= number_format((int) $line['quantity']) ?> <small class="muted"><?= e($line['unit_code'] ?? '') ?></small></td>
                            <?php if ($canCost && $doc['doc_type'] !== 'transfer'): ?>
                                <td class="num"><?= ($line['unit_cost'] ?? null) !== null ? e(money($line['unit_cost'])) : '—' ?></td>
                                <td class="num"><?= ($line['line_value'] ?? null) !== null ? e(money($line['line_value'])) : '—' ?></td>
                            <?php endif; ?>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$lines): ?>
                    <tr><td colspan="5" class="empty">No items on this document.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($editable): ?>
                <div class="form-actions form-actions--inline count-actions">
                    <span class="muted" id="countProgress" aria-live="polite"></span>
                    <button type="submit" class="btn btn--light" name="action" value="save" id="saveCountBtn"><?= icon('save') ?> Save</button>
                    <?php if ($actions['submit']): ?>
                        <button type="submit" class="btn btn--primary" name="action" value="submit" id="submitCountBtn"
                                data-confirm-submit="Submit <?= e($label) ?> for approval? The counted quantities can no longer be changed."><?= icon('check') ?> Submit for Approval</button>
                    <?php endif; ?>
                </div>
            </form>
        <?php endif; ?>

        <dl class="sale-totals<?= $status === 'cancelled' ? ' is-void' : '' ?>">
            <?php if ($isCount): ?>
                <div><dt>Items counted</dt><dd><?= $counted ?> of <?= count($lines) ?></dd></div>
                <?php if ($status === 'posted'): ?>
                    <div><dt>Units adjusted</dt><dd><?= number_format((int) $doc['total_qty']) ?></dd></div>
                <?php endif; ?>
            <?php else: ?>
                <div><dt>Total quantity</dt><dd><?= number_format((int) $doc['total_qty']) ?></dd></div>
            <?php endif; ?>
            <?php if ($canCost && ($doc['total_cost'] ?? null) !== null): ?>
                <div class="sale-totals__grand">
                    <dt><?= $isCount ? 'Net value of differences' : 'Total value (average cost)' ?></dt>
                    <dd id="docTotalCost"><?= e(money($doc['total_cost'])) ?></dd>
                </div>
            <?php endif; ?>
        </dl>
    </section>

    <aside class="form-side">
        <section class="card card--pad">
            <h2 class="card__title">Details</h2>
            <dl class="detail-list doc-details">
                <div><dt>Type</dt><dd><?= e(InventoryDocs::typeLabel($doc)) ?></dd></div>
                <div><dt>Branch</dt><dd><?= e($doc['branch_code'] . ' · ' . $doc['branch_name']) ?></dd></div>
                <div><dt><?= $isCount ? 'Location' : 'From' ?></dt><dd><?= e($from . $kindLabel($doc['from_location_kind'], $doc['from_location_name'])) ?></dd></div>
                <?php if ($to !== null): ?><div><dt>To</dt><dd><?= e($to . $kindLabel($doc['to_location_kind'], $doc['to_location_name'])) ?></dd></div><?php endif; ?>
                <div><dt>Created by</dt><dd><?= e($doc['created_by_name']) ?></dd></div>
                <div><dt>Created</dt><dd><?= e($when($doc['created_at'])) ?></dd></div>
                <?php if ($doc['submitted_at']): ?>
                    <div><dt>Counted by</dt><dd><?= e($doc['submitted_by_name'] ?? '—') ?></dd></div>
                    <div><dt>Submitted</dt><dd><?= e($when($doc['submitted_at'])) ?></dd></div>
                <?php endif; ?>
                <?php if ($doc['posted_at']): ?>
                    <div><dt><?= $isCount ? 'Approved by' : 'Posted by' ?></dt><dd><?= e($doc['posted_by_name'] ?? '—') ?></dd></div>
                    <div><dt>Posted</dt><dd><?= e($when($doc['posted_at'])) ?></dd></div>
                <?php endif; ?>
            </dl>
            <?php if ($doc['reason']): ?>
                <p class="rr-notes"><span class="form-label">Reason</span><?= e($doc['reason']) ?></p>
            <?php endif; ?>
        </section>
    </aside>
</div>

<?php if ($actions['approve']): ?>
    <dialog class="modal modal--wide" id="approveDialog" aria-labelledby="approveTitle">
        <form class="modal__body" method="post" action="<?= e(url('pages/' . $self)) ?>">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="return" value="<?= e($returnTo) ?>">
            <header class="modal__head">
                <h2 id="approveTitle">Approve <?= e($label) ?>?</h2>
                <button type="button" class="modal__close" data-close aria-label="Close"><?= icon('x') ?></button>
            </header>
            <?php if (!$withDiff): ?>
                <p class="approve-ok"><?= icon('check') ?> Every item matches the system quantity. Approving closes the count without changing stock.</p>
            <?php else: ?>
                <p class="muted">Approving posts these differences to <?= e($doc['from_warehouse_code'] . ' / ' . $doc['from_location_code']) ?>.
                    The other <?= count($lines) - count($withDiff) ?> <?= count($lines) - count($withDiff) === 1 ? 'item matches' : 'items match' ?>.</p>
                <div class="table-wrap approve-table-wrap">
                    <table class="table approve-table" id="approveTable">
                        <thead>
                        <tr>
                            <th>Item</th><th class="num">System</th><th class="num">Counted</th><th class="num">Difference</th>
                            <?php if ($canCost): ?><th class="num">Est. value</th><?php endif; ?>
                        </tr>
                        </thead>
                        <tbody>
                        <?php $estTotal = 0; ?>
                        <?php foreach ($withDiff as $line): ?>
                            <?php $ev = $canCost ? $estValue($line) : null; $estTotal += $ev ?? 0; ?>
                            <tr>
                                <td><strong class="block"><?= e($line['product_name']) ?></strong><small class="muted"><?= e($line['product_code']) ?><?= (int) $line['track_serial'] === 1 ? ' · S/N' : '' ?></small></td>
                                <td class="num"><?= number_format((int) $line['system_qty']) ?></td>
                                <td class="num"><?= number_format((int) $line['counted_qty']) ?></td>
                                <td class="num <?= e($varClass($line['variance'])) ?>"><strong><?= e($signed($line['variance'])) ?></strong></td>
                                <?php if ($canCost): ?><td class="num <?= e($varClass($line['variance'])) ?>"><?= $ev === null ? '—' : e(money(from_cents($ev))) ?></td><?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <?php if ($canCost): ?>
                            <tfoot><tr><th colspan="4">Net value (at the current average cost)</th><th class="num"><?= e(money(from_cents($estTotal))) ?></th></tr></tfoot>
                        <?php endif; ?>
                    </table>
                </div>
                <p class="form-hint">Serial-tracked items: serial numbers not found are marked removed; extra units of serial items are not added (receive them instead).
                    Sales made since the count started are taken into account.</p>
            <?php endif; ?>
            <footer class="modal__foot">
                <button type="button" class="btn btn--light" data-close>Not Yet</button>
                <button type="submit" class="btn btn--primary" id="approveSubmit"><?= icon('check') ?> Approve &amp; Post</button>
            </footer>
        </form>
    </dialog>
<?php endif; ?>

<?php if ($actions['cancel']): ?>
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
            <p class="void-warning"><?= icon('alert') ?><span>The count is closed without changing stock. The counted quantities stay on record.</span></p>
            <label class="form-field">
                <span class="form-label">Reason *</span>
                <textarea class="form-input" name="reason" id="cancelReason" required minlength="3" maxlength="255"
                          placeholder="e.g. Wrong location, recount next week"></textarea>
            </label>
            <footer class="modal__foot">
                <button type="button" class="btn btn--light" data-close>Keep Count</button>
                <button type="submit" class="btn btn--danger"><?= icon('x') ?> Cancel Count</button>
            </footer>
        </form>
    </dialog>
<?php endif; ?>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
