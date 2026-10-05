<?php
/**
 * POST /api/notifications/read-all.php (CSRF header) -> {ok, marked, unread: 0}: every notification of the
 * signed-in user is read.
 */
declare(strict_types=1);

require __DIR__ . '/../../system/bootstrap.php';
api_guard_login('POST');

$marked = Notifications::markAllRead((int) Auth::id());
json_response(['ok' => true, 'marked' => $marked, 'unread' => 0]);
