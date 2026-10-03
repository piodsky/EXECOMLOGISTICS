<?php
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = settings_page('users', 'Users', 'users.manage');

$selfId = (int) Auth::id();
$id     = input_int($_GET, 'id', 1);
$target   = null;
if ($id !== null) {
    // null when outside the user's branch scope
    $target = Users::find($id) ?? throw new HttpException(404, 'User not found.');
    $blocker = Users::manageBlocker($target, $selfId);
    if ($blocker !== null) {
        abort(403, $blocker);
    }
}
$isSelf = $target !== null && (int) $target['id'] === $selfId;
$page['title'] = $target ? $target['full_name'] : 'Add User';
$self = 'user-form.php' . ($id !== null ? '?id=' . $id : '');

// ---------------------------------------------------------------------
// Save (PRG). Passwords are never flashed back into the session.
// ---------------------------------------------------------------------
if (is_post()) {
    Csrf::verifyRequest();
    [$data, $errors] = Users::validate($_POST, $id, $selfId);

    $keepOld = static function (): void {
        $old = array_intersect_key(array_filter($_POST, 'is_string'), array_flip(['username', 'full_name', 'role', 'branch_id']));
        $old['branches'] = implode(',', array_map('intval', array_filter((array) ($_POST['branches'] ?? []), 'is_numeric')));
        flash_old($old);
    };

    if ($errors) {
        $keepOld();
        flash_errors($errors);
        flash('error', 'Please fix the highlighted fields.');
        redirect('pages/' . $self);
    }

    try {
        if ($target) {
            Users::update($id, $data, $selfId);
            if ($isSelf && $data['password'] !== '') {
                Auth::refreshPasswordStamp(); // stay signed in here; other sessions end
            }
            flash('success', "{$data['full_name']} was updated." . ($data['password'] !== ''
                ? ($isSelf ? ' Your password was changed.' : ' Their password was reset; they are signed out everywhere else.')
                : ''));
        } else {
            Users::create($data);
            flash('success', "{$data['full_name']} can now sign in as {$data['username']}.");
        }
    } catch (HttpException $e) {
        $keepOld();
        flash('error', $e->getMessage());
        redirect('pages/' . $self);
    }
    redirect('pages/users.php');
}

// ---------------------------------------------------------------------
// Form
// ---------------------------------------------------------------------
$val   = static fn (string $key): string => old($key, (string) ($target[$key] ?? ''));
$roles = Roles::assignable();                 // roles this user may give
$role  = old('role', (string) ($target['role'] ?? (isset($roles['cashier']) ? 'cashier' : (string) array_key_first($roles))));
if ($target && !isset($roles[$target['role']])) {
    // Keep showing the current role (e.g. your own, or an inactive one) so the form can be saved unchanged.
    $current = Roles::findByCode($target['role']);
    if ($current) {
        $roles = [$current['code'] => $current] + $roles;
    }
}

$branches     = Branch::allowed();            // home branch choices
$canExtra     = Branch::canSeeAll() && !$isSelf;
$homeDefault  = (string) ($target['branch_id'] ?? (Branch::isConcrete() ? Branch::current() : (Auth::user()['branch_id'] ?? '')));
$home         = old('branch_id', $homeDefault);
if ($target && !isset($branches[(int) $target['branch_id']])) {
    $branches = [(int) $target['branch_id'] => ['id' => (int) $target['branch_id'], 'code' => $target['branch_code'], 'name' => $target['branch_name'] . ' (inactive)']] + $branches;
}
$extraChecked = has_old()
    ? array_map('intval', array_filter(explode(',', old('branches'))))
    : array_keys($target['extra_branches'] ?? []);
$roleIcons = ['super_admin' => 'shield', 'branch_admin' => 'store', 'cashier' => 'cart', 'technician' => 'stock'];

