<?php
/**
 * Tabs across the Master Data pages: the registry lists (config/master-data.php, each needs its
 * permission) + Suppliers (suppliers.view). Each page guards itself; this only hides links.
 *
 * @var string $mdTab list key or 'suppliers'
 */
?>
<nav class="settings-tabs md-tabs" aria-label="Master data lists">
    <?php foreach (MasterData::lists() as $tabKey => $tabDef): ?>
        <?php if (!Auth::can($tabDef['permission'])) continue; ?>
        <a href="<?= e(url('pages/master-data.php?list=' . rawurlencode($tabKey))) ?>" class="settings-tab<?= $mdTab === $tabKey ? ' is-active' : '' ?>"<?= $mdTab === $tabKey ? ' aria-current="page"' : '' ?>>
            <?= icon($tabDef['icon']) ?> <?= e($tabDef['label']) ?>
        </a>
    <?php endforeach; ?>
    <?php if (Auth::can('suppliers.view')): ?>
        <a href="<?= e(url('pages/suppliers.php')) ?>" class="settings-tab<?= $mdTab === 'suppliers' ? ' is-active' : '' ?>"<?= $mdTab === 'suppliers' ? ' aria-current="page"' : '' ?>>
            <?= icon('truck') ?> Suppliers
        </a>
    <?php endif; ?>
</nav>
