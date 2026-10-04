<?php
/**
 * Period filter (quick ranges + From / To) for a report page using includes/report-kit.php.
 * @var array $presets, string $from, string $to, Closure $reportLink, string $reportPath, string $periodLabel
 */
?>
<section class="card report-filters no-print" aria-label="Report period">
    <nav class="quick-ranges" aria-label="Quick periods">
        <?php foreach ($presets as $label => [$pf, $pt]): ?>
            <?php $active = $from === $pf->format('Y-m-d') && $to === $pt->format('Y-m-d'); ?>
            <a class="chip<?= $active ? ' is-active' : '' ?>"<?= $active ? ' aria-current="true"' : '' ?>
               href="<?= e($reportLink(['from' => $pf->format('Y-m-d'), 'to' => $pt->format('Y-m-d')])) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>
    <form class="toolbar report-range" method="get" action="<?= e(url($reportPath)) ?>">
        <label class="date-field"><span>From</span>
            <input class="form-input" type="date" name="from" value="<?= e($from) ?>" max="<?= e(date('Y-m-d')) ?>" required>
        </label>
        <label class="date-field"><span>To</span>
            <input class="form-input" type="date" name="to" value="<?= e($to) ?>" max="<?= e(date('Y-m-d')) ?>" required>
        </label>
        <button type="submit" class="btn btn--primary">Apply</button>
    </form>
</section>
<p class="report-period"><?= icon('calendar') ?> <strong id="periodLabel"><?= e($periodLabel) ?></strong>
    <span class="muted">· <?= e(Branch::label()) ?></span></p>