$pageStyles = ['css/settings.css'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <a class="back-link" href="<?= e(url('pages/users.php')) ?>"><?= icon('arrow-left') ?> Users</a>
        <h1><?= e($target ? $target['full_name'] : 'Add User') ?></h1>
        <?php if ($target): ?>
            <p class="muted">
                @<?= e($target['username']) ?> · added <?= e(date('M j, Y', strtotime($target['created_at']))) ?>
                <?php if ((int) $target['is_active'] !== 1): ?> · <span class="badge">Inactive</span><?php endif; ?>
            </p>
        <?php endif; ?>
    </div>
</div>

<form class="form-layout" method="post" action="<?= e(url('pages/' . $self)) ?>" novalidate id="userForm" autocomplete="off">
    <section class="card card--pad">
        <?= Csrf::field() ?>
        <h2 class="card__title">Account</h2>
        <div class="form-grid">
            <label class="form-field form-field--full">
                <span class="form-label">Full name *</span>
                <input class="form-input" name="full_name" maxlength="100" required value="<?= e($val('full_name')) ?>"<?= invalid('full_name') ?>>
                <?= field_error('full_name') ?>
            </label>
            <label class="form-field">
                <span class="form-label">Username *</span>
                <input class="form-input" name="username" maxlength="50" required autocapitalize="none" spellcheck="false"
                       value="<?= e($val('username')) ?>"<?= invalid('username') ?>>
                <?= field_error('username') ?>
                <p class="form-hint">Letters, numbers, dot, dash or underscore. Used to sign in.</p>
            </label>
            <fieldset class="form-field form-field--full role-field">
                <legend class="form-label">Role *</legend>
                <?php if ($isSelf): ?>
                    <input type="hidden" name="role" value="<?= e($target['role']) ?>">
                <?php endif; ?>
                <div class="role-options">
                    <?php foreach ($roles as $value => $r): ?>
                        <label class="role-option">
                            <input type="radio" name="role" value="<?= e($value) ?>"<?= $role === $value ? ' checked' : '' ?><?= $isSelf ? ' disabled' : '' ?>>
                            <span class="role-option__box">
                                <?= icon($roleIcons[$value] ?? 'user') ?>
                                <span class="role-option__text">
                                    <strong><?= e($r['name']) ?></strong>
                                    <?php if (!empty($r['description'])): ?><small><?= e($r['description']) ?></small><?php endif; ?>
                                </span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <?= field_error('role') ?>
                <p class="form-hint"><?= $isSelf ? "You can't change your own role." : 'What each role can do is set under Settings → Roles.' ?></p>
            </fieldset>
        </div>

        <h2 class="card__title card__title--spaced">Branch</h2>
        <div class="form-grid">
            <label class="form-field">
                <span class="form-label">Home branch *</span>
                <select class="form-input" name="branch_id" required<?= invalid('branch_id') ?><?= count($branches) === 1 ? ' disabled' : '' ?>>
                    <?php foreach ($branches as $b): ?>
                        <option value="<?= (int) $b['id'] ?>"<?= $home === (string) $b['id'] ? ' selected' : '' ?>><?= e($b['code'] . ' · ' . $b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= field_error('branch_id') ?>
                <p class="form-hint">Where the user works. Their sales, customers and stock changes belong to the branch they work in.</p>
            </label>
            <?php if ($canExtra): ?>
                <fieldset class="form-field form-field--full branch-access">
                    <legend class="form-label">Extra branches</legend>
                    <div class="check-list">
                        <?php foreach (Branch::allowed() as $b): ?>
                            <label class="check">
                                <input type="checkbox" name="branches[]" value="<?= (int) $b['id'] ?>"<?= in_array((int) $b['id'], $extraChecked, true) ? ' checked' : '' ?>>
                                <span><strong><?= e($b['code']) ?></strong> <?= e($b['name']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="form-hint">The user can switch to these branches too (the home branch is always included).</p>
                </fieldset>
            <?php elseif ($target && $target['extra_branches']): ?>
                <div class="form-field">
                    <span class="form-label">Extra branches</span>
                    <p><?= e(implode(', ', $target['extra_branches'])) ?></p>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <aside class="form-side">
        <section class="card card--pad">
            <h2 class="card__title"><?= $target ? 'Reset Password' : 'Password' ?></h2>
            <?php if ($target): ?>
                <p class="form-hint preview-hint">Leave empty to keep the current password.<?= $isSelf ? '' : ' A new password signs this user out on every other device.' ?></p>
            <?php endif; ?>
            <div class="form-grid form-grid--single">
                <label class="form-field">
                    <span class="form-label">New password<?= $target ? '' : ' *' ?></span>
                    <input class="form-input" type="password" name="password" maxlength="<?= Users::MAX_PASSWORD ?>" autocomplete="new-password"<?= invalid('password') ?>>
                    <?= field_error('password') ?>
                    <p class="form-hint">At least <?= Users::MIN_PASSWORD ?> characters. Avoid the username and common passwords.</p>
                </label>
                <label class="form-field">
                    <span class="form-label">Confirm password<?= $target ? '' : ' *' ?></span>
                    <input class="form-input" type="password" name="password_confirm" maxlength="<?= Users::MAX_PASSWORD ?>" autocomplete="new-password"<?= invalid('password_confirm') ?>>
                    <?= field_error('password_confirm') ?>
                </label>
            </div>
        </section>
        <?php if ($target): ?>
            <section class="card card--pad">
                <h2 class="card__title">Activity</h2>
                <dl class="detail-list">
                    <div><dt>Sales rung up</dt><dd><?= number_format((int) $target['sales']) ?></dd></div>
                    <div><dt>Last sign-in</dt><dd><?= $target['last_login_at'] ? e(date('M j, Y g:i A', strtotime($target['last_login_at']))) : 'Never' ?></dd></div>
                </dl>
            </section>
        <?php endif; ?>
    </aside>

    <div class="form-actions">
        <a class="btn btn--light" href="<?= e(url('pages/users.php')) ?>">Cancel</a>
        <button type="submit" class="btn btn--primary"><?= icon('save') ?> <?= $target ? 'Save Changes' : 'Add User' ?></button>
    </div>
</form>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
