<?php
/**
 * Receiving report draft: create / edit (receiving.manage), Save & Post (receiving.post + products.cost),
 * Delete draft. PRG: errors come back through flash_old / flash_errors (line errors keyed items.N.field).
 * pages/receiving-form.php[?id=5][&return=receiving.php%3Fpage%3D2]
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('receiving');
Auth::requirePermission('receiving.manage');

$canCost  = Auth::can('products.cost');
$canPost  = Auth::can('receiving.post') && $canCost;
$id       = input_int($_GET, 'id', 1);
$rr       = null;
if ($id !== null) {
    $rr = Receiving::find($id) ?? throw new HttpException(404, 'Receiving report not found.');
}
$returnTo = safe_return($_POST['return'] ?? $_GET['return'] ?? null, 'receiving.php');
$self     = 'receiving-form.php?' . http_build_query(array_filter(['id' => $id, 'return' => $returnTo]));
$viewPath = static fn (int $rrId): string => 'pages/receiving-view.php?' . http_build_query(['id' => $rrId, 'return' => $returnTo]);

if ($rr !== null && $rr['status'] !== 'draft') {
    flash('error', Receiving::label($rr) . ' is ' . strtolower(Receiving::STATUSES[$rr['status']]) . ' and can no longer be edited.');
    redirect($viewPath($id));
}

// ---------------------------------------------------------------------
// Save draft / Save & post / Delete draft (PRG)
// ---------------------------------------------------------------------
if (is_post()) {
    Csrf::verifyRequest();
    $action = input_string($_POST, 'action', 10);

    if ($action === 'delete') {
        if ($rr === null) {
            throw new HttpException(404, 'Receiving report not found.');
        }
        try {
            Receiving::deleteDraft($id);
        } catch (HttpException $e) {
            flash('error', $e->getMessage());
            redirect('pages/' . $self);
        }
        flash('success', 'Draft #' . $id . ' was deleted.');
        redirect('pages/' . $returnTo);
    }
    if ($action !== 'save' && $action !== 'post') {
        throw new HttpException(400, 'Unknown action.');
    }
    if ($action === 'post' && !$canPost) {
        abort(403, 'You do not have permission to post receiving reports.');
    }

    // Keep the header strings and the line rows (strings only) for the redisplay.
    $keepForm = static function (): void {
        $items = [];
        foreach (is_array($_POST['items'] ?? null) ? array_values($_POST['items']) : [] as $line) {
            $items[] = is_array($line) ? array_filter($line, 'is_string') : [];
        }
        flash_old(array_filter($_POST, 'is_string') + ['items' => $items]);
    };

    [$data, $errors] = Receiving::validate($_POST);
    if ($errors) {
        $keepForm();
        flash_errors($errors);
        flash('error', $errors['items'] ?? 'Please fix the highlighted fields.');
        redirect('pages/' . $self);
    }
    try {
        $savedId = Receiving::saveDraft($id, $data, (int) Auth::id());
    } catch (HttpException $e) {
        $keepForm();
        flash('error', $e->getMessage());
        redirect('pages/' . $self);
    }
    if ($action === 'save') {
        flash('success', 'Draft #' . $savedId . ' was saved. Nothing is added to stock until it is posted.');
        redirect($viewPath($savedId));
    }
    try {
        $rrNo = Receiving::post($savedId, (int) Auth::id());
    } catch (HttpException $e) {
        flash('error', 'The draft was saved but not posted: ' . $e->getMessage());
        redirect('pages/receiving-form.php?' . http_build_query(['id' => $savedId, 'return' => $returnTo]));
    }
    flash('success', "{$rrNo} was posted. The items were added to stock.");
    redirect($viewPath($savedId));
}

// ---------------------------------------------------------------------
// Form data
// ---------------------------------------------------------------------
/** "30000.5000" -> "30000.50", "12.3450" -> "12.345" (input value; at least 2 decimals). */
$costInput = static function (?string $v): string {
    if ($v === null || $v === '') {
        return '';
    }
    return preg_match('/^(\d+)\.(\d{2})(\d*)$/', $v, $m) ? $m[1] . '.' . $m[2] . rtrim($m[3], '0') : $v;
};

