<?php
/**
 * New delivery receipt for a customer order (customer_orders.deliver, order confirmed / partially delivered, working in
 * its branch): quantity per line (0 = not on this trip; at most what is still to deliver), serial numbers for
 * serial-tracked items, delivered by, notes. Releasing it takes the stock out of the branch now.
 * pages/dr-form.php?order=5[&return=customer-orders.php]
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('customer-orders');
Auth::requirePermission('customer_orders.deliver');

$orderId  = input_int($_GET, 'order', 1) ?? throw new HttpException(404, 'Customer order not found.');
$returnTo = safe_return($_POST['return'] ?? $_GET['return'] ?? null, 'customer-orders.php');
$self     = 'dr-form.php?' . http_build_query(['order' => $orderId, 'return' => $returnTo]);
$back     = 'pages/co-view.php?' . http_build_query(['id' => $orderId, 'return' => $returnTo]);

if (is_post()) {
    Csrf::verifyRequest();
    $qty = is_array($_POST['qty'] ?? null) ? array_filter($_POST['qty'], 'is_string') : [];
    $sn  = is_array($_POST['serials'] ?? null) ? $_POST['serials'] : [];
    $sn  = array_map(static fn ($v): array => is_array($v) ? array_values(array_filter($v, 'is_string')) : [], $sn);
    try {
        $res = CustomerDeliveries::release($orderId, $qty, $sn, input_string($_POST, 'delivered_by', 101),
            input_string($_POST, 'notes', 256), (int) Auth::id());
    } catch (HttpException $e) {
        if ($e->status === 403) {
            throw $e;
        }
        if (is_array($e->details['errors'] ?? null)) {
            flash_errors($e->details['errors']);
        }
        flash_old(['qty' => $qty, 'serials' => $sn, 'delivered_by' => input_string($_POST, 'delivered_by', 101), 'notes' => input_string($_POST, 'notes', 256)]);
        flash('error', $e->getMessage());
        redirect('pages/' . $self);
    }
    flash('success', "{$res['dr_no']} was released: the items left the branch. Print it for the delivery and record the receiver when it arrives.");
    redirect('pages/dr-view.php?' . http_build_query(['id' => $res['id'], 'return' => $returnTo]));
}

try {
    $pre = CustomerDeliveries::releasePrefill($orderId);
} catch (HttpException $e) {
    if ($e->status === 403 || $e->status === 404) {
        throw $e;
    }
    flash('error', $e->getMessage());
    redirect($back);
}
$o     = $pre['order'];
$lines = $pre['lines'];
$label = CustomerOrders::label($o);
$old    = has_old() ? old_input() : [];
$oldQty = is_array($old['qty'] ?? null) ? $old['qty'] : null;
$oldSn  = is_array($old['serials'] ?? null) ? $old['serials'] : null;
$page['title'] = 'Delivery for ' . $label;

$pageStyles  = ['css/sales.css', 'css/receiving.css', 'css/stock-docs.css', 'css/transfers.css', 'css/purchasing.css'];
$pageScripts = ['js/purchasing.js'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <a class="back-link" href="<?= e(url($back)) ?>"><?= icon('arrow-left') ?> <?= e($label) ?></a>
        <h1>New Delivery Receipt</h1>
        <p class="muted"><?= e($o['customer_name']) ?> · PO <?= e($o['customer_po_no']) ?><?= $o['place_of_delivery'] ? ' · deliver to ' . e($o['place_of_delivery']) : '' ?></p>
    </div>
</div>
<div class="alert alert--info doc-note" role="note"><?= icon('info') ?>
    <span>Enter what goes on this trip (0 = later). Releasing takes the items out of the branch now, using the units reserved for this order.</span></div>

<form method="post" action="<?= e(url('pages/' . $self)) ?>" id="drForm" novalidate data-confirm="Release this delivery receipt? The items leave the branch stock now.">
    <?= Csrf::field() ?>
    <input type="hidden" name="return" value="<?= e($returnTo) ?>">
    <div class="sale-layout">
        <section class="card">
            <header class="card__head"><h2><?= icon('box') ?> Items to deliver</h2></header>
            <div class="table-wrap">
                <table class="table doc-lines bt-lines" id="drLines">
                    <thead>
                    <tr><th>#</th><th>Item</th><th class="num">Ordered</th><th class="num">Still to deliver</th><th class="num">This delivery</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($lines as $i => $l): ?>
                        <?php
                        $lid   = (int) $l['id'];
                        $track = (int) $l['track_serial'] === 1;
                        $v     = $oldQty !== null ? (string) ($oldQty[$lid] ?? $oldQty[(string) $lid] ?? '0') : (string) $l['to_deliver'];
                        $chosen = $oldSn !== null ? array_flip(array_map('intval', $oldSn[$lid] ?? $oldSn[(string) $lid] ?? [])) : [];
                        ?>
                        <tr data-line="<?= $lid ?>">
                            <td class="muted"><?= $i + 1 ?></td>
                            <td>
                                <strong class="block"><?= e($l['product_name']) ?></strong>
                                <small class="muted"><?= e($l['product_code']) ?><?= $track ? ' · S/N' : '' ?> · <?= number_format((int) $l['on_hand']) ?> on hand</small>
                                <?php if ($track): ?>
                                    <fieldset class="sn-pick" data-pick="<?= (int) $v ?>">
                                        <legend class="form-label">Serial numbers to deliver</legend>
                                        <div class="sn-pick__list">
                                            <?php foreach ($l['available'] as $s): ?>
                                                <label class="sn-check"><input type="checkbox" name="serials[<?= $lid ?>][]" value="<?= (int) $s['id'] ?>"<?= isset($chosen[(int) $s['id']]) ? ' checked' : '' ?>>
                                                    <span class="serial-cell"><?= e($s['serial_no']) ?></span></label>
                                            <?php endforeach; ?>
                                            <?php if (!$l['available']): ?><span class="muted">No serial numbers in stock at the branch.</span><?php endif; ?>
                                        </div>
                                        <p class="form-hint" data-pick-count aria-live="polite"></p>
                                    </fieldset>
                                    <?= field_error('serials.' . $lid) ?>
                                <?php endif; ?>
                                <?= field_error('qty.' . $lid) ?>
                            </td>
                            <td class="num"><?= number_format((int) $l['qty_ordered']) ?></td>
                            <td class="num pu-due"><?= number_format((int) $l['to_deliver']) ?></td>
                            <td class="num">
                                <input class="form-input num bt-qty" type="number" name="qty[<?= $lid ?>]" min="0" max="<?= (int) $l['to_deliver'] ?>" step="1"
                                       inputmode="numeric" aria-label="Quantity of <?= e($l['product_name']) ?> to deliver" data-pick-qty value="<?= e($v) ?>"<?= invalid('qty.' . $lid) ?>>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <aside class="form-side">
            <section class="card card--pad">
                <h2 class="card__title">Delivery</h2>
                <label class="form-field">
                    <span class="form-label">Delivered by <small class="muted">(driver / staff, optional)</small></span>
                    <input class="form-input" name="delivered_by" maxlength="100" value="<?= e((string) ($old['delivered_by'] ?? '')) ?>"<?= invalid('delivered_by') ?>>
                    <?= field_error('delivered_by') ?>
                </label>
                <label class="form-field">
                    <span class="form-label">Notes <small class="muted">(printed on the DR)</small></span>
                    <input class="form-input" name="notes" maxlength="255" value="<?= e((string) ($old['notes'] ?? '')) ?>"<?= invalid('notes') ?>>
                    <?= field_error('notes') ?>
                </label>
                <div class="form-actions form-actions--inline">
                    <a class="btn btn--light" href="<?= e(url($back)) ?>">Cancel</a>
                    <button type="submit" class="btn btn--primary" id="releaseDrBtn"><?= icon('truck') ?> Release Delivery</button>
                </div>
            </section>
        </aside>
    </div>
</form>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
