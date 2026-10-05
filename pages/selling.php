<?php
/**
 * Selling overview: quotations → PO Outgoing → deliveries → bills → collections / checks / 2307, one row of stage
 * tiles in the branch scope (includes/overview-page.php, Overview::selling()). Open to anyone with a selling tab.
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$flowSide = 'sell';
require ROOT_PATH . '/includes/overview-page.php';
