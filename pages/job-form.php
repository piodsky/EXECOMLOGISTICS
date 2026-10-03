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

$returnTo = safe_return($_POST['return'] ?? $_GET['return'] ?? null, 'job-orders.php');
$self     = 'job-form.php?' . http_build_query(array_filter(['id' => $id, 'return' => $returnTo]));
$page['title'] = $job === null ? 'New Job Order' : 'Edit ' . $job['job_no'];

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
        flash_old(array_filter($_POST, 'is_string'));
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
$cur      = static fn (string $k): string => $job !== null && $job[$k] !== null ? (string) $job[$k] : '';
$val      = static fn (string $k, string $default = ''): string => old($k, $job !== null ? $cur($k) : $default);

$customers = Customers::active();
if ($job !== null && $job['customer_id'] !== null && !in_array((int) $job['customer_id'], array_map('intval', array_column($customers, 'id')), true)) {
    $customers[] = ['id' => $job['customer_id'], 'name' => $job['customer_name'] . ' (inactive)', 'phone' => $job['customer_phone']];
}
$deviceTypes = MasterData::options('device-types', $job !== null && $job['device_type_id'] !== null ? (int) $job['device_type_id'] : null);
$jobTypes    = MasterData::options('job-types', $job !== null && $job['job_type_id'] !== null ? (int) $job['job_type_id'] : null);
$technicians = $job === null && $concrete && Auth::can('job_orders.assign') ? JobOrders::technicians((int) Branch::current()) : [];

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

    <div class="form-stack">
        <section class="card card--pad">
            <h2 class="card__title">Customer</h2>
            <div class="form-grid">
                <label class="form-field form-field--full">
                    <span class="form-label">Customer record <small class="muted">(optional; leave empty for a walk-in)</small></span>
                    <select class="form-input" name="customer_id" id="customerId"<?= invalid('customer_id') ?>>
                        <option value="">Walk-in / not registered</option>
                        <?php foreach ($customers as $c): ?>
                            <option value="<?= (int) $c['id'] ?>" data-name="<?= e($c['name']) ?>" data-phone="<?= e((string) $c['phone']) ?>"<?= $val('customer_id') === (string) $c['id'] ? ' selected' : '' ?>>
                                <?= e($c['name']) ?><?= $c['phone'] ? ' · ' . e($c['phone']) : '' ?></option>
                        <?php endforeach; ?>
                    </select>
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
                <label class="form-field">
                    <span class="form-label">Accessories left with the device</span>
                    <input class="form-input" name="accessories" maxlength="255" value="<?= e($val('accessories')) ?>" placeholder="e.g. charger, bag"<?= invalid('accessories') ?>>
                    <?= field_error('accessories') ?>
                </label>
                <label class="form-field">
                    <span class="form-label">Condition on arrival</span>
                    <input class="form-input" name="device_condition" maxlength="255" value="<?= e($val('device_condition')) ?>" placeholder="e.g. scratches on the lid"<?= invalid('device_condition') ?>>
                    <?= field_error('device_condition') ?>
                </label>
            </div>
            <p class="form-hint jo-hint"><?= icon('lock') ?> Never write device passwords or PINs here. Ask the customer to be present or to remove the password.</p>
        </section>

        <section class="card card--pad">
            <h2 class="card__title">Job</h2>
            <div class="form-grid">
                <label class="form-field form-field--full">
                    <span class="form-label">Problem reported *</span>
                    <textarea class="form-input" name="problem" maxlength="1000" rows="3" required placeholder="What the customer says is wrong"<?= invalid('problem') ?>><?= e($val('problem')) ?></textarea>
                    <?= field_error('problem') ?>
                </label>
                <label class="form-field">
                    <span class="form-label">Job type</span>
                    <?php $select('job_type_id', array_column($jobTypes, 'name', 'id'), $val('job_type_id'), 'Not set'); ?>
                    <?= field_error('job_type_id') ?>
                </label>
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
                        <span class="form-label">Assign to</span>
                        <select class="form-input" name="technician_id"<?= invalid('technician_id') ?>>
                            <option value="">Leave unassigned</option>
                            <?php foreach ($technicians as $t): ?>
                                <option value="<?= (int) $t['id'] ?>"<?= old('technician_id') === (string) $t['id'] ? ' selected' : '' ?>><?= e($t['full_name']) ?> · <?= (int) $t['open_jobs'] ?> open</option>
                            <?php endforeach; ?>
                        </select>
                        <?= field_error('technician_id') ?>
                    </label>
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

<?php require ROOT_PATH . '/includes/footer.php'; ?>
