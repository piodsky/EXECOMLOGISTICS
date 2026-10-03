<?php
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('master-data');
Auth::requirePermission('suppliers.view');

$canManage = Auth::can('suppliers.manage');
$id        = input_int($_GET, 'id', 1);
$supplier  = null;
if ($id !== null) {
    $supplier = Suppliers::find($id) ?? throw new HttpException(404, 'Supplier not found.');
} elseif (!$canManage) {
    abort(403, 'You do not have permission to add suppliers.');
}
$ro = $canManage ? '' : ' disabled'; // read-only view for suppliers.view only
$page['title'] = $supplier ? $supplier['name'] : 'Add Supplier';
$returnTo = safe_return($_POST['return'] ?? $_GET['return'] ?? null, 'suppliers.php');
$self     = 'supplier-form.php?' . http_build_query(array_filter(['id' => $id, 'return' => $returnTo]));

// ---------------------------------------------------------------------
// Save (PRG)
// ---------------------------------------------------------------------
if (is_post()) {
    Csrf::verifyRequest();
    if (!$canManage) {
        abort(403, 'You do not have permission to manage suppliers.');
    }
    [$data, $contacts, $errors] = Suppliers::validate($_POST, $supplier);
    $keepForm = static fn () => flash_old(array_filter($_POST, 'is_string')
        + ['is_active' => isset($_POST['is_active']) ? '1' : '0'] + Contacts::flatOld($_POST));

    if ($errors) {
        $keepForm();
        flash_errors($errors);
        flash('error', 'Please fix the highlighted fields.');
        redirect('pages/' . $self);
    }
    try {
        Suppliers::save($data, $contacts, $supplier);
    } catch (HttpException $e) {
        $keepForm();
        flash('error', $e->getMessage());
        redirect('pages/' . $self);
    }
    flash('success', $supplier ? "{$data['name']} was updated." : "{$data['name']} was added.");
    redirect('pages/' . $returnTo);
}

// ---------------------------------------------------------------------
// Form
// ---------------------------------------------------------------------
$val         = static fn (string $key): string => old($key, (string) ($supplier[$key] ?? ''));
$isActive    = has_old() ? old('is_active') === '1' : (int) ($supplier['is_active'] ?? 1) === 1;
$contactRows = $canManage ? Contacts::formRows($supplier['contacts'] ?? []) : ($supplier['contacts'] ?? []);
$mdTab       = 'suppliers';

$pageStyles = ['css/settings.css', 'css/master-data.css'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <a class="back-link" href="<?= e(url('pages/' . $returnTo)) ?>"><?= icon('arrow-left') ?> Suppliers</a>
        <h1><?= e($supplier ? $supplier['name'] : 'Add Supplier') ?></h1>
        <?php if ($supplier): ?>
            <p class="muted"><code><?= e($supplier['code']) ?></code><?= (int) $supplier['is_active'] === 1 ? '' : ' · Inactive' ?></p>
        <?php endif; ?>
    </div>
</div>

<form class="form-layout" method="post" action="<?= e(url('pages/' . $self)) ?>" novalidate id="supplierForm">
    <?= Csrf::field() ?>
    <input type="hidden" name="return" value="<?= e($returnTo) ?>">

    <div class="form-stack">
        <section class="card card--pad">
            <h2 class="card__title">Supplier Details</h2>
            <div class="form-grid">
                <label class="form-field">
                    <span class="form-label">Code</span>
                    <input class="form-input form-input--mono" name="code" maxlength="20" autocapitalize="characters"
                           placeholder="<?= e($supplier ? '' : Suppliers::nextCode()) ?>" value="<?= e($val('code')) ?>"<?= invalid('code') ?><?= $ro ?>>
                    <?= field_error('code') ?>
                    <?php if (!$supplier): ?><p class="form-hint">Leave blank to use the next number.</p><?php endif; ?>
                </label>
                <label class="form-field">
                    <span class="form-label">Name *</span>
                    <input class="form-input" name="name" maxlength="120" required value="<?= e($val('name')) ?>"<?= invalid('name') ?><?= $ro ?>>
                    <?= field_error('name') ?>
                </label>
                <label class="form-field">
                    <span class="form-label">TIN</span>
                    <input class="form-input form-input--mono" name="tin" maxlength="20" placeholder="000-000-000-000"
                           value="<?= e($val('tin')) ?>"<?= invalid('tin') ?><?= $ro ?>>
                    <?= field_error('tin') ?>
                </label>
                <label class="form-field">
                    <span class="form-label">Payment terms</span>
                    <input class="form-input" name="payment_terms" maxlength="60" placeholder="e.g. COD, 30 days"
                           value="<?= e($val('payment_terms')) ?>"<?= invalid('payment_terms') ?><?= $ro ?>>
                    <?= field_error('payment_terms') ?>
                </label>
                <label class="form-field">
                    <span class="form-label">Phone</span>
                    <input class="form-input" name="phone" maxlength="30" inputmode="tel" value="<?= e($val('phone')) ?>"<?= invalid('phone') ?><?= $ro ?>>
                    <?= field_error('phone') ?>
                </label>
                <label class="form-field">
                    <span class="form-label">Email</span>
                    <input class="form-input" type="email" name="email" maxlength="120" value="<?= e($val('email')) ?>"<?= invalid('email') ?><?= $ro ?>>
                    <?= field_error('email') ?>
                </label>
                <label class="form-field form-field--full">
                    <span class="form-label">Address</span>
                    <input class="form-input" name="address" maxlength="255" value="<?= e($val('address')) ?>"<?= invalid('address') ?><?= $ro ?>>
                    <?= field_error('address') ?>
                </label>
                <label class="form-field form-field--full">
                    <span class="form-label">Notes</span>
                    <textarea class="form-input" name="notes" rows="2" maxlength="255"<?= invalid('notes') ?><?= $ro ?>><?= e($val('notes')) ?></textarea>
                    <?= field_error('notes') ?>
                </label>
            </div>
        </section>

        <section class="card card--pad">
            <h2 class="card__title">Contact Persons</h2>
            <?php require ROOT_PATH . '/includes/contact-rows.php'; ?>
            <?php if ($canManage): ?>
                <p class="form-hint">Up to <?= Contacts::MAX ?> contacts; empty rows are ignored. Save to get more empty rows.</p>
            <?php endif; ?>
        </section>
    </div>

    <aside class="form-side">
        <section class="card card--pad">
            <h2 class="card__title">Status</h2>
            <label class="check check--switch">
                <input type="checkbox" name="is_active" value="1"<?= $isActive ? ' checked' : '' ?><?= $ro ?>>
                <span><strong>Active</strong><small class="muted block">Inactive suppliers stay on old records but aren't offered for new ones</small></span>
            </label>
        </section>
    </aside>

    <div class="form-actions">
        <a class="btn btn--light" href="<?= e(url('pages/' . $returnTo)) ?>"><?= $canManage ? 'Cancel' : 'Back' ?></a>
        <?php if ($canManage): ?>
            <button type="submit" class="btn btn--primary"><?= icon('save') ?> <?= $supplier ? 'Save Changes' : 'Add Supplier' ?></button>
        <?php endif; ?>
    </div>
</form>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
