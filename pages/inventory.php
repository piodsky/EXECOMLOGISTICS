<?php
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('inventory');

// ---------------------------------------------------------------------
// Row actions (POST → redirect back to the same list view)
// ---------------------------------------------------------------------
if (is_post()) {
    Csrf::verifyRequest();
    $back = safe_return($_POST['return'] ?? null, 'inventory.php');
    $id   = input_int($_POST, 'id', 1) ?? 0;

    try {
        switch (input_string($_POST, 'action', 20)) {
            case 'toggle':
                $p = Products::toggleActive($id);
                flash('success', $p['is_active']
                    ? "{$p['name']} is active again and shows on the POS."
                    : "{$p['name']} was deactivated and is hidden from the POS.");
                break;

            case 'delete':
                $p = Products::delete($id);
                flash('success', "{$p['name']} was deleted.");
                break;

            case 'adjust':
                $direction = $_POST['direction'] ?? '';
                if (!in_array($direction, ['add', 'remove'], true)) {
                    throw new HttpException(422, 'Choose whether to add or remove stock.');
                }
                $qty = input_int($_POST, 'quantity', 1, Products::MAX_STOCK)
                    ?? throw new HttpException(422, 'Enter a quantity from 1 to ' . number_format(Products::MAX_STOCK) . '.');
                $change = $direction === 'add' ? $qty : -$qty;
                [$name, $new, $branchName] = Products::adjustStock(
                    $id, $change, input_string($_POST, 'reason', 20), input_string($_POST, 'note', 200), (int) Auth::id()
                );
                flash('success', sprintf('%s: %s%d. Stock at %s is now %d.', $name, $change > 0 ? '+' : '−', $qty, $branchName, $new));
                break;

            default:
                throw new HttpException(400, 'Unknown action.');
        }
    } catch (HttpException $e) {
        flash('error', $e->getMessage());
    }
    redirect('pages/' . $back);
}

// ---------------------------------------------------------------------
// List
// ---------------------------------------------------------------------
$statuses = ['all' => 'All products', 'active' => 'Active', 'inactive' => 'Inactive', 'low' => 'Low stock', 'out' => 'Out of stock'];
$filters = [
    'q'        => input_string($_GET, 'search', 100),
    'category' => input_int($_GET, 'category', 1),
    'brand'    => input_int($_GET, 'brand', 1),
    'status'   => is_string($_GET['status'] ?? null) && array_key_exists($_GET['status'], $statuses) ? $_GET['status'] : 'all',
];
$pgQuery = array_filter([
    'search'   => $filters['q'],
    'category' => $filters['category'],
    'brand'    => $filters['brand'],
    'status'   => $filters['status'] !== 'all' ? $filters['status'] : null,
], static fn ($v) => $v !== '' && $v !== null);

$pg         = paginate(Products::count($filters), 15);
$products   = Products::search($filters, $pg['per_page'], $pg['offset']);
$summary    = Products::summary();
$categories = Products::categories();
$brands     = MasterData::options('brands', $filters['brand']);
$canCost    = Auth::can('products.cost'); // unit cost column only with products.cost

$canManage   = Auth::can('products.manage');
$canAdjust   = Auth::can('inventory.adjust') && Branch::isConcrete(); // adjustments go to one branch
$returnTo    = 'inventory.php' . (($pgQuery || $pg['page'] > 1) ? '?' . http_build_query($pgQuery + ['page' => $pg['page']]) : '');
$stockReturn = $returnTo;
$pgPath      = 'pages/inventory.php';
$pageScripts = ['js/inventory.js'];

require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Inventory</h1>
        <p class="muted">Products, prices, stock levels and images · stock at <strong id="stockScope"><?= e(Branch::label()) ?></strong>.</p>
    </div>
    <div class="page-actions">
        <?php if (Auth::can('inventory.integrity')): ?>
            <a class="btn btn--light" href="<?= e(url('pages/stock-integrity.php')) ?>" id="integrityLink"><?= icon('shield') ?> Stock Integrity</a>
        <?php endif; ?>
        <?php if ($canManage): ?>
            <a class="btn btn--primary" href="<?= e(url('pages/product-form.php?return=' . rawurlencode($returnTo))) ?>">
                <?= icon('plus') ?> Add Product
            </a>
        <?php endif; ?>
    </div>
