<?php
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = settings_page('branches', 'Branches'); // branches.manage

// ---------------------------------------------------------------------
// Row actions (POST → back to the list). Branches:: checks every rule again.
// ---------------------------------------------------------------------
if (is_post()) {
    Csrf::verifyRequest();
    $id = input_int($_POST, 'id', 1) ?? 0;
    try {
        if (input_string($_POST, 'action', 20) !== 'delete') {
            throw new HttpException(400, 'Unknown action.');
        }
        $b = Branches::delete($id);
        flash('success', "{$b['name']} ({$b['code']}) was deleted.");
    } catch (HttpException $e) {
        flash('error', $e->getMessage());
    }
    redirect('pages/branches.php');
}

$branches = Branches::all();
$settingsTab = 'branches';

$pageStyles = ['css/settings.css'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Settings</h1>
        <p class="muted">Branches, their contact details and stock locations.</p>
    </div>
    <a class="btn btn--primary" href="<?= e(url('pages/branch-form.php')) ?>" id="addBranch"><?= icon('plus') ?> Add Branch</a>
</div>

<?php require ROOT_PATH . '/includes/settings-nav.php'; ?>

<section class="card">
    <div class="table-wrap">
        <table class="table table--list branches-table" id="branchesTable">
            <thead>
            <tr>
                <th>Branch</th>
                <th>Address &amp; Contact</th>
                <th class="col-opt">Stock Location</th>
                <th class="num">Users</th>
                <th class="num">Sales</th>
                <th>Status</th>
                <th class="actions-col">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($branches as $b): ?>
                <?php
                $active  = (int) $b['is_active'] === 1;
                $isMain  = (int) $b['is_main'] === 1;
                $editUrl = url('pages/branch-form.php?id=' . (int) $b['id']);
                $canDelete = !$isMain && (int) $b['users'] === 0 && (int) $b['sales'] === 0;
                ?>
                <tr class="<?= $active ? '' : 'is-inactive' ?>" data-branch="<?= e($b['code']) ?>">
                    <td>
                        <div class="item-cell">
                            <span class="branch-code"><?= e($b['code']) ?></span>
                            <span>
                                <a class="item-cell__name" href="<?= e($editUrl) ?>"><?= e($b['name']) ?></a>
                                <?php if ($isMain): ?><span class="badge badge--main"><?= icon('store') ?> Main store</span><?php endif; ?>
                            </span>
                        </div>
                    </td>
                    <td>
                        <?php if (Branches::needsDetails($b)): ?>
                            <span class="badge badge--warning" data-needs-details><?= icon('alert') ?> Details to be updated</span>
                        <?php endif; ?>
                        <?php if ($b['address']): ?><span class="block"><?= e($b['address']) ?></span><?php endif; ?>
                        <?php if ($b['contact_no']): ?><small class="muted block">Tel: <?= e($b['contact_no']) ?></small><?php endif; ?>
                        <?php if ($b['tin_branch_code']): ?><small class="muted block">TIN branch code: <?= e($b['tin_branch_code']) ?></small><?php endif; ?>
                    </td>
                    <td class="col-opt"><span class="location-tag"><?= icon('box') ?> <?= e($b['locations'] ?? '—') ?></span></td>
                    <td class="num"><?= number_format((int) $b['active_users']) ?></td>
                    <td class="num"><?= number_format((int) $b['sales']) ?></td>
                    <td><span class="badge<?= $active ? ' badge--success' : '' ?>"><?= $active ? 'Active' : 'Inactive' ?></span></td>
                    <td class="actions-col">
                        <div class="row-actions">
                            <a class="icon-btn" href="<?= e($editUrl) ?>" title="Edit" aria-label="Edit <?= e($b['name']) ?>"><?= icon('edit') ?></a>
                            <?php if ($canDelete): ?>
                                <form method="post" data-confirm="Delete the <?= e($b['name']) ?> branch permanently?">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                                    <button type="submit" class="icon-btn icon-btn--danger-outline" data-act="delete" title="Delete"
                                            aria-label="Delete <?= e($b['name']) ?>"><?= icon('trash') ?></button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="table-foot muted"><?= icon('info') ?> Branches with sales, users, customers or stock can't be deleted; deactivate them instead.
        Receipts print the branch's address and contact number when they are filled in.</p>
</section>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
