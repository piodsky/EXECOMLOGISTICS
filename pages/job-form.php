<?php
/**
 * Job order intake: new (job_orders.create, concrete branch) or edit the intake details of an open job
 * (?id=, job_orders.create / job_orders.assign; JobOrders::actions()['edit']). PRG with flash_old / flash_errors.
 * A supervisor (job_orders.assign) may assign the technician at intake.
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('job-orders');

$id  = input_int($_GET, 'id', 1);
$job = $id !== null ? (JobOrders::find($id) ?? throw new HttpException(404, 'Job order not found.')) : null;
if ($job === null) {
    Auth::requirePermission('job_orders.create');
} elseif (!JobOrders::actions($job)['edit']) {
    throw new HttpException(403, 'You cannot edit this job order.');
}

// Back-job (?parent=ID): a new job for a released job's device, prefilled from it (re-checked in JobOrders::create).
$parentId = $job === null ? input_int($_GET, 'parent', 1) : null;
$parent   = $parentId !== null ? (JobOrders::find($parentId) ?? throw new HttpException(404, 'Job order not found.')) : null;
if ($parent !== null && !JobOrders::actions($parent)['back_job']) {
    throw new HttpException(403, 'A back-job can only be opened for a released job of your branch.');
}

$returnTo = safe_return($_POST['return'] ?? $_GET['return'] ?? null, 'job-orders.php');
$self     = 'job-form.php?' . http_build_query(array_filter(['id' => $id, 'parent' => $parentId, 'return' => $returnTo]));
$page['title'] = $job === null ? ($parent !== null ? 'New Back-Job' : 'New Job Order') : 'Edit ' . $job['job_no'];

if (is_post()) {
    Csrf::verifyRequest();
    try {
        if ($job === null) {
            $res = JobOrders::create($_POST, (int) Auth::id());
            flash('success', "Job order {$res['job_no']} was created. Print the ticket and give the claim stub to the customer.");
            redirect('pages/job-view.php?' . http_build_query(['id' => $res['id'], 'return' => $returnTo]));
        }
        $no = JobOrders::update((int) $id, $_POST, (int) Auth::id());
        flash('success', "{$no} was saved.");
        redirect('pages/job-view.php?' . http_build_query(['id' => $id, 'return' => $returnTo]));
    } catch (HttpException $e) {
        if ($e->status === 403 || $e->status === 404) {
            throw $e;
        }
        // Checkbox arrays (job types, helpers, accessories / condition ticks) are kept as newline lists.
        flash_old(array_map(static fn ($v): string => is_array($v) ? implode("\n", array_filter($v, 'is_string')) : (string) $v,
            array_filter($_POST, static fn ($v): bool => is_string($v) || is_array($v))));
        if (is_array($e->details['errors'] ?? null)) {
            flash_errors($e->details['errors']);
            flash('error', count($e->details['errors']) > 1 ? 'Please fix the highlighted fields.' : $e->getMessage());
        } else {
            flash('error', $e->getMessage());
        }
        redirect('pages/' . $self);
    }
}

$concrete = Branch::isConcrete();
$src      = $job ?? $parent; // a back-job starts from the parent's customer + device (not its problem)
$cur      = static fn (string $k): string => $src !== null && ($job !== null || !in_array($k, ['problem', 'remarks', 'expected_at', 'job_type_id', 'priority', 'service_location', 'accessories', 'device_condition'], true))
                                             && $src[$k] !== null ? (string) $src[$k] : '';
$val      = static fn (string $k, string $default = ''): string => old($k, $src !== null && $cur($k) !== '' ? $cur($k) : ($job !== null ? '' : $default));

$customers = Customers::active();
if ($src !== null && $src['customer_id'] !== null && !in_array((int) $src['customer_id'], array_map('intval', array_column($customers, 'id')), true)) {
    $customers[] = ['id' => $src['customer_id'], 'name' => $src['customer_name'] . ' (inactive)', 'phone' => $src['customer_phone']];
}
$deviceTypes = MasterData::options('device-types', $job !== null && $job['device_type_id'] !== null ? (int) $job['device_type_id'] : null);
$technicians = $job === null && $concrete && Auth::can('job_orders.assign') ? JobOrders::technicians((int) Branch::current()) : [];

// Checklists (Master Data). Job types: active ones + the job's current ones (even if deactivated since).
$jobTypes = array_column(MasterData::options('job-types'), 'name', 'id');
foreach ($job['job_types'] ?? [] as $t) {
    $jobTypes[$t['id']] ??= $t['name'];
}
$accessoryNames = array_column(MasterData::options('accessories'), 'name');
$conditionNames = array_column(MasterData::options('conditions'), 'name');
$problemNames   = array_column(MasterData::options('problems'), 'name');
$oldList = static fn (string $k): array => has_old() ? array_values(array_filter(explode("\n", old($k)), static fn ($v) => $v !== '')) : [];
// [ticked names, other text] of a checklist field: after a failed submit from old input, else from the job being edited.
$picks = static function (string $field, array $names) use ($job, $oldList): array {
    if (has_old()) {
        return [$oldList("{$field}_pick"), old("{$field}_other")];
    }
    return JobOrders::splitPicks($job[$field] ?? null, $names);
};
[$accTicked, $accOther]   = $picks('accessories', $accessoryNames);
[$condTicked, $condOther] = $picks('device_condition', $conditionNames);
$typeTicked   = array_map('intval', has_old() ? $oldList('job_type_ids') : ($job['job_type_ids'] ?? []));
$helperTicked = array_map('intval', $oldList('helper_ids'));

/** Checkbox group: $name[] values => labels, $checked values, compact grid. */
$checks = static function (string $name, array $options, array $checked, string $id): void {
    ?>
    <div class="jo-checks" id="<?= e($id) ?>" role="group">
        <?php foreach ($options as $value => $label): ?>
            <label class="jo-check"><input type="checkbox" name="<?= e($name) ?>[]" value="<?= e((string) $value) ?>"<?= in_array($value, $checked, true) ? ' checked' : '' ?>> <span><?= e($label) ?></span></label>
        <?php endforeach; ?>
    </div>
    <?php
};

