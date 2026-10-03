<?php
/**
 * New one-step stock document, posted at once (InventoryDocs::validate + transfer / issue / writeOff):
 *   ?type=transfer&preset=move|damage|display|restore   (preset only pre-filters the location pickers;
 *                                                          the server derives the purpose from the kinds)
 *   ?type=issue      internal use from a stock / display location (inventory.issue)
 *   ?type=writeoff   write-off from any location, normally DAMAGED (inventory.damage)
 * Lines: product + quantity; serial-tracked items pick their serial numbers (api/inventory/serials.php).
 * PRG: errors come back through flash_old / flash_errors (line errors keyed items.N.field).
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('stock-docs');

$type = is_string($_GET['type'] ?? null) && in_array($_GET['type'], ['transfer', 'issue', 'writeoff'], true) ? $_GET['type'] : 'transfer';
$preset = null;
if ($type === 'transfer') {
    $preset = is_string($_GET['preset'] ?? null) && isset(InventoryDocs::PURPOSE_PERMISSIONS[$_GET['preset']]) ? $_GET['preset'] : 'move';
}
$permission = match ($type) {
    'transfer' => InventoryDocs::PURPOSE_PERMISSIONS[$preset],
    'issue'    => 'inventory.issue',
    default    => 'inventory.damage',
};
Auth::requirePermission($permission);

$returnTo = safe_return($_POST['return'] ?? $_GET['return'] ?? null, 'stock-docs.php');
$self     = 'stock-doc-form.php?' . http_build_query(array_filter(['type' => $type, 'preset' => $preset, 'return' => $returnTo]));

/** [title, intro, from kinds, to kinds (transfer), reason required, confirm text] */
$modes = [
    'move'     => ['Transfer Stock', 'Move stock between locations of this branch. Branch stock and cost stay the same.',
                   ['stock'], ['stock'], false, 'Post this transfer? Stock moves now.'],
    'damage'   => ['Mark Damaged', 'Move defective units to the DAMAGED location of the same warehouse. They can no longer be sold.',
                   ['stock', 'display'], ['damaged'], true, 'Mark these units as damaged? They move to DAMAGED now and can no longer be sold.'],
    'display'  => ['Display Unit', 'Put units on display or demo (the DISPLAY location of the same warehouse). They can no longer be sold until restored.',
                   ['stock'], ['display'], true, 'Move these units to DISPLAY now? They can no longer be sold until restored.'],
    'restore'  => ['Restore to Stock', 'Bring damaged (repaired) or display units back to a stock location.',
                   ['damaged', 'display'], ['stock'], false, 'Restore these units to stock now?'],
    'issue'    => ['Internal Use', 'Issue stock for company use (e.g. office or service use). The units leave stock at their average cost.',
                   ['stock', 'display'], [], true, 'Issue these items for internal use? They leave stock now.'],
    'writeoff' => ['Write-off', 'Remove damaged, lost or scrapped units from stock at their average cost. Usually from DAMAGED.',
                   ['stock', 'damaged', 'display'], [], true, 'Write off these items? They leave stock now. This cannot be undone.'],
];
[$title, $intro, $fromKinds, $toKinds, $reasonRequired, $confirmText] = $modes[$preset ?? $type];
$page['title'] = $title;

