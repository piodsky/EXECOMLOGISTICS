<?php
/**
 * One branch transfer: header, lines (requested / approved / released / received), serials, Print.
 * The form follows Transfers::actions():
 *   requested -> Approve (sending branch, not the requester): approved qty per line (0 = not sent)
 *   approved  -> Release (sending branch): serial numbers for serial-tracked lines
 *   released  -> Receive (receiving branch, not the releaser): received qty / serials that arrived + note when short
 *   requested / approved -> Cancel with reason (either side)
 * Every action re-checks everything in Transfers. Cost columns only with products.cost.
 * pages/transfer-view.php?id=5&return=transfers.php
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('transfers');

$id = input_int($_GET, 'id', 1) ?? throw new HttpException(404, 'Transfer not found.');
$t  = Transfers::find($id) ?? throw new HttpException(404, 'Transfer not found.');

$returnTo = safe_return($_POST['return'] ?? $_GET['return'] ?? null, 'transfers.php');
$self     = 'transfer-view.php?' . http_build_query(['id' => $id, 'return' => $returnTo]);
$label    = Transfers::label($t);

if (is_post()) {
    Csrf::verifyRequest();
    $action = input_string($_POST, 'action', 10);
    $uid    = (int) Auth::id();
    $qty    = is_array($_POST['qty'] ?? null) ? array_filter($_POST['qty'], 'is_string') : [];
    $sn     = is_array($_POST['serials'] ?? null) ? $_POST['serials'] : [];
    $sn     = array_map(static fn ($v): array => is_array($v) ? array_values(array_filter($v, 'is_string')) : [], $sn);
    $arr    = is_array($_POST['arrived'] ?? null) ? array_values(array_filter($_POST['arrived'], 'is_string')) : [];
    $note   = input_string($_POST, 'receive_note', 300);
    try {
        switch ($action) {
            case 'approve':
                $no = Transfers::approve($id, $qty, $uid);
                flash('success', "{$no} was approved. Release it when the items are packed.");
                break;
            case 'release':
                $no = Transfers::release($id, $sn, $uid);
                flash('success', "{$no} was released: the items left this branch and are in transit.");
                break;
            case 'receive':
                $no = Transfers::receive($id, $qty, $arr, $note, $uid);
                flash('success', "{$no} was received. The items are now in this branch's stock.");
                break;
            case 'cancel':
                Transfers::cancel($id, input_string($_POST, 'reason', 300), $uid);
                flash('success', "{$label} was cancelled. Stock was not changed.");
                break;
            default:
                throw new HttpException(400, 'Unknown action.');
        }
    } catch (HttpException $e) {
        if (is_array($e->details['errors'] ?? null)) {
            flash_errors($e->details['errors']);
        }
        flash_old(['action' => (string) $action, 'qty' => $qty, 'serials' => $sn, 'arrived' => $arr, 'receive_note' => $note]);
        flash('error', $e->getMessage());
    }
    redirect('pages/' . $self);
}

// ---------------------------------------------------------------------
// View data
// ---------------------------------------------------------------------
$status  = $t['status'];
$canCost = Auth::can('products.cost');
$actions = Transfers::actions($t);
$lines   = $t['lines'];
$page['title'] = $label;

$old       = has_old() ? old_input() : [];
$oldQty    = is_array($old['qty'] ?? null) ? $old['qty'] : null;
$oldSn     = is_array($old['serials'] ?? null) ? $old['serials'] : null;
$oldArr    = is_array($old['arrived'] ?? null) ? array_flip(array_map('intval', $old['arrived'])) : null;
$errors    = form_errors();

// Release: serials available at the sending POS location per serial-tracked line.
$available = [];
if ($actions['release']) {
    $loc = Branch::defaultLocation((int) $t['from_branch_id']);
    foreach ($lines as $l) {
        if ((int) $l['track_serial'] === 1 && (int) $l['qty_approved'] > 0) {
            $available[(int) $l['id']] = Serials::available((int) $l['product_id'], $loc['id']);
        }
    }
}
$mode = $actions['approve'] ? 'approve' : ($actions['release'] ? 'release' : ($actions['receive'] ? 'receive' : null));

$statusBadge = ['requested' => 'badge--info', 'approved' => 'badge--warning', 'released' => 'badge--warning',
                'received' => 'badge--success', 'cancelled' => 'badge--danger'];
$when = static fn (?string $ts): string => $ts ? date('M j, Y g:i A', strtotime($ts)) : '—';
$num  = static fn ($v): string => $v === null ? '—' : number_format((int) $v);
$showReleased = in_array($status, ['released', 'received'], true);

$pageStyles  = ['css/sales.css', 'css/receiving.css', 'css/stock-docs.css', 'css/transfers.css'];
$pageScripts = ['js/transfers.js'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <a class="back-link" href="<?= e(url('pages/' . $returnTo)) ?>"><?= icon('arrow-left') ?> Branch Transfers</a>
        <h1 class="sale-title">
            <span id="transferTitle"><?= e($label) ?></span>
            <span class="badge <?= e($statusBadge[$status] ?? '') ?>" id="transferStatus"><?= e(Transfers::STATUSES[$status] ?? $status) ?></span>
        </h1>
        <p class="muted"><?= e($t['from_code'] . ' · ' . $t['from_name']) ?> → <?= e($t['to_code'] . ' · ' . $t['to_name']) ?> · requested <?= e($when($t['requested_at'])) ?></p>
    </div>
    <div class="page-actions">
        <button type="button" class="btn btn--light" data-print><?= icon('printer') ?> Print Slip</button>
        <?php if ($actions['cancel']): ?>
            <button type="button" class="btn btn--danger" data-open="cancelDialog" id="cancelTransferBtn"><?= icon('x') ?> Cancel Transfer</button>
        <?php endif; ?>
    </div>
</div>

<div class="print-head" aria-hidden="true">
    <strong><?= e(setting('shop_name', 'EXECOM Logistics')) ?></strong>
    <span>Branch Transfer Slip · <?= e($t['from_code'] . ' → ' . $t['to_code']) ?></span>
</div>

<?php if ($status === 'cancelled'): ?>
    <div class="void-box" role="note">
        <?= icon('alert') ?>
        <div>
            <strong>This transfer was cancelled</strong><?= e(($t['cancelled_at'] ? ' on ' . $when($t['cancelled_at']) : '') . ($t['cancelled_by_name'] ? ' by ' . $t['cancelled_by_name'] : '')) ?>. Stock was not changed.
            <?php if ($t['cancel_reason']): ?><p class="void-box__reason">Reason: <?= e($t['cancel_reason']) ?></p><?php endif; ?>
        </div>
    </div>
<?php elseif ($mode === 'approve'): ?>
    <div class="alert alert--info doc-note" role="note"><?= icon('info') ?>
        <span><?= e($t['to_name']) ?> asks for these items. Approve what you can send (0 = not sent). Approval does not move or reserve stock.</span></div>
<?php elseif ($mode === 'release'): ?>
    <div class="alert alert--info doc-note" role="note"><?= icon('info') ?>
        <span>Pack the items, choose the serial numbers you are sending, then release. Stock leaves this branch's POS location now and is in transit until <?= e($t['to_name']) ?> receives it.</span></div>
<?php elseif ($mode === 'receive'): ?>
    <div class="alert alert--info doc-note" role="note"><?= icon('info') ?>
        <span>Check what arrived. Enter the quantity received and untick serial numbers that did not arrive; explain any shortage. Received items go to this branch's POS location.</span></div>
<?php elseif ($status === 'requested'): ?>
    <div class="alert alert--warning doc-note" role="note"><?= icon('clock') ?>
        <span>Waiting for <?= e($t['from_name']) ?> to approve<?= Branch::current() === (int) $t['from_branch_id'] && (int) Auth::id() === (int) $t['requested_by'] ? ' (by someone other than the requester)' : '' ?>.</span></div>
<?php elseif ($status === 'approved'): ?>
    <div class="alert alert--warning doc-note" role="note"><?= icon('clock') ?><span>Approved. Waiting for <?= e($t['from_name']) ?> to release it.</span></div>
<?php elseif ($status === 'released'): ?>
    <div class="alert alert--warning doc-note" role="note"><?= icon('truck') ?>
        <span>In transit since <?= e($when($t['released_at'])) ?>. <?= e($t['to_name']) ?> receives it<?= (int) Auth::id() === (int) $t['released_by'] ? ' (by someone other than the releaser)' : '' ?>.</span></div>
<?php endif; ?>

<div class="sale-layout">
    <section class="card">
        <header class="card__head">
            <h2><?= icon('box') ?> Items</h2>
            <span class="muted"><?= count($lines) ?> <?= count($lines) === 1 ? 'item' : 'items' ?></span>
        </header>

        <?php if ($mode !== null): ?>
            <form method="post" action="<?= e(url('pages/' . $self)) ?>" id="transferActionForm" novalidate>
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="<?= e($mode) ?>">
                <input type="hidden" name="return" value="<?= e($returnTo) ?>">
        <?php endif; ?>

        <div class="table-wrap">
            <table class="table doc-lines bt-lines" id="transferLinesView">
                <thead>
                <tr>
                    <th>#</th>
                    <th>Item</th>
                    <th class="num">Requested</th>
                    <th class="num">Approved</th>
                    <?php if ($showReleased): ?><th class="num">Sent</th><?php endif; ?>
                    <?php if ($status === 'received' || $mode === 'receive'): ?><th class="num">Received</th><?php endif; ?>
                    <?php if ($canCost && $showReleased): ?><th class="num col-opt">Unit Cost</th><th class="num col-opt">Value</th><?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($lines as $i => $l): ?>
                    <?php
                    $lid   = (int) $l['id'];
                    $track = (int) $l['track_serial'] === 1;
                    $short = $l['qty_received'] !== null && (int) $l['qty_received'] < (int) $l['qty_released'];
                    ?>
                    <tr data-line="<?= $lid ?>">
                        <td class="muted"><?= $i + 1 ?></td>
                        <td>
                            <strong class="block"><?= e($l['product_name']) ?></strong>
                            <small class="muted"><?= e($l['product_code']) ?><?= $track ? ' · S/N' : '' ?><?= isset($l['source_qty']) ? ' · ' . number_format((int) $l['source_qty']) . ' in stock here' : '' ?></small>

                            <?php if ($mode === 'release' && $track && (int) $l['qty_approved'] > 0): ?>
                                <?php $chosen = $oldSn !== null ? array_flip(array_map('intval', $oldSn[$lid] ?? $oldSn[(string) $lid] ?? [])) : []; ?>
                                <fieldset class="sn-pick" data-pick="<?= (int) $l['qty_approved'] ?>">
                                    <legend class="form-label">Serial numbers to send <small class="muted">(choose <?= (int) $l['qty_approved'] ?>)</small></legend>
                                    <div class="sn-pick__list">
                                        <?php foreach ($available[$lid] ?? [] as $s): ?>
                                            <label class="sn-check"><input type="checkbox" name="serials[<?= $lid ?>][]" value="<?= (int) $s['id'] ?>"<?= isset($chosen[(int) $s['id']]) ? ' checked' : '' ?>>
                                                <span class="serial-cell"><?= e($s['serial_no']) ?></span></label>
                                        <?php endforeach; ?>
                                        <?php if (!($available[$lid] ?? [])): ?><span class="muted">No serial numbers in stock at the POS location.</span><?php endif; ?>
                                    </div>
                                    <p class="form-hint" data-pick-count aria-live="polite"></p>
                                </fieldset>
                                <?= field_error('serials.' . $lid) ?>
                            <?php elseif ($mode === 'receive' && $l['serials']): ?>
                                <fieldset class="sn-pick" data-arrived>
                                    <legend class="form-label">Serial numbers that arrived</legend>
                                    <div class="sn-pick__list">
                                        <?php foreach ($l['serials'] as $s): ?>
                                            <label class="sn-check"><input type="checkbox" name="arrived[]" value="<?= (int) $s['id'] ?>"<?= $oldArr === null || isset($oldArr[$s['id']]) ? ' checked' : '' ?>>
                                                <span class="serial-cell"><?= e($s['serial_no']) ?></span></label>
                                        <?php endforeach; ?>
                                    </div>
                                </fieldset>
                            <?php elseif ($l['serials']): ?>
                                <ul class="sn-list sn-list--status" aria-label="Serial numbers">
                                    <?php foreach ($l['serials'] as $s): ?>
                                        <li class="<?= $s['received'] === 0 ? 'is-missing' : '' ?>"><?= e($s['serial_no']) ?><?= $s['received'] === 0 ? ' <span class="badge badge--danger">Missing</span>' : '' ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                            <?= field_error('qty.' . $lid) ?>
                        </td>
                        <td class="num"><?= $num($l['qty_requested']) ?> <small class="muted"><?= e($l['unit_code'] ?? '') ?></small></td>
                        <td class="num">
                            <?php if ($mode === 'approve'): ?>
                                <?php $v = $oldQty !== null ? (string) ($oldQty[$lid] ?? $oldQty[(string) $lid] ?? '') : (string) (int) $l['qty_requested']; ?>
                                <input class="form-input num bt-qty" type="number" name="qty[<?= $lid ?>]" min="0" max="<?= (int) $l['qty_requested'] ?>" step="1"
                                       inputmode="numeric" aria-label="Approved quantity of <?= e($l['product_name']) ?>" value="<?= e($v) ?>"<?= invalid('qty.' . $lid) ?>>
                            <?php else: ?>
                                <?= $num($l['qty_approved']) ?>
                            <?php endif; ?>
                        </td>
                        <?php if ($showReleased): ?><td class="num"><?= $num($l['qty_released']) ?></td><?php endif; ?>
                        <?php if ($mode === 'receive'): ?>
                            <td class="num">
                                <?php if ((int) $l['qty_released'] === 0): ?>
                                    <span class="muted">—</span>
                                <?php elseif ($l['serials']): ?>
                                    <strong data-arrived-count><?= count($l['serials']) ?></strong>
                                <?php else: ?>
                                    <?php $v = $oldQty !== null ? (string) ($oldQty[$lid] ?? $oldQty[(string) $lid] ?? '') : (string) (int) $l['qty_released']; ?>
                                    <input class="form-input num bt-qty" type="number" name="qty[<?= $lid ?>]" min="0" max="<?= (int) $l['qty_released'] ?>" step="1"
                                           inputmode="numeric" aria-label="Received quantity of <?= e($l['product_name']) ?>" value="<?= e($v) ?>"<?= invalid('qty.' . $lid) ?>>
                                <?php endif; ?>
                            </td>
                        <?php elseif ($status === 'received'): ?>
                            <td class="num<?= $short ? ' text-danger' : '' ?>"><?= $num($l['qty_received']) ?><?= $short ? ' <small>(' . ((int) $l['qty_released'] - (int) $l['qty_received']) . ' short)</small>' : '' ?></td>
                        <?php endif; ?>
                        <?php if ($canCost && $showReleased): ?>
                            <td class="num col-opt"><?= ($l['unit_cost'] ?? null) !== null ? e(money($l['unit_cost'])) : '—' ?></td>
                            <td class="num col-opt"><?= ($l['line_value'] ?? null) !== null ? e(money($l['line_value'])) : '—' ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($mode !== null): ?>
                <div class="form-actions form-actions--inline bt-actions">
                    <?php if ($mode === 'receive'): ?>
                        <label class="form-field bt-note">
                            <span class="form-label">Note <small class="muted">(required when something is short or missing)</small></span>
                            <input class="form-input" name="receive_note" maxlength="255" value="<?= e(old('receive_note')) ?>"
                                   placeholder="e.g. One box damaged in delivery"<?= invalid('receive_note') ?>>
                            <?= field_error('receive_note') ?>
                        </label>
                    <?php endif; ?>
                    <?php [$btnLabel, $btnIcon, $confirm] = match ($mode) {
                        'approve' => ['Approve', 'check', "Approve {$label}? Stock is not moved yet."],
                        'release' => ['Release (Send)', 'truck', "Release {$label}? The items leave this branch's stock now and are in transit."],
                        default   => ['Receive', 'box', "Receive {$label}? The received items enter this branch's stock now."],
                    }; ?>
                    <button type="submit" class="btn btn--primary" id="transferActionBtn" data-confirm-submit="<?= e($confirm) ?>"><?= icon($btnIcon) ?> <?= e($btnLabel) ?></button>
                </div>
            </form>
        <?php endif; ?>

        <dl class="sale-totals<?= $status === 'cancelled' ? ' is-void' : '' ?>">
            <div><dt><?= $showReleased ? 'Units sent' : ($status === 'requested' ? 'Units requested' : 'Units approved') ?></dt><dd><?= number_format((int) $t['total_qty']) ?></dd></div>
            <?php if ($canCost && ($t['total_cost'] ?? null) !== null): ?>
                <div class="sale-totals__grand"><dt>Value sent (sender's average cost)</dt><dd id="transferTotalCost"><?= e(money($t['total_cost'])) ?></dd></div>
            <?php endif; ?>
        </dl>
    </section>

    <aside class="form-side">
        <section class="card card--pad">
            <h2 class="card__title">Details</h2>
            <dl class="detail-list doc-details">
                <div><dt>From</dt><dd><?= e($t['from_code'] . ' · ' . $t['from_name']) ?></dd></div>
                <div><dt>To</dt><dd><?= e($t['to_code'] . ' · ' . $t['to_name']) ?></dd></div>
                <div><dt>Requested by</dt><dd><?= e($t['requested_by_name']) ?><small class="muted block"><?= e($when($t['requested_at'])) ?></small></dd></div>
                <?php if ($t['approved_at']): ?><div><dt>Approved by</dt><dd><?= e($t['approved_by_name'] ?? '—') ?><small class="muted block"><?= e($when($t['approved_at'])) ?></small></dd></div><?php endif; ?>
                <?php if ($t['released_at']): ?><div><dt>Released by</dt><dd><?= e($t['released_by_name'] ?? '—') ?><small class="muted block"><?= e($when($t['released_at'])) ?></small></dd></div><?php endif; ?>
                <?php if ($t['received_at']): ?><div><dt>Received by</dt><dd><?= e($t['received_by_name'] ?? '—') ?><small class="muted block"><?= e($when($t['received_at'])) ?></small></dd></div><?php endif; ?>
            </dl>
            <?php if ($t['notes']): ?><p class="rr-notes"><span class="form-label">Note</span><?= e($t['notes']) ?></p><?php endif; ?>
            <?php if ($t['receive_note']): ?><p class="rr-notes"><span class="form-label">Receiving note</span><?= e($t['receive_note']) ?></p><?php endif; ?>
        </section>
        <section class="card card--pad bt-signatures" aria-label="Signatures">
            <h2 class="card__title">Signatures</h2>
            <div class="bt-sign"><span>Released by</span></div>
            <div class="bt-sign"><span>Delivered by</span></div>
            <div class="bt-sign"><span>Received by</span></div>
        </section>
    </aside>
</div>

<?php if ($actions['cancel']): ?>
    <dialog class="modal" id="cancelDialog" aria-labelledby="cancelTitle"<?= ($old['action'] ?? '') === 'cancel' ? ' data-reopen' : '' ?>>
        <form class="modal__body" method="post" action="<?= e(url('pages/' . $self)) ?>" data-confirm="Cancel <?= e($label) ?>? This cannot be undone.">
            <header class="modal__head">
                <h2 id="cancelTitle">Cancel <?= e($label) ?>?</h2>
                <button type="button" class="modal__close" data-close aria-label="Close"><?= icon('x') ?></button>
            </header>
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="cancel">
            <input type="hidden" name="return" value="<?= e($returnTo) ?>">
            <p class="void-warning"><?= icon('alert') ?><span>The transfer is closed. No stock was moved, so nothing changes.</span></p>
            <label class="form-field">
                <span class="form-label">Reason *</span>
                <textarea class="form-input" name="reason" id="cancelReason" required minlength="3" maxlength="255"
                          placeholder="e.g. Ordered from the supplier instead"<?= invalid('reason') ?>></textarea>
                <?= field_error('reason') ?>
            </label>
            <footer class="modal__foot">
                <button type="button" class="btn btn--light" data-close>Keep Transfer</button>
                <button type="submit" class="btn btn--danger"><?= icon('x') ?> Cancel Transfer</button>
            </footer>
        </form>
    </dialog>
<?php endif; ?>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
