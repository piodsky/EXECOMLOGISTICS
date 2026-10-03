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
        flash_old(array_filter($_POST, 'is_string') + ['is_active' => isset($_POST['is_active']) ? '1' : '0']);
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
$categories = Products::categories();
$movements  = $product ? Products::movements($id, 15) : [];
$imageUrl   = ImageUpload::url($product['image'] ?? null);
$val = static fn (string $key, mixed $default = ''): string => old($key, (string) ($product[$key] ?? $default));
$isActive   = has_old() ? old('is_active') === '1' : (int) ($product['is_active'] ?? 1) === 1;
$typeLabels = ['initial' => 'Opening stock', 'sale' => 'Sale', 'restock' => 'Restock', 'adjustment' => 'Adjustment', 'void' => 'Void'];

$stockReturn = $self;
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
                        <option value="<?= (int) $cat['id'] ?>"<?= $val('category_id') === (string) $cat['id'] ? ' selected' : '' ?>><?= e($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= field_error('category_id') ?>
            </label>

            <label class="form-field">
                <span class="form-label">Price (<?= e(config('app.currency')) ?>) *</span>
                <input class="form-input" name="price" inputmode="decimal" maxlength="10" required placeholder="0.00"
                       value="<?= e($val('price')) ?>"<?= invalid('price') ?><?= $ro ?>>
                <?= field_error('price') ?>
            </label>

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

            <label class="form-field form-field--full">
                <span class="form-label">Description</span>
                <textarea class="form-input" name="description" rows="3" maxlength="255"<?= invalid('description') ?><?= $ro ?>><?= e($val('description')) ?></textarea>
                <?= field_error('description') ?>
            </label>
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
                            <?php if ($canAdjust): ?>
                                <button type="button" class="btn btn--light btn--sm" data-adjust
                                        data-id="<?= (int) $product['id'] ?>" data-name="<?= e($product['name']) ?>"
                                        data-code="<?= e($product['code']) ?>" data-stock="<?= (int) $product['stock'] ?>">
                                    <?= icon('stock') ?> Adjust
                                </button>
                            <?php endif; ?>
                        </div>
                        <p class="form-hint">At <?= e(Branch::label()) ?><?= Branch::isConcrete() && Branch::canSeeAll() ? ' · company total ' . (int) $product['total_stock'] : '' ?></p>
                    </div>
                <?php else: ?>
                    <label class="form-field">
                        <span class="form-label">Opening stock at <?= e(Branch::label()) ?> *</span>
                        <input class="form-input" type="number" name="stock" min="0" max="<?= Products::MAX_STOCK ?>" step="1"
                               value="<?= e(old('stock', '0')) ?>"<?= invalid('stock') ?>>
                        <?= field_error('stock') ?>
                    </label>
                <?php endif; ?>
                <label class="form-field">
                    <span class="form-label">Low-stock alert at</span>
                    <input class="form-input" type="number" name="reorder_level" min="0" max="9999" step="1"
                           value="<?= e($val('reorder_level', '5')) ?>"<?= invalid('reorder_level') ?><?= $ro ?>>
                    <?= field_error('reorder_level') ?>
                </label>
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
    <section class="card history-card">
        <header class="card__head"><h2><?= icon('clock') ?> Stock History</h2><span class="muted">Last <?= count($movements) ?> changes</span></header>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Date</th><th>Type</th><th class="num">Change</th><th class="num">Stock after</th><th>Note</th><th>By</th><th>Branch</th></tr></thead>
                <tbody>
                <?php foreach ($movements as $m): ?>
                    <tr>
                        <td><?= e(date('M j, Y g:i A', strtotime($m['created_at']))) ?></td>
                        <td><span class="badge"><?= e($typeLabels[$m['type']] ?? $m['type']) ?></span></td>
                        <td class="num <?= (int) $m['quantity'] < 0 ? 'text-danger' : 'text-success' ?>"><?= (int) $m['quantity'] > 0 ? '+' : '' ?><?= (int) $m['quantity'] ?></td>
                        <td class="num"<?= Branch::canSeeAll() ? ' title="Company total after: ' . (int) $m['stock_after'] . '"' : '' ?>><?= $m['location_qty_after'] !== null ? (int) $m['location_qty_after'] : (Branch::canSeeAll() ? (int) $m['stock_after'] : '—') ?></td>
                        <td>
                            <?php if ($m['sale_no'] && Auth::can('sales.view')): ?>
                                <a href="<?= e(url('pages/sale-view.php?id=' . (int) $m['sale_id'])) ?>"><?= e($m['type'] === 'void' ? ($m['note'] ?? 'Voided sale No. ' . $m['sale_no']) : 'Sale No. ' . $m['sale_no']) ?></a>
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