// ---------------------------------------------------------------------
// Post (PRG)
// ---------------------------------------------------------------------
if (is_post()) {
    Csrf::verifyRequest();
    // Keep the header strings and the line rows (strings + serial id lists) for the redisplay.
    $keepForm = static function (): void {
        $items = [];
        foreach (is_array($_POST['items'] ?? null) ? array_values($_POST['items']) : [] as $line) {
            $line = is_array($line) ? $line : [];
            $row  = array_filter($line, 'is_string');
            $row['serial_ids'] = is_array($line['serial_ids'] ?? null) ? array_values(array_filter($line['serial_ids'], 'is_string')) : [];
            $items[] = $row;
        }
        flash_old(array_filter($_POST, 'is_string') + ['items' => $items]);
    };
    try {
        [$data, $errors] = InventoryDocs::validate($type, $_POST);
        if ($errors) {
            $keepForm();
            flash_errors($errors);
            flash('error', $errors['items'] ?? $errors['to_location_id'] ?? 'Please fix the highlighted fields.');
            redirect('pages/' . $self);
        }
        $doc = match ($type) {
            'transfer' => InventoryDocs::transfer($data, (int) Auth::id()),
            'issue'    => InventoryDocs::issue($data, (int) Auth::id()),
            default    => InventoryDocs::writeOff($data, (int) Auth::id()),
        };
    } catch (HttpException $e) {
        $keepForm();
        flash('error', $e->getMessage());
        redirect('pages/' . $self);
    }
    flash('success', "{$doc['doc_no']} was posted. The stock has been updated.");
    redirect('pages/stock-doc-view.php?' . http_build_query(['id' => $doc['id'], 'return' => $returnTo]));
}

// ---------------------------------------------------------------------
// Form data
// ---------------------------------------------------------------------
$concrete  = Branch::isConcrete();
$locations = $concrete ? Warehouses::pickerLocations((int) Branch::current()) : [];
$fromList  = array_values(array_filter($locations, static fn (array $l): bool => in_array($l['kind'], $fromKinds, true)));
$toList    = array_values(array_filter($locations, static fn (array $l): bool => in_array($l['kind'], $toKinds, true)));

// Default source: the POS location (or the first DAMAGED for a write-off, the first match otherwise).
$defaultFrom = '';
foreach ($fromList as $l) {
    if (($type === 'writeoff' && $l['kind'] === 'damaged') || ($type !== 'writeoff' && $l['is_default'])) {
        $defaultFrom = (string) $l['id'];
        break;
    }
}
if ($defaultFrom === '' && $fromList) {
    $defaultFrom = (string) $fromList[0]['id'];
}

$old  = has_old() ? old_input() : [];
$rows = is_array($old['items'] ?? null) ? array_values($old['items']) : [];
if (!$rows) {
    $rows = [[]];
}
$rowVal = static fn (array $row, string $key): string => is_string($row[$key] ?? null) ? $row[$key] : '';
$fromId = old('from_location_id', $defaultFrom);
$toId   = old('to_location_id', '');

$stmt = db()->prepare(
    'SELECT p.id, p.code, p.barcode, p.name, p.track_serial, un.code AS unit_code
       FROM products p LEFT JOIN units un ON un.id = p.unit_id
      WHERE p.is_active = ? ORDER BY p.code'
);
$stmt->execute([1]);
$products = $stmt->fetchAll();

$errors = form_errors();

$pageStyles  = ['css/sales.css', 'css/receiving.css', 'css/stock-docs.css'];
$pageScripts = ['js/stock-docs.js'];
require ROOT_PATH . '/includes/header.php';

/** <option>s grouped by warehouse. */
$locationOptions = static function (array $list, string $selected): void {
    $lastWh = null;
    foreach ($list as $l) {
        if ($lastWh !== $l['warehouse_id']) {
            if ($lastWh !== null) {
                echo '</optgroup>';
            }
            echo '<optgroup label="' . e($l['warehouse_code'] . ' · ' . $l['warehouse_name']) . '">';
            $lastWh = $l['warehouse_id'];
        }
        echo '<option value="' . (int) $l['id'] . '" data-warehouse="' . (int) $l['warehouse_id'] . '" data-kind="' . e($l['kind']) . '"'
            . ' data-label="' . e($l['warehouse_code'] . ' / ' . $l['code']) . '"'
            . ($selected === (string) $l['id'] ? ' selected' : '') . '>'
            . e($l['code'] . ' - ' . $l['name']) . ($l['is_default'] ? ' (POS)' : '') . '</option>';
    }
    if ($lastWh !== null) {
        echo '</optgroup>';
    }
};

