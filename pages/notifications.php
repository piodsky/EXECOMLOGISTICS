<?php
/**
 * Notifications (every signed-in user): the user's own notifications, newest first, All / Unread, Mark all read.
 * Clicking one opens notification-open.php (marks it read, opens the document). The bell panel shows the newest.
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
Auth::requireLogin();
$uid = (int) Auth::id();

if (is_post()) {
    Csrf::verifyRequest();
    $n = Notifications::markAllRead($uid);
    flash('success', $n > 0 ? "{$n} notification" . ($n === 1 ? '' : 's') . ' marked as read.' : 'Everything was already read.');
    redirect('pages/notifications.php');
}

$unreadOnly = ($_GET['filter'] ?? '') === 'unread';
$pgQuery    = $unreadOnly ? ['filter' => 'unread'] : [];
$pg         = paginate(Notifications::count($uid, $unreadOnly), 30);
$rows       = Notifications::forUser($uid, $unreadOnly, $pg['per_page'], $pg['offset']);
$pgPath     = 'pages/notifications.php';
$unread     = Notifications::unreadCount($uid);

$page = ['key' => 'notifications', 'title' => 'Notifications'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Notifications</h1>
        <p class="muted">What needs your attention: approvals, deliveries, payments and jobs. Click one to open it; it is then marked as read.</p>
    </div>
    <div class="page-actions">
        <?php if ($unread > 0): ?>
            <form method="post" action="<?= e(url('pages/notifications.php')) ?>">
                <?= Csrf::field() ?>
                <button type="submit" class="btn btn--light" id="markAllRead"><?= icon('check') ?> Mark all read (<?= $unread ?>)</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<section class="card notif-page">
    <nav class="notif-tabs" aria-label="Show">
        <a class="notif-tab<?= !$unreadOnly ? ' is-active' : '' ?>" href="<?= e(url('pages/notifications.php')) ?>"<?= !$unreadOnly ? ' aria-current="page"' : '' ?>>All</a>
        <a class="notif-tab<?= $unreadOnly ? ' is-active' : '' ?>" href="<?= e(url('pages/notifications.php?filter=unread')) ?>"<?= $unreadOnly ? ' aria-current="page"' : '' ?>>Unread<?= $unread > 0 ? ' (' . $unread . ')' : '' ?></a>
    </nav>
    <?php if ($rows): ?>
        <ul class="notif-list notif-list--page" id="notifPageList">
            <?php foreach ($rows as $row): ?>
                <?php $nItem = Notifications::present($row); ?>
                <li>
                    <a class="notif-item<?= $nItem['unread'] ? ' is-unread' : '' ?>" href="<?= e($nItem['url']) ?>" data-notification="<?= $nItem['id'] ?>">
                        <span class="notif-icon notif-icon--<?= e($nItem['tone']) ?>"><?= icon($nItem['icon']) ?></span>
                        <span class="notif-body">
                            <span class="notif-text"><?= str_replace(e($nItem['ref']), '<strong>' . e($nItem['ref']) . '</strong>', e($nItem['message'])) ?></span>
                            <small class="notif-meta"><?= e($nItem['ago']) ?><?= $nItem['branch'] !== '' ? ' · ' . e($nItem['branch']) : '' ?><?= $nItem['actor'] !== '' ? ' · ' . e($nItem['actor']) : '' ?></small>
                        </span>
                        <?php if ($nItem['unread']): ?><span class="notif-dot" aria-label="Unread"></span><?php endif; ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php require ROOT_PATH . '/includes/pagination.php'; ?>
    <?php else: ?>
        <p class="empty notif-empty"><?= $unreadOnly ? 'No unread notifications. You are all caught up.' : 'No notifications yet.' ?></p>
    <?php endif; ?>
</section>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
