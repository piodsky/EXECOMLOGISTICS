<?php
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('inventory');

$canManage = Auth::can('products.manage');                          // catalogue is company-wide
$canAdjust = Auth::can('inventory.adjust') && Branch::isConcrete();  // stock is per branch
$id      = input_int($_GET, 'id', 1);
$product = null;
if ($id !== null) {
    $product = Products::find($id) ?? throw new HttpException(404, 'Product not found.');
} elseif (!$canManage) {
    abort(403, 'You do not have permission to add products.');
}
$ro = $canManage ? '' : ' disabled'; // read-only view for inventory.view only
$page['title'] = $product ? ($canManage ? 'Edit Product' : $product['name']) : 'Add Product';
$returnTo = safe_return($_POST['return'] ?? $_GET['return'] ?? null, 'inventory.php');
$self     = 'product-form.php?' . http_build_query(array_filter(['id' => $id, 'return' => $returnTo]));

// ---------------------------------------------------------------------
// Save
// ---------------------------------------------------------------------
if (is_post()) {
    // A file bigger than PHP's post_max_size arrives as an empty POST (no CSRF token either).
    if ($_POST === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        flash('error', 'The upload is too large. Images can be at most 2 MB.');
        redirect('pages/' . $self);
    }
    Csrf::verifyRequest();

    // Branch prices card (own form; products.branch_price for the branches the user works in).
    if (($_POST['form'] ?? '') === 'branch_prices' && $product !== null) {
        $prices = [];
        foreach (BranchPrices::editableBranches() as $bid => $b) {
            $v    = $_POST['branch_price_' . $bid] ?? null;
            $orig = $_POST['orig_price_' . $bid] ?? null;
            // Only boxes the user changed, so a stale page never overwrites a newer price.
            if (is_string($v) && (!is_string($orig) || trim($v) !== trim($orig))) {
                $prices[$bid] = trim($v);
            }
        }
        if (!BranchPrices::editableBranches()) {
            abort(403, 'You may not set branch prices.');
        }
        try {
            $n = BranchPrices::saveForProduct((int) $id, $prices);
            flash('success', $n > 0 ? "Branch prices of {$product['name']} were saved." : 'Nothing changed.');
        } catch (HttpException $e) {
            if ($e->status !== 422) {
                throw $e;
            }
            flash_old(array_filter($_POST, 'is_string'));
            flash_errors($e->details['errors'] ?? []);
            flash('error', $e->getMessage());
        }
        redirect('pages/' . $self . '#branchPrices');
    }

    if (!$canManage) {
        abort(403, 'You do not have permission to manage products.');
    }

    [$data, $errors] = Products::validate($_POST, $id);
    $file     = $_FILES['image'] ?? null;
    $newImage = null;

    if (!ImageUpload::isEmpty($file)) {
        if ($errors) {
            $errors['image'] = 'Please choose the image again after fixing the other fields.';
        } else {
            try {
                $newImage = ImageUpload::store($file);
            } catch (HttpException $e) {
                $errors['image'] = $e->getMessage();
            }
        }
    }

    if ($errors) {
        flash_old(array_filter($_POST, 'is_string') + [
            'is_active'    => isset($_POST['is_active']) ? '1' : '0',
            'track_serial' => isset($_POST['track_serial']) ? '1' : '0',
        ]);
        flash_errors($errors);
        flash('error', 'Please fix the highlighted fields.');
        redirect('pages/' . $self);
    }

    $oldImage = $product['image'] ?? null;
    $image    = $newImage ?? (isset($_POST['remove_image']) ? null : $oldImage);

    try {
        if ($product) {
            Products::update($id, $data, $image);
        } else {
            $id = Products::create($data, $image, (int) Auth::id());
        }
    } catch (Throwable $e) {
        ImageUpload::delete($newImage); // don't leave an orphan file behind
        throw $e;
    }
    if ($oldImage !== null && $oldImage !== $image) {
        ImageUpload::delete($oldImage);
    }

    flash('success', $product ? "{$data['name']} was updated." : "{$data['name']} was added to the inventory.");
    redirect('pages/' . $returnTo);
}

