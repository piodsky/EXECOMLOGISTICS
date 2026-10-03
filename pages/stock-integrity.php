<?php
/**
 * Stock integrity checks (inventory.integrity; non-menu page linked from Inventory).
 * Each check lists the rows that break its rule: 0 rows = OK (green). Scoped to the current branch.
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
Auth::requirePermission('inventory.integrity');
$page = ['key' => 'inventory', 'title' => 'Stock Integrity', 'icon' => 'shield'];

$checks   = Integrity::run();
$problems = Integrity::problemCount($checks);
$canItems = Auth::can('inventory.view');
$heading  = static fn (string $col): string => ucfirst(str_replace(['_id', '_'], [' ID', ' '], $col));

$pageStyles = ['css/sales.css', 'css/receiving.css'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <?php if ($canItems): ?>
            <a class="back-link" href="<?= e(url('pages/inventory.php')) ?>"><?= icon('arrow-left') ?> Inventory</a>
        <?php endif; ?>
        <h1>Stock Integrity</h1>
        <p class="muted">Checks that stock balances, stock movements, serial numbers and receiving reports agree. Checked <?= e(date('M j, Y g:i A')) ?>.</p>
    </div>
    <div class="page-actions">
        <span class="badge badge--period badge--branch"><?= icon('store') ?> <?= e(Branch::label()) ?></span>
        <a class="btn btn--light" href="<?= e(url('pages/stock-integrity.php')) ?>"><?= icon('clock') ?> Run Again</a>
    </div>
</div>

<div class="alert <?= $problems === 0 ? 'alert--success' : 'alert--error' ?>" role="status" id="integritySummary">
    <?= icon($problems === 0 ? 'check' : 'alert') ?>
    <span><?= $problems === 0
        ? 'All ' . count($checks) . ' checks passed: no problems found.'
        : e(number_format($problems)) . ' ' . ($problems === 1 ? 'problem' : 'problems') . ' found. Each row below breaks the rule of its check.' ?></span>
</div>

<div class="integrity-list">
    <?php foreach ($checks as $check): ?>
        <?php $n = count($check['rows']); ?>
        <section class="card integrity-check <?= $n === 0 ? 'is-ok' : 'is-bad' ?>" data-check="<?= e($check['key']) ?>">
            <header class="card__head">
                <h2><?= icon($n === 0 ? 'check' : 'alert') ?> <?= e($check['label']) ?></h2>
                <span class="badge <?= $n === 0 ? 'badge--success' : 'badge--danger' ?>"><?= $n === 0 ? 'OK · 0 rows' : e(($n >= Integrity::MAX_ROWS ? $n . '+' : (string) $n) . ($n === 1 ? ' row' : ' rows')) ?></span>
            </header>
            <?php if ($n > 0): ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr><?php foreach (array_keys($check['rows'][0]) as $col): ?><th><?= e($heading((string) $col)) ?></th><?php endforeach; ?></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($check['rows'] as $row): ?>
                            <tr>
                                <?php foreach ($row as $col => $value): ?>
                                    <td>
                                        <?php if ($col === 'product_id' && $canItems && $value !== null): ?>
                                            <a href="<?= e(url('pages/product-form.php?id=' . (int) $value)) ?>"><?= e((string) $value) ?></a>
                                        <?php else: ?>
                                            <?= e($value === null ? '—' : (string) $value) ?>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
</div>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