if (has_old()) {
    $old  = old_input();
    $rows = is_array($old['items'] ?? null) ? array_values($old['items']) : [];
} elseif ($rr !== null) {
    $rows = array_map(static fn (array $it): array => [
        'product_id' => (string) $it['product_id'],
        'quantity'   => (string) $it['quantity'],
        'unit_cost'  => $costInput(isset($it['unit_cost']) ? (string) $it['unit_cost'] : null),
        'serials'    => implode("\n", $it['serials']),
    ], $rr['items']);
} else {
    $rows = [];
}
if (!$rows) {
    $rows = [[]];
}
$rowVal = static fn (array $row, string $key): string => is_string($row[$key] ?? null) ? $row[$key] : '';

// Products: active ones, plus any already on this draft.
$onDraft = array_filter(array_map(static fn (array $r): int => (int) ($r['product_id'] ?? 0), $rows));
$sql = 'SELECT p.id, p.code, p.barcode, p.name, p.track_serial, p.is_active FROM products p WHERE p.is_active = ?';
$params = [1];
if ($onDraft) {
    $sql .= ' OR p.id IN (' . implode(',', array_fill(0, count($onDraft), '?')) . ')';
    array_push($params, ...array_values($onDraft));
}
$stmt = db()->prepare($sql . ' ORDER BY p.code');
$stmt->execute($params);
$products = $stmt->fetchAll();
$trackById = [];
foreach ($products as $p) {
    $trackById[(int) $p['id']] = (int) $p['track_serial'] === 1;
}

$supplierId = old('supplier_id', (string) ($rr['supplier_id'] ?? ''));
$stmt = db()->prepare('SELECT id, code, name, is_active FROM suppliers WHERE is_active = ? OR id = ? ORDER BY name');
$stmt->execute([1, (int) $supplierId]);
$suppliers = $stmt->fetchAll();

$val = static fn (string $key, string $default = ''): string => old($key, (string) ($rr[$key] ?? $default));
$errors = form_errors();
$title  = $rr ? 'Draft #' . $rr['id'] : 'New Receiving';
$page['title'] = $title;

$pageStyles  = ['css/receiving.css'];
$pageScripts = ['js/receiving.js'];
require ROOT_PATH . '/includes/header.php';