</div>
<?php if (Auth::can('inventory.adjust') && !Branch::isConcrete()): ?>
    <div class="alert alert--info" role="status">
        <?= icon('info') ?>
        <span>Showing the total stock of all branches. Choose a branch in the top bar to adjust its stock.</span>
    </div>
<?php endif; ?>

<section class="stats" aria-label="Inventory summary">
    <a class="stat stat--link" href="<?= e(url('pages/inventory.php?status=active')) ?>">
        <span class="stat__icon"><?= icon('box') ?></span>
        <div><p class="stat__label">Active Products</p><p class="stat__value"><?= (int) $summary['items'] ?></p></div>
    </a>
    <div class="stat">
        <span class="stat__icon"><?= icon('wallet') ?></span>
        <div><p class="stat__label">Stock Value</p><p class="stat__value"><?= e(money($summary['stock_value'])) ?></p></div>
    </div>
    <a class="stat stat--link<?= (int) $summary['low'] > 0 ? ' stat--warn' : '' ?>" href="<?= e(url('pages/inventory.php?status=low')) ?>">
        <span class="stat__icon"><?= icon('alert') ?></span>
        <div><p class="stat__label">Low Stock</p><p class="stat__value"><?= (int) $summary['low'] ?></p></div>
    </a>
    <a class="stat stat--link<?= (int) $summary['out_of_stock'] > 0 ? ' stat--danger' : '' ?>" href="<?= e(url('pages/inventory.php?status=out')) ?>">
        <span class="stat__icon"><?= icon('x') ?></span>
        <div><p class="stat__label">Out of Stock</p><p class="stat__value"><?= (int) $summary['out_of_stock'] ?></p></div>
    </a>
</section>

