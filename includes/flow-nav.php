<?php
/**
 * One tab bar across a whole document chain (Flow::tabs()): buying = requests → POs → receiving → supplier invoices →
 * disbursements, selling = quotations → customer POs → deliveries → bills → collections → checks → statements.
 *
 * @var string $flowSide 'buy' | 'sell'
 * @var string $flowTab  key of the current tab
 */
declare(strict_types=1);

$flowTabs = Flow::tabs($flowSide);
?>
<?php if ($flowTabs): ?>
<nav class="flow-tabs no-print" aria-label="<?= e(Flow::SIDES[$flowSide]) ?>" data-flow="<?= e($flowSide) ?>">
    <?php foreach ($flowTabs as $tabKey => [$tabLabel, $tabIcon, $tabPath, , $money]): ?>
        <?php if ($money): ?><span class="flow-tabs__sep" aria-hidden="true"><?= $flowSide === 'buy' ? 'Payables' : 'Receivables' ?></span><?php endif; ?>
        <a href="<?= e(url($tabPath)) ?>" class="flow-tab<?= $flowTab === $tabKey ? ' is-active' : '' ?>" data-tab="<?= e($tabKey) ?>"<?= $flowTab === $tabKey ? ' aria-current="page"' : '' ?>>
            <?= icon($tabIcon) ?> <?= e($tabLabel) ?>
        </a>
    <?php endforeach; ?>
</nav>
<?php endif; ?>
