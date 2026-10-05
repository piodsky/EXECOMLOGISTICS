<?php
/**
 * The two document chains as one tab bar each (includes/flow-nav.php), shown on every list page of the chain:
 *   buy   Overview · Requests · PO Internal · Receiving | Supplier Invoices · Disbursements
 *   sell  Overview · Quotations · PO Outgoing · Deliveries · Tracking | Bills · Collections · Checks · Statement
 * A tab is shown only when the user can open that page (the same checks the pages make); the '|' starts the money
 * part (payables / receivables). The Overview pages (buying.php / selling.php) open for anyone with a tab.
 */
declare(strict_types=1);

final class Flow
{
    public const SIDES = ['buy' => 'Buying', 'sell' => 'Selling'];

    /** @return array<string, array{0:string, 1:string, 2:string, 3:bool, 4:bool}> key => [label, icon, path, visible, starts money part] */
    public static function tabs(string $side): array
    {
        $orders  = Auth::canAny(...CustomerOrders::VIEW_PERMISSIONS);
        $collect = Collections::canView();
        $tabs = match ($side) {
            'buy' => [
                'requests'      => ['Requests', 'clipboard', 'pages/purchase-requests.php', Auth::canAny('purchasing.request', 'purchasing.approve', 'purchasing.order'), false],
                'orders'        => ['PO Internal', 'cart', 'pages/purchase-orders.php', PurchaseOrders::canView(), false],
                'receiving'     => ['Receiving', 'truck', 'pages/receiving.php', Auth::can('receiving.view'), false],
                'invoices'      => ['Supplier Invoices', 'file', 'pages/payables.php', Payables::canView(), true],
                'disbursements' => ['Disbursements', 'wallet', 'pages/disbursements.php', Payables::canView(), false],
            ],
            'sell' => [
                'quotes'     => ['Quotations', 'tag', 'pages/quotations.php', $orders, false],
                'orders'     => ['PO Outgoing', 'file', 'pages/customer-orders.php', $orders, false],
                'deliveries' => ['Deliveries', 'truck', 'pages/deliveries.php', $orders, false],
                'tracking'   => ['Tracking', 'clock', 'pages/order-tracking.php', $orders, false],
                'receivables' => ['Bills', 'wallet', 'pages/collections.php', $collect, true],
                'receipts'   => ['Collections', 'receipt', 'pages/collection-receipts.php', $collect, false],
                'checks'     => ['Checks', 'check', 'pages/checks.php', $collect, false],
                'statement'  => ['Statement', 'printer', 'pages/soa.php', $collect, false],
            ],
            default => [],
        };
        $tabs = array_filter($tabs, static fn (array $t): bool => $t[3]);
        if ($tabs) {
            $tabs = ['overview' => ['Overview', 'grid', $side === 'buy' ? 'pages/buying.php' : 'pages/selling.php', true, false]] + $tabs;
        }
        return $tabs;
    }

    public static function canOpen(string $side): bool
    {
        return self::tabs($side) !== [];
    }
}
