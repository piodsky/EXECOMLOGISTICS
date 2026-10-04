<?php
/**
 * Billing & Collections → Checks Received (post-dated check register): checks of collections in the branch scope by
 * check date; filter on hand (default) / deposited / cleared / bounced / all. Actions (working in the branch):
 * Deposit + Cleared (collections.manage, date), Bounced (collections.cancel, reason: the collection is cancelled and its
 * bills are open again).
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('collections');

$filters = [
    'check'  => is_string($_GET['check'] ?? null) && (isset(Collections::CHECK_STATUSES[$_GET['check']]) || $_GET['check'] === 'all') ? $_GET['check'] : 'on_hand',
    'search' => input_string($_GET, 'search', 100),
];
$pgQuery = array_filter($filters, static fn ($v, $k) => $v !== '' && !($k === 'check' && $v === 'on_hand'), ARRAY_FILTER_USE_BOTH);
$self = 'checks.php' . ($pgQuery ? '?' . http_build_query($pgQuery) : '');

if (is_post()) {
    Csrf::verifyRequest();
    $action = input_string($_POST, 'action', 10);
    $id     = input_int($_POST, 'id', 1) ?? throw new HttpException(404, 'Collection not found.');
    try {
        $no = Collections::checkAction($id, $action, input_string($_POST, 'date', 10), input_string($_POST, 'reason', 200), (int) Auth::id());
        flash('success', match ($action) {
            'deposit' => "The check of {$no} was recorded as deposited.",
            'clear'   => "The check of {$no} cleared.",
            default   => "The check of {$no} bounced: the collection was cancelled and its bills are open again.",
        });
    } catch (HttpException $e) {
        flash('error', $e->getMessage());
    }
    redirect('pages/' . $self);
}

$pg     = paginate(Collections::checkCount($filters), 25);
$rows   = Collections::checks($filters, $pg['per_page'], $pg['offset']);
$pgPath = 'pages/checks.php';
$showBranch = Branch::current() === Branch::ALL;
$today  = date('Y-m-d');
$canManage = Auth::can('collections.manage');
$canCancel = Auth::can('collections.cancel');
$cols   = 7 + ($showBranch ? 1 : 0);

$collectionsTab = 'checks';
$page['title'] = 'Checks Received';
$pageStyles  = ['css/sales.css', 'css/stock-docs.css', 'css/transfers.css', 'css/reports.css', 'css/purchasing.css'];
$pageScripts = ['js/purchasing.js'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Billing &amp; Collections</h1>
        <p class="muted">Checks received from customers: deposit them on their date, then mark them cleared. A bounced check cancels its collection.</p>
    </div>
    <div class="page-actions"><span class="badge badge--period badge--branch"><?= icon('store') ?> <?= e(Branch::label()) ?></span></div>
</div>

<?php require ROOT_PATH . '/includes/collections-nav.php'; ?>

<section class="card">
    <form class="toolbar" method="get" action="<?= e(url('pages/checks.php')) ?>" role="search">
        <label class="toolbar__search">
            <?= icon('search') ?>
            <input class="form-input" type="search" name="search" maxlength="100" placeholder="Receipt no., customer, check no., bank" value="<?= e($filters['search']) ?>" aria-label="Search checks">
        </label>
        <select class="form-input" name="check" aria-label="Check status">
            <?php foreach (Collections::CHECK_STATUSES + ['all' => 'All checks'] as $k => $lbl): ?>
                <option value="<?= e($k) ?>"<?= $filters['check'] === $k ? ' selected' : '' ?>><?= e($lbl) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn--primary">Filter</button>
    </form>

    <div class="table-wrap">
        <table class="table table--list pu-table" id="checkTable">
            <thead>
            <tr>
                <th>Check</th>
                <th>Customer / receipt</th>
                <?php if ($showBranch): ?><th class="col-opt">Branch</th><?php endif; ?>
                <th>Check date</th>
                <th class="num">Amount</th>
                <th>Status</th>
                <th class="col-opt">Deposited / cleared</th>
                <th><span class="visually-hidden">Actions</span></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $c): ?>
                <?php $here = Branch::current() === (int) $c['branch_id']; $due = $c['check_status'] === 'on_hand' && $c['check_date'] !== null && $c['check_date'] <= $today; ?>
                <tr data-check="<?= e((string) $c['reference']) ?>">
                    <td><strong class="doc-no"><?= e((string) $c['reference']) ?></strong><small class="muted block"><?= e((string) $c['bank_name']) ?></small></td>
                    <td><?= e($c['customer_name']) ?><small class="block"><a class="item-cell__name doc-no" href="<?= e(url('pages/collection-view.php?id=' . (int) $c['id'])) ?>"><?= e($c['collection_no']) ?></a></small></td>
                    <?php if ($showBranch): ?><td class="col-opt"><span class="badge badge--branch"><?= e($c['branch_code']) ?></span></td><?php endif; ?>
                    <td class="nowrap<?= $due ? ' text-danger' : '' ?>"><?= $c['check_date'] ? e(date('M j, Y', strtotime($c['check_date']))) : '—' ?><?= $due ? ' <small>(deposit now)</small>' : '' ?></td>
                    <td class="num doc-value"><?= e(money($c['amount_received'])) ?></td>
                    <td><span class="badge <?= e(Collections::CHECK_BADGES[$c['check_status']] ?? '') ?>"><?= e(Collections::CHECK_STATUSES[$c['check_status']] ?? $c['check_status']) ?></span></td>
                    <td class="col-opt"><small><?= $c['deposited_at'] ? 'Dep. ' . e(date('M j', strtotime($c['deposited_at']))) : '' ?><?= $c['cleared_at'] ? ' · Cleared ' . e(date('M j', strtotime($c['cleared_at']))) : '' ?></small></td>
                    <td class="c-act nowrap">
                        <?php if ($here && $c['status'] === 'posted'): ?>
                            <?php foreach (['deposit' => ['on_hand', 'Deposited', $canManage], 'clear' => ['deposited', 'Cleared', $canManage]] as $act => [$from, $lbl, $ok]): ?>
                                <?php if ($ok && $c['check_status'] === $from): ?>
                                    <form method="post" action="<?= e(url('pages/' . $self)) ?>" class="inline-form chk-form">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="action" value="<?= e($act) ?>"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                        <input class="form-input form-input--sm" type="date" name="date" max="<?= e($today) ?>" value="<?= e($today) ?>" aria-label="<?= e($lbl) ?> date">
                                        <button type="submit" class="btn btn--light btn--sm" data-check-act="<?= e($act) ?>"><?= e($lbl) ?></button>
                                    </form>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php if ($canCancel && in_array($c['check_status'], ['on_hand', 'deposited'], true)): ?>
                                <form method="post" action="<?= e(url('pages/' . $self)) ?>" class="inline-form chk-form" data-confirm="Mark the check <?= e((string) $c['reference']) ?> as bounced? <?= e($c['collection_no']) ?> is cancelled and its bills are open again.">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="action" value="bounce"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                    <input type="hidden" name="reason" value="DAIF">
                                    <button type="submit" class="btn btn--danger btn--sm" data-check-act="bounce">Bounced</button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="<?= $cols ?>" class="empty">No checks <?= $filters['check'] === 'all' ? '' : strtolower(Collections::CHECK_STATUSES[$filters['check']] ?? '') ?> found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php require ROOT_PATH . '/includes/pagination.php'; ?>
</section>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