// ---------------------------------------------------------------------
// Form
// ---------------------------------------------------------------------
$movements  = $product ? Products::movements($id, 15) : [];
$imageUrl   = ImageUpload::url($product['image'] ?? null);
$val = static fn (string $key, mixed $default = ''): string => old($key, (string) ($product[$key] ?? $default));
$mainOld    = has_old() && old('form') !== 'branch_prices'; // old input of the product form, not the Branch prices card
$isActive   = $mainOld ? old('is_active') === '1' : (int) ($product['is_active'] ?? 1) === 1;
$tracksSerial = $mainOld ? old('track_serial') === '1' : (int) ($product['track_serial'] ?? 0) === 1;
$canCost    = Auth::can('products.cost'); // unit cost is never rendered without it
$cur        = static fn (string $key): ?int => isset($product[$key]) ? (int) $product[$key] : null;
$categories = MasterData::options('categories', $cur('category_id'));
$brands     = MasterData::options('brands', $cur('brand_id'));
$models     = MasterData::options('models', $cur('model_id'));
$units      = MasterData::options('units', $cur('unit_id'));
$unitValue  = $val('unit_id', (string) ($product ? '' : (Products::defaultUnitId() ?? '')));
$inactive   = static fn (array $o): string => (int) $o['is_active'] === 1 ? '' : ' (inactive)';
$typeLabels = ['initial' => 'Opening stock', 'sale' => 'Sale', 'restock' => 'Restock', 'adjustment' => 'Adjustment', 'void' => 'Void', 'receiving' => 'Receiving',
               'transfer' => 'Transfer', 'issue' => 'Internal use', 'write_off' => 'Write-off', 'count' => 'Stock count'];
$canDocs    = Auth::canAny(...InventoryDocs::VIEW_PERMISSIONS);
// Branch prices: every active branch (view); inputs only for branches the user may price.
$branchPrices  = $product ? BranchPrices::forProduct($id) : [];
$priceEditable = array_filter(array_column($branchPrices, 'id'), static fn ($bid): bool => BranchPrices::canEdit((int) $bid));

// Stock per storage location (current scope) and the POS location, where adjustments go.
$locStock      = $product ? Products::locations($id) : [];
$stockLocation = null;
if ($product && Branch::isConcrete()) {
    foreach (Warehouses::pickerLocations((int) Branch::current()) as $l) {
        if ($l['is_default']) {
            $stockLocation = $l;
            break;
        }
    }
}
$posQty = 0;
foreach ($locStock as $ls) {
    if ($stockLocation !== null && (int) $ls['location_id'] === $stockLocation['id']) {
        $posQty = (int) $ls['qty'];
    }
}
$kindBadge = ['damaged' => 'badge--danger', 'display' => 'badge--warning'];
// Serial registration: an untracked item that already has stock (products.manage; Serials::register checks again).
$canRegister = $product !== null && $canManage && (int) $product['track_serial'] === 0
    && (int) $product['total_stock'] > 0 && (int) $product['serial_count'] === 0;

// Serial tracking can only change while the product has no stock in any branch (Products::update).
$serialLocked = $product !== null && (int) $product['total_stock'] > 0;
$isSerial     = (int) ($product['track_serial'] ?? 0) === 1;
$stockReturn = $self;
$pageStyles  = ['css/stock-docs.css'];
$pageScripts = ['js/inventory.js'];

require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <a class="back-link" href="<?= e(url('pages/' . $returnTo)) ?>"><?= icon('arrow-left') ?> Inventory</a>
        <h1><?= e($product ? $product['name'] : 'Add Product') ?></h1>
        <?php if ($product): ?>
            <p class="muted"><?= e($product['code']) ?> · <?= e($product['category_name']) ?></p>
        <?php endif; ?>
    </div>
</div>

