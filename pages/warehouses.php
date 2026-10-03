<?php
/**
 * Settings → Warehouses (warehouses.manage): warehouses and storage locations of the current branch.
 * Add / rename warehouses and locations (dialogs, PRG), activate / deactivate (row POST forms).
 * DAMAGED and DISPLAY are system locations (rename only); the default warehouse / location can't be
 * deactivated. "All branches": read-only list grouped by branch. Warehouses:: checks every rule again.
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = settings_page('warehouses', 'Warehouses'); // warehouses.manage

$self = 'warehouses.php';

// ---------------------------------------------------------------------
// Save (dialogs) and activate / deactivate: POST -> back to the list
// ---------------------------------------------------------------------
if (is_post()) {
    Csrf::verifyRequest();
    $action = input_string($_POST, 'action', 20);
    $id     = input_int($_POST, 'id', 1);
    $keepForm = static fn (string $form) => flash_old(array_filter($_POST, 'is_string') + [
        'form' => $form,
        'id'   => $id !== null ? (string) $id : '',
    ]);
    try {
        switch ($action) {
            case 'save_warehouse':
                $row = $id !== null ? (Warehouses::findWarehouse($id) ?? throw new HttpException(404, 'Warehouse not found.')) : null;
                [$data, $errors] = Warehouses::validateWarehouse($_POST, $row);
                if ($errors) {
                    $keepForm('warehouse');
                    flash_errors($errors);
                    flash('error', 'Please fix the highlighted fields.');
                    break;
                }
                Warehouses::saveWarehouse($id, $data);
                flash('success', $row
                    ? "Warehouse {$row['code']} was renamed to {$data['name']}."
                    : "Warehouse {$data['code']} was added with GENERAL, DAMAGED and DISPLAY locations.");
                break;

            case 'save_location':
                $warehouseId = input_int($_POST, 'warehouse_id', 1) ?? 0;
                $row = $id !== null ? (Warehouses::findLocation($id) ?? throw new HttpException(404, 'Location not found.')) : null;
                if ($row === null) {
                    Warehouses::findWarehouse($warehouseId) ?? throw new HttpException(404, 'Warehouse not found.');
                }
                [$data, $errors] = Warehouses::validateLocation($_POST, $row, $warehouseId);
                if ($errors) {
                    $keepForm('location');
                    flash_errors($errors);
                    flash('error', 'Please fix the highlighted fields.');
                    break;
                }
                Warehouses::saveLocation($id, $warehouseId, $data);
                flash('success', $row
                    ? "Location {$row['code']} was renamed to {$data['name']}."
                    : "Location {$data['code']} was added.");
                break;

            case 'toggle':
                $type   = input_string($_POST, 'type', 10);
                $active = input_string($_POST, 'active', 1) === '1';
                $row = Warehouses::setActive($type, $id ?? 0, $active);
                $what = ($type === 'location' ? 'Location ' : 'Warehouse ') . $row['code'];
                flash('success', $active ? "{$what} is active again." : "{$what} was deactivated. It is no longer offered for stock operations.");
                break;

            default:
                throw new HttpException(400, 'Unknown action.');
        }
    } catch (HttpException $e) {
        if ($e->status === 403) {
            throw $e;
        }
        if ($action === 'save_warehouse' || $action === 'save_location') {
            $keepForm($action === 'save_warehouse' ? 'warehouse' : 'location');
            if (is_array($e->details['errors'] ?? null)) {
                flash_errors($e->details['errors']);
            }
        }
        flash('error', $e->getMessage());
    }
    redirect('pages/' . $self);
}

// ---------------------------------------------------------------------
// List
// ---------------------------------------------------------------------
$warehouses = Warehouses::list();
$canEdit    = Branch::isConcrete(); // writes happen in one branch; "All branches" is read-only
$byBranch   = [];
foreach ($warehouses as $w) {
    $byBranch[(int) $w['branch_id']]['name'] = $w['branch_code'] . ' · ' . $w['branch_name'];
    $byBranch[(int) $w['branch_id']]['warehouses'][] = $w;
}

$reopen     = has_old() ? old('form') : '';
$kindBadge  = ['stock' => 'badge--info', 'damaged' => 'badge--danger', 'display' => 'badge--warning'];
$settingsTab = 'warehouses';

$pageStyles  = ['css/sales.css', 'css/settings.css', 'css/stock-docs.css'];
$pageScripts = ['js/warehouses.js'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Settings</h1>
        <p class="muted">Warehouses and storage locations: where stock is kept inside a branch.</p>
    </div>
    <div class="page-actions">
        <span class="badge badge--period badge--branch"><?= icon('store') ?> <?= e(Branch::label()) ?></span>
        <?php if ($canEdit): ?>
            <button type="button" class="btn btn--primary" data-wh-new id="addWarehouse"><?= icon('plus') ?> Add Warehouse</button>
        <?php endif; ?>
    </div>
</div>

<?php require ROOT_PATH . '/includes/settings-nav.php'; ?>

<?php if (!$canEdit): ?>
    <div class="alert alert--info" role="status">
        <?= icon('info') ?>
        <span>Showing the warehouses of all branches. Switch to a branch in the top bar to add, rename or deactivate warehouses and locations.</span>
    </div>
<?php endif; ?>

<?php foreach ($byBranch as $group): ?>
    <?php if (!$canEdit): ?><h2 class="wh-branch"><?= icon('store') ?> <?= e($group['name']) ?></h2><?php endif; ?>
    <div class="wh-list">
    <?php foreach ($group['warehouses'] as $w): ?>
        <?php
        $wActive  = (int) $w['is_active'] === 1;
        $wDefault = (int) $w['is_default'] === 1;
        ?>
        <section class="card wh-card<?= $wActive ? '' : ' is-inactive' ?>" data-warehouse="<?= e($w['code']) ?>">
            <header class="card__head wh-card__head">
                <h2>
                    <span class="branch-code"><?= e($w['code']) ?></span>
                    <span><?= e($w['name']) ?></span>
                    <?php if ($wDefault): ?><span class="badge badge--main"><?= icon('check') ?> Default</span><?php endif; ?>
                    <?php if (!$wActive): ?><span class="badge">Inactive</span><?php endif; ?>
                </h2>
                <?php if ($canEdit): ?>
                    <div class="row-actions">
                        <?php if ($wActive): ?>
                            <button type="button" class="btn btn--light btn--sm" data-loc-new data-warehouse-id="<?= (int) $w['id'] ?>"
                                    data-warehouse-label="<?= e($w['code'] . ' · ' . $w['name']) ?>"><?= icon('plus') ?> Add Location</button>
                        <?php endif; ?>
                        <button type="button" class="icon-btn" title="Rename" aria-label="Rename warehouse <?= e($w['code']) ?>" data-wh-edit
                                data-id="<?= (int) $w['id'] ?>" data-code="<?= e($w['code']) ?>" data-name="<?= e($w['name']) ?>"><?= icon('edit') ?></button>
                        <?php if (!$wDefault): ?>
                            <form method="post" action="<?= e(url('pages/' . $self)) ?>"
                                  <?= $wActive ? 'data-confirm="Deactivate warehouse ' . e($w['code']) . '? Its locations must be empty."' : '' ?>>
                                <?= Csrf::field() ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="type" value="warehouse">
                                <input type="hidden" name="id" value="<?= (int) $w['id'] ?>">
                                <input type="hidden" name="active" value="<?= $wActive ? '0' : '1' ?>">
                                <?php $label = $wActive ? 'Deactivate' : 'Activate'; ?>
                                <button type="submit" class="icon-btn<?= $wActive ? '' : ' icon-btn--success' ?>" data-act="toggle"
                                        title="<?= $label ?>" aria-label="<?= $label ?> warehouse <?= e($w['code']) ?>"><?= icon('power') ?></button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </header>
            <div class="table-wrap">
                <table class="table table--list wh-table">
                    <thead>
                    <tr>
                        <th>Location</th>
                        <th>Kind</th>
                        <th class="col-opt">Use</th>
                        <th class="num">Items</th>
                        <th class="num">Units</th>
                        <th>Status</th>
                        <?php if ($canEdit): ?><th class="actions-col">Actions</th><?php endif; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($w['locations'] as $l): ?>
                        <?php
                        $lActive  = (int) $l['is_active'] === 1;
                        $lDefault = (int) $l['is_default'] === 1;
                        $canToggle = !$lDefault && !$l['is_system'];
                        ?>
                        <tr class="<?= $lActive ? '' : 'is-inactive' ?>" data-location="<?= e($l['code']) ?>">
                            <td>
                                <span class="wh-code"><?= e($l['code']) ?></span>
                                <span class="block"><?= e($l['name']) ?></span>
                            </td>
                            <td><span class="badge <?= e($kindBadge[$l['kind']] ?? '') ?>"><?= e(Warehouses::KINDS[$l['kind']] ?? $l['kind']) ?></span></td>
                            <td class="col-opt">
                                <?php if ($lDefault && $wDefault && (int) $l['is_sellable'] === 1): ?>
                                    <span class="badge badge--success"><?= icon('cart') ?> POS sells here</span>
                                <?php elseif ($lDefault): ?>
                                    <span class="muted">Default of the warehouse</span>
                                <?php elseif ($l['is_system']): ?>
                                    <span class="muted"><?= icon('lock') ?> System location</span>
                                <?php else: ?>
                                    <span class="muted">Storage bin</span>
                                <?php endif; ?>
                                <?php if ((int) $l['open_counts'] > 0): ?><span class="badge badge--info">Count open</span><?php endif; ?>
                            </td>
                            <td class="num"><?= number_format((int) $l['items']) ?></td>
                            <td class="num"><?= number_format((int) $l['qty']) ?></td>
                            <td><span class="badge<?= $lActive ? ' badge--success' : '' ?>"><?= $lActive ? 'Active' : 'Inactive' ?></span></td>
                            <?php if ($canEdit): ?>
                                <td class="actions-col">
                                    <div class="row-actions">
                                        <button type="button" class="icon-btn" title="Rename" aria-label="Rename location <?= e($l['code']) ?>" data-loc-edit
                                                data-id="<?= (int) $l['id'] ?>" data-code="<?= e($l['code']) ?>" data-name="<?= e($l['name']) ?>"
                                                data-warehouse-id="<?= (int) $w['id'] ?>" data-warehouse-label="<?= e($w['code'] . ' · ' . $w['name']) ?>"><?= icon('edit') ?></button>
                                        <?php if ($canToggle): ?>
                                            <form method="post" action="<?= e(url('pages/' . $self)) ?>"
                                                  <?= $lActive ? 'data-confirm="Deactivate location ' . e($w['code'] . ' / ' . $l['code']) . '? It must be empty."' : '' ?>>
                                                <?= Csrf::field() ?>
                                                <input type="hidden" name="action" value="toggle">
                                                <input type="hidden" name="type" value="location">
                                                <input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                                                <input type="hidden" name="active" value="<?= $lActive ? '0' : '1' ?>">
                                                <?php $label = $lActive ? 'Deactivate' : 'Activate'; ?>
                                                <button type="submit" class="icon-btn<?= $lActive ? '' : ' icon-btn--success' ?>" data-act="toggle"
                                                        title="<?= $label ?>" aria-label="<?= $label ?> location <?= e($l['code']) ?>"><?= icon('power') ?></button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endforeach; ?>
    </div>
<?php endforeach; ?>

<?php if (!$warehouses): ?>
    <section class="card"><p class="empty-note">No warehouses found.</p></section>
<?php endif; ?>

<p class="doc-foot muted wh-foot"><?= icon('info') ?> <span>The POS sells only from the default location of the default warehouse.
    DAMAGED and DISPLAY are created with every warehouse and can only be renamed. A location can be deactivated only when it is empty
    and has no open stock count.</span></p>

<?php if ($canEdit): ?>
    <?php $editingWh = $reopen === 'warehouse' && old('id') !== ''; ?>
    <dialog class="modal" id="whDialog" aria-labelledby="whTitle"<?= $reopen === 'warehouse' ? ' data-reopen' : '' ?>
            data-title-add="Add Warehouse" data-title-edit="Rename Warehouse">
        <form class="modal__body" method="post" action="<?= e(url('pages/' . $self)) ?>" novalidate id="whForm">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="save_warehouse">
            <input type="hidden" name="id" id="whId" value="<?= e($reopen === 'warehouse' ? old('id') : '') ?>">
            <header class="modal__head">
                <h2 id="whTitle"><?= $editingWh ? 'Rename Warehouse' : 'Add Warehouse' ?></h2>
                <button type="button" class="modal__close" data-close aria-label="Close"><?= icon('x') ?></button>
            </header>
            <div class="form-grid form-grid--single">
                <label class="form-field">
                    <span class="form-label">Code *</span>
                    <input class="form-input form-input--mono" name="code" id="whCode" maxlength="20" required placeholder="WH2"
                           autocapitalize="characters" value="<?= e($reopen === 'warehouse' ? old('code') : '') ?>"<?= $reopen === 'warehouse' ? invalid('code') : '' ?><?= $editingWh ? ' readonly' : '' ?>>
                    <?= $reopen === 'warehouse' ? field_error('code') : '' ?>
                    <p class="form-hint" id="whCodeHint">Capital letters, numbers, dashes. The code can't be changed later.</p>
                </label>
                <label class="form-field">
                    <span class="form-label">Name *</span>
                    <input class="form-input" name="name" id="whName" maxlength="100" required placeholder="e.g. Back Storage"
                           value="<?= e($reopen === 'warehouse' ? old('name') : '') ?>"<?= $reopen === 'warehouse' ? invalid('name') : '' ?>>
                    <?= $reopen === 'warehouse' ? field_error('name') : '' ?>
                </label>
                <p class="form-hint" id="whNewHint">A new warehouse gets three locations: GENERAL (stock), DAMAGED and DISPLAY.</p>
            </div>
            <footer class="modal__foot">
                <button type="button" class="btn btn--light" data-close>Cancel</button>
                <button type="submit" class="btn btn--primary"><?= icon('save') ?> Save</button>
            </footer>
        </form>
    </dialog>

    <?php $editingLoc = $reopen === 'location' && old('id') !== ''; ?>
    <dialog class="modal" id="locDialog" aria-labelledby="locTitle"<?= $reopen === 'location' ? ' data-reopen' : '' ?>
            data-title-add="Add Location" data-title-edit="Rename Location">
        <form class="modal__body" method="post" action="<?= e(url('pages/' . $self)) ?>" novalidate id="locForm">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="save_location">
            <input type="hidden" name="id" id="locId" value="<?= e($reopen === 'location' ? old('id') : '') ?>">
            <input type="hidden" name="warehouse_id" id="locWarehouse" value="<?= e($reopen === 'location' ? old('warehouse_id') : '') ?>">
            <header class="modal__head">
                <h2 id="locTitle"><?= $editingLoc ? 'Rename Location' : 'Add Location' ?></h2>
                <button type="button" class="modal__close" data-close aria-label="Close"><?= icon('x') ?></button>
            </header>
            <p class="muted wh-dialog-sub">Warehouse <strong id="locWarehouseLabel"><?= e($reopen === 'location' ? old('warehouse_label') : '') ?></strong></p>
            <input type="hidden" name="warehouse_label" id="locWarehouseLabelInput" value="<?= e($reopen === 'location' ? old('warehouse_label') : '') ?>">
            <div class="form-grid form-grid--single">
                <label class="form-field">
                    <span class="form-label">Code *</span>
                    <input class="form-input form-input--mono" name="code" id="locCode" maxlength="20" required placeholder="BIN-A"
                           autocapitalize="characters" value="<?= e($reopen === 'location' ? old('code') : '') ?>"<?= $reopen === 'location' ? invalid('code') : '' ?><?= $editingLoc ? ' readonly' : '' ?>>
                    <?= $reopen === 'location' ? field_error('code') : '' ?>
                    <p class="form-hint">DAMAGED and DISPLAY are reserved. The code can't be changed later.</p>
                </label>
                <label class="form-field">
                    <span class="form-label">Name *</span>
                    <input class="form-input" name="name" id="locName" maxlength="100" required placeholder="e.g. Shelf A"
                           value="<?= e($reopen === 'location' ? old('name') : '') ?>"<?= $reopen === 'location' ? invalid('name') : '' ?>>
                    <?= $reopen === 'location' ? field_error('name') : '' ?>
                </label>
                <p class="form-hint">New locations hold stock but are not sold from: move stock to the POS location with a transfer.</p>
            </div>
            <footer class="modal__foot">
                <button type="button" class="btn btn--light" data-close>Cancel</button>
                <button type="submit" class="btn btn--primary"><?= icon('save') ?> Save</button>
            </footer>
        </form>
    </dialog>
<?php endif; ?>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
