<?php
/**
 * Buying overview: purchase requests → PO Internal → receiving → supplier invoices → disbursements, one row of stage
 * tiles in the branch scope (includes/overview-page.php, Overview::buying()). Open to anyone with a buying tab.
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$flowSide = 'buy';
require ROOT_PATH . '/includes/overview-page.php';
