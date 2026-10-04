<?php
/**
 * Customer PO (PO Outgoing) draft: create / edit (customer_orders.manage), Save Draft, Save & Send for Confirmation,
 * Delete Draft. Header = the customer's purchase order (PO no. / date, end-user, place of delivery, terms, deadline,
 * mode of procurement, award / BAC reference); lines = product, quantity, agreed unit price (VAT-exclusive), reason
 * when below the suggested price. PRG with flash_old / flash_errors (line errors keyed items.N.field).
 * ?quote=ID (new order only): prefilled from a sent quotation; saving it marks the quotation won.
 * pages/co-form.php[?id=5 | ?quote=3][&return=customer-orders.php]
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('customer-orders');
Auth::requirePermission('customer_orders.manage');

$id = input_int($_GET, 'id', 1);
$o  = null;
if ($id !== null) {
    $o = CustomerOrders::find($id) ?? throw new HttpException(404, 'Customer order not found.');
}
$returnTo = safe_return($_POST['return'] ?? $_GET['return'] ?? null, 'customer-orders.php');
$quote    = null;
if ($o === null && ($quoteId = input_int($_GET, 'quote', 1)) !== null) {
    $quote = Quotations::find($quoteId) ?? throw new HttpException(404, 'Quotation not found.');
    if (!Quotations::actions($quote)['order']) {
        flash('error', "No customer PO can be made from {$quote['quote_no']} now (it must be sent, at your current branch).");
        redirect('pages/quote-view.php?id=' . $quoteId);
    }
}
$self     = 'co-form.php?' . http_build_query(array_filter(['id' => $id, 'quote' => $quote['id'] ?? null, 'return' => $returnTo]));
$viewPath = static fn (int $oid): string => 'pages/co-view.php?' . http_build_query(['id' => $oid, 'return' => $returnTo]);

if ($o !== null && $o['status'] !== 'draft') {
    flash('error', CustomerOrders::label($o) . ' is ' . strtolower(CustomerOrders::STATUSES[$o['status']]) . ' and can no longer be edited.');
    redirect($viewPath($id));
}

if (is_post()) {
    Csrf::verifyRequest();
    $action = input_string($_POST, 'action', 10);
    if ($action === 'delete') {
        if ($o === null) {
            throw new HttpException(404, 'Customer order not found.');
        }
        try {
            CustomerOrders::deleteDraft($id);
        } catch (HttpException $e) {
            flash('error', $e->getMessage());
            redirect('pages/' . $self);
        }
        flash('success', 'Draft Order #' . $id . ' was deleted.');
        redirect('pages/' . $returnTo);
    }
    if ($action !== 'save' && $action !== 'submit') {
        throw new HttpException(400, 'Unknown action.');
    }
    $keepForm = static function (): void {
        $items = [];
        foreach (is_array($_POST['items'] ?? null) ? array_values($_POST['items']) : [] as $line) {
            $items[] = is_array($line) ? array_filter($line, 'is_string') : [];
        }
        flash_old(array_filter($_POST, 'is_string') + ['items' => $items]);
    };
    [$data, $errors] = CustomerOrders::validate($_POST);
    if ($errors) {
        $keepForm();
        flash_errors($errors);
        flash('error', $errors['items'] ?? 'Please fix the highlighted fields.');
        redirect('pages/' . $self);
    }
    try {
        $savedId = CustomerOrders::saveDraft($id, $data, (int) Auth::id());
    } catch (HttpException $e) {
        if ($e->status === 403) {
            throw $e;
        }
        $keepForm();
        flash('error', $e->getMessage());
        redirect('pages/' . $self);
    }
    if ($action === 'save') {
        flash('success', 'Draft Order #' . $savedId . ' was saved.');
        redirect($viewPath($savedId));
    }
    try {
        CustomerOrders::submit($savedId, (int) Auth::id());
    } catch (HttpException $e) {
        flash('error', 'The draft was saved but not sent: ' . $e->getMessage());
        redirect('pages/co-form.php?' . http_build_query(['id' => $savedId, 'return' => $returnTo]));
    }
    flash('success', 'Draft Order #' . $savedId . ' was sent for confirmation. Its stock is reserved when a branch admin confirms it.');
    redirect($viewPath($savedId));
}

// ---------------------------------------------------------------------
// Form data
// ---------------------------------------------------------------------
$concrete = Branch::isConcrete();
if (has_old()) {
    $old  = old_input();
    $rows = is_array($old['items'] ?? null) ? array_values($old['items']) : [];
} elseif ($quote !== null) {
    $rows = array_map(static fn (array $l): array => [
        'product_id'   => (string) $l['product_id'],
        'quantity'     => (string) $l['quantity'],
        'unit_price'   => (string) $l['unit_price'],
        'price_reason' => (string) ($l['price_reason'] ?? ''),
    ], $quote['lines']);
} elseif ($o !== null) {
    $rows = array_map(static fn (array $l): array => [
        'product_id'   => (string) $l['product_id'],
        'quantity'     => (string) $l['qty_ordered'],
        'unit_price'   => (string) $l['unit_price'],
        'price_reason' => (string) ($l['price_reason'] ?? ''),
    ], $o['lines']);
} else {
    $rows = [];
}
if (!$rows) {
    $rows = [[]];
}
$rowVal = static fn (array $row, string $key): string => is_string($row[$key] ?? null) ? $row[$key] : '';

// Products with their suggested price and the units free at this branch (POS location minus reservations).
$location = $concrete ? Branch::defaultLocation((int) Branch::current()) : null;
$stmt = db()->prepare(
    "SELECT p.id, p.code, p.barcode, p.name, p.price, p.track_serial, p.is_active,
            COALESCE(sb.qty, 0) - COALESCE((SELECT SUM(l.qty_ordered - l.qty_delivered) FROM customer_order_lines l
                                              JOIN customer_orders o ON o.id = l.order_id
                                             WHERE l.product_id = p.id AND o.location_id = ? AND o.status IN ('confirmed', 'partial')), 0) AS free
       FROM products p LEFT JOIN stock_balances sb ON sb.product_id = p.id AND sb.location_id = ?
      WHERE p.is_active = 1 OR p.id IN (SELECT product_id FROM customer_order_lines WHERE order_id = ?)
      ORDER BY p.code"
);
$stmt->execute([$location['id'] ?? 0, $location['id'] ?? 0, $id ?? 0]);
$products = $stmt->fetchAll();

$fromQuote = $quote !== null ? ['customer_id' => $quote['customer_id'], 'end_user' => $quote['end_user'], 'delivery_term' => $quote['delivery_term'],
    'payment_term' => $quote['payment_term'], 'place_of_delivery' => $quote['customer_address'], 'notes' => 'From quotation ' . $quote['quote_no']] : [];
$customerId = old('customer_id', (string) ($o['customer_id'] ?? $fromQuote['customer_id'] ?? ''));
$customers  = CustomerOrders::customers($o !== null ? (int) $o['customer_id'] : null);
$val    = static fn (string $key): string => old($key, (string) ($o[$key] ?? $fromQuote[$key] ?? ''));
$errors = form_errors();
$title  = $o ? 'Draft Order #' . $o['id'] : 'New Customer PO';
$page['title'] = $title;

$pageStyles  = ['css/sales.css', 'css/receiving.css', 'css/stock-docs.css', 'css/transfers.css', 'css/purchasing.css'];
$pageScripts = ['js/purchasing.js'];
require ROOT_PATH . '/includes/header.php';

/** One line; $i = 'N' or '__i__' in the template. */
$renderLine = static function (string $i, array $row, int $n) use ($products, $rowVal): void {
    $pid    = (int) $rowVal($row, 'product_id');
    $field  = static fn (string $f): string => "items[{$i}][{$f}]";
    $errKey = static fn (string $f): string => "items.{$i}.{$f}";
    ?>
    <tbody class="rr-line" data-line>
    <tr>
        <td class="rr-line__n muted" data-line-no><?= $n ?></td>
        <td class="rr-line__item">
            <div class="rr-item-pick">
                <input class="form-input form-input--mono rr-code" type="text" maxlength="60" placeholder="Code / barcode"
                       aria-label="Product code or barcode" data-code-input autocomplete="off">
                <select class="form-input" name="<?= e($field('product_id')) ?>" aria-label="Product" data-product<?= invalid($errKey('product_id')) ?>>
                    <option value="">Choose a product…</option>
                    <?php foreach ($products as $p): ?>
                        <option value="<?= (int) $p['id'] ?>" data-code="<?= e($p['code']) ?>" data-barcode="<?= e((string) $p['barcode']) ?>"
                                data-price="<?= e(number_format((float) $p['price'], 2, '.', '')) ?>" data-free="<?= max(0, (int) $p['free']) ?>"<?= $pid === (int) $p['id'] ? ' selected' : '' ?>>
                            <?= e($p['code'] . ' · ' . $p['name']) ?><?= (int) $p['is_active'] === 1 ? '' : ' (inactive)' ?><?= (int) $p['track_serial'] === 1 ? ' · S/N' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <small class="muted block co-hint" data-price-hint aria-live="polite"></small>
            <?= field_error($errKey('product_id')) ?>
        </td>
        <td class="rr-line__qty">
            <input class="form-input num" type="number" name="<?= e($field('quantity')) ?>" min="1" max="<?= Products::MAX_STOCK ?>" step="1"
                   inputmode="numeric" aria-label="Quantity" data-qty value="<?= e($rowVal($row, 'quantity')) ?>"<?= invalid($errKey('quantity')) ?>>
            <?= field_error($errKey('quantity')) ?>
        </td>
        <td class="rr-line__cost">
            <input class="form-input num" type="text" name="<?= e($field('unit_price')) ?>" inputmode="decimal" maxlength="13" placeholder="0.00"
                   aria-label="Agreed unit price" data-cost data-price-input value="<?= e($rowVal($row, 'unit_price')) ?>"<?= invalid($errKey('unit_price')) ?>>
            <?= field_error($errKey('unit_price')) ?>
            <input class="form-input co-reason" type="text" name="<?= e($field('price_reason')) ?>" maxlength="255" placeholder="Reason if lower"
                   aria-label="Reason for a lower price" data-reason value="<?= e($rowVal($row, 'price_reason')) ?>"<?= invalid($errKey('price_reason')) ?>>
            <?= field_error($errKey('price_reason')) ?>
        </td>
        <td class="num rr-line__total" data-line-total>—</td>
        <td class="c-act">
            <button type="button" class="icon-btn icon-btn--danger-outline" data-remove-line aria-label="Remove line" title="Remove line"><?= icon('trash') ?></button>
        </td>
    </tr>
    </tbody>
    <?php
};
?>

