<?php
/**
 * Page layout — top. Expects $page from require_page().
 * Optional: $pageStyles = ['css/pos.css'] for page-specific CSS.
 *
 * @var array $page
 */
$user      = Auth::user();
$activeKey = $page['key'] ?? '';
$roleName  = $user['role_name'] ?? ucfirst($user['role']);
$now       = new DateTimeImmutable();

// Branch chip + switcher (only when there is something to switch to)
$hdrBranchId  = Branch::current();
$hdrBranches  = Branch::allowed();
$hdrCanAll    = Branch::canSeeAll();
$hdrSwitch    = $hdrCanAll || count($hdrBranches) > 1;
$hdrBranchRow = Branch::currentBranch();
$hdrQuery     = (string) ($_SERVER['QUERY_STRING'] ?? '');
$hdrReturn    = safe_return(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) . ($hdrQuery !== '' ? '?' . $hdrQuery : ''), basename(home_path()));
// Topbar search: POS when allowed, else Inventory (technician), else none
$hdrSearch = Auth::can('pos.access') ? ['pages/pos.php', 'q'] : (Auth::can('inventory.view') ? ['pages/inventory.php', 'search'] : null);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(Csrf::token()) ?>">
    <title><?= e($page['title'] ?? 'POS') ?> · <?= e(config('app.name')) ?> POS</title>
    <link rel="icon" href="<?= e(asset('img/favicon.png')) ?>" type="image/png">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <?php foreach ($pageStyles ?? [] as $style): ?>
        <link rel="stylesheet" href="<?= e(asset($style)) ?>">
    <?php endforeach; ?>
</head>
<?php $sidebarCollapsed = ($_COOKIE['execom_sidebar'] ?? '') === 'collapsed'; // desktop icon-only sidebar (app.js sets the cookie) ?>
<body data-base-url="<?= e(base_path()) ?>" data-page="<?= e($activeKey) ?>"<?= Auth::can('pos.access') ? ' data-pos="1"' : '' ?><?= $sidebarCollapsed ? ' class="sidebar-collapsed"' : '' ?>>

<header class="topbar">
    <button class="topbar__toggle" type="button" data-sidebar-toggle aria-label="<?= $sidebarCollapsed ? 'Expand menu' : 'Collapse menu' ?>" aria-controls="sidebar" aria-expanded="<?= $sidebarCollapsed ? 'false' : 'true' ?>" title="Menu">
        <?= icon('menu') ?>
    </button>

    <a class="brand" href="<?= e(home_url()) ?>">
        <img class="brand__logo" src="<?= e(asset('img/execom-mark.png')) ?>" alt="" width="46" height="46">
        <span class="brand__text">
            <strong>EXECOM</strong>
            <small>LOGISTICS</small>
        </span>
    </a>

    <div class="topbar__title">
        <strong>POS SYSTEM</strong>
        <small>Fast <span>•</span> Secure <span>•</span> Reliable</small>
    </div>

    <?php if ($hdrSearch !== null): ?>
        <form class="topbar__search" action="<?= e(url($hdrSearch[0])) ?>" method="get" role="search">
            <?= icon('search') ?>
            <input type="search" name="<?= e($hdrSearch[1]) ?>" id="globalSearch" maxlength="100" autocomplete="off"
                   placeholder="Search for product, barcode or item name..."
                   aria-label="Search products"
                   value="<?= e($hdrSearch[1] === 'q' ? input_string($_GET, 'q', 100) : '') ?>">
        </form>
    <?php endif; ?>

    <div class="branch-chip<?= $hdrSwitch ? ' branch-chip--switch' : '' ?><?= $hdrBranchId === Branch::ALL ? ' is-all' : '' ?>" id="branchChip">
        <?= icon('store', 'branch-chip__icon') ?>
        <?php if ($hdrSwitch): ?>
            <form class="branch-switch" action="<?= e(url('pages/switch-branch.php')) ?>" method="post" data-branch-switch>
                <?= Csrf::field() ?>
                <input type="hidden" name="return" value="<?= e($hdrReturn) ?>">
                <label>
                    <small><?= $hdrBranchId === Branch::ALL ? 'Viewing' : 'Branch' ?></small>
                    <select name="branch_id" id="branchSelect" aria-label="Switch branch">
                        <?php if ($hdrCanAll): ?>
                            <option value="0"<?= $hdrBranchId === Branch::ALL ? ' selected' : '' ?>>All branches</option>
                        <?php endif; ?>
                        <?php foreach ($hdrBranches as $hdrB): ?>
                            <option value="<?= (int) $hdrB['id'] ?>"<?= $hdrBranchId === (int) $hdrB['id'] ? ' selected' : '' ?>><?= e($hdrB['code'] . ' · ' . $hdrB['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= icon('chevron-down', 'branch-switch__caret') ?>
                </label>
                <noscript><button type="submit" class="btn btn--sm btn--light">Switch</button></noscript>
            </form>
        <?php else: ?>
            <span class="branch-chip__label">
                <small>Branch</small>
                <strong data-branch-code="<?= e($hdrBranchRow['code'] ?? '') ?>"><?= e($hdrBranchRow ? $hdrBranchRow['code'] . ' · ' . $hdrBranchRow['name'] : 'No branch') ?></strong>
            </span>
        <?php endif; ?>
    </div>

    <div class="topbar__meta">
        <div class="meta-item">
            <?= icon('calendar') ?>
            <span><small>Date</small><strong data-clock-date><?= e($now->format('M j, Y')) ?></strong></span>
        </div>
        <div class="meta-item">
            <?= icon('clock') ?>
            <span><small>Time</small><strong data-clock-time><?= e($now->format('g:i:s A')) ?></strong></span>
        </div>
    </div>

    <details class="user-menu">
        <summary class="user-menu__trigger">
            <span class="avatar"><?= icon('user') ?></span>
            <span class="user-menu__name">
                <strong><?= e($user['username']) ?></strong>
                <small><?= e($roleName) ?></small>
            </span>
            <?= icon('chevron-down', 'user-menu__caret') ?>
        </summary>
        <div class="user-menu__panel">
            <div class="user-menu__head">
                <strong><?= e($user['full_name']) ?></strong>
                <small>@<?= e($user['username']) ?> · <?= e($roleName) ?> · <?= e(Branch::label()) ?></small>
            </div>
            <a href="<?= e(url('pages/account.php')) ?>" id="myAccountLink"><?= icon('lock') ?> Change Password</a>
            <?php if (Auth::can('settings.manage')): ?>
                <a href="<?= e(url('pages/settings.php')) ?>"><?= icon('settings') ?> Settings</a>
            <?php endif; ?>
            <?php if (Auth::can('users.view')): ?>
                <a href="<?= e(url('pages/users.php')) ?>"><?= icon('user') ?> Users</a>
            <?php endif; ?>
            <form action="<?= e(url('logout.php')) ?>" method="post">
                <?= Csrf::field() ?>
                <button type="submit"><?= icon('logout') ?> Sign out</button>
            </form>
        </div>
    </details>

    <form class="topbar__logout" action="<?= e(url('logout.php')) ?>" method="post">
        <?= Csrf::field() ?>
        <button type="submit" class="btn-logout"><?= icon('logout') ?><span>Logout</span></button>
    </form>
</header>

<div class="layout">
    <?php require ROOT_PATH . '/includes/sidebar.php'; ?>

    <main class="content" id="main">
        <?php require ROOT_PATH . '/includes/flash.php'; ?>
