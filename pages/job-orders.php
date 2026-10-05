<?php
/**
 * Job Orders (any of JobOrders::VIEW_PERMISSIONS): the branch's jobs (technicians without job_orders.view: their
 * own, the ones they took in and unassigned new jobs), work lists, filters, "New Job Order" (job_orders.create,
 * concrete branch).
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('job-orders');

$concrete = Branch::isConcrete();
$statusIn = is_string($_GET['status'] ?? null) ? $_GET['status'] : null;
$techIn   = is_string($_GET['technician'] ?? null) ? $_GET['technician'] : '';
$filters = [
    'search'     => input_string($_GET, 'search', 100),
    // Default: open jobs. "all" = every status.
    'status'     => $statusIn === null ? 'open' : ($statusIn === 'open' || isset(JobOrders::STATUSES[$statusIn]) ? $statusIn : ''),
    'technician' => in_array($techIn, ['me', 'none'], true) || ctype_digit($techIn) ? $techIn : '',
    'priority'   => is_string($_GET['priority'] ?? null) && isset(JobOrders::PRIORITIES[$_GET['priority']]) ? $_GET['priority'] : '',
    'parts'      => ($_GET['parts'] ?? '') === 'pending' ? 'pending' : '',
    'type'       => (string) (input_int($_GET, 'type', 1) ?? ''),
];
// Query string for links: "open" is the default (left out), "" = every status ("all").
$pgQuery = array_filter(array_diff_key($filters, ['status' => 1]), static fn ($v) => $v !== '');
if ($filters['status'] !== 'open') {
    $pgQuery['status'] = $filters['status'] !== '' ? $filters['status'] : 'all';
}

$pg       = paginate(JobOrders::count($filters), 20);
$jobs     = JobOrders::search($filters, $pg['per_page'], $pg['offset']);
$pgPath   = 'pages/job-orders.php';
$returnTo = 'job-orders.php' . (($pgQuery || $pg['page'] > 1) ? '?' . http_build_query($pgQuery + ['page' => $pg['page']]) : '');

$work     = JobOrders::workCounts();
$listUrl  = static fn (array $q): string => url('pages/job-orders.php?' . http_build_query($q));
$canWork  = Auth::can('job_orders.update');
$tiles = array_values(array_filter([
    $canWork ? ['My Jobs', 'Open jobs you lead or help on', 'wrench', $work['mine'], ['technician' => 'me']] : null,
    ['Unassigned', 'New jobs nobody has taken yet', 'clipboard', $work['unassigned'], ['status' => 'new', 'technician' => 'none']],
    ['For Approval', "Waiting for the customer's answer", 'clock', $work['for_approval'], ['status' => 'for_approval']],
    ['Waiting for Parts', 'Repairs on hold for parts', 'box', $work['waiting_parts'], ['status' => 'waiting_parts']],
    ['Completed', 'Ready to bill and release', 'check', $work['completed'], ['status' => 'completed']],
    Auth::can('job_parts.issue') ? ['Parts to Issue', 'Open parts requests of technicians', 'truck', $work['parts'], ['parts' => 'pending']] : null,
]));
$assignees = JobOrders::seesAll() ? JobOrders::assignees() : [];
$today     = date('Y-m-d');

$pageStyles  = ['css/sales.css', 'css/stock-docs.css', 'css/transfers.css', 'css/jobs.css'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Job Orders</h1>
        <p class="muted">Devices taken in for repair or service: who works on them, their status and the customer's approval.</p>
    </div>
    <div class="page-actions">
        <span class="badge badge--period badge--branch"><?= icon('store') ?> <?= e(Branch::label()) ?></span>
        <?php if ($concrete && Auth::can('job_orders.create')): ?>
            <a class="btn btn--primary" href="<?= e(url('pages/job-form.php?return=' . rawurlencode($returnTo))) ?>" id="newJobBtn"><?= icon('plus') ?> New Job Order</a>
        <?php endif; ?>
    </div>
</div>

<?php if (!$concrete): ?>
    <div class="alert alert--info" role="status">
        <?= icon('info') ?>
        <span>Showing the job orders of all branches. Choose a branch in the top bar to take in devices or work on jobs.</span>
    </div>
<?php else: ?>
    <nav class="op-tiles bt-tiles" aria-label="Work lists">
        <?php foreach ($tiles as [$label, $hint, $ic, $count, $q]): ?>
            <a class="op-tile<?= $count > 0 ? ' has-work' : '' ?>" href="<?= e($listUrl($q)) ?>">
                <span class="op-tile__icon"><?= icon($ic) ?></span>
                <span><strong><?= e($label) ?> <span class="bt-count" data-work="<?= e(strtolower(str_replace(' ', '-', $label))) ?>"><?= $count ?></span></strong><small><?= e($hint) ?></small></span>
            </a>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>

<section class="card">
    <form class="toolbar" method="get" action="<?= e(url('pages/job-orders.php')) ?>" role="search">
        <label class="toolbar__search">
            <?= icon('search') ?>
            <input class="form-input" type="search" name="search" maxlength="100" placeholder="Job no., customer, phone, serial, brand or model"
                   value="<?= e($filters['search']) ?>" aria-label="Search job orders">
        </label>
        <select class="form-input" name="status" aria-label="Status">
            <option value="open"<?= $filters['status'] === 'open' ? ' selected' : '' ?>>Open jobs</option>
            <option value="all"<?= $filters['status'] === '' ? ' selected' : '' ?>>All statuses</option>
            <?php foreach (JobOrders::STATUSES as $value => $label): ?>
                <option value="<?= e($value) ?>"<?= $filters['status'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <select class="form-input" name="technician" aria-label="Technician">
            <option value="">Any technician</option>
            <?php if ($canWork): ?><option value="me"<?= $filters['technician'] === 'me' ? ' selected' : '' ?>>Assigned to me</option><?php endif; ?>
            <option value="none"<?= $filters['technician'] === 'none' ? ' selected' : '' ?>>Unassigned</option>
            <?php foreach ($assignees as $a): ?>
                <option value="<?= (int) $a['id'] ?>"<?= $filters['technician'] === (string) $a['id'] ? ' selected' : '' ?>><?= e($a['full_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select class="form-input" name="type" aria-label="Job type">
            <option value="">Any job type</option>
            <?php foreach (MasterData::options('job-types', $filters['type'] !== '' ? (int) $filters['type'] : null) as $jt): ?>
                <option value="<?= (int) $jt['id'] ?>"<?= $filters['type'] === (string) $jt['id'] ? ' selected' : '' ?>><?= e($jt['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select class="form-input" name="priority" aria-label="Priority">
            <option value="">Any priority</option>
            <?php foreach (JobOrders::PRIORITIES as $value => $label): ?>
                <option value="<?= e($value) ?>"<?= $filters['priority'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn--primary">Filter</button>
        <?php if ($pgQuery): ?>
            <a class="btn btn--light" href="<?= e(url('pages/job-orders.php')) ?>">Reset</a>
        <?php endif; ?>
    </form>

    <div class="table-wrap">
        <table class="table table--list jo-table" id="jobTable">
            <thead>
            <tr>
                <th>Job</th>
                <th>Customer</th>
                <th>Device</th>
                <th class="col-opt">Problem</th>
                <th>Technician</th>
                <th class="col-opt">Expected</th>
                <th>Status</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($jobs as $j): ?>
                <?php
                $viewUrl = url('pages/job-view.php?id=' . (int) $j['id'] . '&return=' . rawurlencode($returnTo));
                $device  = trim(($j['brand'] ?? '') . ' ' . ($j['model'] ?? ''));
                $late    = $j['expected_at'] !== null && $j['expected_at'] < $today && in_array($j['status'], JobOrders::OPEN, true);
                ?>
                <tr class="<?= $j['status'] === 'cancelled' ? 'is-void' : '' ?>" data-job="<?= e($j['job_no']) ?>">
                    <td>
                        <a class="item-cell__name doc-no" href="<?= e($viewUrl) ?>"><?= e($j['job_no']) ?></a>
                        <small class="muted block"><?= e(date('M j, Y', strtotime($j['created_at']))) ?><?= Branch::current() === Branch::ALL ? ' · ' . e($j['branch_code']) : '' ?>
                            <?php if ($j['priority'] !== 'normal'): ?><span class="badge <?= e(JobOrders::PRIORITY_BADGES[$j['priority']]) ?> jo-prio"><?= e(JobOrders::PRIORITIES[$j['priority']]) ?></span><?php endif; ?></small>
                    </td>
                    <td><?= e($j['customer_name']) ?><small class="muted block"><?= e($j['customer_phone']) ?></small></td>
                    <td><?= e($device !== '' ? $device : ($j['device_type'] ?? '—')) ?>
                        <small class="muted block"><?= e($device !== '' ? ($j['device_type'] ?? '') : '') ?><?= $j['serial_no'] !== null ? ($device !== '' && $j['device_type'] ? ' · ' : '') . 'S/N ' . e($j['serial_no']) : '' ?></small></td>
                    <td class="col-opt"><small class="doc-reason jo-problem"><?= e($j['problem']) ?></small>
                        <?php if ($j['job_types']): ?><small class="muted block"><?= e($j['job_types']) ?></small><?php endif; ?></td>
                    <td><?= $j['technician_name'] !== null ? e($j['technician_name']) : '<span class="muted">Unassigned</span>' ?>
                        <?php if ($j['helper_names']): ?><small class="muted block" title="Helpers">+ <?= e($j['helper_names']) ?></small><?php endif; ?></td>
                    <td class="col-opt nowrap<?= $late ? ' text-danger' : '' ?>"><?= $j['expected_at'] !== null ? e(date('M j', strtotime($j['expected_at']))) . ($late ? ' <small>(late)</small>' : '') : '<span class="muted">—</span>' ?></td>
                    <td><span class="badge <?= e(JobOrders::BADGES[$j['status']] ?? '') ?>"><?= e(JobOrders::STATUSES[$j['status']] ?? $j['status']) ?></span></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$jobs): ?>
                <tr><td colspan="7" class="empty">No job orders found<?= $pgQuery ? ' for these filters' : ' yet' ?>.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php require ROOT_PATH . '/includes/pagination.php'; ?>
    <?php if (!JobOrders::seesAll()): ?>
        <p class="doc-foot muted"><?= icon('info') ?> <span>You see the jobs assigned to you, the ones you took in and new jobs nobody has taken yet.</span></p>
    <?php endif; ?>
</section>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
