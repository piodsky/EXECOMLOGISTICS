<?php
/**
 * Tabs on the supplier invoices / disbursements pages: the shared buy chain bar (includes/flow-nav.php, Flow::tabs('buy')).
 *
 * @var string $payablesTab the current tab key
 */
$flowSide = 'buy';
$flowTab  = $payablesTab; // 'invoices' | 'disbursements'
require ROOT_PATH . '/includes/flow-nav.php';
