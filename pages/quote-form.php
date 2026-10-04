<?php
/**
 * Quotation draft: create (numbered at once) / edit (customer_orders.manage). Save, or Save & Mark as Sent. Header =
 * customer, attention, RFQ no. / date, end-user, quotation date, valid until, delivery / payment terms, warranty,
 * notes; lines = product, quantity, quoted unit price (VAT-exclusive), reason when below the suggested price.
 * PRG with flash_old / flash_errors (line errors keyed items.N.field).
 * pages/quote-form.php[?id=5][&return=quotations.php]
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('customer-orders');
Auth::requirePermission('customer_orders.manage');

$id = input_int($_GET, 'id', 1);
$q  = null;
if ($id !== null) {
    $q = Quotations::find($id) ?? throw new HttpException(404, 'Quotation not found.');
}
$returnTo = safe_return($_POST['return'] ?? $_GET['return'] ?? null, 'quotations.php');
$self     = 'quote-form.php?' . http_build_query(array_filter(['id' => $id, 'return' => $returnTo]));
$viewPath = static fn (int $qid): string => 'pages/quote-view.php?' . http_build_query(['id' => $qid, 'return' => $returnTo]);

if ($q !== null && $q['status'] !== 'draft') {
    flash('error', $q['quote_no'] . ' is ' . strtolower(Quotations::STATUSES[$q['status']]) . ' and can no longer be edited.');
    redirect($viewPath($id));
}

if (is_post()) {
    Csrf::verifyRequest();
    $action = input_string($_POST, 'action', 10);
    if ($action !== 'save' && $action !== 'send') {
        throw new HttpException(400, 'Unknown action.');
    }
    $keepForm = static function (): void {
        $items = [];
        foreach (is_array($_POST['items'] ?? null) ? array_values($_POST['items']) : [] as $line) {
            $items[] = is_array($line) ? array_filter($line, 'is_string') : [];
        }
        flash_old(array_filter($_POST, 'is_string') + ['items' => $items]);
    };
    [$data, $errors] = Quotations::validate($_POST);
    if ($errors) {
        $keepForm();
        flash_errors($errors);
        flash('error', $errors['items'] ?? 'Please fix the highlighted fields.');
        redirect('pages/' . $self);
    }
    try {
        $savedId = Quotations::save($id, $data, (int) Auth::id());
    } catch (HttpException $e) {
        if ($e->status === 403) {
            throw $e;
        }
        $keepForm();
        flash('error', $e->getMessage());
        redirect('pages/' . $self);
    }
    $stmt = db()->prepare('SELECT quote_no FROM quotations WHERE id = ?');
    $stmt->execute([$savedId]);
    $no = (string) $stmt->fetchColumn();
    if ($action === 'save') {
        flash('success', "{$no} was saved.");
        redirect($viewPath($savedId));
    }
    try {
        Quotations::send($savedId, (int) Auth::id());
    } catch (HttpException $e) {
        flash('error', 'The quotation was saved but not marked as sent: ' . $e->getMessage());
        redirect($viewPath($savedId));
    }
    flash('success', "{$no} was saved and marked as sent. Print it for the customer.");
    redirect($viewPath($savedId));
}

// ---------------------------------------------------------------------
// Form data
// ---------------------------------------------------------------------
$concrete = Branch::isConcrete();
if (has_old()) {
    $old  = old_input();
    $rows = is_array($old['items'] ?? null) ? array_values($old['items']) : [];
} elseif ($q !== null) {
    $rows = array_map(static fn (array $l): array => [
        'product_id'   => (string) $l['product_id'],
        'quantity'     => (string) $l['quantity'],
        'unit_price'   => (string) $l['unit_price'],
        'price_reason' => (string) ($l['price_reason'] ?? ''),
    ], $q['lines']);
} else {
    $rows = [];
}
if (!$rows) {
    $rows = [[]];
}
$rowVal = static fn (array $row, string $key): string => is_string($row[$key] ?? null) ? $row[$key] : '';

$location = $concrete ? Branch::defaultLocation((int) Branch::current()) : null;
$stmt = db()->prepare(
    "SELECT p.id, p.code, p.barcode, p.name, p.price, p.track_serial, p.is_active,
            COALESCE(sb.qty, 0) - COALESCE((SELECT SUM(l.qty_ordered - l.qty_delivered) FROM customer_order_lines l
                                              JOIN customer_orders o ON o.id = l.order_id
                                             WHERE l.product_id = p.id AND o.location_id = ? AND o.status IN ('confirmed', 'partial')), 0) AS free
       FROM products p LEFT JOIN stock_balances sb ON sb.product_id = p.id AND sb.location_id = ?
      WHERE p.is_active = 1 OR p.id IN (SELECT product_id FROM quotation_lines WHERE quotation_id = ?)
      ORDER BY p.code"
);
$stmt->execute([$location['id'] ?? 0, $location['id'] ?? 0, $id ?? 0]);
$products = $stmt->fetchAll();

$customerId = old('customer_id', (string) ($q['customer_id'] ?? ''));
$customers  = CustomerOrders::customers($q !== null ? (int) $q['customer_id'] : null);
$today = date('Y-m-d');
$val   = static fn (string $key, string $default = ''): string => old($key, (string) ($q[$key] ?? $default));
$errors = form_errors();
$title  = $q ? $q['quote_no'] : 'New Quotation';
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
                            <?= e($p['code'] . ' · ' . $p['name']) ?><?= (int) $p['is_active'] === 1 ? '' : ' (inactive)' ?>
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
                   aria-label="Quoted unit price" data-cost data-price-input value="<?= e($rowVal($row, 'unit_price')) ?>"<?= invalid($errKey('unit_price')) ?>>
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
        <a class="back-link" href="<?= e(url('pages/' . $returnTo)) ?>"><?= icon('arrow-left') ?> Quotations</a>
        <h1 class="sale-title"><?= e($title) ?> <span class="badge">Draft</span></h1>
        <p class="muted">Our price quotation for <strong><?= e($q ? $q['branch_code'] . ' · ' . $q['branch_name'] : Branch::label()) ?></strong>.
            It reserves no stock; the number is given when you first save it.</p>
    </div>
</div>
<?php if (!$concrete): ?>
    <div class="alert alert--warning" role="status">
        <?= icon('info') ?>
        <span>Choose a branch in the top bar before saving: a quotation belongs to one branch.</span>
    </div>
<?php endif; ?>

<form class="form-layout rr-form" method="post" action="<?= e(url('pages/' . $self)) ?>" novalidate id="quoteForm"
      data-lines-form data-cost-lines data-max-lines="<?= Quotations::MAX_LINES ?>" data-currency="<?= e(config('app.currency')) ?>">
    <?= Csrf::field() ?>
    <input type="hidden" name="return" value="<?= e($returnTo) ?>">

    <div class="form-stack">
        <section class="card card--pad">
            <h2 class="card__title">Customer &amp; request</h2>
            <div class="form-grid">
                <label class="form-field form-field--full">
                    <span class="form-label">Customer *</span>
                    <select class="form-input" name="customer_id" required id="qtCustomer"<?= invalid('customer_id') ?>>
                        <option value="">Choose a customer…</option>
                        <?php foreach ($customers as $c): ?>
                            <option value="<?= (int) $c['id'] ?>"<?= $customerId === (string) $c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?><?= $c['type_name'] ? ' · ' . e($c['type_name']) : '' ?><?= (int) $c['is_active'] === 1 ? '' : ' (inactive)' ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= field_error('customer_id') ?>
                    <?php if (!$customers && $concrete): ?><small class="form-hint">Add the customer first (Customers → Add).</small><?php endif; ?>
                </label>
                <label class="form-field">
                    <span class="form-label">Attention</span>
                    <input class="form-input" name="attention" maxlength="100" placeholder="e.g. BAC Secretariat / Supply Officer" value="<?= e($val('attention')) ?>"<?= invalid('attention') ?>>
                    <?= field_error('attention') ?>
                </label>
                <label class="form-field">
                    <span class="form-label">End-user / office</span>
                    <input class="form-input" name="end_user" maxlength="150" value="<?= e($val('end_user')) ?>"<?= invalid('end_user') ?>>
                    <?= field_error('end_user') ?>
                </label>
                <label class="form-field">
                    <span class="form-label">RFQ / canvass no.</span>
                    <input class="form-input form-input--mono" name="rfq_no" maxlength="60" value="<?= e($val('rfq_no')) ?>"<?= invalid('rfq_no') ?>>
                    <?= field_error('rfq_no') ?>
                </label>
                <label class="form-field">
                    <span class="form-label">RFQ date</span>
                    <input class="form-input" type="date" name="rfq_date" value="<?= e($val('rfq_date')) ?>"<?= invalid('rfq_date') ?>>
                    <?= field_error('rfq_date') ?>
                </label>
                <label class="form-field">
                    <span class="form-label">Quotation date *</span>
                    <input class="form-input" type="date" name="quote_date" required value="<?= e($val('quote_date', $today)) ?>"<?= invalid('quote_date') ?>>
                    <?= field_error('quote_date') ?>
                </label>
                <label class="form-field">
                    <span class="form-label">Valid until</span>
                    <input class="form-input" type="date" name="valid_until" value="<?= e($val('valid_until', date('Y-m-d', strtotime('+' . Quotations::VALID_DAYS . ' days')))) ?>"<?= invalid('valid_until') ?>>
                    <?= field_error('valid_until') ?>
                </label>
                <label class="form-field">
                    <span class="form-label">Delivery term</span>
                    <input class="form-input" name="delivery_term" maxlength="100" placeholder="e.g. 7–15 days upon receipt of PO" value="<?= e($val('delivery_term')) ?>"<?= invalid('delivery_term') ?>>
                    <?= field_error('delivery_term') ?>
                </label>
                <label class="form-field">
                    <span class="form-label">Payment term</span>
                    <input class="form-input" name="payment_term" maxlength="100" placeholder="e.g. 30 days after acceptance" value="<?= e($val('payment_term')) ?>"<?= invalid('payment_term') ?>>
                    <?= field_error('payment_term') ?>
                </label>
                <label class="form-field">
                    <span class="form-label">Warranty</span>
                    <input class="form-input" name="warranty" maxlength="100" placeholder="e.g. 1 year parts and service" value="<?= e($val('warranty')) ?>"<?= invalid('warranty') ?>>
                    <?= field_error('warranty') ?>
                </label>
                <label class="form-field form-field--full">
                    <span class="form-label">Notes / terms</span>
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
            <p class="form-hint rr-lines__hint">Unit price = the quoted price before VAT (VAT is added on top). A price below the suggested price needs a reason.</p>
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
            <p class="form-hint doc-side-hint"><?= icon('info') ?> When the customer awards it, open the quotation and choose "Create Customer PO".</p>
        </section>
    </aside>

    <div class="form-actions">
        <a class="btn btn--light" href="<?= e(url('pages/' . $returnTo)) ?>">Cancel</a>
        <button type="submit" class="btn btn--light" name="action" value="save" id="saveQuoteBtn"<?= $concrete || $q ? '' : ' disabled' ?>><?= icon('save') ?> Save</button>
        <button type="submit" class="btn btn--primary" name="action" value="send" id="sendQuoteBtn"<?= $concrete || $q ? '' : ' disabled' ?>><?= icon('check') ?> Save &amp; Mark as Sent</button>
    </div>
</form>

<template id="lineTpl"><?php $renderLine('__i__', [], 0); ?></template>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
