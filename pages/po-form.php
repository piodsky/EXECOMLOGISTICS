<?php
/**
 * PO Internal draft: create / edit (purchasing.order + products.cost), Save Draft, Save & Send for Approval,
 * Delete Draft. New POs can start from approved purchase requests: ?pr[]=ID (one line per product with what the
 * requests still need; each line keeps its request links in items[N][links] = "requestLineId:qty,...").
 * PRG: errors come back through flash_old / flash_errors (line errors keyed items.N.field).
 * pages/po-form.php[?id=5][?pr[]=3&pr[]=4][&return=purchase-orders.php]
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('purchasing');
if (!PurchaseOrders::canManage()) {
    abort(403, 'You do not have permission to prepare purchase orders.');
}

$id = input_int($_GET, 'id', 1);
$po = null;
if ($id !== null) {
    $po = PurchaseOrders::find($id) ?? throw new HttpException(404, 'Purchase order not found.');
}
$returnTo = safe_return($_POST['return'] ?? $_GET['return'] ?? null, 'purchase-orders.php');
$self     = 'po-form.php?' . http_build_query(array_filter(['id' => $id, 'return' => $returnTo]));
$viewPath = static fn (int $poId): string => 'pages/po-view.php?' . http_build_query(['id' => $poId, 'return' => $returnTo]);

if ($po !== null && $po['status'] !== 'draft') {
    flash('error', PurchaseOrders::label($po) . ' is ' . strtolower(PurchaseOrders::STATUSES[$po['status']]) . ' and can no longer be edited.');
    redirect($viewPath($id));
}

// ---------------------------------------------------------------------
// Save draft / Save & send / Delete draft (PRG)
// ---------------------------------------------------------------------
if (is_post()) {
    Csrf::verifyRequest();
    $action = input_string($_POST, 'action', 10);
    if ($action === 'delete') {
        if ($po === null) {
            throw new HttpException(404, 'Purchase order not found.');
        }
        try {
            PurchaseOrders::deleteDraft($id);
        } catch (HttpException $e) {
            flash('error', $e->getMessage());
            redirect('pages/' . $self);
        }
        flash('success', 'Draft PO #' . $id . ' was deleted.');
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
    [$data, $errors] = PurchaseOrders::validate($_POST);
    if ($errors) {
        $keepForm();
        flash_errors($errors);
        flash('error', $errors['items'] ?? 'Please fix the highlighted fields.');
        redirect('pages/' . $self);
    }
    try {
        $savedId = PurchaseOrders::saveDraft($id, $data, (int) Auth::id());
    } catch (HttpException $e) {
        if ($e->status === 403) {
            throw $e;
        }
        $keepForm();
        flash('error', $e->getMessage());
        redirect('pages/' . $self);
    }
    if ($action === 'save') {
        flash('success', 'Draft PO #' . $savedId . ' was saved.');
        redirect($viewPath($savedId));
    }
    try {
        PurchaseOrders::submit($savedId, (int) Auth::id());
    } catch (HttpException $e) {
        flash('error', 'The draft was saved but not sent: ' . $e->getMessage());
        redirect('pages/po-form.php?' . http_build_query(['id' => $savedId, 'return' => $returnTo]));
    }
    flash('success', 'Draft PO #' . $savedId . ' was sent for approval. It gets its PO number when approved.');
    redirect($viewPath($savedId));
}

// ---------------------------------------------------------------------
// Form data
// ---------------------------------------------------------------------
$concrete = Branch::isConcrete();
$prIds    = is_array($_GET['pr'] ?? null) ? array_values(array_filter(array_map(static fn ($v) => filter_var($v, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]), $_GET['pr']))) : [];
$prRows   = [];
if ($po === null && $prIds && $concrete && !has_old()) {
    $prRows = PurchaseOrders::prefillFromRequests($prIds);
    if (!$prRows) {
        flash('error', 'Those purchase requests have nothing left to order.');
    }
}

if (has_old()) {
    $old  = old_input();
    $rows = is_array($old['items'] ?? null) ? array_values($old['items']) : [];
} elseif ($po !== null) {
    $rows = array_map(static fn (array $l): array => [
        'product_id' => (string) $l['product_id'],
        'quantity'   => (string) $l['qty_ordered'],
        'unit_cost'  => PurchaseOrders::costInput((string) $l['unit_cost']),
        'end_user'   => (string) ($l['end_user'] ?? ''),
        'links'      => implode(',', array_map(static fn (array $x): string => $x['line_id'] . ':' . $x['qty'], $l['requests'])),
    ], $po['lines']);
} else {
    $rows = $prRows;
}
if (!$rows) {
    $rows = [[]];
}
$rowVal = static fn (array $row, string $key): string => is_string($row[$key] ?? null) ? $row[$key] : '';

// PR numbers for the "from request" notes under linked lines.
$linkIds = [];
foreach ($rows as $row) {
    foreach (explode(',', $rowVal(is_array($row) ? $row : [], 'links')) as $pair) {
        if (preg_match('/^(\d+):\d+$/', trim($pair), $m)) {
            $linkIds[(int) $m[1]] = true;
        }
    }
}
$prNoByLine = [];
if ($linkIds) {
    $in_  = implode(',', array_fill(0, count($linkIds), '?'));
    $stmt = db()->prepare("SELECT rl.id, r.pr_no FROM purchase_request_lines rl JOIN purchase_requests r ON r.id = rl.request_id WHERE rl.id IN ({$in_})");
    $stmt->execute(array_keys($linkIds));
    $prNoByLine = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

$onDraft = array_filter(array_map(static fn ($r): int => (int) (is_array($r) ? ($r['product_id'] ?? 0) : 0), $rows));
$sql = 'SELECT p.id, p.code, p.barcode, p.name, p.track_serial, p.is_active FROM products p WHERE p.is_active = ?';
$params = [1];
if ($onDraft) {
    $sql .= ' OR p.id IN (' . implode(',', array_fill(0, count($onDraft), '?')) . ')';
    array_push($params, ...array_values($onDraft));
}
$stmt = db()->prepare($sql . ' ORDER BY p.code');
$stmt->execute($params);
$products = $stmt->fetchAll();

$defaults   = $po ?? PurchaseOrders::defaults();
$supplierId = old('supplier_id', (string) ($po['supplier_id'] ?? ''));
$stmt = db()->prepare('SELECT id, code, name, payment_terms, is_active FROM suppliers WHERE is_active = ? OR id = ? ORDER BY name');
$stmt->execute([1, (int) $supplierId]);
$suppliers = $stmt->fetchAll();
$openRequests = $po === null && $concrete ? PurchaseRequests::openRequests((int) Branch::current()) : [];

$val    = static fn (string $key): string => old($key, (string) ($defaults[$key] ?? ''));
$errors = form_errors();
$title  = $po ? 'Draft PO #' . $po['id'] : 'New Purchase Order';
$page['title'] = $title;

$pageStyles  = ['css/sales.css', 'css/receiving.css', 'css/stock-docs.css', 'css/transfers.css', 'css/purchasing.css'];
$pageScripts = ['js/purchasing.js'];
require ROOT_PATH . '/includes/header.php';

/** One line; $i = 'N' or '__i__' in the template. */
$renderLine = static function (string $i, array $row, int $n) use ($products, $rowVal, $prNoByLine): void {
    $pid    = (int) $rowVal($row, 'product_id');
    $field  = static fn (string $f): string => "items[{$i}][{$f}]";
    $errKey = static fn (string $f): string => "items.{$i}.{$f}";
    $links  = $rowVal($row, 'links');
    $prNos  = [];
    foreach ($links !== '' ? explode(',', $links) : [] as $pair) {
        if (preg_match('/^(\d+):(\d+)$/', trim($pair), $m) && isset($prNoByLine[(int) $m[1]])) {
            $prNos[] = $prNoByLine[(int) $m[1]] . ' (' . $m[2] . ')';
        }
    }
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
                        <option value="<?= (int) $p['id'] ?>" data-code="<?= e($p['code']) ?>" data-barcode="<?= e((string) $p['barcode']) ?>"<?= $pid === (int) $p['id'] ? ' selected' : '' ?>>
                            <?= e($p['code'] . ' · ' . $p['name']) ?><?= (int) $p['is_active'] === 1 ? '' : ' (inactive)' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <input type="hidden" name="<?= e($field('links')) ?>" value="<?= e($links) ?>" data-links>
            <?php if ($prNos): ?><small class="pu-from muted block" data-links-note><?= icon('clipboard') ?> For <?= e(implode(', ', $prNos)) ?></small><?php endif; ?>
            <?= field_error($errKey('product_id')) ?>
        </td>
        <td class="pu-line__user">
            <input class="form-input" type="text" name="<?= e($field('end_user')) ?>" maxlength="100" placeholder="End-user"
                   aria-label="End-user" value="<?= e($rowVal($row, 'end_user')) ?>"<?= invalid($errKey('end_user')) ?>>
            <?= field_error($errKey('end_user')) ?>
        </td>
        <td class="rr-line__qty">
            <input class="form-input num" type="number" name="<?= e($field('quantity')) ?>" min="1" max="<?= Products::MAX_STOCK ?>" step="1"
                   inputmode="numeric" aria-label="Quantity" data-qty value="<?= e($rowVal($row, 'quantity')) ?>"<?= invalid($errKey('quantity')) ?>>
            <?= field_error($errKey('quantity')) ?>
        </td>
        <td class="rr-line__cost">
            <input class="form-input num" type="text" name="<?= e($field('unit_cost')) ?>" inputmode="decimal" maxlength="12" placeholder="0.00"
                   aria-label="Unit cost" data-cost value="<?= e($rowVal($row, 'unit_cost')) ?>"<?= invalid($errKey('unit_cost')) ?>>
            <?= field_error($errKey('unit_cost')) ?>
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
        <a class="back-link" href="<?= e(url('pages/' . $returnTo)) ?>"><?= icon('arrow-left') ?> PO Internal</a>
        <h1 class="sale-title"><?= e($title) ?> <span class="badge">Draft</span></h1>
        <p class="muted">Order for <strong><?= e($po ? $po['branch_code'] . ' · ' . $po['branch_name'] : Branch::label()) ?></strong>.
            It gets its PO number when a branch admin (not you) approves it.</p>
    </div>
</div>
<?php if (!$concrete): ?>
    <div class="alert alert--warning" role="status">
        <?= icon('info') ?>
        <span>Choose a branch in the top bar before saving: a purchase order belongs to one branch.</span>
    </div>
<?php endif; ?>
<?php if ($po !== null && $po['return_note']): ?>
    <div class="alert alert--warning" role="note" id="poReturnNote"><?= icon('alert') ?><span>Returned for changes: <?= e($po['return_note']) ?></span></div>
<?php endif; ?>

<?php if ($openRequests && !$prRows && !has_old()): ?>
    <section class="card card--pad pu-picker" id="prPicker">
        <form method="get" action="<?= e(url('pages/po-form.php')) ?>">
            <input type="hidden" name="return" value="<?= e($returnTo) ?>">
            <h2 class="card__title"><?= icon('clipboard') ?> Approved requests to order</h2>
            <div class="pu-picker__list">
                <?php foreach ($openRequests as $req): ?>
                    <label class="sn-check pu-pick">
                        <input type="checkbox" name="pr[]" value="<?= (int) $req['id'] ?>">
                        <span><strong class="doc-no"><?= e($req['pr_no']) ?></strong>
                            <small class="muted block"><?= number_format($req['qty']) ?> units · <?= e($req['requested_by_name'] ?? '') ?><?= ($req['purpose'] ?? null) ? ' · ' . e($req['purpose']) : '' ?></small></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <button type="submit" class="btn btn--light btn--sm" id="loadRequestsBtn"><?= icon('download') ?> Load Selected Requests</button>
        </form>
    </section>
<?php endif; ?>

<form class="form-layout rr-form" method="post" action="<?= e(url('pages/' . $self)) ?>" novalidate id="poForm"
      data-lines-form data-cost-lines data-max-lines="<?= PurchaseOrders::MAX_LINES ?>" data-currency="<?= e(config('app.currency')) ?>">
    <?= Csrf::field() ?>
    <input type="hidden" name="return" value="<?= e($returnTo) ?>">

    <div class="form-stack">
        <section class="card card--pad">
            <h2 class="card__title">Supplier &amp; delivery</h2>
            <div class="form-grid">
                <label class="form-field">
                    <span class="form-label">Supplier *</span>
                    <select class="form-input" name="supplier_id" required id="poSupplier"<?= invalid('supplier_id') ?>>
                        <option value="">Choose a supplier…</option>
                        <?php foreach ($suppliers as $s): ?>
                            <option value="<?= (int) $s['id'] ?>" data-terms="<?= e((string) $s['payment_terms']) ?>"<?= $supplierId === (string) $s['id'] ? ' selected' : '' ?>><?= e($s['name'] . ' (' . $s['code'] . ')') ?><?= (int) $s['is_active'] === 1 ? '' : ' (inactive)' ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= field_error('supplier_id') ?>
                </label>
                <label class="form-field">
                    <span class="form-label">Payment terms</span>
                    <input class="form-input" name="payment_terms" maxlength="60" id="poTerms" placeholder="e.g. 30 days, COD"
                           value="<?= e($val('payment_terms')) ?>"<?= invalid('payment_terms') ?>>
                    <?= field_error('payment_terms') ?>
                </label>
                <label class="form-field">
                    <span class="form-label">PO date *</span>
                    <input class="form-input" type="date" name="order_date" required value="<?= e($val('order_date')) ?>"<?= invalid('order_date') ?>>
                    <?= field_error('order_date') ?>
                </label>
                <label class="form-field">
                    <span class="form-label">Expected delivery</span>
                    <input class="form-input" type="date" name="expected_date" value="<?= e($val('expected_date')) ?>"<?= invalid('expected_date') ?>>
                    <?= field_error('expected_date') ?>
                </label>
                <label class="form-field">
                    <span class="form-label">Contact person</span>
                    <input class="form-input" name="contact_person" maxlength="100" value="<?= e($val('contact_person')) ?>"<?= invalid('contact_person') ?>>
                    <?= field_error('contact_person') ?>
                </label>
                <label class="form-field">
                    <span class="form-label">Contact number</span>
                    <input class="form-input" name="contact_number" maxlength="60" value="<?= e($val('contact_number')) ?>"<?= invalid('contact_number') ?>>
                    <?= field_error('contact_number') ?>
                </label>
                <label class="form-field form-field--full">
                    <span class="form-label">Ship to address</span>
                    <input class="form-input" name="ship_to" maxlength="255" value="<?= e($val('ship_to')) ?>"<?= invalid('ship_to') ?>>
                    <?= field_error('ship_to') ?>
                </label>
                <label class="form-field">
                    <span class="form-label">Forwarder</span>
                    <input class="form-input" name="forwarder" maxlength="100" placeholder="e.g. AP Cargo - Air" value="<?= e($val('forwarder')) ?>"<?= invalid('forwarder') ?>>
                    <?= field_error('forwarder') ?>
                </label>
                <label class="form-field">
                    <span class="form-label">Notes <small class="muted">(printed on the PO)</small></span>
                    <input class="form-input" name="notes" maxlength="500" placeholder="e.g. DV: 5,000" value="<?= e($val('notes')) ?>"<?= invalid('notes') ?>>
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
                        <th>End-user</th>
                        <th class="num">Qty</th>
                        <th class="num">Unit Cost</th>
                        <th class="num">Total</th>
                        <th><span class="visually-hidden">Remove</span></th>
                    </tr>
                    </thead>
                    <?php foreach ($rows as $n => $row): ?>
                        <?php $renderLine((string) $n, is_array($row) ? $row : [], $n + 1); ?>
                    <?php endforeach; ?>
                </table>
            </div>
            <p class="form-hint rr-lines__hint">One line per product, up to <?= PurchaseOrders::MAX_LINES ?> lines. Unit cost = the supplier's price per unit.
                Lines loaded from purchase requests may order more than requested (extra stock), never less.</p>
        </section>
    </div>

    <aside class="form-side">
        <section class="card card--pad">
            <h2 class="card__title">Summary</h2>
            <dl class="detail-list">
                <div><dt>Lines</dt><dd id="sumLines">0</dd></div>
                <div><dt>Total quantity</dt><dd id="sumQty">0</dd></div>
                <div><dt>Total amount</dt><dd id="sumCost">—</dd></div>
            </dl>
            <p class="form-hint doc-side-hint"><?= icon('info') ?> A purchase order does not change stock. The items enter the branch when the delivery is received from this PO.</p>
        </section>
    </aside>

    <div class="form-actions">
        <?php if ($po): ?>
            <button type="submit" class="btn btn--light rr-delete" form="poDeleteForm"><?= icon('trash') ?> Delete Draft</button>
        <?php endif; ?>
        <a class="btn btn--light" href="<?= e(url('pages/' . $returnTo)) ?>">Cancel</a>
        <button type="submit" class="btn btn--light" name="action" value="save" id="savePoBtn"<?= $concrete || $po ? '' : ' disabled' ?>><?= icon('save') ?> Save Draft</button>
        <button type="submit" class="btn btn--primary" name="action" value="submit" id="submitPoBtn"<?= $concrete || $po ? '' : ' disabled' ?>
                data-confirm-submit="Send this purchase order for approval? It can't be edited while it waits."><?= icon('check') ?> Save &amp; Send for Approval</button>
    </div>
</form>

<?php if ($po): ?>
    <form method="post" action="<?= e(url('pages/' . $self)) ?>" id="poDeleteForm" data-confirm="Delete Draft PO #<?= (int) $po['id'] ?>? This cannot be undone.">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="return" value="<?= e($returnTo) ?>">
    </form>
<?php endif; ?>

<template id="lineTpl"><?php $renderLine('__i__', [], 0); ?></template>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
