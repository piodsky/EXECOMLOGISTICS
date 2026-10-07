<?php
/**
 * Branch Prices: the selling price of each product at the working branch (BranchPrices).
 * Blank = the company price (products.price). Save writes only the rows that changed (one audit record).
 * Needs products.branch_price and a concrete branch the user works in; "All branches" asks to choose one.
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('branch-prices');

$branchId = Branch::isConcrete() ? (int) Branch::current() : null;
$canEdit  = $branchId !== null && BranchPrices::canEdit($branchId);

$filters = [
    'q'        => input_string($_GET, 'search', 100),
    'category' => input_int($_GET, 'category', 1),
    'only'     => ($_GET['only'] ?? '') === '1',
];
$pgQuery = array_filter([
    'search'   => $filters['q'],
    'category' => $filters['category'],
    'only'     => $filters['only'] ? '1' : null,
], static fn ($v) => $v !== '' && $v !== null);

if (is_post()) {
    Csrf::verifyRequest();
    $back = safe_return($_POST['return'] ?? null, 'branch-prices.php');
    if (!$canEdit) {
        abort(403, 'You may not set prices for this branch.');
    }
    // Only the boxes the user changed (orig_N = the value when the page loaded), so a stale page never
    // overwrites a price someone else saved meanwhile.
    $prices = [];
    foreach ($_POST as $k => $v) {
        if (is_string($k) && preg_match('/^price_(\d{1,10})$/', $k, $m)) {
            $v    = is_string($v) ? trim($v) : '';
            $orig = is_string($_POST['orig_' . $m[1]] ?? null) ? trim($_POST['orig_' . $m[1]]) : null;
            if ($orig === null || $v !== $orig) {
                $prices[(int) $m[1]] = $v;
            }
        }
    }
    try {
        $n = BranchPrices::saveForBranch($branchId, $prices);
        flash('success', $n === 0 ? 'Nothing changed.'
            : sprintf('%d %s saved for %s.', $n, $n === 1 ? 'price' : 'prices', Branch::label()));
    } catch (HttpException $e) {
        if ($e->status !== 422) {
            throw $e;
        }
        flash_old(array_filter($_POST, 'is_string'));
        $errors = [];
        foreach ($e->details['errors'] ?? [] as $pid => $msg) {
            $errors['price_' . $pid] = $msg;
        }
        flash_errors($errors);
        flash('error', $e->getMessage());
    }
    redirect('pages/' . $back);
}

$categories = Products::categories();
$rows   = [];
$setCnt = 0;
$pg     = paginate(0, 50);
if ($branchId !== null) {
    $pg     = paginate(BranchPrices::count($branchId, $filters), 50);
    $rows   = BranchPrices::search($branchId, $filters, $pg['per_page'], $pg['offset']);
    $setCnt = BranchPrices::countSet($branchId);
}
$returnTo = 'branch-prices.php' . (($pgQuery || $pg['page'] > 1) ? '?' . http_build_query($pgQuery + ['page' => $pg['page']]) : '');
$pgPath   = 'pages/branch-prices.php';

require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Branch Prices</h1>
        <p class="muted">Selling prices at <strong><?= e(Branch::label()) ?></strong>. Leave a price blank to sell at the company price. Past sales keep their price.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn--light" href="<?= e(url('pages/inventory.php')) ?>"><?= icon('box') ?> Inventory</a>
    </div>
</div>

<?php if ($branchId === null): ?>
    <div class="alert alert--info" role="status">
        <?= icon('info') ?>
        <span>Branch prices are set per branch. Choose a branch in the top bar.</span>
    </div>
<?php else: ?>
    <?php if (!$canEdit): ?>
        <div class="alert alert--info" role="status"><?= icon('info') ?><span>You can view these prices but may not change them at this branch.</span></div>
    <?php endif; ?>

    <section class="card">
        <form class="toolbar" method="get" action="<?= e(url('pages/branch-prices.php')) ?>" role="search">
            <label class="toolbar__search">
                <?= icon('search') ?>
                <input class="form-input" type="search" name="search" maxlength="100" placeholder="Name, code or barcode"
                       value="<?= e($filters['q']) ?>" aria-label="Search products">
            </label>
            <select class="form-input" name="category" aria-label="Category">
                <option value="">All categories</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= (int) $cat['id'] ?>"<?= $filters['category'] === (int) $cat['id'] ? ' selected' : '' ?>><?= e($cat['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <label class="check">
                <input type="checkbox" name="only" value="1"<?= $filters['only'] ? ' checked' : '' ?>>
                <span>Only branch prices (<?= $setCnt ?>)</span>
            </label>
            <button type="submit" class="btn btn--primary">Filter</button>
            <?php if ($pgQuery): ?>
                <a class="btn btn--light" href="<?= e(url('pages/branch-prices.php')) ?>">Reset</a>
            <?php endif; ?>
        </form>

        <form method="post" action="<?= e(url('pages/branch-prices.php')) ?>" novalidate id="branchPricesForm">
            <?= Csrf::field() ?>
            <input type="hidden" name="return" value="<?= e($returnTo) ?>">
            <div class="table-wrap">
                <table class="table table--list bp-table" id="branchPricesTable">
                    <thead>
                    <tr>
                        <th>Product</th>
                        <th class="col-opt">Category</th>
                        <th class="num">Company price</th>
                        <th class="num">Price at <?= e(Branch::label()) ?></th>
                        <th class="num col-opt">Difference</th>
                        <th class="col-opt">Last change</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <?php
                        $key  = 'price_' . (int) $r['id'];
                        $diff = null;
                        if ($r['branch_price'] !== null) {
                            $d    = to_cents((string) $r['branch_price']) - to_cents((string) $r['company_price']);
                            $base = to_cents((string) $r['company_price']);
                            $diff = ($d > 0 ? '+' : ($d < 0 ? '−' : '')) . money(from_cents(abs($d)))
                                  . ($base > 0 && $d !== 0 ? sprintf(' (%s%.1f%%)', $d > 0 ? '+' : '−', abs($d) * 100 / $base) : '');
                        }
                        ?>
                        <tr data-product="<?= (int) $r['id'] ?>">
                            <td>
                                <span class="item-cell__name"><?= e($r['name']) ?></span>
                                <small class="muted block"><?= e($r['code']) ?><?= $r['unit_code'] ? ' · ' . e($r['unit_code']) : '' ?></small>
                            </td>
                            <td class="col-opt"><?= e($r['category_name']) ?></td>
                            <td class="num"><?= e(money($r['company_price'])) ?></td>
                            <td class="num">
                                <?php if ($canEdit): ?>
                                    <input type="hidden" name="orig_<?= (int) $r['id'] ?>" value="<?= e($r['branch_price'] !== null ? number_format((float) $r['branch_price'], 2, '.', '') : '') ?>">
                                    <input class="form-input bp-input" name="<?= e($key) ?>" inputmode="decimal" maxlength="12"
                                           placeholder="<?= e(number_format((float) $r['company_price'], 2)) ?>" aria-label="Price of <?= e($r['name']) ?> at <?= e(Branch::label()) ?>"
                                           value="<?= e(old($key, $r['branch_price'] !== null ? number_format((float) $r['branch_price'], 2, '.', '') : '')) ?>"<?= invalid($key) ?>>
                                    <?= field_error($key) ?>
                                <?php elseif ($r['branch_price'] !== null): ?>
                                    <strong><?= e(money($r['branch_price'])) ?></strong>
                                <?php else: ?>
                                    <span class="muted">Company price</span>
                                <?php endif; ?>
                            </td>
                            <td class="num col-opt<?= $diff !== null && str_starts_with($diff, '−') ? ' text-danger' : '' ?>"><?= $diff !== null ? e($diff) : '—' ?></td>
                            <td class="col-opt muted"><?= $r['updated_at'] !== null ? e(date('M j, Y', strtotime($r['updated_at'])) . ($r['updated_by_name'] ? ' · ' . $r['updated_by_name'] : '')) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?>
                        <tr><td colspan="6" class="empty">No products found<?= $pgQuery ? ' for these filters' : '' ?>.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($canEdit && $rows): ?>
                <div class="form-actions bp-actions">
                    <span class="muted">Only the prices you change are saved.</span>
                    <button type="submit" class="btn btn--primary" id="saveBranchPrices"><?= icon('save') ?> Save Prices</button>
                </div>
            <?php endif; ?>
        </form>

        <?php require ROOT_PATH . '/includes/pagination.php'; ?>
    </section>
<?php endif; ?>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