<div class="page-head">
    <div>
        <a class="back-link" href="<?= e(url('pages/' . $returnTo)) ?>"><?= icon('arrow-left') ?> Customer Orders</a>
        <h1 class="sale-title"><?= e($title) ?> <span class="badge">Draft</span></h1>
        <p class="muted">Enter the customer's purchase order for <strong><?= e($o ? $o['branch_code'] . ' · ' . $o['branch_name'] : Branch::label()) ?></strong>.
            A branch admin (not you) confirms it; then its items are reserved.</p>
    </div>
</div>
<?php if (!$concrete): ?>
    <div class="alert alert--warning" role="status">
        <?= icon('info') ?>
        <span>Choose a branch in the top bar before saving: an order belongs to one branch.</span>
    </div>
<?php endif; ?>
<?php if ($quote !== null): ?>
    <div class="alert alert--info" role="note" id="coFromQuote"><?= icon('tag') ?><span>From quotation <strong><?= e($quote['quote_no']) ?></strong>: enter the customer's PO number and check the quantities and prices. Saving marks the quotation as won.</span></div>
<?php endif; ?>
<?php if ($o !== null && $o['return_note']): ?>
    <div class="alert alert--warning" role="note" id="coReturnNote"><?= icon('alert') ?><span>Returned for changes: <?= e($o['return_note']) ?></span></div>
