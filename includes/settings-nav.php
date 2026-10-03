<?php
/**
 * Tabs across the Settings pages (only the ones the user may open; see settings_tabs()).
 *
 * @var string $settingsTab 'company' | 'users' | 'roles' | 'branches' | 'audit'
 */
?>
<nav class="settings-tabs" aria-label="Settings sections">
    <?php foreach (settings_tabs() as $tabKey => [$tabLabel, $tabIcon, $tabPath, $tabPerm]): ?>
        <?php if (!Auth::can($tabPerm)) continue; ?>
        <a href="<?= e(url($tabPath)) ?>" class="settings-tab<?= $settingsTab === $tabKey ? ' is-active' : '' ?>"<?= $settingsTab === $tabKey ? ' aria-current="page"' : '' ?>>
            <?= icon($tabIcon) ?> <?= e($tabLabel) ?>
        </a>
    <?php endforeach; ?>
</nav>
