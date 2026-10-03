<?php
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('master-data'); // master_data.manage or suppliers.view

$listKey = is_string($_GET['list'] ?? null) ? $_GET['list'] : null;
if (!Auth::can('master_data.manage') && $listKey === null && Auth::can('suppliers.view')) {
    redirect('pages/suppliers.php'); // the only Master Data tab this user can open
}
$listKey ??= (string) array_key_first(MasterData::lists());
$def = MasterData::def($listKey); // 404 for an unknown list
Auth::requirePermission($def['permission']);
$page['title'] = $def['label'];
$self = 'master-data.php?list=' . rawurlencode($listKey);

// ---------------------------------------------------------------------
// Save (dialog) and row actions: POST → back to the list. MasterData:: checks every rule again.
// ---------------------------------------------------------------------
if (is_post()) {
    Csrf::verifyRequest();
    $back   = safe_return($_POST['return'] ?? null, $self);
    $action = input_string($_POST, 'action', 20);
    $id     = input_int($_POST, 'id', 1);
    $keepForm = static fn () => flash_old(array_filter($_POST, 'is_string') + [
        'is_active' => isset($_POST['is_active']) ? '1' : '0',
        'id'        => $id !== null ? (string) $id : '',
    ]);
    try {
        switch ($action) {
            case 'save':
                $row = $id !== null ? (MasterData::find($def, $id) ?? throw new HttpException(404, "{$def['singular']} not found.")) : null;
                [$data, $errors] = MasterData::validate($def, $_POST, $row);
                if ($errors) {
                    $keepForm();
                    flash_errors($errors);
                    flash('error', 'Please fix the highlighted fields.');
                    break;
                }
                MasterData::save($def, $data, $row);
                flash('success', $row ? "{$data['name']} was updated." : "{$def['singular']} {$data['name']} was added.");
                break;

            case 'toggle':
                $row = MasterData::toggleActive($def, $id ?? 0);
                flash('success', $row['is_active']
                    ? "{$row['name']} is active again."
                    : "{$row['name']} was deactivated. Records that use it keep it; it is no longer offered for new ones.");
                break;

            case 'delete':
                $row = MasterData::delete($def, $id ?? 0);
                flash('success', "{$row['name']} was deleted.");
                break;

            default:
                throw new HttpException(400, 'Unknown action.');
        }
    } catch (HttpException $e) {
        if ($action === 'save') {
            $keepForm();
        }
        flash('error', $e->getMessage());
    }
    redirect('pages/' . $back);
}

// ---------------------------------------------------------------------
// List
// ---------------------------------------------------------------------
$statuses = ['all' => 'All', 'active' => 'Active', 'inactive' => 'Inactive'];
$filters = [
    'q'      => input_string($_GET, 'search', 100),
    'status' => is_string($_GET['status'] ?? null) && array_key_exists($_GET['status'], $statuses) ? $_GET['status'] : 'all',
];
$pgQuery = array_filter([
    'list'   => $listKey,
    'search' => $filters['q'],
    'status' => $filters['status'] !== 'all' ? $filters['status'] : null,
], static fn ($v) => $v !== '' && $v !== null);

$pg       = paginate(MasterData::count($def, $filters), 20);
$rows     = MasterData::search($def, $filters, $pg['per_page'], $pg['offset']);
$returnTo = 'master-data.php?' . http_build_query($pgQuery + ($pg['page'] > 1 ? ['page' => $pg['page']] : []));
$pgPath   = 'pages/master-data.php';

$has      = static fn (string $f): bool => in_array($f, $def['fields'], true);
$brands   = [];
if ($has('brand_id')) {
    // Every brand, so a model under a now-inactive brand still shows its brand when edited.
    $stmt = db()->prepare('SELECT id, name, is_active FROM brands ORDER BY name');
    $stmt->execute();
    $brands = $stmt->fetchAll();
}
$reopen   = has_old();
$editing  = $reopen && old('id') !== '';
$isActive = $reopen ? old('is_active') === '1' : true;
$mdTab    = $listKey;
$cols     = 4 + ($has('code') ? 1 : 0) + ($has('brand_id') ? 1 : 0) + ($has('sort_order') ? 1 : 0);

