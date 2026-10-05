<?php
/**
 * Job orders & technicians report (reports.view): jobs received / completed / released in the period, turnaround,
 * back-jobs, job revenue (parts + labour from job bills), technician productivity, device types, open jobs by age.
 * CSV export.
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('reports');
$page['title'] = 'Job Orders Report';

$reportPath = 'pages/report-jobs.php';
require ROOT_PATH . '/includes/report-kit.php';

$t       = Reports::jobTotals($from, $to);
$techs   = Reports::jobTechnicians($from, $to);
$devices = Reports::jobsByDevice($from, $to);
$open    = Reports::openJobs(20);
$days    = static fn ($v): string => $v === null ? '—' : number_format((float) $v, 1) . ' days';
$backRate = $t['released'] > 0 ? $t['back_jobs'] / $t['released'] * 100 : null;

if (($_GET['export'] ?? '') === 'csv') {
    $out = $csvStart('job-orders', 'job orders report');
    fputcsv($out, ['Received', 'Completed', 'Released', 'Cancelled', 'Back-jobs', 'Under warranty', 'Open now', 'Avg days to complete', 'Job bills', 'Job revenue (incl. VAT)', 'Parts', 'Labour']);
    fputcsv($out, [$t['received'], $t['completed'], $t['released'], $t['cancelled'], $t['back_jobs'], $t['warranty'], $t['open'],
        $t['avg_days'] === null ? '' : number_format($t['avg_days'], 1, '.', ''), (int) $t['bills'], $t['revenue'], $t['parts'], $t['labor']]);
    fputcsv($out, []);
    fputcsv($out, ['Technician', 'Open now', 'Completed (lead)', 'Helped', 'Avg days', 'Back-jobs', 'Labour billed']);
    foreach ($techs as $r) {
        fputcsv($out, [csv_cell($r['name']), (int) $r['open_now'], (int) $r['completed'], (int) $r['helped'], $r['avg_days'] === null ? '' : number_format((float) $r['avg_days'], 1, '.', ''), (int) $r['back_jobs'], $r['labor']]);
    }
    fclose($out);
    exit;
}

$devMax = max(array_map(static fn ($r) => (int) $r['count'], $devices) ?: [0]);
$kpis = [
    ['Received', 'clipboard', number_format($t['received']), 'jobsReceived', $t['open'] . ' open now'],
    ['Completed', 'check', number_format($t['completed']), 'jobsCompleted', 'avg ' . $days($t['avg_days'])],
    ['Released', 'truck', number_format($t['released']), 'jobsReleased', $t['warranty'] . ' under warranty'],
    ['Back-jobs', 'alert', number_format($t['back_jobs']), 'jobsBack', $pct($backRate) . ' of released'],
    ['Job Revenue', 'wallet', money($t['revenue']), 'jobsRevenue', (int) $t['bills'] . ' bills · incl. VAT'],
];

$pageStyles  = ['css/reports.css'];
$pageScripts = ['js/reports.js'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Reports</h1>
        <p class="muted">Repairs and service: how many came in, how fast they were done, back-jobs and what they earned.</p>
    </div>
    <div class="page-actions no-print">
        <a class="btn btn--light" href="<?= e($reportLink(['from' => $from, 'to' => $to, 'export' => 'csv'])) ?>" id="exportCsv"><?= icon('download') ?> Export CSV</a>
        <button type="button" class="btn btn--light" id="printReport"><?= icon('printer') ?> Print</button>
    </div>
</div>

<?php $reportTab = 'jobs'; require ROOT_PATH . '/includes/reports-nav.php'; ?>
<?php require ROOT_PATH . '/includes/report-filter.php'; ?>

<section class="stats kpis kpis--5" aria-label="Job order summary">
    <?php foreach ($kpis as [$label, $ic, $value, $id, $sub]): ?>
        <div class="stat kpi"><span class="stat__icon"><?= icon($ic) ?></span>
            <div><p class="stat__label"><?= e($label) ?></p><p class="stat__value" id="<?= e($id) ?>"><?= e($value) ?></p><p class="kpi__sub"><?= e($sub) ?></p></div></div>
    <?php endforeach; ?>
</section>
<p class="report-note"><?= icon('info') ?> Job bills: parts <?= e(money($t['parts'])) ?> + labour <?= e(money($t['labor'])) ?> before discount &amp; VAT. Each figure counts the jobs whose step (received, completed, released) falls in the period.</p>

<section class="card report-card">
    <header class="card__head"><h2><?= icon('user') ?> Technicians</h2></header>
    <div class="table-wrap">
        <table class="table report-table" id="jobTechnicians">
            <thead><tr><th>Technician</th><th class="num">Open now</th><th class="num">Completed (lead)</th><th class="num">Helped</th><th class="num">Avg time</th><th class="num">Back-jobs</th><th class="num">Labour billed</th></tr></thead>
            <tbody>
            <?php foreach ($techs as $r): ?>
                <tr data-tech="<?= e($r['name']) ?>"><td><?= e($r['name']) ?></td><td class="num"><?= (int) $r['open_now'] ?></td><td class="num"><?= (int) $r['completed'] ?></td><td class="num"><?= (int) $r['helped'] ?></td>
                    <td class="num"><?= e($days($r['avg_days'])) ?></td><td class="num<?= (int) $r['back_jobs'] > 0 ? ' is-neg' : '' ?>"><?= (int) $r['back_jobs'] ?></td>
                    <td class="num"><?= e(money($r['labor'])) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$techs): ?><tr><td colspan="7" class="empty">No jobs have been assigned yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <p class="card__sub muted">Back-jobs are counted against the technician of the original job.</p>
</section>

<div class="report-grid">
    <section class="card report-card">
        <header class="card__head"><h2><?= icon('laptop') ?> Jobs Received by Device</h2></header>
        <?php if ($devices): ?>
            <ul class="barlist" id="jobDevices">
                <?php foreach ($devices as $d): ?>
                    <?php $barRow($d['name'], null, number_format((int) $d['count']), $devMax > 0 ? (int) $d['count'] / $devMax : 0, $share((float) $d['count'], (float) $t['received']) . ' of jobs received'); ?>
                <?php endforeach; ?>
            </ul>
        <?php else: ?><p class="chart-empty">No jobs received in this period.</p><?php endif; ?>
    </section>

    <section class="card report-card">
        <header class="card__head"><h2><?= icon('clock') ?> Oldest Open Jobs</h2><a class="btn btn--sm btn--light no-print" href="<?= e(url('pages/job-orders.php')) ?>">Open Job Orders</a></header>
        <div class="table-wrap">
            <table class="table report-table" id="openJobs">
                <thead><tr><th>Job</th><th>Status</th><th class="col-opt">Technician</th><th class="num">Days open</th></tr></thead>
                <tbody>
                <?php foreach ($open as $j): ?>
                    <?php $late = $j['expected_at'] !== null && $j['expected_at'] < date('Y-m-d'); ?>
                    <tr><td><a class="doc-no" href="<?= e(url('pages/job-view.php?id=' . (int) $j['id'])) ?>"><?= e($j['job_no']) ?></a><small class="muted block"><?= e($j['customer_name']) ?></small></td>
                        <td><span class="badge <?= e(JobOrders::BADGES[$j['status']] ?? '') ?>"><?= e(JobOrders::STATUSES[$j['status']] ?? $j['status']) ?></span></td>
                        <td class="col-opt"><?= e($j['technician_name'] ?? 'Unassigned') ?></td>
                        <td class="num<?= $late ? ' is-neg' : '' ?>"><?= (int) $j['days_open'] ?><?= $late ? ' <small>(late)</small>' : '' ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$open): ?><tr><td colspan="4" class="empty"><?= icon('check') ?> No open jobs.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<div class="chart-tip" id="chartTip" role="status" hidden>
    <strong class="chart-tip__value"></strong>
    <span class="chart-tip__title"></span>
    <small class="chart-tip__note"></small>
</div>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
