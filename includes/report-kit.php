<?php
/**
 * Shared helpers for the Reports pages (sales, profit, jobs, price overrides, branches).
 * Before including: $reportPath (e.g. 'pages/report-jobs.php'). Defines:
 *   $period, $from, $to         Reports::period() of ?from=&to=
 *   $reportLink(array $q)       URL of this report with a query
 *   $presets                    quick ranges label => [from, to]
 *   $periodLabel, $fmtRange     "Sep 5 – Oct 4, 2026 · 30 days"
 *   $barRow(...)                one horizontal bar row (SVG, % width: no inline styles under the CSP)
 *   $share(part, whole)         "42.5%"
 *   $pct(?float)                "12.3%" or "—"
 *   $csvStart(string $name, string $title) sends the CSV headers + BOM + title row, returns the stream
 */
declare(strict_types=1);

$period = Reports::period(input_date($_GET, 'from'), input_date($_GET, 'to'));
['from' => $from, 'to' => $to] = $period;

$reportLink = static fn (array $q): string => url($reportPath) . ($q ? '?' . http_build_query($q) : '');

$kitToday = new DateTimeImmutable('today');
$presets = [
    'Today'        => [$kitToday, $kitToday],
    'Last 7 days'  => [$kitToday->modify('-6 days'), $kitToday],
    'Last 30 days' => [$kitToday->modify('-29 days'), $kitToday],
    'This month'   => [$kitToday->modify('first day of this month'), $kitToday],
    'Last month'   => [$kitToday->modify('first day of last month'), $kitToday->modify('last day of last month')],
    'This year'    => [$kitToday->modify('first day of january this year'), $kitToday],
];
$fmtRange = static function (string $a, string $b): string {
    if ($a === $b) {
        return date('M j, Y', strtotime($a));
    }
    $sameYear = substr($a, 0, 4) === substr($b, 0, 4);
    return date($sameYear ? 'M j' : 'M j, Y', strtotime($a)) . ' – ' . date('M j, Y', strtotime($b));
};
$periodLabel = $fmtRange($from, $to) . ' · ' . $period['days'] . ' ' . ($period['days'] === 1 ? 'day' : 'days');

$share = static fn (float $part, float $whole): string => $whole > 0 ? number_format($part / $whole * 100, 1) . '%' : '0%';
$pct   = static fn (?float $v): string => $v === null ? '—' : number_format($v, 1) . '%';

$barRow = static function (string $label, ?string $sub, string $value, float $fraction, string $tipNote): void {
    $p     = max(0.0, min(1.0, $fraction)) * 100;
    $width = $p > 0 ? max($p, 0.6) : 0;
    ?>
    <li class="barlist__row" tabindex="0" data-tip-title="<?= e($label) ?>" data-tip-value="<?= e($value) ?>" data-tip-note="<?= e($tipNote) ?>">
        <span class="barlist__label"><?= e($label) ?><?php if ($sub !== null && $sub !== ''): ?> <small><?= e($sub) ?></small><?php endif; ?></span>
        <svg class="barlist__bar" width="100%" height="14" aria-hidden="true" focusable="false">
            <?php if ($width > 0): ?>
                <rect class="bar" width="<?= e(number_format($width, 2, '.', '')) ?>%" height="14" rx="4"/>
                <?php if ($width >= 3): ?><rect class="bar" width="4" height="14"/><?php endif; ?>
            <?php endif; ?>
        </svg>
        <span class="barlist__value"><?= e($value) ?></span>
    </li>
    <?php
};

$csvStart = static function (string $name, string $title) use ($from, $to) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="execom-' . $name . '-' . $from . '-to-' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads it correctly
    fputcsv($out, ['EXECOM Logistics ' . $title, $from . ' to ' . $to, csv_cell(Branch::label())]);
    fputcsv($out, []);
    return $out;
};
