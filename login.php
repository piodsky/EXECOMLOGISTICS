<?php
declare(strict_types=1);

require __DIR__ . '/system/bootstrap.php';

if (Auth::check()) {
    redirect(home_url());
}

if (is_post()) {
    if (!Csrf::validate($_POST['_csrf'] ?? null)) {
        flash('error', 'Security check failed. Please try again.');
        redirect('login.php');
    }

    $username = input_string($_POST, 'username', 50);
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    flash_old(['username' => $username]);

    if ($username === '' || $password === '') {
        flash('error', 'Please enter your username and password.');
        redirect('login.php');
    }

    // Usernames are letters, numbers, dot, dash, underscore. Anything else can't match.
    if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username) || strlen($password) > 200) {
        flash('error', 'Invalid username or password.');
        redirect('login.php');
    }

    $result = Auth::attempt($username, $password);
    if ($result['ok']) {
        if (in_array($password, Users::DEFAULT_PASSWORDS, true)) {
            flash('warning', 'You signed in with a default password. Please change it now: user menu (top right) → Change Password.');
        }
        redirect(Auth::intendedUrl());
    }

    flash('error', $result['error']);
    redirect('login.php');
}

$showDemo = config('app.env') === 'local';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · <?= e(config('app.name')) ?> POS</title>
    <link rel="icon" href="<?= e(asset('img/favicon.png')) ?>" type="image/png">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="page-login">
<main class="login">
    <section class="login__brand" aria-hidden="true">
        <div class="login__brand-inner">
            <img class="login__logo" src="<?= e(asset('img/execom-mark.png')) ?>" alt="" width="80" height="80">
            <h1>EXECOM</h1>
            <p class="login__sub">LOGISTICS</p>
            <p class="login__tagline">POS &amp; Inventory System</p>
            <ul class="login__points">
                <li><?= icon('check') ?> Fast checkout with barcode scanning</li>
                <li><?= icon('check') ?> Live inventory &amp; stock alerts</li>
                <li><?= icon('check') ?> Sales, customers &amp; distribution in one place</li>
            </ul>
        </div>
    </section>

    <section class="login__panel">
        <form class="login__form" method="post" action="<?= e(url('login.php')) ?>" novalidate>
            <h2>Welcome back</h2>
            <p class="muted">Sign in to start your shift.</p>

            <?php require ROOT_PATH . '/includes/flash.php'; ?>

            <?= Csrf::field() ?>

            <label class="field">
                <span class="field__label">Username</span>
                <span class="field__control">
                    <?= icon('user') ?>
                    <input type="text" name="username" required maxlength="50"
                           autocomplete="username" autocapitalize="none" spellcheck="false" autofocus
                           value="<?= e(old('username')) ?>">
                </span>
            </label>

            <label class="field">
                <span class="field__label">Password</span>
                <span class="field__control">
                    <?= icon('lock') ?>
                    <input type="password" name="password" id="password" required maxlength="200"
                           autocomplete="current-password">
                    <button type="button" class="field__toggle" data-toggle-password="password" aria-label="Show password">
                        <?= icon('eye') ?>
                    </button>
                </span>
            </label>

            <button type="submit" class="btn btn--primary btn--block btn--lg">Sign in</button>

            <?php if ($showDemo): ?>
                <div class="login__demo">
                    <strong>Demo accounts <small>(local only)</small></strong>
                    <code>admin / admin123</code>
                    <code>cashier / cashier123</code>
                </div>
            <?php endif; ?>
        </form>
        <p class="login__foot">© <?= date('Y') ?> EXECOM Logistics · v<?= e(config('app.version')) ?></p>
    </section>
</main>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