<form class="form-layout" method="post" enctype="multipart/form-data" novalidate
      action="<?= e(url('pages/' . $self)) ?>">
    <?= Csrf::field() ?>
    <input type="hidden" name="return" value="<?= e($returnTo) ?>">
    <input type="hidden" name="MAX_FILE_SIZE" value="<?= ImageUpload::MAX_BYTES ?>">

    <section class="card card--pad form-main">
        <h2 class="card__title">Product details</h2>
        <div class="form-grid">
            <label class="form-field form-field--full">
                <span class="form-label">Name *</span>
                <input class="form-input" name="name" maxlength="100" required value="<?= e($val('name')) ?>"<?= invalid('name') ?><?= $ro ?>>
                <?= field_error('name') ?>
            </label>

            <label class="form-field">
                <span class="form-label">Category *</span>
                <select class="form-input" name="category_id" required<?= invalid('category_id') ?><?= $ro ?>>
                    <option value="">Choose…</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= (int) $cat['id'] ?>"<?= $val('category_id') === (string) $cat['id'] ? ' selected' : '' ?>><?= e($cat['name'] . $inactive($cat)) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= field_error('category_id') ?>
            </label>

            <label class="form-field">
                <span class="form-label">Suggested price (<?= e(config('app.currency')) ?>) *</span>
                <input class="form-input" name="price" inputmode="decimal" maxlength="10" required placeholder="0.00"
                       value="<?= e($val('price')) ?>"<?= invalid('price') ?><?= $ro ?>>
                <?= field_error('price') ?>
                <p class="form-hint">Company price: every branch sells at it unless the branch has its own price (Branch prices).</p>
            </label>

            <?php if ($canCost): ?>
                <label class="form-field">
                    <span class="form-label">Default cost (<?= e(config('app.currency')) ?>)</span>
                    <input class="form-input" name="unit_cost" inputmode="decimal" maxlength="10" placeholder="0.00"
                           value="<?= e($val('unit_cost', '0.00')) ?>"<?= invalid('unit_cost') ?><?= $ro ?>>
                    <?= field_error('unit_cost') ?>
                    <p class="form-hint">Starting cost for opening stock and a branch's first average cost; Receiving sets the branch average. Not shown on the POS or receipts.</p>
                </label>
            <?php endif; ?>

            <label class="form-field">
                <span class="form-label">Product code *</span>
                <input class="form-input form-input--mono" name="code" maxlength="20" required placeholder="ITM-0013"
                       autocapitalize="characters" value="<?= e($val('code')) ?>"<?= invalid('code') ?><?= $ro ?>>
                <?= field_error('code') ?>
            </label>

            <label class="form-field">
                <span class="form-label">Barcode</span>
                <input class="form-input form-input--mono" name="barcode" maxlength="50" placeholder="Scan or type"
                       value="<?= e($val('barcode')) ?>"<?= invalid('barcode') ?><?= $ro ?>>
                <?= field_error('barcode') ?>
            </label>

            <label class="form-field">
                <span class="form-label">Brand</span>
                <select class="form-input" name="brand_id" id="productBrand"<?= invalid('brand_id') ?><?= $ro ?>>
                    <option value="">No brand</option>
                    <?php foreach ($brands as $b): ?>
                        <option value="<?= (int) $b['id'] ?>"<?= $val('brand_id') === (string) $b['id'] ? ' selected' : '' ?>><?= e($b['name'] . $inactive($b)) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= field_error('brand_id') ?>
            </label>

            <label class="form-field">
                <span class="form-label">Model</span>
                <select class="form-input" name="model_id" id="productModel" data-filter-by="productBrand"<?= $ro !== '' ? ' data-locked' : '' ?><?= invalid('model_id') ?><?= $ro ?>>
                    <option value="">No model</option>
                    <?php foreach ($models as $m): ?>
                        <option value="<?= (int) $m['id'] ?>" data-parent="<?= (int) $m['brand_id'] ?>"<?= $val('model_id') === (string) $m['id'] ? ' selected' : '' ?>><?= e($m['name'] . $inactive($m)) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= field_error('model_id') ?>
            </label>

            <label class="form-field">
                <span class="form-label">Unit *</span>
                <select class="form-input" name="unit_id" id="productUnit" required<?= invalid('unit_id') ?><?= $ro ?>>
                    <option value="">Choose…</option>
                    <?php foreach ($units as $u): ?>
                        <option value="<?= (int) $u['id'] ?>"<?= $unitValue === (string) $u['id'] ? ' selected' : '' ?>><?= e($u['code'] . ' · ' . $u['name'] . $inactive($u)) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= field_error('unit_id') ?>
            </label>

            <label class="form-field">
                <span class="form-label">Warranty (days)</span>
                <input class="form-input" type="number" name="warranty_days" min="0" max="<?= Products::MAX_WARRANTY_DAYS ?>" step="1"
                       value="<?= e($val('warranty_days', '0')) ?>"<?= invalid('warranty_days') ?><?= $ro ?>>
                <?= field_error('warranty_days') ?>
                <p class="form-hint">0 = no warranty · 365 = 1 year</p>
            </label>

            <label class="form-field form-field--full">
                <span class="form-label">Description</span>
                <textarea class="form-input" name="description" rows="3" maxlength="255"<?= invalid('description') ?><?= $ro ?>><?= e($val('description')) ?></textarea>
                <?= field_error('description') ?>
            </label>

            <label class="form-field form-field--full">
                <span class="form-label">Specifications</span>
                <textarea class="form-input" name="specs" rows="3" maxlength="500" placeholder="e.g. Core i5, 8GB RAM, 512GB SSD, 14-inch FHD"<?= invalid('specs') ?><?= $ro ?>><?= e($val('specs')) ?></textarea>
                <?= field_error('specs') ?>
            </label>

            <label class="check form-field--full">
                <input type="checkbox" name="track_serial" value="1" id="trackSerial"<?= $tracksSerial ? ' checked' : '' ?><?= $serialLocked ? ' disabled' : $ro ?>>
                <span><strong>Track serial numbers</strong><small class="muted block">For items with a serial number per unit (laptops, printers, routers).
                    Their stock comes in through Receiving with one serial per unit.</small></span>
            </label>
            <?php if ($serialLocked): ?>
                <?php if ($isSerial): ?><input type="hidden" name="track_serial" value="1"><?php endif; ?>
                <p class="form-hint form-field--full"><?= icon('lock') ?> Serial tracking can only change when the product has no stock in any branch.</p>
            <?php endif; ?>
        </div>
    </section>

    <div class="form-side">
        <section class="card card--pad">
            <h2 class="card__title">Image</h2>
            <div class="image-drop">
                <div class="image-preview" id="imagePreview"<?= $imageUrl ? '' : ' data-empty' ?>>
                    <img id="imagePreviewImg" alt=""<?= $imageUrl ? ' src="' . e($imageUrl) . '"' : ' hidden' ?>>
                    <span class="image-preview__empty"<?= $imageUrl ? ' hidden' : '' ?>><?= icon('image') ?> No image</span>
                </div>
                <label class="btn btn--light btn--block file-btn">
                    <?= icon('image') ?> <span id="imageLabel"><?= $imageUrl ? 'Replace image' : 'Choose image' ?></span>
                    <input type="file" name="image" id="imageInput" accept="image/jpeg,image/png,image/webp"<?= invalid('image') ?><?= $ro ?>>
                </label>
                <p class="form-hint">JPG, PNG or WebP · max 2 MB · square looks best</p>
                <?= field_error('image') ?>
                <?php if ($imageUrl): ?>
                    <label class="check">
                        <input type="checkbox" name="remove_image" value="1" id="removeImage"<?= $ro ?>> Remove current image
                    </label>
                <?php endif; ?>
            </div>
        </section>

        <section class="card card--pad">
            <h2 class="card__title">Stock</h2>
            <div class="form-grid">
                <?php if ($product): ?>
                    <div class="form-field">
                        <span class="form-label">In stock</span>
                        <div class="stock-now">
                            <strong><?= (int) $product['stock'] ?></strong>
                            <?php if ($canAdjust && $stockLocation !== null && !$isSerial): ?>
                                <button type="button" class="btn btn--light btn--sm" data-adjust
                                        data-id="<?= (int) $product['id'] ?>" data-name="<?= e($product['name']) ?>"
                                        data-code="<?= e($product['code']) ?>" data-stock="<?= $posQty ?>">
                                    <?= icon('stock') ?> Adjust
                                </button>
                            <?php endif; ?>
                        </div>
                        <p class="form-hint">At <?= e(Branch::label()) ?><?= Branch::isConcrete() && Branch::canSeeAll() ? ' · company total ' . (int) $product['total_stock'] : '' ?></p>
                        <?php if ($isSerial): ?><p class="form-hint">Serial-tracked: stock changes through Receiving, sales and stock operations.</p><?php endif; ?>
                        <?php if ($canRegister): ?>
                            <a class="btn btn--light btn--sm reg-btn" id="registerSerialsBtn"
                               href="<?= e(url('pages/serial-register.php?' . http_build_query(['id' => (int) $product['id'], 'return' => $self]))) ?>"><?= icon('barcode') ?> Register Serials</a>
                            <p class="form-hint">Start tracking serial numbers for the units already in stock.</p>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <label class="form-field" id="openingStockField">
                        <span class="form-label">Opening stock at <?= e(Branch::label()) ?> *</span>
                        <input class="form-input" type="number" name="stock" id="openingStock" min="0" max="<?= Products::MAX_STOCK ?>" step="1"
                               value="<?= e($tracksSerial ? '0' : old('stock', '0')) ?>"<?= invalid('stock') ?><?= $tracksSerial ? ' readonly' : '' ?>>
                        <?= field_error('stock') ?>
                        <p class="form-hint" id="openingStockHint"<?= $tracksSerial ? '' : ' hidden' ?>>Serial-tracked items start at 0: receive them through Receiving with their serial numbers.</p>
                    </label>
                <?php endif; ?>
                <label class="form-field">
                    <span class="form-label">Low-stock alert at</span>
                    <input class="form-input" type="number" name="reorder_level" min="0" max="9999" step="1"
                           value="<?= e($val('reorder_level', '5')) ?>"<?= invalid('reorder_level') ?><?= $ro ?>>
                    <?= field_error('reorder_level') ?>
                </label>
                <?php if ($product): ?>
                    <div class="form-field form-field--full" id="stockByLocation">
                        <span class="form-label">Stock by location</span>
                        <?php if ($locStock): ?>
                            <table class="loc-stock">
                                <tbody>
                                <?php foreach ($locStock as $ls): ?>
                                    <tr>
                                        <td>
                                            <?php if (Branch::current() === Branch::ALL): ?><span class="badge badge--branch" title="<?= e($ls['branch_name']) ?>"><?= e($ls['branch_code']) ?></span><?php endif; ?>
                                            <span class="loc-code"><?= e($ls['warehouse_code'] . ' / ' . $ls['location_code']) ?></span>
                                            <?php if ((int) $ls['is_pos_location'] === 1): ?><span class="badge badge--success">POS</span><?php endif; ?>
                                            <?php if (isset($kindBadge[$ls['kind']])): ?><span class="badge <?= e($kindBadge[$ls['kind']]) ?>"><?= e(Warehouses::KINDS[$ls['kind']]) ?></span><?php endif; ?>
                                            <?php if ((int) $ls['is_active'] !== 1): ?><span class="badge">Inactive</span><?php endif; ?>
                                            <small class="muted block"><?= e($ls['location_name']) ?></small>
                                        </td>
                                        <td class="num"><?= number_format((int) $ls['qty']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                            <p class="form-hint">The POS sells only from the POS location; damaged and display units can't be sold.</p>
                        <?php else: ?>
                            <p class="form-hint">No stock at <?= e(Branch::label()) ?>.</p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="card card--pad">
            <label class="check check--switch">
                <input type="checkbox" name="is_active" value="1"<?= $isActive ? ' checked' : '' ?><?= $ro ?>>
                <span><strong>Active</strong><small class="muted block">Shown on the POS and can be sold</small></span>
            </label>
        </section>
    </div>

    <div class="form-actions">
        <a class="btn btn--light" href="<?= e(url('pages/' . $returnTo)) ?>"><?= $canManage ? 'Cancel' : 'Back' ?></a>
        <?php if ($canManage): ?>
            <button type="submit" class="btn btn--primary"><?= icon('save') ?> <?= $product ? 'Save Changes' : 'Add Product' ?></button>
        <?php endif; ?>
    </div>
</form>

<?php if ($product): ?>
    <section class="card card--pad branch-prices-card" id="branchPrices">
        <h2 class="card__title"><?= icon('tag') ?> Branch prices</h2>
        <p class="muted">Leave a branch blank to sell at the company price (<?= e(money($product['price'])) ?>). Past sales keep their price.</p>
        <form method="post" action="<?= e(url('pages/' . $self)) ?>" novalidate>
            <?= Csrf::field() ?>
            <input type="hidden" name="form" value="branch_prices">
            <input type="hidden" name="return" value="<?= e($returnTo) ?>">
            <div class="table-wrap">
                <table class="table bp-table">
                    <thead><tr><th>Branch</th><th class="num">Selling price</th><th class="col-opt">Last change</th></tr></thead>
                    <tbody>
                    <?php foreach ($branchPrices as $bp): ?>
                        <?php $bid = (int) $bp['id']; $key = 'branch_price_' . $bid; ?>
                        <tr>
                            <td><span class="badge badge--branch"><?= e($bp['code']) ?></span> <?= e($bp['name']) ?></td>
                            <td class="num">
                                <?php if (in_array($bid, $priceEditable, true)): ?>
                                    <input type="hidden" name="orig_price_<?= $bid ?>" value="<?= e($bp['price'] !== null ? number_format((float) $bp['price'], 2, '.', '') : '') ?>">
                                    <input class="form-input bp-input" name="<?= e($key) ?>" inputmode="decimal" maxlength="12"
                                           placeholder="<?= e(number_format((float) $product['price'], 2)) ?>" aria-label="Price at <?= e($bp['name']) ?>"
                                           value="<?= e(old($key, $bp['price'] !== null ? number_format((float) $bp['price'], 2, '.', '') : '')) ?>"<?= invalid($key) ?>>
                                    <?= field_error($key) ?>
                                <?php elseif ($bp['price'] !== null): ?>
                                    <strong><?= e(money($bp['price'])) ?></strong>
                                <?php else: ?>
                                    <span class="muted"><?= e(money($product['price'])) ?> (company)</span>
                                <?php endif; ?>
                            </td>
                            <td class="col-opt muted"><?= $bp['updated_at'] !== null ? e(date('M j, Y', strtotime($bp['updated_at'])) . ($bp['updated_by_name'] ? ' · ' . $bp['updated_by_name'] : '')) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($priceEditable): ?>
                <div class="form-actions"><button type="submit" class="btn btn--primary" id="saveBranchPrices"><?= icon('save') ?> Save Branch Prices</button></div>
            <?php endif; ?>
        </form>
    </section>

    <section class="card history-card">
        <header class="card__head"><h2><?= icon('clock') ?> Stock History</h2><span class="muted">Last <?= count($movements) ?> changes</span></header>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Date</th><th>Type</th><th class="num">Change</th><th class="num">Stock after</th><th>Note</th><th>By</th><th>Branch</th></tr></thead>
                <tbody>
                <?php foreach ($movements as $m): ?>
                    <tr>
                        <td><?= e(date('M j, Y g:i A', strtotime($m['created_at']))) ?></td>
                        <td><span class="badge"><?= e($typeLabels[$m['type']] ?? $m['type']) ?></span><?php if ($m['location_code'] !== null): ?><small class="muted block loc-code"><?= e($m['warehouse_code'] . ' / ' . $m['location_code']) ?></small><?php endif; ?></td>
                        <td class="num <?= (int) $m['quantity'] < 0 ? 'text-danger' : 'text-success' ?>"><?= (int) $m['quantity'] > 0 ? '+' : '' ?><?= (int) $m['quantity'] ?></td>
                        <td class="num"<?= Branch::canSeeAll() ? ' title="Company total after: ' . (int) $m['stock_after'] . '"' : '' ?>><?= $m['location_qty_after'] !== null ? (int) $m['location_qty_after'] : (Branch::canSeeAll() ? (int) $m['stock_after'] : '—') ?></td>
                        <td>
                            <?php if ($m['sale_no'] && Auth::can('sales.view')): ?>
                                <a href="<?= e(url('pages/sale-view.php?id=' . (int) $m['sale_id'])) ?>"><?= e($m['type'] === 'void' ? ($m['note'] ?? 'Voided sale No. ' . $m['sale_no']) : 'Sale No. ' . $m['sale_no']) ?></a>
                            <?php elseif ($m['rr_no'] !== null && Auth::can('receiving.view')): ?>
                                <a href="<?= e(url('pages/receiving-view.php?id=' . (int) $m['receiving_id'])) ?>"><?= e($m['note'] !== null && $m['note'] !== $m['rr_no'] ? $m['note'] : $m['rr_no']) ?></a>
                            <?php elseif ($m['doc_no'] !== null && $canDocs): ?>
                                <a href="<?= e(url('pages/stock-doc-view.php?id=' . (int) $m['inventory_doc_id'])) ?>"><?= e($m['note'] ?? $m['doc_no']) ?></a>
                            <?php elseif ($m['sale_no']): ?>
                                <?= e($m['type'] === 'void' ? ($m['note'] ?? 'Voided sale No. ' . $m['sale_no']) : 'Sale No. ' . $m['sale_no']) ?>
                            <?php else: ?>
                                <?= e($m['note'] ?? '') ?>
                            <?php endif; ?>
                        </td>
                        <td class="muted"><?= e($m['username'] ?? '—') ?></td>
                        <td><span class="badge" title="<?= e($m['branch_name'] ?? '') ?>"><?= e($m['branch_code'] ?? '') ?></span></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$movements): ?>
                    <tr><td colspan="7" class="empty">No stock changes yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php if ($canAdjust): ?>
        <?php require ROOT_PATH . '/includes/stock-dialog.php'; ?>
    <?php endif; ?>
<?php endif; ?>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
