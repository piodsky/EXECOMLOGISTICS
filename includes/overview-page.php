<?php
/**
 * Buying / Selling Overview: the chain tab bar + every step as a row of tiles (Overview::buying() / selling()),
 * each opening its filtered list. Included by pages/buying.php and pages/selling.php.
 *
 * @var string $flowSide 'buy' | 'sell'
 */
declare(strict_types=1);

Auth::requireLogin();
if (!Flow::canOpen($flowSide)) {
    abort(403, 'You do not have access to any ' . strtolower(Flow::SIDES[$flowSide]) . ' page.');
}
$stages = $flowSide === 'buy' ? Overview::buying() : Overview::selling();
$parts  = ['flow' => $flowSide === 'buy' ? 'Ordering & receiving' : 'Orders & deliveries', 'money' => $flowSide === 'buy' ? 'Payables' : 'Receivables'];
$flowTab = 'overview';
$page = ['key' => 'overview-' . $flowSide, 'title' => Flow::SIDES[$flowSide] . ' Overview'];
$pageStyles = ['css/purchasing.css'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1><?= e(Flow::SIDES[$flowSide]) ?> overview</h1>
        <p class="muted"><?= $flowSide === 'buy'
            ? 'Every step from purchase request to payment, at a glance. Click a step to open its list.'
            : 'Every step from quotation to collection, at a glance. Click a step to open its list.' ?></p>
    </div>
    <div class="page-actions">
        <span class="badge badge--period badge--branch"><?= icon('store') ?> <?= e(Branch::label()) ?></span>
        <a class="btn btn--light" href="<?= e(url('pages/' . ($flowSide === 'buy' ? 'buying.php' : 'selling.php'))) ?>"><?= icon('clock') ?> Refresh</a>
    </div>
</div>

<?php require ROOT_PATH . '/includes/flow-nav.php'; ?>

<?php foreach ($parts as $part => $partLabel): ?>
    <?php $list = array_values(array_filter($stages, static fn (array $s): bool => $s['part'] === $part)); ?>
    <?php if (!$list) continue; ?>
    <section class="ov-part" data-part="<?= e($part) ?>">
        <h2 class="ov-part__title"><?= e($partLabel) ?></h2>
        <ol class="ov-flow">
            <?php foreach ($list as $s): ?>
                <li class="ov-stage<?= $s['count'] > 0 ? ' has-work' : '' ?><?= $s['tone'] ? ' ov-stage--' . e($s['tone']) : '' ?>" data-stage="<?= e($s['key']) ?>">
                    <a href="<?= e(url($s['url'])) ?>">
                        <span class="ov-stage__count"><?= number_format($s['count']) ?></span>
                        <strong class="ov-stage__label"><?= e($s['label']) ?></strong>
                        <?php if ($s['amount'] !== null): ?><span class="ov-stage__amount"><?= e($s['amount']) ?></span><?php endif; ?>
                        <?php if ($s['note']): ?><span class="ov-stage__note"><?= icon('alert') ?> <?= e($s['note']) ?></span><?php endif; ?>
                        <small class="ov-stage__hint"><?= e($s['hint']) ?></small>
                    </a>
                </li>
            <?php endforeach; ?>
        </ol>
    </section>
<?php endforeach; ?>

<p class="doc-foot muted"><?= icon('info') ?> <span>Numbers are for <?= e(Branch::label()) ?>. Each document page also shows its whole chain
    (request → PO → receiving → invoice → payment, or quotation → order → delivery → bill → collection) at the top.</span></p>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