/** One line (a <tbody> with the item row + its serial row). $i = 'N' or '__i__' in the template. */
$renderLine = static function (string $i, array $row, int $n) use ($products, $rowVal): void {
    $pid     = (int) $rowVal($row, 'product_id');
    $field   = static fn (string $f): string => "items[{$i}][{$f}]";
    $errKey  = static fn (string $f): string => "items.{$i}.{$f}";
    $chosen  = is_array($row['serial_ids'] ?? null) ? implode(',', array_map('intval', $row['serial_ids'])) : '';
    ?>
    <tbody class="rr-line" data-line data-index="<?= e($i) ?>">
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
                                data-serial="<?= (int) $p['track_serial'] ?>" data-unit="<?= e((string) $p['unit_code']) ?>"<?= $pid === (int) $p['id'] ? ' selected' : '' ?>>
                            <?= e($p['code'] . ' · ' . $p['name']) ?><?= (int) $p['track_serial'] === 1 ? ' · S/N' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?= field_error($errKey('product_id')) ?>
        </td>
        <td class="rr-line__qty">
            <input class="form-input num" type="number" name="<?= e($field('quantity')) ?>" min="1" max="<?= Products::MAX_STOCK ?>" step="1"
                   inputmode="numeric" aria-label="Quantity" data-qty value="<?= e($rowVal($row, 'quantity')) ?>"<?= invalid($errKey('quantity')) ?>>
            <p class="form-hint doc-avail" data-avail aria-live="polite"></p>
            <?= field_error($errKey('quantity')) ?>
        </td>
        <td class="c-act">
            <button type="button" class="icon-btn icon-btn--danger-outline" data-remove-line aria-label="Remove line" title="Remove line"><?= icon('trash') ?></button>
        </td>
    </tr>
    <tr class="rr-serials" data-serial-row hidden>
        <td></td>
        <td colspan="2">
            <fieldset class="sn-pick" data-serial-box data-name="<?= e($field('serial_ids')) ?>[]" data-selected="<?= e($chosen) ?>">
                <legend class="form-label">Serial numbers <small class="muted">(tick one per unit)</small></legend>
                <div class="sn-pick__list" data-serial-list></div>
                <p class="form-hint rr-serials__count" data-serial-count aria-live="polite"></p>
            </fieldset>
            <?= field_error($errKey('serial_ids')) ?>
        </td>
        <td></td>
    </tr>
    </tbody>
    <?php
};
?>

<div class="page-head">
    <div>
        <a class="back-link" href="<?= e(url('pages/' . $returnTo)) ?>"><?= icon('arrow-left') ?> Stock Operations</a>
        <h1><?= e($title) ?></h1>
        <p class="muted"><?= e($intro) ?></p>
    </div>
    <div class="page-actions">
        <span class="badge badge--period badge--branch"><?= icon('store') ?> <?= e(Branch::label()) ?></span>
    </div>
</div>
<?php if (!$concrete): ?>
    <div class="alert alert--warning" role="status">
        <?= icon('info') ?>
        <span>Choose a branch in the top bar first: stock documents belong to one branch.</span>
    </div>
<?php elseif (!$fromList || ($type === 'transfer' && !$toList)): ?>
    <div class="alert alert--warning" role="status">
        <?= icon('info') ?>
        <span>This branch has no active location for this operation<?= Auth::can('warehouses.manage') ? '. Check Settings → Warehouses.' : '.' ?></span>
    </div>
<?php endif; ?>