/** One line (a <tbody> with the item row + its serial row). $i = 'N' or '__i__' in the template. */
$renderLine = static function (string $i, array $row, int $n) use ($products, $trackById, $canCost, $rowVal): void {
    $pid     = (int) $rowVal($row, 'product_id');
    $track   = $trackById[$pid] ?? false;
    $field   = static fn (string $f): string => "items[{$i}][{$f}]";
    $errKey  = static fn (string $f): string => "items.{$i}.{$f}";
    ?>
    <tbody class="rr-line" data-line>
    <tr>
        <td class="rr-line__n muted"><?= $n ?></td>
        <td class="rr-line__item">
            <div class="rr-item-pick">
                <input class="form-input form-input--mono rr-code" type="text" maxlength="60" placeholder="Code / barcode"
                       aria-label="Product code or barcode" data-code-input autocomplete="off">
                <select class="form-input" name="<?= e($field('product_id')) ?>" aria-label="Product" data-product<?= invalid($errKey('product_id')) ?>>
                    <option value="">Choose a product…</option>
                    <?php foreach ($products as $p): ?>
                        <option value="<?= (int) $p['id'] ?>" data-code="<?= e($p['code']) ?>" data-barcode="<?= e((string) $p['barcode']) ?>"
                                data-serial="<?= (int) $p['track_serial'] ?>"<?= $pid === (int) $p['id'] ? ' selected' : '' ?>>
                            <?= e($p['code'] . ' · ' . $p['name']) ?><?= (int) $p['is_active'] === 1 ? '' : ' (inactive)' ?><?= (int) $p['track_serial'] === 1 ? ' · S/N' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?= field_error($errKey('product_id')) ?>
        </td>
        <td class="rr-line__qty">
            <input class="form-input num" type="number" name="<?= e($field('quantity')) ?>" min="1" max="<?= Products::MAX_STOCK ?>" step="1"
                   inputmode="numeric" aria-label="Quantity" data-qty value="<?= e($rowVal($row, 'quantity')) ?>"<?= invalid($errKey('quantity')) ?>>
            <?= field_error($errKey('quantity')) ?>
        </td>
        <?php if ($canCost): ?>
            <td class="rr-line__cost">
                <input class="form-input num" type="text" name="<?= e($field('unit_cost')) ?>" inputmode="decimal" maxlength="12" placeholder="0.00"
                       aria-label="Unit cost" data-cost value="<?= e($rowVal($row, 'unit_cost')) ?>"<?= invalid($errKey('unit_cost')) ?>>
                <?= field_error($errKey('unit_cost')) ?>
            </td>
            <td class="num rr-line__total" data-line-total>—</td>
        <?php endif; ?>
        <td class="c-act">
            <button type="button" class="icon-btn icon-btn--danger-outline" data-remove-line aria-label="Remove line" title="Remove line"><?= icon('trash') ?></button>
        </td>
    </tr>
    <tr class="rr-serials" data-serial-row<?= $track || $rowVal($row, 'serials') !== '' ? '' : ' hidden' ?>>
        <td></td>
        <td colspan="<?= $canCost ? 4 : 2 ?>">
            <label class="form-field">
                <span class="form-label">Serial numbers <small class="muted">(one per line; the count must equal the quantity before posting)</small></span>
                <textarea class="form-input form-input--mono rr-serials__input" name="<?= e($field('serials')) ?>" rows="3"
                          spellcheck="false" autocapitalize="characters" data-serials<?= invalid($errKey('serials')) ?>><?= e($rowVal($row, 'serials')) ?></textarea>
            </label>
            <p class="form-hint rr-serials__count" data-serial-count aria-live="polite"></p>
            <?= field_error($errKey('serials')) ?>
        </td>
        <td></td>
    </tr>
    </tbody>
    <?php
};
?>

<div class="page-head">
    <div>
        <a class="back-link" href="<?= e(url('pages/' . $returnTo)) ?>"><?= icon('arrow-left') ?> Receiving</a>
        <h1 class="sale-title"><?= e($title) ?> <span class="badge badge--info">Draft</span></h1>
        <p class="muted">Receiving at <strong><?= e($rr ? $rr['branch_code'] . ' · ' . $rr['branch_name'] : Branch::label()) ?></strong>.
            A draft doesn't change stock until it is posted.</p>
    </div>
</div>
<?php if (!Branch::isConcrete()): ?>
    <div class="alert alert--warning" role="status">
        <?= icon('info') ?>
        <span>Choose a branch in the top bar before saving: receiving reports belong to one branch.</span>
    </div>
<?php endif; ?>

