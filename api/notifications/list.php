<?php
/**
 * GET /api/notifications/list.php[?filter=unread] -> {ok, unread, items: [Notifications::present()]} (newest 30)
 * GET /api/notifications/list.php?count=1           -> {ok, unread} (the bell badge, polled every minute)
 * Only the signed-in user's own notifications.
 */
declare(strict_types=1);

require __DIR__ . '/../../system/bootstrap.php';
api_guard_login('GET');

$uid    = (int) Auth::id();
$unread = Notifications::unreadCount($uid);
if (isset($_GET['count'])) {
    json_response(['ok' => true, 'unread' => $unread]);
}
$rows = Notifications::forUser($uid, ($_GET['filter'] ?? '') === 'unread', 30);
json_response(['ok' => true, 'unread' => $unread, 'items' => array_map([Notifications::class, 'present'], $rows)]);
