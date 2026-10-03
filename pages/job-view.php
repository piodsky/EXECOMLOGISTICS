<?php
/**
 * One job order: next step (per JobOrders::actions()), device / problem, diagnosis & quotation, work done,
 * timeline + notes, customer, assignment, warranty (our sale of the serial). Print = job ticket + claim stub.
 * Every action posts here (PRG) and is re-checked in JobOrders::act().
 * pages/job-view.php?id=5&return=job-orders.php
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('job-orders');

$id  = input_int($_GET, 'id', 1) ?? throw new HttpException(404, 'Job order not found.');
$job = JobOrders::find($id) ?? throw new HttpException(404, 'Job order not found.');

$returnTo = safe_return($_POST['return'] ?? $_GET['return'] ?? null, 'job-orders.php');
$self     = 'job-view.php?' . http_build_query(['id' => $id, 'return' => $returnTo]);

if (is_post()) {
    Csrf::verifyRequest();
    $action = input_string($_POST, 'action', 20);
    try {
        flash('success', JobOrders::act($id, $action, $_POST, (int) Auth::id()));
    } catch (HttpException $e) {
        if ($e->status === 404) {
            throw $e;
        }
        if (is_array($e->details['errors'] ?? null)) {
            flash_errors($e->details['errors']);
        }
        flash_old(array_filter($_POST, 'is_string'));
        flash('error', $e->getMessage());
    }
    redirect('pages/' . $self);
}

$no      = (string) $job['job_no'];
$status  = (string) $job['status'];
$act     = JobOrders::actions($job);
$oldAct  = has_old() ? old('action') : '';
$page['title'] = $no;
$when    = static fn (?string $ts): string => $ts ? date('M j, Y g:i A', strtotime($ts)) : '—';
$day     = static fn (?string $d): string => $d ? date('M j, Y', strtotime($d)) : '—';
$device  = trim(($job['brand'] ?? '') . ' ' . ($job['model'] ?? ''));
$sold    = $job['sold'];
$inWarranty = $job['warranty_until'] !== null && $job['warranty_until'] >= substr((string) $job['created_at'], 0, 10);
$technicians = $act['assign'] ? JobOrders::technicians((int) $job['branch_id']) : [];
$threshold   = JobOrders::quoteThreshold();
$hasStep = $act['take'] || $act['start'] || $act['diagnose'] || $act['decision'] || $act['wait_parts'] || $act['resume']
        || $act['to_testing'] || $act['test_failed'] || $act['complete'];
$hidden = static function (string $action) use ($returnTo): string {
    return Csrf::field() . '<input type="hidden" name="action" value="' . e($action) . '"><input type="hidden" name="return" value="' . e($returnTo) . '">';
};
$actionUrl = url('pages/' . $self);
$eventLabels = [
    'create' => 'Job order created', 'assign' => 'Assigned', 'take' => 'Taken', 'start' => 'Diagnosis started',
    'diagnose' => 'Diagnosed', 'decision' => 'Customer decision', 'wait_parts' => 'Waiting for parts', 'resume' => 'Repair resumed',
    'to_testing' => 'Ready for testing', 'test_failed' => 'Test failed', 'complete' => 'Completed', 'cancel' => 'Cancelled',
    'note' => 'Note', 'edit' => 'Intake edited',
];

$pageStyles  = ['css/sales.css', 'css/receiving.css', 'css/stock-docs.css', 'css/jobs.css'];
$pageScripts = ['js/jobs.js'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="jo-screen">
<div class="page-head">
    <div>
        <a class="back-link" href="<?= e(url('pages/' . $returnTo)) ?>"><?= icon('arrow-left') ?> Job Orders</a>
        <h1 class="sale-title">
            <span id="jobTitle"><?= e($no) ?></span>
            <span class="badge <?= e(JobOrders::BADGES[$status] ?? '') ?>" id="jobStatus"><?= e(JobOrders::STATUSES[$status] ?? $status) ?></span>
            <?php if ($job['priority'] !== 'normal'): ?><span class="badge <?= e(JobOrders::PRIORITY_BADGES[$job['priority']]) ?>"><?= e(JobOrders::PRIORITIES[$job['priority']]) ?> priority</span><?php endif; ?>
        </h1>
        <p class="muted"><?= e($job['branch_code'] . ' · ' . $job['branch_name']) ?> · received <?= e($when($job['created_at'])) ?> by <?= e($job['created_by_name']) ?></p>
    </div>
    <div class="page-actions">
        <button type="button" class="btn btn--light" data-print id="printTicketBtn"><?= icon('printer') ?> Print Ticket</button>
        <?php if ($act['edit']): ?>
            <a class="btn btn--light" href="<?= e(url('pages/job-form.php?' . http_build_query(['id' => $id, 'return' => $returnTo]))) ?>" id="editJobBtn"><?= icon('edit') ?> Edit</a>
        <?php endif; ?>
        <?php if ($act['cancel']): ?>
            <button type="button" class="btn btn--danger" data-open="cancelDialog" id="cancelJobBtn"><?= icon('x') ?> Cancel Job</button>
        <?php endif; ?>
    </div>
</div>

<?php if ($status === 'cancelled'): ?>
    <div class="void-box" role="note"><?= icon('alert') ?>
        <div><strong>This job order was cancelled</strong> on <?= e($when($job['cancelled_at'])) ?><?= $job['cancelled_by_name'] ? ' by ' . e($job['cancelled_by_name']) : '' ?>.
            <?php if ($job['cancel_reason']): ?><p class="void-box__reason">Reason: <?= e($job['cancel_reason']) ?></p><?php endif; ?></div>
    </div>
<?php elseif ($status === 'completed'): ?>
    <div class="alert alert--success doc-note" role="note"><?= icon('check') ?>
        <span><?= $job['approval'] === 'declined' ? 'The customer declined the quotation. Return the device unrepaired.' : 'Repair completed. The device is ready to return to the customer.' ?>
            Release with payment comes in the next update.</span></div>
<?php elseif ($status === 'new' && !$act['take'] && !$act['assign']): ?>
    <div class="alert alert--warning doc-note" role="note"><?= icon('clock') ?><span>Waiting for a technician to take this job.</span></div>
<?php elseif (!$hasStep && in_array($status, JobOrders::OPEN, true)): ?>
    <div class="alert alert--info doc-note" role="note"><?= icon('info') ?>
        <span><?= $job['technician_name'] !== null ? e($job['technician_name']) . ' is working on this job.' : 'Not assigned yet.' ?>
            <?= Branch::current() !== (int) $job['branch_id'] ? 'Switch to branch ' . e($job['branch_name']) . ' to work on it.' : '' ?></span></div>
<?php endif; ?>

<div class="sale-layout">
    <div class="form-stack">
        <?php if ($hasStep): ?>
            <section class="card card--pad jo-step" id="nextStep">
                <h2 class="card__title"><?= icon('wrench') ?> Next Step</h2>

                <?php if ($act['take']): ?>
                    <form method="post" action="<?= e($actionUrl) ?>" class="jo-step__row">
                        <?= $hidden('take') ?>
                        <p class="muted">Nobody has taken this job yet.</p>
                        <button type="submit" class="btn btn--primary" id="takeJobBtn"><?= icon('check') ?> Take This Job</button>
                    </form>
                <?php endif; ?>

                <?php if ($act['start']): ?>
                    <form method="post" action="<?= e($actionUrl) ?>" class="jo-step__row">
                        <?= $hidden('start') ?>
                        <p class="muted">Check the device and find the cause of the problem.</p>
                        <button type="submit" class="btn btn--primary" id="startBtn"><?= icon('search') ?> Start Diagnosis</button>
                    </form>
                <?php endif; ?>

                <?php if ($act['diagnose']): ?>
                    <form method="post" action="<?= e($actionUrl) ?>" id="diagnoseForm" novalidate>
                        <?= $hidden('diagnose') ?>
                        <div class="form-grid">
                            <label class="form-field form-field--full">
                                <span class="form-label">Diagnosis *</span>
                                <textarea class="form-input" name="diagnosis" maxlength="2000" rows="3" required placeholder="What is wrong and what needs to be done"<?= invalid('diagnosis') ?>><?= e($oldAct === 'diagnose' ? old('diagnosis') : '') ?></textarea>
                                <?= field_error('diagnosis') ?>
                            </label>
                            <label class="form-field">
                                <span class="form-label">Estimate (parts + labour, ₱)</span>
                                <input class="form-input num" name="estimate" inputmode="decimal" maxlength="13" placeholder="0.00" value="<?= e($oldAct === 'diagnose' ? old('estimate') : '') ?>"<?= invalid('estimate') ?>>
                                <?= field_error('estimate') ?>
                                <p class="form-hint">Above <?= e(money($threshold)) ?> the customer must approve before the repair starts.</p>
                            </label>
                            <label class="form-field jo-check">
                                <input type="checkbox" name="ask_customer" value="1"<?= $oldAct === 'diagnose' && old('ask_customer') === '1' ? ' checked' : '' ?>>
                                <span>Ask the customer anyway</span>
                            </label>
                        </div>
                        <div class="form-actions form-actions--inline">
                            <button type="submit" class="btn btn--primary" id="diagnoseBtn"><?= icon('save') ?> Save Diagnosis</button>
                        </div>
                    </form>
                <?php endif; ?>

                <?php if ($act['decision']): ?>
                    <form method="post" action="<?= e($actionUrl) ?>" id="decisionForm" novalidate>
                        <?= $hidden('decision') ?>
                        <p class="jo-quote">Estimate: <strong><?= e(money($job['estimate'] ?? 0)) ?></strong>. Record the customer's answer.</p>
                        <div class="form-grid">
                            <fieldset class="form-field form-field--full jo-radios">
                                <legend class="form-label">Decision *</legend>
                                <label><input type="radio" name="decision" value="approve"<?= $oldAct === 'decision' && old('decision') === 'approve' ? ' checked' : '' ?>> Approved: go ahead with the repair</label>
                                <label><input type="radio" name="decision" value="decline"<?= $oldAct === 'decision' && old('decision') === 'decline' ? ' checked' : '' ?>> Declined: return the device unrepaired</label>
                                <?= field_error('decision') ?>
                            </fieldset>
                            <label class="form-field">
                                <span class="form-label">How *</span>
                                <select class="form-input" name="method"<?= invalid('method') ?>>
                                    <option value="">Choose…</option>
                                    <?php foreach (JobOrders::METHODS as $k => $label): ?>
                                        <option value="<?= e($k) ?>"<?= $oldAct === 'decision' && old('method') === $k ? ' selected' : '' ?>><?= e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?= field_error('method') ?>
                            </label>
                            <label class="form-field">
                                <span class="form-label">Answered by *</span>
                                <input class="form-input" name="by_name" maxlength="100" value="<?= e($oldAct === 'decision' ? old('by_name') : (string) $job['customer_name']) ?>"<?= invalid('by_name') ?>>
                                <?= field_error('by_name') ?>
                            </label>
                            <label class="form-field form-field--full">
                                <span class="form-label">Note <small class="muted">(optional)</small></span>
                                <input class="form-input" name="note" maxlength="255" value="<?= e($oldAct === 'decision' ? old('note') : '') ?>"<?= invalid('note') ?>>
                                <?= field_error('note') ?>
                            </label>
                        </div>
                        <div class="form-actions form-actions--inline">
                            <button type="submit" class="btn btn--primary" id="decisionBtn"><?= icon('check') ?> Record Decision</button>
                        </div>
                    </form>
                <?php endif; ?>

                <?php if ($act['to_testing'] || $act['wait_parts'] || $act['resume'] || $act['test_failed'] || $act['complete']): ?>
                    <div class="jo-step__buttons">
                        <?php if ($act['to_testing']): ?>
                            <form method="post" action="<?= e($actionUrl) ?>"><?= $hidden('to_testing') ?>
                                <button type="submit" class="btn btn--primary" id="toTestingBtn"><?= icon('check') ?> Repair Done: Test It</button></form>
                        <?php endif; ?>
                        <?php if ($act['wait_parts']): ?>
                            <button type="button" class="btn btn--light" data-open="partsDialog" id="waitPartsBtn"><?= icon('box') ?> Waiting for Parts</button>
                        <?php endif; ?>
                        <?php if ($act['resume']): ?>
                            <form method="post" action="<?= e($actionUrl) ?>"><?= $hidden('resume') ?>
                                <button type="submit" class="btn btn--primary" id="resumeBtn"><?= icon('wrench') ?> Parts Arrived: Resume Repair</button></form>
                        <?php endif; ?>
                        <?php if ($act['complete']): ?>
                            <button type="button" class="btn btn--primary" data-open="completeDialog" id="completeBtn"><?= icon('check') ?> Test Passed: Complete</button>
                        <?php endif; ?>
                        <?php if ($act['test_failed']): ?>
                            <button type="button" class="btn btn--light" data-open="failDialog" id="testFailedBtn"><?= icon('x') ?> Test Failed</button>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <section class="card card--pad">
            <h2 class="card__title">Device &amp; Problem</h2>
            <dl class="detail-list jo-details">
                <div><dt>Device</dt><dd><?= e($job['device_type'] ?? '—') ?><?= $device !== '' ? ' · ' . e($device) : '' ?></dd></div>
                <div><dt>Serial no.</dt><dd class="serial-cell"><?= e($job['serial_no'] ?? '—') ?></dd></div>
                <div><dt>Job type</dt><dd><?= e($job['job_type'] ?? '—') ?></dd></div>
                <div><dt>Service</dt><dd><?= e(JobOrders::LOCATIONS[$job['service_location']] ?? '') ?></dd></div>
                <div><dt>Accessories</dt><dd><?= e($job['accessories'] ?? 'None') ?></dd></div>
                <div><dt>Condition</dt><dd><?= e($job['device_condition'] ?? '—') ?></dd></div>
                <div><dt>Expected</dt><dd><?= e($day($job['expected_at'])) ?></dd></div>
            </dl>
            <p class="rr-notes"><span class="form-label">Problem reported</span><span class="jo-memo"><?= e($job['problem']) ?></span></p>
            <?php if ($job['remarks']): ?><p class="rr-notes"><span class="form-label">Remarks</span><span class="jo-memo"><?= e($job['remarks']) ?></span></p><?php endif; ?>
        </section>

        <?php if ($job['diagnosis'] !== null): ?>
            <section class="card card--pad" id="diagnosisCard">
                <h2 class="card__title">Diagnosis &amp; Quotation</h2>
                <p class="jo-memo"><?= e($job['diagnosis']) ?></p>
                <dl class="detail-list jo-details">
                    <div><dt>Estimate</dt><dd id="jobEstimate"><?= e(money($job['estimate'] ?? 0)) ?></dd></div>
                    <div><dt>Diagnosed</dt><dd><?= e($when($job['diagnosed_at'])) ?></dd></div>
                    <div><dt>Customer approval</dt><dd id="jobApproval"><?= e(match ($job['approval']) {
                        'not_needed' => 'Not needed', 'pending' => 'Waiting for the customer', 'approved' => 'Approved', 'declined' => 'Declined', default => '—',
                    }) ?><?php if ($job['approval_at']): ?><small class="muted block"><?= e($job['approval_by_name'] . ' · ' . strtolower(JobOrders::METHODS[$job['approval_method']] ?? '') . ' · ' . $when($job['approval_at'])) ?>
                        · recorded by <?= e($job['approval_recorded_by_name'] ?? '—') ?></small><?php endif; ?></dd></div>
                </dl>
                <?php if ($job['approval_note']): ?><p class="rr-notes"><span class="form-label">Customer's note</span><?= e($job['approval_note']) ?></p><?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if ($job['resolution'] !== null): ?>
            <section class="card card--pad" id="resolutionCard">
                <h2 class="card__title">Work Done</h2>
                <p class="jo-memo"><?= e($job['resolution']) ?></p>
                <p class="muted"><?= e($when($job['completed_at'])) ?><?= $job['completed_by_name'] ? ' · ' . e($job['completed_by_name']) : '' ?></p>
            </section>
        <?php endif; ?>

        <section class="card card--pad" id="historyCard">
            <h2 class="card__title">History</h2>
            <?php if ($act['note']): ?>
                <form method="post" action="<?= e($actionUrl) ?>" class="jo-note-form" id="noteForm" novalidate>
                    <?= $hidden('note') ?>
                    <label class="form-field">
                        <span class="visually-hidden">Add a note</span>
                        <textarea class="form-input" name="note" maxlength="2000" rows="2" placeholder="Add a note (e.g. called the customer, found a second fault)"<?= $oldAct === 'note' ? invalid('note') : '' ?>><?= e($oldAct === 'note' ? old('note') : '') ?></textarea>
                        <?= $oldAct === 'note' ? field_error('note') : '' ?>
                    </label>
                    <button type="submit" class="btn btn--light btn--sm" id="addNoteBtn"><?= icon('plus') ?> Add Note</button>
                </form>
            <?php endif; ?>
            <ol class="jo-timeline" id="jobTimeline">
                <?php foreach ($job['events'] as $ev): ?>
                    <li class="jo-event jo-event--<?= e($ev['action']) ?>">
                        <div class="jo-event__head">
                            <strong><?= e($eventLabels[$ev['action']] ?? $ev['action']) ?></strong>
                            <?php if ($ev['to_status'] !== null): ?><span class="badge <?= e(JobOrders::BADGES[$ev['to_status']] ?? '') ?>"><?= e(JobOrders::STATUSES[$ev['to_status']] ?? $ev['to_status']) ?></span><?php endif; ?>
                            <small class="muted"><?= e($ev['user_name']) ?> · <?= e($when($ev['created_at'])) ?></small>
                        </div>
                        <?php if ($ev['note'] !== null): ?><p class="jo-memo"><?= e($ev['note']) ?></p><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        </section>
    </div>

    <aside class="form-side">
        <section class="card card--pad">
            <h2 class="card__title">Customer</h2>
            <dl class="detail-list">
                <div><dt>Name</dt><dd id="jobCustomer"><?php if ($job['customer_id'] !== null && Auth::can('customers.view')): ?><a href="<?= e(url('pages/customer-form.php?id=' . (int) $job['customer_id'])) ?>"><?= e($job['customer_name']) ?></a><?php else: ?><?= e($job['customer_name']) ?><?php endif; ?></dd></div>
                <div><dt>Contact</dt><dd><?= e($job['customer_phone']) ?></dd></div>
                <?php if ($job['contact_person']): ?><div><dt>Contact person</dt><dd><?= e($job['contact_person']) ?></dd></div><?php endif; ?>
            </dl>
        </section>

        <section class="card card--pad" id="assignCard">
            <h2 class="card__title">Technician</h2>
            <p id="jobTechnician"><?= $job['technician_name'] !== null ? e($job['technician_name']) : '<span class="muted">Not assigned</span>' ?>
                <?php if ($job['assigned_at']): ?><small class="muted block">since <?= e($when($job['assigned_at'])) ?></small><?php endif; ?></p>
            <?php if ($act['assign']): ?>
                <form method="post" action="<?= e($actionUrl) ?>" class="jo-assign" id="assignForm" novalidate>
                    <?= $hidden('assign') ?>
                    <label class="form-field">
                        <span class="form-label"><?= $job['technician_id'] === null ? 'Assign to' : 'Reassign to' ?></span>
                        <select class="form-input" name="technician_id" id="assignTech"<?= $oldAct === 'assign' ? invalid('technician_id') : '' ?>>
                            <option value="">Choose…</option>
                            <?php foreach ($technicians as $t): ?>
                                <?php if ((int) $t['id'] === (int) $job['technician_id']) continue; ?>
                                <option value="<?= (int) $t['id'] ?>"><?= e($t['full_name']) ?> · <?= (int) $t['open_jobs'] ?> open</option>
                            <?php endforeach; ?>
                        </select>
                        <?= $oldAct === 'assign' ? field_error('technician_id') : '' ?>
                    </label>
                    <button type="submit" class="btn btn--light btn--sm" id="assignBtn"><?= icon('user') ?> Assign</button>
                    <?php if (!$technicians): ?><p class="form-hint">No active user of this branch has the "work on assigned jobs" permission.</p><?php endif; ?>
                </form>
            <?php endif; ?>
        </section>

        <section class="card card--pad" id="warrantyCard">
            <h2 class="card__title">Warranty</h2>
            <?php if ($sold !== null): ?>
                <p><span class="badge <?= $inWarranty ? 'badge--success' : 'badge--danger' ?>" id="warrantyBadge"><?= $inWarranty ? 'Under warranty' : ($job['warranty_until'] !== null ? 'Warranty expired' : 'No warranty') ?></span></p>
                <dl class="detail-list">
                    <div><dt>Sold by us</dt><dd><?= e($sold['product_code'] . ' · ' . $sold['product_name']) ?></dd></div>
                    <div><dt>Sale</dt><dd><?php if (Auth::can('sales.view') && Branch::inScope((int) $sold['branch_id'])): ?><a href="<?= e(url('pages/sale-view.php?id=' . (int) $sold['sale_id'])) ?>">No. <?= e($sold['sale_no']) ?></a><?php else: ?>No. <?= e($sold['sale_no']) ?><?php endif; ?>
                        <small class="muted block"><?= e($day($sold['sold_at'])) ?></small></dd></div>
                    <?php if ($job['warranty_until'] !== null): ?><div><dt>Warranty until</dt><dd><?= e($day($job['warranty_until'])) ?></dd></div><?php endif; ?>
                </dl>
            <?php else: ?>
                <p class="muted" id="warrantyBadge"><?= $job['serial_no'] !== null ? 'This serial number was not sold by us.' : 'No serial number recorded.' ?></p>
            <?php endif; ?>
        </section>
    </aside>
</div>
</div>

<?php // ---------------- Printed job ticket + claim stub (print only) ---------------- ?>
<section class="jo-ticket" aria-hidden="true">
    <header class="jo-ticket__head">
        <div>
            <strong><?= e(setting('shop_name', 'EXECOM Logistics')) ?></strong>
            <span><?= e($job['branch_name']) ?><?= $job['branch_address'] ? ' · ' . e($job['branch_address']) : '' ?></span>
            <?php if ($job['branch_contact']): ?><span>Tel: <?= e($job['branch_contact']) ?></span><?php endif; ?>
        </div>
        <div class="jo-ticket__no"><span>JOB ORDER</span><strong><?= e($no) ?></strong><span><?= e($when($job['created_at'])) ?></span></div>
    </header>
    <table class="jo-ticket__grid">
        <tr><th>Customer</th><td><?= e($job['customer_name']) ?></td><th>Contact</th><td><?= e($job['customer_phone']) ?><?= $job['contact_person'] ? ' (' . e($job['contact_person']) . ')' : '' ?></td></tr>
        <tr><th>Device</th><td><?= e(trim(($job['device_type'] ?? '') . ' ' . $device)) ?></td><th>Serial no.</th><td><?= e($job['serial_no'] ?? '—') ?></td></tr>
        <tr><th>Accessories</th><td><?= e($job['accessories'] ?? 'None') ?></td><th>Condition</th><td><?= e($job['device_condition'] ?? '—') ?></td></tr>
        <tr><th>Job type</th><td><?= e($job['job_type'] ?? '—') ?></td><th>Expected</th><td><?= e($day($job['expected_at'])) ?></td></tr>
        <tr><th>Problem</th><td colspan="3" class="jo-memo"><?= e($job['problem']) ?></td></tr>
        <?php if ($job['remarks']): ?><tr><th>Remarks</th><td colspan="3" class="jo-memo"><?= e($job['remarks']) ?></td></tr><?php endif; ?>
        <?php if ($job['warranty_until'] !== null): ?><tr><th>Warranty</th><td colspan="3"><?= $inWarranty ? 'Under warranty' : 'Expired' ?> (until <?= e($day($job['warranty_until'])) ?>)</td></tr><?php endif; ?>
    </table>
    <p class="jo-ticket__terms">Repairs estimated above <?= e(money($threshold)) ?> start only after the customer approves the quotation.</p>
    <div class="jo-ticket__signs">
        <div><span>Customer's signature</span></div>
        <div><span>Received by (<?= e($job['created_by_name']) ?>)</span></div>
    </div>
    <div class="jo-ticket__cut"><span>✂ cut here · give this stub to the customer</span></div>
    <div class="jo-stub">
        <div><strong>CLAIM STUB</strong><span><?= e(setting('shop_name', 'EXECOM Logistics')) ?> · <?= e($job['branch_name']) ?></span>
            <?php if ($job['branch_contact']): ?><span>Tel: <?= e($job['branch_contact']) ?></span><?php endif; ?></div>
        <div class="jo-ticket__no"><strong><?= e($no) ?></strong><span><?= e($day($job['created_at'])) ?></span></div>
        <p><?= e($job['customer_name']) ?> · <?= e(trim(($job['device_type'] ?? '') . ' ' . $device)) ?><?= $job['serial_no'] ? ' · S/N ' . e($job['serial_no']) : '' ?></p>
        <p class="jo-ticket__terms">Present this stub to claim the device.</p>
    </div>
</section>

<?php if ($act['wait_parts']): ?>
    <dialog class="modal" id="partsDialog" aria-labelledby="partsTitle"<?= $oldAct === 'wait_parts' ? ' data-reopen' : '' ?>>
        <form class="modal__body" method="post" action="<?= e($actionUrl) ?>" novalidate>
            <header class="modal__head"><h2 id="partsTitle">Waiting for Parts</h2>
                <button type="button" class="modal__close" data-close aria-label="Close"><?= icon('x') ?></button></header>
            <?= $hidden('wait_parts') ?>
            <label class="form-field"><span class="form-label">Parts needed *</span>
                <textarea class="form-input" name="note" maxlength="2000" rows="3" required placeholder="e.g. Keyboard for Lenovo IdeaPad 3, ordered from the supplier"<?= $oldAct === 'wait_parts' ? invalid('note') : '' ?>><?= e($oldAct === 'wait_parts' ? old('note') : '') ?></textarea>
                <?= $oldAct === 'wait_parts' ? field_error('note') : '' ?></label>
            <footer class="modal__foot"><button type="button" class="btn btn--light" data-close>Back</button>
                <button type="submit" class="btn btn--primary"><?= icon('box') ?> Waiting for Parts</button></footer>
        </form>
    </dialog>
<?php endif; ?>

<?php if ($act['test_failed']): ?>
    <dialog class="modal" id="failDialog" aria-labelledby="failTitle"<?= $oldAct === 'test_failed' ? ' data-reopen' : '' ?>>
        <form class="modal__body" method="post" action="<?= e($actionUrl) ?>" novalidate>
            <header class="modal__head"><h2 id="failTitle">Test Failed</h2>
                <button type="button" class="modal__close" data-close aria-label="Close"><?= icon('x') ?></button></header>
            <?= $hidden('test_failed') ?>
            <label class="form-field"><span class="form-label">What failed *</span>
                <textarea class="form-input" name="note" maxlength="2000" rows="3" required<?= $oldAct === 'test_failed' ? invalid('note') : '' ?>><?= e($oldAct === 'test_failed' ? old('note') : '') ?></textarea>
                <?= $oldAct === 'test_failed' ? field_error('note') : '' ?></label>
            <footer class="modal__foot"><button type="button" class="btn btn--light" data-close>Back</button>
                <button type="submit" class="btn btn--primary"><?= icon('wrench') ?> Back to Repair</button></footer>
        </form>
    </dialog>
<?php endif; ?>

<?php if ($act['complete']): ?>
    <dialog class="modal" id="completeDialog" aria-labelledby="completeTitle"<?= $oldAct === 'complete' ? ' data-reopen' : '' ?>>
        <form class="modal__body" method="post" action="<?= e($actionUrl) ?>" novalidate>
            <header class="modal__head"><h2 id="completeTitle">Complete <?= e($no) ?></h2>
                <button type="button" class="modal__close" data-close aria-label="Close"><?= icon('x') ?></button></header>
            <?= $hidden('complete') ?>
            <label class="form-field"><span class="form-label">Work done *</span>
                <textarea class="form-input" name="resolution" id="resolutionInput" maxlength="2000" rows="4" required placeholder="What was repaired or replaced, and the test result"<?= invalid('resolution') ?>><?= e($oldAct === 'complete' ? old('resolution') : '') ?></textarea>
                <?= field_error('resolution') ?></label>
            <footer class="modal__foot"><button type="button" class="btn btn--light" data-close>Back</button>
                <button type="submit" class="btn btn--primary" id="completeSubmit"><?= icon('check') ?> Complete Job</button></footer>
        </form>
    </dialog>
<?php endif; ?>

<?php if ($act['cancel']): ?>
    <dialog class="modal" id="cancelDialog" aria-labelledby="cancelTitle"<?= $oldAct === 'cancel' ? ' data-reopen' : '' ?>>
        <form class="modal__body" method="post" action="<?= e($actionUrl) ?>" data-confirm="Cancel <?= e($no) ?>? This cannot be undone." novalidate>
            <header class="modal__head"><h2 id="cancelTitle">Cancel <?= e($no) ?>?</h2>
                <button type="button" class="modal__close" data-close aria-label="Close"><?= icon('x') ?></button></header>
            <?= $hidden('cancel') ?>
            <label class="form-field"><span class="form-label">Reason *</span>
                <textarea class="form-input" name="reason" id="cancelReason" required minlength="3" maxlength="255" placeholder="e.g. Customer took the device back before diagnosis"<?= invalid('reason') ?>><?= e($oldAct === 'cancel' ? old('reason') : '') ?></textarea>
                <?= field_error('reason') ?></label>
            <footer class="modal__foot"><button type="button" class="btn btn--light" data-close>Keep Job</button>
                <button type="submit" class="btn btn--danger"><?= icon('x') ?> Cancel Job</button></footer>
        </form>
    </dialog>
<?php endif; ?>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
