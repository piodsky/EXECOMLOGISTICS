<?php
/**
 * My Account — any signed-in user can change their own password (from the user menu).
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
Auth::requireLogin(); // every signed-in user, whatever their role
$page = ['key' => 'account', 'title' => 'My Account', 'icon' => 'user'];
$me   = Auth::user();

if (is_post()) {
    Csrf::verifyRequest();
    $pw = static fn (string $k): string => is_string($_POST[$k] ?? null) ? $_POST[$k] : '';
    $errors = Users::changeOwnPassword((int) $me['id'], $pw('current_password'), $pw('password'), $pw('password_confirm'));
    if ($errors) {
        flash_errors($errors);
        flash('error', 'Your password was not changed.');
        redirect('pages/account.php');
    }
    Auth::refreshPasswordStamp(); // stay signed in here; other devices are signed out
    flash('success', 'Your password was changed. Other devices signed in as you have been signed out.');
    redirect('pages/account.php');
}

$myRole = $me['role_name'];
$pageStyles = ['css/settings.css'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>My Account</h1>
        <p class="muted">Signed in as <strong><?= e($me['full_name']) ?></strong> (@<?= e($me['username']) ?> · <?= e($myRole) ?> · <?= e(Branch::label()) ?>).</p>
    </div>
</div>

<div class="account-layout">
    <form class="card card--pad" method="post" action="<?= e(url('pages/account.php')) ?>" novalidate id="passwordForm">
        <?= Csrf::field() ?>
        <h2 class="card__title">Change Password</h2>
        <div class="form-grid form-grid--single">
            <label class="form-field">
                <span class="form-label">Current password *</span>
                <input class="form-input" type="password" name="current_password" required maxlength="200" autocomplete="current-password"<?= invalid('current_password') ?>>
                <?= field_error('current_password') ?>
            </label>
            <label class="form-field">
                <span class="form-label">New password *</span>
                <input class="form-input" type="password" name="password" required maxlength="<?= Users::MAX_PASSWORD ?>" autocomplete="new-password"<?= invalid('password') ?>>
                <?= field_error('password') ?>
                <p class="form-hint">At least <?= Users::MIN_PASSWORD ?> characters. Avoid your username and common passwords.</p>
            </label>
            <label class="form-field">
                <span class="form-label">Confirm new password *</span>
                <input class="form-input" type="password" name="password_confirm" required maxlength="<?= Users::MAX_PASSWORD ?>" autocomplete="new-password"<?= invalid('password_confirm') ?>>
                <?= field_error('password_confirm') ?>
            </label>
        </div>
        <div class="form-actions form-actions--inline">
            <button type="submit" class="btn btn--primary"><?= icon('lock') ?> Change Password</button>
        </div>
    </form>
</div>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