<form class="form-layout rr-form" method="post" action="<?= e(url('pages/' . $self)) ?>" novalidate id="rrForm"
      data-max-lines="<?= Receiving::MAX_LINES ?>" data-currency="<?= e(config('app.currency')) ?>">
    <?= Csrf::field() ?>
    <input type="hidden" name="return" value="<?= e($returnTo) ?>">

    <div class="form-stack">
        <section class="card card--pad">
            <h2 class="card__title">Delivery</h2>
            <div class="form-grid">
                <label class="form-field">
                    <span class="form-label">Supplier *</span>
                    <select class="form-input" name="supplier_id" required<?= invalid('supplier_id') ?>>
                        <option value="">Choose a supplier…</option>
                        <?php foreach ($suppliers as $s): ?>
                            <option value="<?= (int) $s['id'] ?>"<?= $supplierId === (string) $s['id'] ? ' selected' : '' ?>><?= e($s['name'] . ' (' . $s['code'] . ')') ?><?= (int) $s['is_active'] === 1 ? '' : ' (inactive)' ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= field_error('supplier_id') ?>
                </label>
                <label class="form-field">
                    <span class="form-label">Received date *</span>
                    <input class="form-input" type="date" name="received_date" required max="<?= e(date('Y-m-d')) ?>"
                           value="<?= e($val('received_date', date('Y-m-d'))) ?>"<?= invalid('received_date') ?>>
                    <?= field_error('received_date') ?>
                </label>
                <label class="form-field">
                    <span class="form-label">Reference / DR no.</span>
                    <input class="form-input form-input--mono" name="reference_no" maxlength="50" placeholder="Supplier delivery receipt or invoice no."
                           value="<?= e($val('reference_no')) ?>"<?= invalid('reference_no') ?>>
                    <?= field_error('reference_no') ?>
                </label>
                <label class="form-field form-field--full">
                    <span class="form-label">Notes</span>
                    <textarea class="form-input" name="notes" rows="2" maxlength="500"<?= invalid('notes') ?>><?= e($val('notes')) ?></textarea>
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
                <table class="table rr-lines" id="rrLines">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Item</th>
                        <th class="num">Qty</th>
                        <?php if ($canCost): ?><th class="num">Unit Cost</th><th class="num">Line Total</th><?php endif; ?>
                        <th><span class="visually-hidden">Remove</span></th>
                    </tr>
                    </thead>
                    <?php foreach ($rows as $n => $row): ?>
                        <?php $renderLine((string) $n, is_array($row) ? $row : [], $n + 1); ?>
                    <?php endforeach; ?>
                </table>
            </div>
            <p class="form-hint rr-lines__hint">Type a code or scan a barcode in the first box, or pick the product. One line per product, up to <?= Receiving::MAX_LINES ?> lines.</p>
        </section>
    </div>

    <aside class="form-side">
        <section class="card card--pad">
            <h2 class="card__title">Summary</h2>
            <dl class="detail-list">
                <div><dt>Lines</dt><dd id="sumLines">0</dd></div>
                <div><dt>Total quantity</dt><dd id="sumQty">0</dd></div>
                <?php if ($canCost): ?><div><dt>Total cost</dt><dd id="sumCost">—</dd></div><?php endif; ?>
            </dl>
            <?php if (!$canCost): ?>
                <p class="form-hint rr-side-hint">Costs are entered by a user who can see product costs before the report is posted.</p>
            <?php endif; ?>
        </section>
        <?php if ($rr): ?>
            <section class="card card--pad">
                <h2 class="card__title">Draft</h2>
                <dl class="detail-list">
                    <div><dt>Created by</dt><dd><?= e($rr['created_by_name']) ?></dd></div>
                    <div><dt>Created</dt><dd><?= e(date('M j, Y g:i A', strtotime($rr['created_at']))) ?></dd></div>
                    <div><dt>Location</dt><dd><?= e($rr['warehouse_code'] . ' / ' . $rr['location_code']) ?></dd></div>
                </dl>
            </section>
        <?php endif; ?>
    </aside>

    <div class="form-actions">
        <?php if ($rr): ?>
            <button type="submit" class="btn btn--light rr-delete" form="rrDeleteForm"><?= icon('trash') ?> Delete Draft</button>
        <?php endif; ?>
        <a class="btn btn--light" href="<?= e(url('pages/' . $returnTo)) ?>">Cancel</a>
        <button type="submit" class="btn <?= $canPost ? 'btn--light' : 'btn--primary' ?>" name="action" value="save" id="saveDraftBtn"><?= icon('save') ?> Save Draft</button>
        <?php if ($canPost): ?>
            <button type="submit" class="btn btn--primary" name="action" value="post" id="postBtn"
                    data-confirm-submit="Post this receiving report? The items are added to stock and the average cost is updated. A posted report can only be cancelled while its stock is untouched."><?= icon('check') ?> Save &amp; Post</button>
        <?php endif; ?>
    </div>
</form>

<?php if ($rr): ?>
    <form method="post" action="<?= e(url('pages/' . $self)) ?>" id="rrDeleteForm" data-confirm="Delete Draft #<?= (int) $rr['id'] ?>? This cannot be undone.">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="return" value="<?= e($returnTo) ?>">
    </form>
<?php endif; ?>

<template id="rrLineTpl"><?php $renderLine('__i__', [], 0); ?></template>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
