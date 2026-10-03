<?php
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = settings_page('audit', 'Audit Log'); // audit_logs.view

// ---------------------------------------------------------------------
// Filters (rows are limited to the current branch scope inside Audit::)
// ---------------------------------------------------------------------
$filters = [
    'q'      => input_string($_GET, 'search', 100),
    'module' => is_string($_GET['module'] ?? null) && isset(Audit::MODULES[$_GET['module']]) ? $_GET['module'] : '',
    'user'   => input_int($_GET, 'user', 1),
    'from'   => input_date($_GET, 'from'),
    'to'     => input_date($_GET, 'to'),
];
if ($filters['from'] !== null && $filters['to'] !== null && $filters['from'] > $filters['to']) {
    [$filters['from'], $filters['to']] = [$filters['to'], $filters['from']];
}
$pgQuery = array_filter([
    'search' => $filters['q'],
    'module' => $filters['module'],
    'user'   => $filters['user'],
    'from'   => $filters['from'],
    'to'     => $filters['to'],
], static fn ($v) => $v !== '' && $v !== null);

$pg      = paginate(Audit::count($filters), 25);
$rows    = Audit::search($filters, $pg['per_page'], $pg['offset']);
$users   = Audit::users();
$pgPath  = 'pages/audit-log.php';
$settingsTab = 'audit';
$today   = (new DateTimeImmutable('today'))->format('Y-m-d');

/** Decoded JSON values (or []) for the change list. */
$decode = static function (?string $json): array {
    if ($json === null || $json === '') {
        return [];
    }
    $v = json_decode($json, true);
    return is_array($v) ? $v : [];
};
/** One value as short readable text. */
$show = static function (mixed $v): string {
    if ($v === null) {
        return '—';
    }
    if (is_bool($v)) {
        return $v ? 'yes' : 'no';
    }
    if (is_array($v)) {
        return $v === [] ? '(none)' : implode(', ', array_map(static fn ($x) => is_scalar($x) ? (string) $x : json_encode($x), $v));
    }
    $s = (string) $v;
    if ($s === '') {
        return '(empty)';
    }
    return mb_strlen($s) > 120 ? mb_substr($s, 0, 117) . '…' : $s;
};

/** Chip colour per action (the action name is always printed too). */
$tone = static fn (string $action): string => match ($action) {
    'create', 'activate' => 'success',
    'delete', 'void' => 'danger',
    'deactivate', 'password_reset' => 'warning',
    default => 'info',
};

$pageStyles = ['css/settings.css'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Settings</h1>
        <p class="muted">Who changed what, and when · <?= e(Branch::label()) ?>.</p>
    </div>
</div>

<?php require ROOT_PATH . '/includes/settings-nav.php'; ?>

<section class="card">
    <form class="toolbar" method="get" action="<?= e(url('pages/audit-log.php')) ?>" role="search">
        <label class="toolbar__search">
            <?= icon('search') ?>
            <input class="form-input" type="search" name="search" maxlength="100" placeholder="Reference, user or action"
                   value="<?= e($filters['q']) ?>" aria-label="Search the audit log">
        </label>
        <select class="form-input" name="module" aria-label="Module">
            <option value="">All modules</option>
            <?php foreach (Audit::MODULES as $value => $label): ?>
                <option value="<?= e($value) ?>"<?= $filters['module'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <select class="form-input" name="user" aria-label="User">
            <option value="">All users</option>
            <?php foreach ($users as $u): ?>
                <option value="<?= (int) $u['id'] ?>"<?= $filters['user'] === (int) $u['id'] ? ' selected' : '' ?>><?= e($u['username']) ?></option>
            <?php endforeach; ?>
        </select>
        <label class="date-field">
            <span>From</span>
            <input class="form-input" type="date" name="from" value="<?= e($filters['from'] ?? '') ?>" max="<?= e($today) ?>">
        </label>
        <label class="date-field">
            <span>To</span>
            <input class="form-input" type="date" name="to" value="<?= e($filters['to'] ?? '') ?>" max="<?= e($today) ?>">
        </label>
        <button type="submit" class="btn btn--primary">Filter</button>
        <?php if ($pgQuery): ?>
            <a class="btn btn--light" href="<?= e(url('pages/audit-log.php')) ?>">Reset</a>
        <?php endif; ?>
    </form>

    <div class="table-wrap">
        <table class="table table--list audit-table" id="auditTable">
            <thead>
            <tr>
                <th>When</th>
                <th>User</th>
                <th>Branch</th>
                <th>Action</th>
                <th>Record</th>
                <th>Changes</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $a): ?>
                <?php
                $old  = $decode($a['old_values']);
                $new  = $decode($a['new_values']);
                $keys = array_unique([...array_keys($old), ...array_keys($new)]);
                ?>
                <tr data-module="<?= e($a['module']) ?>" data-action="<?= e($a['action']) ?>">
                    <td class="nowrap"><?= e(date('M j, Y', strtotime($a['occurred_at']))) ?> <small class="muted block"><?= e(date('g:i:s A', strtotime($a['occurred_at']))) ?></small></td>
                    <td>
                        <?= e($a['username'] ?? '—') ?>
                        <?php if ($a['role']): ?><small class="muted block"><?= e($a['role']) ?></small><?php endif; ?>
                    </td>
                    <td><?= $a['branch_code'] ? '<span class="badge badge--branch" title="' . e($a['branch_name']) . '">' . e($a['branch_code']) . '</span>' : '<span class="muted">Company</span>' ?></td>
                    <td>
                        <span class="badge audit-action badge--<?= e($tone($a['action'])) ?>"><?= e(str_replace('_', ' ', $a['action'])) ?></span>
                        <small class="muted block"><?= e(Audit::MODULES[$a['module']] ?? $a['module']) ?></small>
                    </td>
                    <td>
                        <?= e($a['entity_ref'] ?? '') ?>
                        <?php if ($a['entity_type']): ?><small class="muted block"><?= e($a['entity_type']) ?><?= $a['entity_id'] !== null ? ' #' . (int) $a['entity_id'] : '' ?></small><?php endif; ?>
                    </td>
                    <td>
                        <?php if ($keys): ?>
                            <dl class="audit-changes">
                                <?php foreach ($keys as $k): ?>
                                    <div>
                                        <dt><?= e(str_replace('_', ' ', (string) $k)) ?></dt>
                                        <dd>
                                            <?php if (array_key_exists($k, $old) && array_key_exists($k, $new)): ?>
                                                <span class="audit-old"><?= e($show($old[$k])) ?></span> → <span class="audit-new"><?= e($show($new[$k])) ?></span>
                                            <?php elseif (array_key_exists($k, $new)): ?>
                                                <span class="audit-val"><?= e($show($new[$k])) ?></span>
                                            <?php else: ?>
                                                <span class="audit-val"><?= e($show($old[$k])) ?></span>
                                            <?php endif; ?>
                                        </dd>
                                    </div>
                                <?php endforeach; ?>
                            </dl>
                        <?php else: ?>
                            <span class="muted">—</span>
                        <?php endif; ?>
                        <?php if ($a['ip_address']): ?><small class="muted block">IP <?= e($a['ip_address']) ?></small><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="6" class="empty">No activity found<?= $pgQuery ? ' for these filters' : ' yet' ?>.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php require ROOT_PATH . '/includes/pagination.php'; ?>
</section>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
