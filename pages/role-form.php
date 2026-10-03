<?php
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = settings_page('roles', 'Roles'); // roles.manage

$id   = input_int($_GET, 'id', 1);
$role = null;
if ($id !== null) {
    $role = Roles::find($id) ?? throw new HttpException(404, 'Role not found.');
}
$blocker  = $role ? Roles::editBlocker($role) : null;   // null = editable
$locked   = $blocker !== null;
$isSystem = $role !== null && (int) $role['is_system'] === 1;
$page['title'] = $role ? $role['name'] : 'Add Role';
$self = 'role-form.php' . ($id !== null ? '?id=' . $id : '');

// ---------------------------------------------------------------------
// Save (PRG)
// ---------------------------------------------------------------------
if (is_post()) {
    Csrf::verifyRequest();
    if ($locked) {
        abort(403, $blocker);
    }
    [$data, $errors] = Roles::validate($_POST, $role);

    if ($errors) {
        $old = array_intersect_key(array_filter($_POST, 'is_string'), array_flip(['code', 'name', 'description']));
        $old['is_active']   = isset($_POST['is_active']) ? '1' : '0';
        $old['permissions'] = implode(',', array_filter((array) ($_POST['permissions'] ?? []), 'is_string'));
        flash_old($old);
        flash_errors($errors);
        flash('error', 'Please fix the highlighted fields.');
        redirect('pages/' . $self);
    }

    try {
        if ($role) {
            Roles::update($role, $data);
            flash('success', "The {$data['name']} role was updated.");
        } else {
            Roles::create($data);
            flash('success', "The {$data['name']} role was added. Assign it to users under Users.");
        }
    } catch (HttpException $e) {
        flash('error', $e->getMessage());
        redirect('pages/' . $self);
    }
    redirect('pages/roles.php');
}

// ---------------------------------------------------------------------
// Form
// ---------------------------------------------------------------------
$val     = static fn (string $key): string => old($key, (string) ($role[$key] ?? ''));
$checked = has_old() ? array_filter(explode(',', old('permissions'))) : ($role['permissions'] ?? []);
$active  = has_old() ? old('is_active') === '1' : (int) ($role['is_active'] ?? 1) === 1;
$fieldRo = $locked || $isSystem ? ' disabled' : ''; // name/description/status of system roles stay fixed

$pageStyles = ['css/settings.css'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <a class="back-link" href="<?= e(url('pages/roles.php')) ?>"><?= icon('arrow-left') ?> Roles</a>
        <h1><?= e($role ? $role['name'] : 'Add Role') ?></h1>
        <?php if ($role): ?>
            <p class="muted"><code><?= e($role['code']) ?></code> · <?= number_format((int) $role['users']) ?> <?= (int) $role['users'] === 1 ? 'user' : 'users' ?></p>
        <?php endif; ?>
    </div>
</div>

<?php if ($locked): ?>
    <div class="alert alert--info role-locked" role="status" id="roleLocked">
        <?= icon('lock') ?>
        <span><?= e($blocker) ?></span>
    </div>
<?php endif; ?>

<form class="role-form<?= $locked ? ' is-locked' : '' ?>" method="post" action="<?= e(url('pages/' . $self)) ?>" novalidate id="roleForm">
    <?= Csrf::field() ?>
    <section class="card card--pad">
        <h2 class="card__title">Role</h2>
        <div class="form-grid">
            <label class="form-field">
                <span class="form-label">Name *</span>
                <input class="form-input" name="name" maxlength="60" required value="<?= e($val('name')) ?>"<?= invalid('name') ?><?= $fieldRo ?>>
                <?= field_error('name') ?>
            </label>
            <label class="form-field">
                <span class="form-label">Code *</span>
                <input class="form-input form-input--mono" name="code" maxlength="30" required placeholder="stock_clerk"
                       autocapitalize="none" spellcheck="false" value="<?= e($val('code')) ?>"<?= invalid('code') ?><?= $role ? ' disabled' : '' ?>>
                <?= field_error('code') ?>
                <p class="form-hint"><?= $role ? "The code can't be changed." : 'Lowercase letters, numbers and underscores. Can\'t be changed later.' ?></p>
            </label>
            <label class="form-field form-field--full">
                <span class="form-label">Description</span>
                <input class="form-input" name="description" maxlength="255" value="<?= e($val('description')) ?>"<?= invalid('description') ?><?= $fieldRo ?>>
            </label>
            <?php if (!$isSystem): ?>
                <label class="check check--switch form-field--full">
                    <input type="checkbox" name="is_active" value="1"<?= $active ? ' checked' : '' ?><?= $locked ? ' disabled' : '' ?>>
                    <span><strong>Active</strong><small class="muted block">Users with an inactive role can't sign in</small></span>
                </label>
            <?php endif; ?>
        </div>
    </section>

    <section class="card card--pad">
        <h2 class="card__title">Permissions</h2>
        <p class="form-hint perm-hint">Grouped by module. You can only grant permissions you hold yourself.</p>
        <?= field_error('permissions') ?>
        <div class="perm-grid" id="permGrid">
            <?php foreach (Roles::grouped() as $module => $perms): ?>
                <fieldset class="perm-group">
                    <legend><?= e($module) ?></legend>
                    <?php foreach ($perms as $key => $label): ?>
                        <?php
                        $isOn = $role !== null && (int) $role['is_super'] === 1 ? true : in_array($key, $checked, true);
                        $mayGrant = Auth::can($key);
                        ?>
                        <label class="check perm-item<?= !$locked && !$mayGrant ? ' is-unavailable' : '' ?>"<?= !$locked && !$mayGrant ? ' title="You can only grant permissions you hold yourself."' : '' ?>>
                            <input type="checkbox" name="permissions[]" value="<?= e($key) ?>"<?= $isOn ? ' checked' : '' ?><?= $locked || !$mayGrant ? ' disabled' : '' ?>>
                            <span><?= e($label) ?> <small class="muted"><code><?= e($key) ?></code></small></span>
                            <?php if (!$locked && !$mayGrant): ?><?= icon('lock', 'perm-item__lock') ?><?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
            <?php endforeach; ?>
        </div>
    </section>

    <div class="form-actions">
        <a class="btn btn--light" href="<?= e(url('pages/roles.php')) ?>"><?= $locked ? 'Back' : 'Cancel' ?></a>
        <?php if (!$locked): ?>
            <button type="submit" class="btn btn--primary"><?= icon('save') ?> <?= $role ? 'Save Changes' : 'Add Role' ?></button>
        <?php endif; ?>
    </div>
</form>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