<section class="card">
    <form class="toolbar" method="get" action="<?= e(url('pages/inventory.php')) ?>" role="search">
        <label class="toolbar__search">
            <?= icon('search') ?>
            <input class="form-input" type="search" name="search" maxlength="100" placeholder="Name, code, barcode, brand or model"
                   value="<?= e($filters['q']) ?>" aria-label="Search products">
        </label>
        <select class="form-input" name="category" aria-label="Category">
            <option value="">All categories</option>
            <?php foreach ($categories as $cat): ?>
                <option value="<?= (int) $cat['id'] ?>"<?= $filters['category'] === (int) $cat['id'] ? ' selected' : '' ?>><?= e($cat['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select class="form-input" name="brand" aria-label="Brand">
            <option value="">All brands</option>
            <?php foreach ($brands as $b): ?>
                <option value="<?= (int) $b['id'] ?>"<?= $filters['brand'] === (int) $b['id'] ? ' selected' : '' ?>><?= e($b['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select class="form-input" name="status" aria-label="Status">
            <?php foreach ($statuses as $value => $label): ?>
                <option value="<?= e($value) ?>"<?= $filters['status'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn--primary">Filter</button>
        <?php if ($pgQuery): ?>
            <a class="btn btn--light" href="<?= e(url('pages/inventory.php')) ?>">Reset</a>
        <?php endif; ?>
    </form>

    <div class="table-wrap">
        <table class="table table--list inventory-table" id="inventoryTable">
            <thead>
            <tr>
                <th>Product</th>
                <th>Category</th>
                <th class="col-opt">Brand</th>
                <th class="num">Price</th>
                <?php if ($canCost): ?><th class="num col-opt">Unit cost</th><?php endif; ?>
                <th class="num">Stock</th>
                <th class="col-opt">Unit</th>
                <th>Status</th>
                <th class="actions-col">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($products as $p): ?>
                <?php
                $active     = (int) $p['is_active'] === 1;
                $stock      = (int) $p['stock'];
                $stockClass = $stock === 0 ? 'badge--danger' : ($stock <= (int) $p['reorder_level'] ? 'badge--warning' : 'badge--success');
                $imageUrl   = ImageUpload::url($p['image']);
                $editUrl    = url('pages/product-form.php?id=' . (int) $p['id'] . '&return=' . rawurlencode($returnTo));
                ?>
                <tr class="<?= $active ? '' : 'is-inactive' ?>">
                    <td>
                        <div class="item-cell">
                            <span class="thumb">
                                <?php if ($imageUrl): ?>
                                    <img src="<?= e($imageUrl) ?>" alt="" loading="lazy">
                                <?php else: ?>
                                    <?= icon('image') ?>
                                <?php endif; ?>
                            </span>
                            <span>
                                <a class="item-cell__name" href="<?= e($editUrl) ?>"><?= e($p['name']) ?></a>
                                <small class="muted block"><?= e($p['code']) ?><?= $p['barcode'] ? ' · ' . e($p['barcode']) : '' ?></small>
                            </span>
                        </div>
                    </td>
                    <td><?= e($p['category_name']) ?></td>
                    <td class="col-opt"><?= e($p['brand_name'] ?? '—') ?><?php if ($p['model_name']): ?><span class="cell-sub"><?= e($p['model_name']) ?></span><?php endif; ?></td>
                    <td class="num"><?= e(money($p['price'])) ?></td>
                    <?php if ($canCost): ?><td class="num col-opt"><?= e(money($p['unit_cost'])) ?></td><?php endif; ?>
                    <td class="num">
                        <span class="badge <?= $stockClass ?>"><?= $stock === 0 ? 'Out' : $stock ?></span>
                        <small class="muted block">min <?= (int) $p['reorder_level'] ?></small>
                    </td>
                    <td class="col-opt"><span title="<?= e($p['unit_name'] ?? '') ?>"><?= e($p['unit_code'] ?? '—') ?></span></td>
                    <td>
                        <span class="badge<?= $active ? ' badge--success' : '' ?>"><?= $active ? 'Active' : 'Inactive' ?></span>
                    </td>
                    <td class="actions-col">
                        <div class="row-actions">
                            <?php if ($canAdjust && (int) $p['track_serial'] !== 1): ?>
                                <button type="button" class="icon-btn" title="Adjust stock" aria-label="Adjust stock of <?= e($p['name']) ?>"
                                        data-adjust data-id="<?= (int) $p['id'] ?>" data-name="<?= e($p['name']) ?>"
                                        data-code="<?= e($p['code']) ?>" data-stock="<?= $stock ?>">
                                    <?= icon('stock') ?>
                                </button>
                            <?php endif; ?>
                            <a class="icon-btn" title="<?= $canManage ? 'Edit' : 'View' ?>" aria-label="<?= $canManage ? 'Edit' : 'View' ?> <?= e($p['name']) ?>" href="<?= e($editUrl) ?>">
                                <?= icon($canManage ? 'edit' : 'eye') ?>
                            </a>
                            <?php if ($canManage): ?>
                            <form method="post">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                <input type="hidden" name="return" value="<?= e($returnTo) ?>">
                                <?php $label = $active ? 'Deactivate' : 'Activate'; ?>
                                <button type="submit" class="icon-btn<?= $active ? '' : ' icon-btn--success' ?>"
                                        title="<?= $label ?>" aria-label="<?= $label ?> <?= e($p['name']) ?>">
                                    <?= icon('power') ?>
                                </button>
                            </form>
                            <?php if ((int) $p['times_sold'] === 0): ?>
                                <form method="post" data-confirm="Delete <?= e($p['name']) ?> permanently? This cannot be undone.">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                    <input type="hidden" name="return" value="<?= e($returnTo) ?>">
                                    <button type="submit" class="icon-btn icon-btn--danger-outline" title="Delete" aria-label="Delete <?= e($p['name']) ?>">
                                        <?= icon('trash') ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$products): ?>
                <tr><td colspan="<?= $canCost ? 9 : 8 ?>" class="empty">No products found<?= $pgQuery ? ' for these filters' : '' ?>.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php require ROOT_PATH . '/includes/pagination.php'; ?>
</section>

<?php if ($canAdjust): ?>
    <?php require ROOT_PATH . '/includes/stock-dialog.php'; ?>
<?php endif; ?>
<?php require ROOT_PATH . '/includes/footer.php'; ?>
