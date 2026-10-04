<?php
/**
 * Standalone error page, rendered by abort().
 *
 * @var int    $code
 * @var string $title
 * @var string $message
 */
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> · EXECOM Logistics POS</title>
    <link rel="icon" href="<?= e(asset('img/favicon.png')) ?>" type="image/png">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="page-error">
    <main class="error-card">
        <span class="error-card__icon"><?= icon($code === 403 ? 'lock' : 'box') ?></span>
        <p class="error-card__code"><?= (int) $code ?></p>
        <h1><?= e($title) ?></h1>
        <p class="error-card__msg"><?= e($message) ?></p>
        <a class="btn btn--primary" href="<?= e(url('index.php')) ?>">Back to POS</a>
    </main>
</body>
</html>
