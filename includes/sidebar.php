<?php
/**
 * Sidebar — items come from config/menu.php and are hidden when the
 * user lacks the item's permission (the page itself also enforces this).
 *
 * @var string $activeKey
 */
?>
<aside class="sidebar" id="sidebar">
    <nav class="sidebar__nav" aria-label="Main menu">
        <?php foreach (config('menu', []) as $key => $item): ?>
            <?php if (!can_open_menu($item)) continue; ?>
            <a href="<?= e(url(menu_path($item))) ?>"
               class="nav-link<?= $key === $activeKey ? ' is-active' : '' ?>"
               <?= $key === $activeKey ? 'aria-current="page"' : '' ?>>
                <?= icon($item['icon']) ?>
                <span><?= e($item['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar__bottom">
        <?php if (Auth::can('pos.access')): ?>
            <a class="scan-card" href="<?= e(url('pages/pos.php')) ?>" data-scan-trigger>
                <?= icon('barcode') ?>
                <span>
                    <strong>Scan Barcode</strong>
                    <small>Use scanner or type</small>
                </span>
                <kbd>F2</kbd>
            </a>
        <?php endif; ?>

        <div class="sidebar__footer">
            <strong>EXECOM Logistics</strong>
            <small>Inventory <span>•</span> Sales <span>•</span> Distribution</small>
            <small class="sidebar__version">v<?= e(config('app.version')) ?></small>
        </div>
    </div>
</aside>
<div class="sidebar-backdrop" data-sidebar-toggle hidden></div>
