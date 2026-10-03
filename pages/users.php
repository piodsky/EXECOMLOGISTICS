<?php
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = settings_page('users', 'Users'); // users.view
$selfId    = (int) Auth::id();
$canManage = Auth::can('users.manage');
$canDelete = Auth::can('users.delete');

// ---------------------------------------------------------------------
// Row actions (POST → back to the list). Users:: checks permission, scope and role again.
// ---------------------------------------------------------------------
if (is_post()) {
    Csrf::verifyRequest();
    $id = input_int($_POST, 'id', 1) ?? 0;
    try {
        switch (input_string($_POST, 'action', 20)) {
            case 'toggle':
                $u = Users::toggleActive($id, $selfId);
                flash('success', (int) $u['is_active'] === 1
                    ? "{$u['full_name']} can sign in again."
                    : "{$u['full_name']} was deactivated and can no longer sign in.");
                break;
            case 'delete':
                $u = Users::delete($id, $selfId);
                flash('success', "{$u['full_name']} ({$u['username']}) was deleted.");
                break;
            default:
                throw new HttpException(400, 'Unknown action.');
        }
    } catch (HttpException $e) {
        flash('error', $e->getMessage());
    }
    redirect('pages/users.php');
}

$users = Users::all();
$settingsTab = 'users';

$pageStyles = ['css/settings.css'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Settings</h1>
        <p class="muted">Company details on receipts, VAT, and who can sign in.</p>
    </div>
    <?php if ($canManage): ?>
        <a class="btn btn--primary" href="<?= e(url('pages/user-form.php')) ?>" id="addUser"><?= icon('plus') ?> Add User</a>
    <?php endif; ?>
</div>

<?php require ROOT_PATH . '/includes/settings-nav.php'; ?>

<section class="card">
    <div class="table-wrap">
        <table class="table table--list users-table" id="usersTable">
            <thead>
            <tr>
                <th>User</th>
                <th>Role</th>
                <th>Branch</th>
                <th class="num">Sales</th>
                <th class="col-opt">Last Sign-in</th>
                <th>Status</th>
                <th class="actions-col">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <?php
                $active   = (int) $u['is_active'] === 1;
                $isSelf   = (int) $u['id'] === $selfId;
                $manage   = $canManage && Users::manageBlocker($u, $selfId) === null;
                $editUrl  = url('pages/user-form.php?id=' . (int) $u['id']);
                ?>
                <tr class="<?= $active ? '' : 'is-inactive' ?>" data-username="<?= e($u['username']) ?>" data-role="<?= e($u['role']) ?>">
                    <td>
                        <div class="item-cell">
                            <span class="avatar avatar--sm"><?= e(mb_strtoupper(mb_substr($u['full_name'], 0, 1))) ?></span>
                            <span>
                                <?php if ($manage): ?>
                                    <a class="item-cell__name" href="<?= e($editUrl) ?>"><?= e($u['full_name']) ?></a>
                                <?php else: ?>
                                    <strong class="item-cell__name"><?= e($u['full_name']) ?></strong>
                                <?php endif; ?>
                                <?php if ($isSelf): ?><span class="badge badge--you">You</span><?php endif; ?>
                                <small class="muted block">@<?= e($u['username']) ?></small>
                            </span>
                        </div>
                    </td>
                    <td><span class="badge<?= (int) $u['is_super'] === 1 ? ' badge--admin' : '' ?>"><?= e($u['role_name']) ?></span></td>
                    <td>
                        <span class="badge badge--branch" title="<?= e($u['branch_name']) ?>"><?= e($u['branch_code']) ?></span>
                        <?php if ($u['extra_branches']): ?>
                            <small class="muted block user-extra">+ <?= e(implode(', ', $u['extra_branches'])) ?></small>
                        <?php endif; ?>
                    </td>
                    <td class="num"><?= number_format((int) $u['sales']) ?></td>
                    <td class="nowrap col-opt"><?= $u['last_login_at'] ? e(date('M j, Y', strtotime($u['last_login_at']))) . ' <small class="muted block">' . e(date('g:i A', strtotime($u['last_login_at']))) . '</small>' : '<span class="muted">Never</span>' ?></td>
                    <td><span class="badge<?= $active ? ' badge--success' : '' ?>"><?= $active ? 'Active' : 'Inactive' ?></span></td>
                    <td class="actions-col">
                        <div class="row-actions">
                            <?php if ($manage): ?>
                                <a class="icon-btn" title="Edit / reset password" aria-label="Edit <?= e($u['full_name']) ?>" href="<?= e($editUrl) ?>"><?= icon('edit') ?></a>
                            <?php endif; ?>
                            <?php if (!$isSelf && $manage): ?>
                                <form method="post"<?= $active ? ' data-confirm="Deactivate ' . e($u['full_name']) . '? They will be signed out and can no longer sign in."' : '' ?>>
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                    <?php $label = $active ? 'Deactivate' : 'Activate'; ?>
                                    <button type="submit" class="icon-btn<?= $active ? '' : ' icon-btn--success' ?>" data-act="toggle"
                                            title="<?= $label ?>" aria-label="<?= $label ?> <?= e($u['full_name']) ?>"><?= icon('power') ?></button>
                                </form>
                                <?php if ($canDelete && (int) $u['sales'] === 0): ?>
                                    <form method="post" data-confirm="Delete <?= e($u['full_name']) ?> (<?= e($u['username']) ?>) permanently?">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                        <button type="submit" class="icon-btn icon-btn--danger-outline" data-act="delete" title="Delete"
                                                aria-label="Delete <?= e($u['full_name']) ?>"><?= icon('trash') ?></button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$users): ?>
                <tr><td colspan="7" class="empty">No users at this branch.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <p class="table-foot muted"><?= icon('info') ?> What each role can do is set under Roles.
        <?= Branch::canSeeAll() ? 'All branches are listed.' : 'Users whose home branch is ' . e(Branch::label()) . ' are listed.' ?>
        Users with sales can only be deactivated, so their sales keep their name.</p>
</section>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
