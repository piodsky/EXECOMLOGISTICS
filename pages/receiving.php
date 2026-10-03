<?php
/**
 * Receiving reports (RR): list with filters (receiving.view, current branch scope).
 * Total cost only with products.cost (Receiving::search leaves it out otherwise).
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('receiving');

$canCost   = Auth::can('products.cost');
$canManage = Auth::can('receiving.manage');

// ---------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------
$filters = [
    'search'   => input_string($_GET, 'search', 100),
    'status'   => is_string($_GET['status'] ?? null) && isset(Receiving::STATUSES[$_GET['status']]) ? $_GET['status'] : '',
    'from'     => input_date($_GET, 'from'),
    'to'       => input_date($_GET, 'to'),
    'supplier' => input_int($_GET, 'supplier', 1),
];
if ($filters['from'] !== null && $filters['to'] !== null && $filters['from'] > $filters['to']) {
    [$filters['from'], $filters['to']] = [$filters['to'], $filters['from']];
}
$pgQuery = array_filter($filters, static fn ($v) => $v !== '' && $v !== null);

$pg       = paginate(Receiving::count($filters), 20);
$reports  = Receiving::search($filters, $pg['per_page'], $pg['offset']);
$pgPath   = 'pages/receiving.php';
$returnTo = 'receiving.php' . (($pgQuery || $pg['page'] > 1) ? '?' . http_build_query($pgQuery + ['page' => $pg['page']]) : '');
$showBranch = Branch::current() === Branch::ALL;

$stmt = db()->prepare('SELECT id, code, name, is_active FROM suppliers ORDER BY name');
$stmt->execute();
$suppliers = $stmt->fetchAll();

$statusBadge = ['draft' => 'badge--info', 'posted' => 'badge--success', 'cancelled' => 'badge--danger'];
$today = date('Y-m-d');
$cols  = 7 + ($canCost ? 1 : 0) + ($showBranch ? 1 : 0);

$pageStyles = ['css/sales.css', 'css/receiving.css'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Receiving</h1>
        <p class="muted">Supplier deliveries. Posting a report adds the stock at the branch<?= $canCost ? ' and updates its average cost' : '' ?>.</p>
    </div>
    <div class="page-actions">
        <span class="badge badge--period badge--branch"><?= icon('store') ?> <?= e(Branch::label()) ?></span>
        <?php if ($canManage && Branch::isConcrete()): ?>
            <a class="btn btn--primary" id="newRrBtn" href="<?= e(url('pages/receiving-form.php?return=' . rawurlencode($returnTo))) ?>"><?= icon('plus') ?> New Receiving</a>
        <?php endif; ?>
    </div>
</div>
<?php if ($canManage && !Branch::isConcrete()): ?>
    <div class="alert alert--info" role="status">
        <?= icon('info') ?>
        <span>Showing the receiving reports of all branches. Choose a branch in the top bar to receive items.</span>
    </div>
<?php endif; ?>

<section class="card">
    <form class="toolbar" method="get" action="<?= e(url('pages/receiving.php')) ?>" role="search">
        <label class="toolbar__search">
            <?= icon('search') ?>
            <input class="form-input" type="search" name="search" maxlength="100" placeholder="RR no., reference or supplier"
                   value="<?= e($filters['search']) ?>" aria-label="Search receiving reports">
        </label>
        <label class="date-field">
            <span>From</span>
            <input class="form-input" type="date" name="from" value="<?= e($filters['from'] ?? '') ?>" max="<?= e($today) ?>">
        </label>
        <label class="date-field">
            <span>To</span>
            <input class="form-input" type="date" name="to" value="<?= e($filters['to'] ?? '') ?>" max="<?= e($today) ?>">
        </label>
        <select class="form-input" name="status" aria-label="Status">
            <option value="">All statuses</option>
            <?php foreach (Receiving::STATUSES as $value => $label): ?>
                <option value="<?= e($value) ?>"<?= $filters['status'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <select class="form-input" name="supplier" aria-label="Supplier">
            <option value="">All suppliers</option>
            <?php foreach ($suppliers as $s): ?>
                <option value="<?= (int) $s['id'] ?>"<?= $filters['supplier'] === (int) $s['id'] ? ' selected' : '' ?>><?= e($s['name']) ?><?= (int) $s['is_active'] === 1 ? '' : ' (inactive)' ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn--primary">Filter</button>
        <?php if ($pgQuery): ?>
            <a class="btn btn--light" href="<?= e(url('pages/receiving.php')) ?>">Reset</a>
        <?php endif; ?>
    </form>

    <div class="table-wrap">
        <table class="table table--list rr-table">
            <thead>
            <tr>
                <th>RR No.</th>
                <th>Received</th>
                <th>Supplier</th>
                <?php if ($showBranch): ?><th class="col-opt">Branch</th><?php endif; ?>
                <th class="col-opt">Reference</th>
                <th class="num">Lines</th>
                <th class="num col-opt">Qty</th>
                <?php if ($canCost): ?><th class="num">Total Cost</th><?php endif; ?>
                <th>Status</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($reports as $r): ?>
                <?php
                $viewUrl = url('pages/receiving-view.php?id=' . (int) $r['id'] . '&return=' . rawurlencode($returnTo));
                $isDraft = $r['status'] === 'draft';
                ?>
                <tr class="<?= $r['status'] === 'cancelled' ? 'is-void' : '' ?>">
                    <td>
                        <a class="item-cell__name rr-no" href="<?= e($viewUrl) ?>"><?= e($isDraft ? 'Draft #' . $r['id'] : $r['rr_no']) ?></a>
                        <small class="muted block"><?= e($r['created_by_name']) ?></small>
                    </td>
                    <td class="nowrap"><?= e(date('M j, Y', strtotime($r['received_date']))) ?></td>
                    <td><?= e($r['supplier_name']) ?> <small class="muted block"><?= e($r['supplier_code']) ?></small></td>
                    <?php if ($showBranch): ?><td class="col-opt"><span class="badge badge--branch" title="<?= e($r['branch_name']) ?>"><?= e($r['branch_code']) ?></span></td><?php endif; ?>
                    <td class="col-opt"><?= e($r['reference_no'] ?? '—') ?></td>
                    <td class="num"><?= (int) $r['line_count'] ?></td>
                    <td class="num col-opt"><?= number_format((int) $r['total_qty']) ?></td>
                    <?php if ($canCost): ?>
                        <td class="num rr-total"><?= $r['total_cost'] !== null ? e(money($r['total_cost'])) : '<span class="muted">—</span>' ?></td>
                    <?php endif; ?>
                    <td><span class="badge <?= e($statusBadge[$r['status']] ?? '') ?>"><?= e(Receiving::STATUSES[$r['status']] ?? $r['status']) ?></span></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$reports): ?>
                <tr><td colspan="<?= $cols ?>" class="empty">No receiving reports found<?= $pgQuery ? ' for these filters' : ' yet' ?>.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php require ROOT_PATH . '/includes/pagination.php'; ?>
</section>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
