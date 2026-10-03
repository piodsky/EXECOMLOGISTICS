<?php
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = settings_page('roles', 'Roles'); // roles.manage

// ---------------------------------------------------------------------
// Row actions (POST → back to the list). Roles:: checks every rule again.
// ---------------------------------------------------------------------
if (is_post()) {
    Csrf::verifyRequest();
    $id = input_int($_POST, 'id', 1) ?? 0;
    try {
        if (input_string($_POST, 'action', 20) !== 'delete') {
            throw new HttpException(400, 'Unknown action.');
        }
        $r = Roles::delete($id);
        flash('success', "The {$r['name']} role was deleted.");
    } catch (HttpException $e) {
        flash('error', $e->getMessage());
    }
    redirect('pages/roles.php');
}

$roles = Roles::all();
$registryCount = count(Roles::registry());
$settingsTab = 'roles';

$pageStyles = ['css/settings.css'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Settings</h1>
        <p class="muted">Roles decide what each user can see and do.</p>
    </div>
    <a class="btn btn--primary" href="<?= e(url('pages/role-form.php')) ?>" id="addRole"><?= icon('plus') ?> Add Role</a>
</div>

<?php require ROOT_PATH . '/includes/settings-nav.php'; ?>

<section class="card">
    <div class="table-wrap">
        <table class="table table--list" id="rolesTable">
            <thead>
            <tr>
                <th>Role</th>
                <th>Type</th>
                <th class="num">Permissions</th>
                <th class="num">Users</th>
                <th>Status</th>
                <th class="actions-col">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($roles as $r): ?>
                <?php
                $isSuper  = (int) $r['is_super'] === 1;
                $isSystem = (int) $r['is_system'] === 1;
                $active   = (int) $r['is_active'] === 1;
                $blocker  = Roles::editBlocker($r);
                $formUrl  = url('pages/role-form.php?id=' . (int) $r['id']);
                ?>
                <tr class="<?= $active ? '' : 'is-inactive' ?>" data-role="<?= e($r['code']) ?>">
                    <td>
                        <a class="item-cell__name" href="<?= e($formUrl) ?>"><?= e($r['name']) ?></a>
                        <small class="muted block"><code><?= e($r['code']) ?></code><?= $r['description'] ? ' · ' . e($r['description']) : '' ?></small>
                    </td>
                    <td>
                        <?php if ($isSuper): ?>
                            <span class="badge badge--admin"><?= icon('lock') ?> Super (locked)</span>
                        <?php elseif ($isSystem): ?>
                            <span class="badge">System</span>
                        <?php else: ?>
                            <span class="badge badge--success">Custom</span>
                        <?php endif; ?>
                    </td>
                    <td class="num"><?= $isSuper ? 'All' : (int) $r['perms'] . ' / ' . $registryCount ?></td>
                    <td class="num"><?= number_format((int) $r['users']) ?></td>
                    <td><span class="badge<?= $active ? ' badge--success' : '' ?>"><?= $active ? 'Active' : 'Inactive' ?></span></td>
                    <td class="actions-col">
                        <div class="row-actions">
                            <a class="icon-btn" href="<?= e($formUrl) ?>" data-act="edit"
                               title="<?= $blocker === null ? 'Edit permissions' : 'View permissions' ?>"
                               aria-label="<?= $blocker === null ? 'Edit' : 'View' ?> <?= e($r['name']) ?>"><?= icon($blocker === null ? 'edit' : 'eye') ?></a>
                            <?php if ($blocker === null && !$isSystem && (int) $r['users'] === 0): ?>
                                <form method="post" data-confirm="Delete the <?= e($r['name']) ?> role permanently?">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <button type="submit" class="icon-btn icon-btn--danger-outline" data-act="delete" title="Delete"
                                            aria-label="Delete <?= e($r['name']) ?>"><?= icon('trash') ?></button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="table-foot muted"><?= icon('info') ?> System roles can't be deleted. A role can only be deleted when no user has it.
        Changes apply to signed-in users on their next click.</p>
</section>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