$select = static function (string $name, array $options, string $value, string $placeholder): void {
    ?>
    <select class="form-input" name="<?= e($name) ?>" id="<?= e($name) ?>"<?= invalid($name) ?>>
        <?php if ($placeholder !== ''): ?><option value=""><?= e($placeholder) ?></option><?php endif; ?>
        <?php foreach ($options as $k => $label): ?>
            <option value="<?= e((string) $k) ?>"<?= $value === (string) $k ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
    </select>
    <?php
};

$pageStyles  = ['css/jobs.css'];
$pageScripts = ['js/jobs.js'];
$canAddCustomer = customer_quick_add_allowed(); // "+" next to the customer select
if ($canAddCustomer) {
    $pageScripts[] = 'js/customer-add.js';
}
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <a class="back-link" href="<?= e(url('pages/' . ($job !== null ? 'job-view.php?' . http_build_query(['id' => $id, 'return' => $returnTo]) : $returnTo))) ?>"><?= icon('arrow-left') ?> <?= $job !== null ? e($job['job_no']) : 'Job Orders' ?></a>
        <h1><?= $job === null ? 'New Job Order' : 'Edit Intake Details' ?></h1>
        <p class="muted"><?= $job === null ? 'Take in a device: customer, device, the problem reported and when it should be ready.' : 'Correct the customer, device or problem details. The repair status is changed on the job page.' ?></p>
    </div>
    <div class="page-actions">
        <span class="badge badge--period badge--branch"><?= icon('store') ?> <?= e($job !== null ? $job['branch_code'] . ' · ' . $job['branch_name'] : Branch::label()) ?></span>
    </div>
</div>
<?php if ($job === null && !$concrete): ?>
    <div class="alert alert--warning" role="status"><?= icon('info') ?><span>Choose your branch in the top bar first: the job belongs to the branch you work in.</span></div>
<?php endif; ?>