$pageStyles  = ['css/settings.css', 'css/master-data.css'];
$pageScripts = ['js/master-data.js'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Master Data</h1>
        <p class="muted"><?= e($def['hint']) ?></p>
    </div>
    <button type="button" class="btn btn--primary" data-md-new id="mdAdd"><?= icon('plus') ?> Add <?= e($def['singular']) ?></button>
</div>

<?php require ROOT_PATH . '/includes/master-data-nav.php'; ?>

<section class="card">
    <form class="toolbar" method="get" action="<?= e(url('pages/master-data.php')) ?>" role="search">
        <input type="hidden" name="list" value="<?= e($listKey) ?>">
        <label class="toolbar__search">
            <?= icon('search') ?>
            <input class="form-input" type="search" name="search" maxlength="100" placeholder="Search <?= e(strtolower($def['label'])) ?>"
                   value="<?= e($filters['q']) ?>" aria-label="Search <?= e(strtolower($def['label'])) ?>">
        </label>
        <select class="form-input" name="status" aria-label="Status">
            <?php foreach ($statuses as $value => $label): ?>
                <option value="<?= e($value) ?>"<?= $filters['status'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn--primary">Filter</button>
        <?php if (count($pgQuery) > 1): ?>
            <a class="btn btn--light" href="<?= e(url('pages/' . $self)) ?>">Reset</a>
        <?php endif; ?>
    </form>

    <div class="table-wrap">
        <table class="table table--list md-table" id="mdTable" data-list="<?= e($listKey) ?>">
            <thead>
            <tr>
                <?php if ($has('code')): ?><th>Code</th><?php endif; ?>
                <th>Name</th>
                <?php if ($has('brand_id')): ?><th>Brand</th><?php endif; ?>
                <?php if ($has('sort_order')): ?><th class="num col-opt">Order</th><?php endif; ?>
                <th class="num">Used by</th>
                <th>Status</th>
                <th class="actions-col">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <?php $active = (int) $r['is_active'] === 1; $used = (int) $r['used']; ?>
                <tr class="<?= $active ? '' : 'is-inactive' ?>" data-row="<?= e($r['name']) ?>">
                    <?php if ($has('code')): ?><td><span class="md-code"><?= e($r['code']) ?></span></td><?php endif; ?>
                    <td>
                        <span class="md-name">
                            <?php if ($has('icon')): ?><span class="md-icon"><?= icon((string) $r['icon']) ?></span><?php endif; ?>
                            <strong><?= e($r['name']) ?></strong>
                        </span>
                    </td>
                    <?php if ($has('brand_id')): ?>
                        <td><?= e($r['brand_name']) ?><?= (int) $r['brand_active'] === 1 ? '' : ' <span class="badge">Inactive brand</span>' ?></td>
                    <?php endif; ?>
                    <?php if ($has('sort_order')): ?><td class="num col-opt"><?= (int) $r['sort_order'] ?></td><?php endif; ?>
                    <td class="num"><?= $used > 0 ? number_format($used) : '<span class="muted">—</span>' ?></td>
                    <td><span class="badge<?= $active ? ' badge--success' : '' ?>"><?= $active ? 'Active' : 'Inactive' ?></span></td>
                    <td class="actions-col">
                        <div class="row-actions">
                            <button type="button" class="icon-btn" title="Edit" aria-label="Edit <?= e($r['name']) ?>" data-md-edit
                                    data-id="<?= (int) $r['id'] ?>" data-name="<?= e($r['name']) ?>" data-active="<?= $active ? '1' : '0' ?>"
                                    <?php if ($has('code')): ?>data-code="<?= e($r['code']) ?>"<?php endif; ?>
                                    <?php if ($has('brand_id')): ?>data-brand="<?= (int) $r['brand_id'] ?>"<?php endif; ?>
                                    <?php if ($has('icon')): ?>data-icon="<?= e($r['icon']) ?>"<?php endif; ?>
                                    <?php if ($has('sort_order')): ?>data-sort="<?= (int) $r['sort_order'] ?>"<?php endif; ?>><?= icon('edit') ?></button>
                            <form method="post" action="<?= e(url('pages/' . $self)) ?>">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                <input type="hidden" name="return" value="<?= e($returnTo) ?>">
                                <?php $label = $active ? 'Deactivate' : 'Activate'; ?>
                                <button type="submit" class="icon-btn<?= $active ? '' : ' icon-btn--success' ?>" data-act="toggle"
                                        title="<?= $label ?>" aria-label="<?= $label ?> <?= e($r['name']) ?>"><?= icon('power') ?></button>
                            </form>
                            <?php if ($used === 0): ?>
                                <form method="post" action="<?= e(url('pages/' . $self)) ?>" data-confirm="Delete <?= e($r['name']) ?> permanently?">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <input type="hidden" name="return" value="<?= e($returnTo) ?>">
                                    <button type="submit" class="icon-btn icon-btn--danger-outline" data-act="delete" title="Delete"
                                            aria-label="Delete <?= e($r['name']) ?>"><?= icon('trash') ?></button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="<?= $cols ?>" class="empty">No <?= e(strtolower($def['label'])) ?> found<?= count($pgQuery) > 1 ? ' for these filters' : '' ?>.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php require ROOT_PATH . '/includes/pagination.php'; ?>
    <p class="table-foot muted"><?= icon('info') ?> Entries in use can't be deleted; deactivate them instead.
        Inactive entries stay on the records that already use them but aren't offered for new ones.</p>
</section>

<dialog class="modal md-dialog" id="mdDialog" aria-labelledby="mdTitle"<?= $reopen ? ' data-reopen' : '' ?>
        data-title-add="Add <?= e($def['singular']) ?>" data-title-edit="Edit <?= e($def['singular']) ?>">
    <form class="modal__body" method="post" action="<?= e(url('pages/' . $self)) ?>" novalidate id="mdForm">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="mdId" value="<?= e(old('id')) ?>">
        <input type="hidden" name="return" value="<?= e($returnTo) ?>">

        <header class="modal__head">
            <h2 id="mdTitle"><?= e(($editing ? 'Edit ' : 'Add ') . $def['singular']) ?></h2>
            <button type="button" class="modal__close" data-close aria-label="Close"><?= icon('x') ?></button>
        </header>

        <div class="form-grid form-grid--single">
            <?php if ($has('code')): ?>
                <label class="form-field">
                    <span class="form-label">Code *</span>
                    <input class="form-input form-input--mono" name="code" id="mdCode" maxlength="10" required placeholder="PC"
                           autocapitalize="characters" value="<?= e(old('code')) ?>"<?= invalid('code') ?>>
                    <?= field_error('code') ?>
                </label>
            <?php endif; ?>
            <?php if ($has('brand_id')): ?>
                <label class="form-field">
                    <span class="form-label">Brand *</span>
                    <select class="form-input" name="brand_id" id="mdBrand" required<?= invalid('brand_id') ?>>
                        <option value="">Choose…</option>
                        <?php foreach ($brands as $b): ?>
                            <option value="<?= (int) $b['id'] ?>"<?= old('brand_id') === (string) $b['id'] ? ' selected' : '' ?>><?= e($b['name']) ?><?= (int) $b['is_active'] === 1 ? '' : ' (inactive)' ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= field_error('brand_id') ?>
                    <?php if (!$brands): ?><p class="form-hint">Add a brand first (Brands tab).</p><?php endif; ?>
                </label>
            <?php endif; ?>
            <label class="form-field">
                <span class="form-label">Name *</span>
                <input class="form-input" name="name" id="mdName" maxlength="<?= (int) $def['name_max'] ?>" required
                       value="<?= e(old('name')) ?>"<?= invalid('name') ?>>
                <?= field_error('name') ?>
            </label>
            <?php if ($has('icon')): ?>
                <fieldset class="form-field md-icons">
                    <legend class="form-label">Icon *</legend>
                    <div class="md-icons__grid">
                        <?php foreach (MasterData::CATEGORY_ICONS as $ic): ?>
                            <label title="<?= e($ic) ?>">
                                <input type="radio" name="icon" value="<?= e($ic) ?>" aria-label="<?= e(ucfirst($ic)) ?>"<?= old('icon', 'grid') === $ic ? ' checked' : '' ?>>
                                <span><?= icon($ic) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <?= field_error('icon') ?>
                </fieldset>
            <?php endif; ?>
            <?php if ($has('sort_order')): ?>
                <label class="form-field">
                    <span class="form-label">Sort order</span>
                    <input class="form-input" type="number" name="sort_order" id="mdSort" min="0" max="<?= MasterData::MAX_SORT ?>" step="1"
                           value="<?= e(old('sort_order', '0')) ?>"<?= invalid('sort_order') ?>>
                    <?= field_error('sort_order') ?>
                    <p class="form-hint">Lower numbers come first in lists.</p>
                </label>
            <?php endif; ?>
            <label class="check check--switch">
                <input type="checkbox" name="is_active" value="1" id="mdActive"<?= $isActive ? ' checked' : '' ?>>
                <span><strong>Active</strong><small class="muted block">Offered when adding or editing records</small></span>
            </label>
        </div>

        <footer class="modal__foot">
            <button type="button" class="btn btn--light" data-close>Cancel</button>
            <button type="submit" class="btn btn--primary"><?= icon('save') ?> Save</button>
        </footer>
    </form>
</dialog>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
