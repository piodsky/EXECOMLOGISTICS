<?php
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = settings_page('branches', 'Branches'); // branches.manage

$id     = input_int($_GET, 'id', 1);
$branch = null;
if ($id !== null) {
    $branch = Branches::find($id) ?? throw new HttpException(404, 'Branch not found.');
}
$page['title'] = $branch ? $branch['name'] : 'Add Branch';
$self = 'branch-form.php' . ($id !== null ? '?id=' . $id : '');

// ---------------------------------------------------------------------
// Save (PRG)
// ---------------------------------------------------------------------
if (is_post()) {
    Csrf::verifyRequest();
    [$data, $errors] = Branches::validate($_POST, $branch);

    if ($errors) {
        flash_old(array_filter($_POST, 'is_string') + [
            'is_active' => isset($_POST['is_active']) ? '1' : '0',
            'is_main'   => isset($_POST['is_main']) ? '1' : '0',
        ]);
        flash_errors($errors);
        flash('error', 'Please fix the highlighted fields.');
        redirect('pages/' . $self);
    }

    try {
        if ($branch) {
            Branches::update($branch, $data);
            flash('success', "{$data['name']} was updated.");
        } else {
            Branches::create($data);
            flash('success', "{$data['name']} ({$data['code']}) was added with a Main Warehouse and a General stock location.");
        }
    } catch (HttpException $e) {
        flash('error', $e->getMessage());
        redirect('pages/' . $self);
    }
    redirect('pages/branches.php');
}

// ---------------------------------------------------------------------
// Form
// ---------------------------------------------------------------------
$val       = static fn (string $key): string => old($key, (string) ($branch[$key] ?? ''));
$isActive  = has_old() ? old('is_active') === '1' : (int) ($branch['is_active'] ?? 1) === 1;
$isMain    = has_old() ? old('is_main') === '1' : (int) ($branch['is_main'] ?? 0) === 1;
$locations = $branch ? Branches::locations((int) $branch['id']) : [];

$pageStyles = ['css/settings.css'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <a class="back-link" href="<?= e(url('pages/branches.php')) ?>"><?= icon('arrow-left') ?> Branches</a>
        <h1><?= e($branch ? $branch['name'] : 'Add Branch') ?></h1>
        <?php if ($branch): ?>
            <p class="muted"><code><?= e($branch['code']) ?></code><?= (int) $branch['is_main'] === 1 ? ' · Main store' : '' ?></p>
        <?php endif; ?>
    </div>
</div>

<?php if ($branch && Branches::needsDetails($branch) && !has_old()): ?>
    <div class="alert alert--warning" role="status">
        <?= icon('alert') ?>
        <span>Details to be updated: add this branch's address and contact number. Until then receipts show the company's.</span>
    </div>
<?php endif; ?>

<form class="form-layout" method="post" action="<?= e(url('pages/' . $self)) ?>" novalidate id="branchForm">
    <section class="card card--pad">
        <?= Csrf::field() ?>
        <h2 class="card__title">Branch Details</h2>
        <div class="form-grid">
            <label class="form-field">
                <span class="form-label">Code *</span>
                <input class="form-input form-input--mono" name="code" maxlength="10" required placeholder="MAR"
                       autocapitalize="characters" value="<?= e($val('code')) ?>"<?= invalid('code') ?>>
                <?= field_error('code') ?>
                <p class="form-hint">2–10 capital letters, shown on lists and the branch switcher.</p>
            </label>
            <label class="form-field">
                <span class="form-label">Name *</span>
                <input class="form-input" name="name" maxlength="100" required value="<?= e($val('name')) ?>"<?= invalid('name') ?>>
                <?= field_error('name') ?>
            </label>
            <label class="form-field form-field--full">
                <span class="form-label">Address</span>
                <input class="form-input" name="address" maxlength="255" value="<?= e($val('address')) ?>"<?= invalid('address') ?>
                       placeholder="Street, Barangay, City, Province">
                <?= field_error('address') ?>
            </label>
            <label class="form-field">
                <span class="form-label">Contact no.</span>
                <input class="form-input" name="contact_no" maxlength="50" inputmode="tel" value="<?= e($val('contact_no')) ?>"<?= invalid('contact_no') ?>>
                <?= field_error('contact_no') ?>
            </label>
            <label class="form-field">
                <span class="form-label">TIN branch code</span>
                <input class="form-input form-input--mono" name="tin_branch_code" maxlength="30" value="<?= e($val('tin_branch_code')) ?>"<?= invalid('tin_branch_code') ?>>
                <?= field_error('tin_branch_code') ?>
            </label>
        </div>
    </section>

    <aside class="form-side">
        <section class="card card--pad">
            <h2 class="card__title">Status</h2>
            <label class="check check--switch">
                <input type="checkbox" name="is_active" value="1"<?= $isActive ? ' checked' : '' ?><?= invalid('is_active') ?>>
                <span><strong>Active</strong><small class="muted block">Users whose only branch is inactive can't sign in</small></span>
            </label>
            <?= field_error('is_active') ?>
            <label class="check check--switch">
                <input type="checkbox" name="is_main" value="1"<?= $isMain ? ' checked' : '' ?><?= invalid('is_main') ?>>
                <span><strong>Main store</strong><small class="muted block">Exactly one branch is the main store</small></span>
            </label>
            <?= field_error('is_main') ?>
        </section>
        <?php if ($branch): ?>
            <section class="card card--pad">
                <h2 class="card__title">Stock Locations</h2>
                <dl class="detail-list location-list" id="branchLocations">
                    <?php foreach ($locations as $l): ?>
                        <div>
                            <dt><?= e($l['warehouse_name']) ?> <small class="muted">(<?= e($l['warehouse_code']) ?>)</small></dt>
                            <dd><?= e($l['name']) ?> <small class="muted">(<?= e($l['code']) ?><?= (int) $l['is_default'] === 1 ? ', default' : '' ?><?= (int) $l['is_sellable'] === 1 ? ', sellable' : '' ?>)</small></dd>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$locations): ?>
                        <div><dt>None</dt><dd class="muted">—</dd></div>
                    <?php endif; ?>
                </dl>
                <p class="form-hint">The POS sells from the default sellable location; stock adjustments go there too.</p>
            </section>
        <?php endif; ?>
    </aside>

    <div class="form-actions">
        <a class="btn btn--light" href="<?= e(url('pages/branches.php')) ?>">Cancel</a>
        <button type="submit" class="btn btn--primary"><?= icon('save') ?> <?= $branch ? 'Save Changes' : 'Add Branch' ?></button>
    </div>
</form>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
