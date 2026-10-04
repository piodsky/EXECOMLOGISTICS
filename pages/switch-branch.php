<?php
/**
 * POST pages/switch-branch.php  (branch_id = 0 for "All branches", return = page to go back to)
 * Changes the branch the user is working in (session only). The topbar switcher posts here.
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
Auth::requireLogin();

if (!is_post()) {
    redirect(home_url());
}
Csrf::verifyRequest();

$branchId = input_int($_POST, 'branch_id', 0);
if ($branchId === null || !Branch::switchTo($branchId)) {
    abort(403, 'You do not have access to that branch.');
}

Audit::record('auth', 'branch_switch', 'branch', $branchId ?: null, Branch::label(), null, null, $branchId ?: null);
flash('info', 'Now working in: ' . Branch::label() . '.');
redirect('pages/' . safe_return($_POST['return'] ?? null, basename(home_path())));
