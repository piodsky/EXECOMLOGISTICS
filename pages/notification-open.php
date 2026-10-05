<?php
/**
 * GET pages/notification-open.php?id=N: marks the signed-in user's notification read and opens its document
 * (the Notifications page when it has no link). Another user's notification -> 404.
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
Auth::requireLogin();

$id   = input_int($_GET, 'id', 1) ?? throw new HttpException(404, 'Notification not found.');
$link = Notifications::open((int) Auth::id(), $id) ?? throw new HttpException(404, 'Notification not found.');
redirect(preg_match('#^pages/[a-z-]+\.php(\?[A-Za-z0-9=&_.-]*)?$#', $link) ? $link : 'pages/notifications.php');