<form class="form-layout" method="post" action="<?= e(url('pages/' . $self)) ?>" novalidate id="jobForm">
    <?= Csrf::field() ?>
    <input type="hidden" name="return" value="<?= e($returnTo) ?>">
    <?php if ($parent !== null): ?>
        <input type="hidden" name="parent_job_id" value="<?= (int) $parent['id'] ?>">
    <?php endif; ?>

    <div class="form-stack">
        <?php if ($parent !== null): ?>
            <div class="alert alert--info" role="note" id="backJobNote"><?= icon('info') ?>
                <span>Back-job of <strong><?= e($parent['job_no']) ?></strong> (released <?= e(date('M j, Y', strtotime((string) $parent['released_at']))) ?>): the same device came back. Describe the new problem.</span></div>
        <?php endif; ?>
        <section class="card card--pad">
            <h2 class="card__title">Customer</h2>
            <div class="form-grid">
                <label class="form-field form-field--full">
                    <span class="form-label">Customer record <small class="muted">(optional; leave empty for a walk-in)</small></span>
                    <div class="select-add">
                    <select class="form-input" name="customer_id" id="customerId" data-label-phone<?= invalid('customer_id') ?>>
                        <option value="">Walk-in / not registered</option>
                        <?php foreach ($customers as $c): ?>
                            <option value="<?= (int) $c['id'] ?>" data-name="<?= e($c['name']) ?>" data-phone="<?= e((string) $c['phone']) ?>"<?= $val('customer_id') === (string) $c['id'] ? ' selected' : '' ?>>
                                <?= e($c['name']) ?><?= $c['phone'] ? ' · ' . e($c['phone']) : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                        <?php if ($canAddCustomer): ?>
                            <button type="button" class="icon-btn select-add__btn" data-add-customer="customerId" aria-label="Add a new customer" title="Add a new customer"><?= icon('plus') ?></button>
                        <?php endif; ?>
                    </div>
                    <?= field_error('customer_id') ?>
                </label>
                <label class="form-field">
                    <span class="form-label">Name *</span>
                    <input class="form-input" name="customer_name" id="customerName" maxlength="100" required value="<?= e($val('customer_name')) ?>"<?= invalid('customer_name') ?>>
                    <?= field_error('customer_name') ?>
                </label>
                <label class="form-field">
                    <span class="form-label">Contact number *</span>
                    <input class="form-input" name="customer_phone" id="customerPhone" maxlength="30" inputmode="tel" required value="<?= e($val('customer_phone')) ?>"<?= invalid('customer_phone') ?>>
                    <?= field_error('customer_phone') ?>
                </label>
                <label class="form-field form-field--full">
                    <span class="form-label">Contact person <small class="muted">(optional, e.g. for a company)</small></span>
                    <input class="form-input" name="contact_person" maxlength="100" value="<?= e($val('contact_person')) ?>"<?= invalid('contact_person') ?>>
                    <?= field_error('contact_person') ?>
                </label>
            </div>
        </section>

        <section class="card card--pad">
            <h2 class="card__title">Device</h2>
            <div class="form-grid">
                <label class="form-field">
                    <span class="form-label">Device type *</span>
                    <?php $select('device_type_id', array_column($deviceTypes, 'name', 'id'), $val('device_type_id'), 'Choose…'); ?>
                    <?= field_error('device_type_id') ?>
                </label>
                <label class="form-field">
                    <span class="form-label">Serial number</span>
                    <input class="form-input form-input--mono" name="serial_no" maxlength="80" value="<?= e($val('serial_no')) ?>"<?= invalid('serial_no') ?>>
                    <?= field_error('serial_no') ?>
                    <p class="form-hint">If we sold this unit, the sale and warranty are found automatically.</p>
                </label>
                <label class="form-field">
                    <span class="form-label">Brand</span>
                    <input class="form-input" name="brand" maxlength="80" value="<?= e($val('brand')) ?>"<?= invalid('brand') ?>>
                    <?= field_error('brand') ?>
                </label>
                <label class="form-field">
                    <span class="form-label">Model</span>
                    <input class="form-input" name="model" maxlength="80" value="<?= e($val('model')) ?>"<?= invalid('model') ?>>
                    <?= field_error('model') ?>
                </label>
                <fieldset class="form-field form-field--full jo-fieldset">
                    <legend class="form-label">Accessories left with the device <small class="muted">(tick all that apply)</small></legend>
                    <?php $checks('accessories_pick', array_combine($accessoryNames, $accessoryNames) ?: [], $accTicked, 'accessoryChecks'); ?>
                    <input class="form-input jo-other" name="accessories_other" maxlength="300" value="<?= e($accOther) ?>" placeholder="Other accessories (type here)" aria-label="Other accessories"<?= invalid('accessories') ?>>
                    <?= field_error('accessories') ?>
                </fieldset>
                <fieldset class="form-field form-field--full jo-fieldset">
                    <legend class="form-label">Condition on arrival <small class="muted">(tick all that apply)</small></legend>
                    <?php $checks('device_condition_pick', array_combine($conditionNames, $conditionNames) ?: [], $condTicked, 'conditionChecks'); ?>
                    <input class="form-input jo-other" name="device_condition_other" maxlength="300" value="<?= e($condOther) ?>" placeholder="Other details, e.g. scratch on the lid, upper left" aria-label="Other condition details"<?= invalid('device_condition') ?>>
                    <?= field_error('device_condition') ?>
                </fieldset>
            </div>
            <p class="form-hint jo-hint"><?= icon('lock') ?> Never write device passwords or PINs here. Ask the customer to be present or to remove the password.</p>
        </section>

        <section class="card card--pad">
            <h2 class="card__title">Job</h2>
            <div class="form-grid">
                <div class="form-field form-field--full">
                    <span class="form-label" id="problemLabel">Problem reported * <small class="muted">(tick the common problems, then add details)</small></span>
                    <?php if ($problemNames): ?>
                        <div class="jo-checks jo-checks--picks" id="problemPicks" role="group" aria-labelledby="problemLabel">
                            <?php foreach ($problemNames as $p): ?>
                                <label class="jo-check"><input type="checkbox" data-problem-pick="<?= e($p) ?>"> <span><?= e($p) ?></span></label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <textarea class="form-input" name="problem" id="jobProblem" maxlength="1000" rows="4" required placeholder="What the customer says is wrong" aria-labelledby="problemLabel"<?= invalid('problem') ?>><?= e($val('problem')) ?></textarea>
                    <?= field_error('problem') ?>
                </div>
                <fieldset class="form-field form-field--full jo-fieldset">
                    <legend class="form-label">Job type <small class="muted">(one or more)</small></legend>
                    <input type="hidden" name="job_type_ids[]" value="">
                    <?php $checks('job_type_ids', $jobTypes, $typeTicked, 'jobTypeChecks'); ?>
                    <?= field_error('job_type_ids') ?>
                </fieldset>
                <label class="form-field">
                    <span class="form-label">Priority</span>
                    <?php $select('priority', JobOrders::PRIORITIES, $val('priority', 'normal'), ''); ?>
                    <?= field_error('priority') ?>
                </label>
                <label class="form-field">
                    <span class="form-label">Service location</span>
                    <?php $select('service_location', JobOrders::LOCATIONS, $val('service_location', 'in_shop'), ''); ?>
                    <?= field_error('service_location') ?>
                </label>
                <label class="form-field">
                    <span class="form-label">Expected completion</span>
                    <input class="form-input" type="date" name="expected_at" value="<?= e($val('expected_at')) ?>"<?= $job === null ? ' min="' . e(date('Y-m-d')) . '"' : '' ?><?= invalid('expected_at') ?>>
                    <?= field_error('expected_at') ?>
                </label>
                <?php if ($technicians): ?>
                    <label class="form-field">
                        <span class="form-label">Assign to (lead technician)</span>
                        <select class="form-input" name="technician_id" id="leadTech" data-lead-select="helperChecks"<?= invalid('technician_id') ?>>
                            <option value="">Leave unassigned</option>
                            <?php foreach ($technicians as $t): ?>
                                <option value="<?= (int) $t['id'] ?>"<?= old('technician_id') === (string) $t['id'] ? ' selected' : '' ?>><?= e($t['full_name']) ?> · <?= (int) $t['open_jobs'] ?> open</option>
                            <?php endforeach; ?>
                        </select>
                        <?= field_error('technician_id') ?>
                    </label>
                    <?php if (count($technicians) > 1): ?>
                        <fieldset class="form-field form-field--full jo-fieldset">
                            <legend class="form-label">Helpers <small class="muted">(optional; they can also work on the job)</small></legend>
                            <?php $checks('helper_ids', array_combine(array_map('intval', array_column($technicians, 'id')), array_map(
                                static fn (array $t): string => $t['full_name'] . ' · ' . (int) $t['open_jobs'] . ' open', $technicians)) ?: [], $helperTicked, 'helperChecks'); ?>
                            <?= field_error('helper_ids') ?>
                        </fieldset>
                    <?php endif; ?>
                <?php endif; ?>
                <label class="form-field form-field--full">
                    <span class="form-label">Remarks <small class="muted">(optional, printed on the ticket)</small></span>
                    <textarea class="form-input" name="remarks" maxlength="500" rows="2"<?= invalid('remarks') ?>><?= e($val('remarks')) ?></textarea>
                    <?= field_error('remarks') ?>
                </label>
            </div>
        </section>
    </div>

    <aside class="form-side">
        <section class="card card--pad">
            <h2 class="card__title">Before you save</h2>
            <ul class="jo-checklist">
                <li>Check the serial number on the unit itself.</li>
                <li>List every accessory left with the device.</li>
                <li>Note visible damage under "Condition on arrival".</li>
                <li>Repairs estimated above <?= e(money(JobOrders::quoteThreshold())) ?> need the customer's approval first.</li>
            </ul>
        </section>
    </aside>

    <div class="form-actions">
        <a class="btn btn--light" href="<?= e(url('pages/' . ($job !== null ? 'job-view.php?' . http_build_query(['id' => $id, 'return' => $returnTo]) : $returnTo))) ?>">Cancel</a>
        <button type="submit" class="btn btn--primary" id="jobSaveBtn"<?= $job === null && !$concrete ? ' disabled' : '' ?>><?= icon('save') ?> <?= $job === null ? 'Create Job Order' : 'Save Changes' ?></button>
    </div>
</form>

<?php if ($canAddCustomer) { require ROOT_PATH . '/includes/customer-quick-add.php'; } ?>
<?php require ROOT_PATH . '/includes/footer.php'; ?>