<?php endif; ?>

<form class="form-layout rr-form" method="post" action="<?= e(url('pages/' . $self)) ?>" novalidate id="coForm"
      data-lines-form data-cost-lines data-max-lines="<?= CustomerOrders::MAX_LINES ?>" data-currency="<?= e(config('app.currency')) ?>">
    <?= Csrf::field() ?>
    <input type="hidden" name="return" value="<?= e($returnTo) ?>">
    <?php if ($quote !== null): ?><input type="hidden" name="quotation_id" value="<?= (int) $quote['id'] ?>"><?php endif; ?>

    <div class="form-stack">
        <section class="card card--pad">
            <h2 class="card__title">Customer's purchase order</h2>
            <div class="form-grid">
                <label class="form-field form-field--full">
                    <span class="form-label">Customer *</span>
                    <select class="form-input" name="customer_id" required id="coCustomer"<?= invalid('customer_id') ?>>
                        <option value="">Choose a customer…</option>
                        <?php foreach ($customers as $c): ?>
                            <option value="<?= (int) $c['id'] ?>" data-address="<?= e((string) $c['address']) ?>"<?= $customerId === (string) $c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?><?= $c['type_name'] ? ' · ' . e($c['type_name']) : '' ?><?= (int) $c['is_active'] === 1 ? '' : ' (inactive)' ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= field_error('customer_id') ?>
                    <?php if (!$customers && $concrete): ?><small class="form-hint">Add the customer first (Customers → Add).</small><?php endif; ?>
                </label>
                <label class="form-field">
                    <span class="form-label">Customer PO no. *</span>
                    <input class="form-input form-input--mono" name="customer_po_no" maxlength="60" required value="<?= e($val('customer_po_no')) ?>"<?= invalid('customer_po_no') ?>>
                    <?= field_error('customer_po_no') ?>
                </label>
                <label class="form-field">
                    <span class="form-label">PO date</span>
                    <input class="form-input" type="date" name="customer_po_date" value="<?= e($val('customer_po_date')) ?>"<?= invalid('customer_po_date') ?>>
                    <?= field_error('customer_po_date') ?>
                </label>
                <label class="form-field">
                    <span class="form-label">End-user / office</span>
                    <input class="form-input" name="end_user" maxlength="150" placeholder="e.g. Municipal Engineering Office" value="<?= e($val('end_user')) ?>"<?= invalid('end_user') ?>>
                    <?= field_error('end_user') ?>
                </label>
                <label class="form-field">
                    <span class="form-label">Mode of procurement</span>
                    <input class="form-input" name="procurement_mode" maxlength="60" list="procModes" value="<?= e($val('procurement_mode')) ?>"<?= invalid('procurement_mode') ?>>
                    <datalist id="procModes"><?php foreach (CustomerOrders::PROCUREMENT_MODES as $m): ?><option value="<?= e($m) ?>"></option><?php endforeach; ?></datalist>
                    <?= field_error('procurement_mode') ?>
                </label>
                <label class="form-field form-field--full">
                    <span class="form-label">Place of delivery</span>
                    <input class="form-input" name="place_of_delivery" maxlength="255" id="coPlace" value="<?= e($val('place_of_delivery')) ?>"<?= invalid('place_of_delivery') ?>>
                    <?= field_error('place_of_delivery') ?>
                </label>
                <label class="form-field">
                    <span class="form-label">Delivery term</span>
                    <input class="form-input" name="delivery_term" maxlength="100" placeholder="e.g. Within 15 calendar days" value="<?= e($val('delivery_term')) ?>"<?= invalid('delivery_term') ?>>
                    <?= field_error('delivery_term') ?>
                </label>
                <label class="form-field">
                    <span class="form-label">Delivery deadline</span>
                    <input class="form-input" type="date" name="due_date" value="<?= e($val('due_date')) ?>"<?= invalid('due_date') ?>>
                    <?= field_error('due_date') ?>
                </label>
                <label class="form-field">
                    <span class="form-label">Payment term</span>
                    <input class="form-input" name="payment_term" maxlength="100" placeholder="e.g. 30 days after acceptance" value="<?= e($val('payment_term')) ?>"<?= invalid('payment_term') ?>>
                    <?= field_error('payment_term') ?>
                </label>
                <label class="form-field">
                    <span class="form-label">Award / BAC reference</span>
                    <input class="form-input" name="award_ref" maxlength="100" placeholder="e.g. BAC Reso No. 2026-15" value="<?= e($val('award_ref')) ?>"<?= invalid('award_ref') ?>>
                    <?= field_error('award_ref') ?>
                </label>
                <label class="form-field form-field--full">
                    <span class="form-label">Notes</span>
                    <input class="form-input" name="notes" maxlength="500" value="<?= e($val('notes')) ?>"<?= invalid('notes') ?>>
                    <?= field_error('notes') ?>
                </label>
            </div>
        </section>

        <section class="card">
            <header class="card__head">
                <h2><?= icon('box') ?> Items</h2>
                <button type="button" class="btn btn--light btn--sm" id="addLine"><?= icon('plus') ?> Add Line</button>
            </header>
            <?php if (isset($errors['items'])): ?>
                <p class="form-error rr-items-error" id="err-items"><?= e($errors['items']) ?></p>
            <?php endif; ?>
            <div class="table-wrap">
                <table class="table rr-lines" id="docLines">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Item</th>
                        <th class="num">Qty</th>
                        <th class="num">Unit Price</th>
                        <th class="num">Total</th>
                        <th><span class="visually-hidden">Remove</span></th>
                    </tr>
                    </thead>
                    <?php foreach ($rows as $n => $row): ?>
                        <?php $renderLine((string) $n, is_array($row) ? $row : [], $n + 1); ?>
                    <?php endforeach; ?>
                </table>
            </div>
            <p class="form-hint rr-lines__hint">Unit price = the agreed price before VAT (VAT is added on the bill). A price below the suggested price needs a reason,
                e.g. the awarded bid price. "Free" = units at the branch not reserved by other orders.</p>
        </section>
    </div>

    <aside class="form-side">
        <section class="card card--pad">
            <h2 class="card__title">Summary</h2>
            <dl class="detail-list">
                <div><dt>Lines</dt><dd id="sumLines">0</dd></div>
                <div><dt>Total quantity</dt><dd id="sumQty">0</dd></div>
                <div><dt>Amount (before VAT)</dt><dd id="sumCost">—</dd></div>
            </dl>
            <p class="form-hint doc-side-hint"><?= icon('info') ?> Stock is reserved when the order is confirmed and leaves the branch on the delivery receipts.</p>
        </section>
    </aside>

    <div class="form-actions">
        <?php if ($o): ?>
            <button type="submit" class="btn btn--light rr-delete" form="coDeleteForm"><?= icon('trash') ?> Delete Draft</button>
        <?php endif; ?>
        <a class="btn btn--light" href="<?= e(url('pages/' . $returnTo)) ?>">Cancel</a>
        <button type="submit" class="btn btn--light" name="action" value="save" id="saveCoBtn"<?= $concrete || $o ? '' : ' disabled' ?>><?= icon('save') ?> Save Draft</button>
        <button type="submit" class="btn btn--primary" name="action" value="submit" id="submitCoBtn"<?= $concrete || $o ? '' : ' disabled' ?>
                data-confirm-submit="Send this order for confirmation? It can't be edited while it waits."><?= icon('check') ?> Save &amp; Send for Confirmation</button>
    </div>
</form>

<?php if ($o): ?>
    <form method="post" action="<?= e(url('pages/' . $self)) ?>" id="coDeleteForm" data-confirm="Delete Draft Order #<?= (int) $o['id'] ?>? This cannot be undone.">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="return" value="<?= e($returnTo) ?>">
    </form>
<?php endif; ?>

<template id="lineTpl"><?php $renderLine('__i__', [], 0); ?></template>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