<form class="form-layout rr-form doc-form" method="post" action="<?= e(url('pages/' . $self)) ?>" novalidate id="docForm"
      data-max-lines="<?= InventoryDocs::MAX_LINES ?>" data-type="<?= e($type) ?>" data-preset="<?= e($preset ?? '') ?>"
      data-confirm="<?= e($confirmText) ?>">
    <?= Csrf::field() ?>
    <input type="hidden" name="return" value="<?= e($returnTo) ?>">

    <div class="form-stack">
        <section class="card card--pad">
            <h2 class="card__title"><?= $type === 'transfer' ? 'From / To' : 'Location' ?></h2>
            <div class="form-grid">
                <label class="form-field">
                    <span class="form-label"><?= $type === 'transfer' ? 'From location *' : 'Take from *' ?></span>
                    <select class="form-input" name="from_location_id" id="fromLocation" required<?= invalid('from_location_id') ?>>
                        <option value="">Choose a location…</option>
                        <?php $locationOptions($fromList, $fromId); ?>
                    </select>
                    <?= field_error('from_location_id') ?>
                </label>
                <?php if ($type === 'transfer'): ?>
                    <label class="form-field">
                        <span class="form-label">To location *</span>
                        <select class="form-input" name="to_location_id" id="toLocation" required<?= invalid('to_location_id') ?>
                                data-same-warehouse="<?= in_array($preset, ['damage', 'display'], true) ? '1' : '0' ?>">
                            <option value="">Choose a location…</option>
                            <?php $locationOptions($toList, $toId); ?>
                        </select>
                        <?= field_error('to_location_id') ?>
                        <?php if (in_array($preset, ['damage', 'display'], true)): ?>
                            <p class="form-hint">Only the <?= $preset === 'damage' ? 'DAMAGED' : 'DISPLAY' ?> location of the source warehouse.</p>
                        <?php endif; ?>
                    </label>
                <?php endif; ?>
                <label class="form-field form-field--full">
                    <span class="form-label">Reason<?= $reasonRequired ? ' *' : ' <small class="muted">(optional)</small>' ?></span>
                    <input class="form-input" name="reason" maxlength="255" value="<?= e(old('reason')) ?>"<?= $reasonRequired ? ' required minlength="3"' : '' ?>
                           placeholder="<?= e(match ($preset ?? $type) {
                               'damage'   => 'e.g. Cracked screen found during checking',
                               'display'  => 'e.g. Demo unit for the front counter',
                               'issue'    => 'e.g. Laptop for the service technician',
                               'writeoff' => 'e.g. Beyond repair, supplier RMA rejected',
                               default    => 'e.g. Restock the front shelf',
                           }) ?>"<?= invalid('reason') ?>>
                    <?= field_error('reason') ?>
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
                        <th><span class="visually-hidden">Remove</span></th>
                    </tr>
                    </thead>
                    <?php foreach ($rows as $n => $row): ?>
                        <?php $renderLine((string) $n, is_array($row) ? $row : [], $n + 1); ?>
                    <?php endforeach; ?>
                </table>
            </div>
            <p class="form-hint rr-lines__hint">Type a code or scan a barcode in the first box, or pick the product. One line per product,
                up to <?= InventoryDocs::MAX_LINES ?> lines. Serial-tracked items list the serial numbers at the source location.</p>
        </section>
    </div>

    <aside class="form-side">
        <section class="card card--pad">
            <h2 class="card__title">Summary</h2>
            <dl class="detail-list">
                <div><dt>Branch</dt><dd><?= e(Branch::label()) ?></dd></div>
                <div><dt>Lines</dt><dd id="sumLines">0</dd></div>
                <div><dt>Total quantity</dt><dd id="sumQty">0</dd></div>
            </dl>
            <p class="form-hint doc-side-hint"><?= icon('info') ?> Saving posts the document at once and changes stock. It gets its number when saved.</p>
        </section>
    </aside>

    <div class="form-actions">
        <a class="btn btn--light" href="<?= e(url('pages/' . $returnTo)) ?>">Cancel</a>
        <button type="submit" class="btn btn--primary" id="postDocBtn"<?= $concrete && $fromList ? '' : ' disabled' ?>><?= icon('check') ?> Save &amp; Post</button>
    </div>
</form>

<template id="docLineTpl"><?php $renderLine('__i__', [], 0); ?></template>
<template id="serialTpl"><label class="sn-check"><input type="checkbox"><span class="serial-cell"></span></label></template>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
