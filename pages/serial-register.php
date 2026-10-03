<?php
/**
 * Register serial numbers for an item that already has stock (products.manage): one serial per unit
 * at every location that holds it, then the item tracks serial numbers (Serials::register checks
 * everything again; no stock movement, quantities don't change). Every location holding the item
 * must be in the current branch scope ("All branches" when it has stock in several branches).
 * pages/serial-register.php?id=5&return=product-form.php%3Fid%3D5
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('inventory');
Auth::requirePermission('products.manage');

$id      = input_int($_GET, 'id', 1) ?? throw new HttpException(404, 'Product not found.');
$product = Products::find($id) ?? throw new HttpException(404, 'Product not found.');
$productPage = 'product-form.php?id=' . $id;
$returnTo = safe_return($_POST['return'] ?? $_GET['return'] ?? null, $productPage);
$self     = 'serial-register.php?' . http_build_query(['id' => $id, 'return' => $returnTo]);

if ((int) $product['track_serial'] === 1) {
    flash('info', "{$product['name']} already tracks serial numbers.");
    redirect('pages/' . $returnTo);
}

// ---------------------------------------------------------------------
// Register (PRG)
// ---------------------------------------------------------------------
if (is_post()) {
    Csrf::verifyRequest();
    $serials = is_array($_POST['serials'] ?? null) ? array_filter($_POST['serials'], 'is_string') : [];
    try {
        $count = Serials::register($id, $serials, (int) Auth::id());
    } catch (HttpException $e) {
        if ($e->status === 403) {
            throw $e;
        }
        flash_old(['serials' => $serials]);
        if (is_array($e->details['errors'] ?? null)) {
            flash_errors($e->details['errors']);
        }
        flash('error', $e->getMessage());
        redirect('pages/' . $self);
    }
    flash('success', "{$count} serial " . ($count === 1 ? 'number was' : 'numbers were') . " registered. {$product['name']} now tracks serial numbers.");
    redirect('pages/' . $returnTo);
}

// ---------------------------------------------------------------------
// Form
// ---------------------------------------------------------------------
$locations = Products::locations($id);
$inScope   = array_sum(array_map(static fn (array $l): int => (int) $l['qty'], $locations));
$elsewhere = (int) $product['total_stock'] - $inScope; // units at branches outside the current scope
$old       = has_old() ? old_input() : [];
$oldSerials = is_array($old['serials'] ?? null) ? $old['serials'] : [];
$page['title'] = 'Register Serials';

$pageStyles  = ['css/receiving.css', 'css/stock-docs.css'];
$pageScripts = ['js/inventory.js'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <a class="back-link" href="<?= e(url('pages/' . $returnTo)) ?>"><?= icon('arrow-left') ?> <?= e($product['name']) ?></a>
        <h1>Register Serial Numbers</h1>
        <p class="muted"><?= e($product['code'] . ' · ' . $product['name']) ?> · <?= number_format((int) $product['total_stock']) ?> in stock company-wide</p>
    </div>
</div>

<?php if ($elsewhere > 0): ?>
    <div class="alert alert--warning" role="status" id="registerScopeWarning">
        <?= icon('alert') ?>
        <span><?= number_format($elsewhere) ?> <?= $elsewhere === 1 ? 'unit is' : 'units are' ?> at other branches. Every unit needs a serial number at once:
            <?= Branch::canSeeAll() ? 'switch to All branches in the top bar.' : 'ask a user who can see all branches.' ?></span>
    </div>
<?php endif; ?>

<form class="form-layout" method="post" action="<?= e(url('pages/' . $self)) ?>" novalidate id="registerForm"
      data-confirm="Register these serial numbers? <?= e($product['name']) ?> will track serial numbers from now on: sales and stock operations then need a serial per unit.">
    <?= Csrf::field() ?>
    <input type="hidden" name="return" value="<?= e($returnTo) ?>">

    <section class="card card--pad">
        <h2 class="card__title">Serial numbers per location</h2>
        <p class="muted reg-intro">Scan or type one serial number per line for every unit at each location. The count must match the stock exactly.
            Letters, numbers, dot, dash, slash or underscore; up to 60 characters.</p>
        <div class="reg-list">
            <?php foreach ($locations as $l): ?>
                <?php
                $locId = (int) $l['location_id'];
                $qty   = (int) $l['qty'];
                $key   = 'serials.' . $locId;
                ?>
                <div class="reg-loc" data-reg-location="<?= $locId ?>">
                    <div class="reg-loc__head">
                        <h3>
                            <?php if (Branch::current() === Branch::ALL): ?><span class="badge badge--branch" title="<?= e($l['branch_name']) ?>"><?= e($l['branch_code']) ?></span><?php endif; ?>
                            <span class="loc-code"><?= e($l['warehouse_code'] . ' / ' . $l['location_code']) ?></span> · <?= e($l['location_name']) ?>
                        </h3>
                        <span class="muted"><?= number_format($qty) ?> <?= $qty === 1 ? 'unit' : 'units' ?></span>
                    </div>
                    <textarea class="form-input reg-input" name="serials[<?= $locId ?>]" rows="<?= min(10, max(3, $qty)) ?>" spellcheck="false"
                              autocapitalize="characters" aria-label="Serial numbers at <?= e($l['warehouse_code'] . ' / ' . $l['location_code']) ?>"
                              data-reg-input data-qty="<?= $qty ?>"<?= invalid($key) ?>><?= e(is_string($oldSerials[$locId] ?? null) ? $oldSerials[$locId] : '') ?></textarea>
                    <p class="form-hint reg-count" data-reg-count aria-live="polite"></p>
                    <?= field_error($key) ?>
                </div>
            <?php endforeach; ?>
            <?php if (!$locations): ?>
                <p class="empty-note">No stock of this item at <?= e(Branch::label()) ?>.</p>
            <?php endif; ?>
        </div>
    </section>

    <aside class="form-side">
        <section class="card card--pad">
            <h2 class="card__title">What happens</h2>
            <ul class="reg-notes">
                <li>Each unit gets its serial number at its current location.</li>
                <li>The item switches to serial tracking; stock quantities don't change.</li>
                <li>Sales, transfers and write-offs then pick serial numbers.</li>
            </ul>
        </section>
    </aside>

    <div class="form-actions">
        <a class="btn btn--light" href="<?= e(url('pages/' . $returnTo)) ?>">Cancel</a>
        <button type="submit" class="btn btn--primary" id="registerBtn"<?= $locations && $elsewhere <= 0 ? '' : ' disabled' ?>><?= icon('check') ?> Register Serials</button>
    </div>
</form>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
