<?php
/**
 * The Buying / Selling Overview pages (pages/buying.php, pages/selling.php): every step of the chain as one row of
 * stage tiles (count, amount, warning), left to right, in the current branch scope. A tile is included only when the
 * user can open the list it links to (the same checks as Flow::tabs()). Amounts that are costs (POs, supplier
 * invoices) only appear on tiles that already need products.cost / Payables::canView().
 *
 * stage = ['key', 'label', 'hint', 'count' => int, 'amount' => ?string, 'note' => ?string, 'tone' => ''|'warn'|'danger',
 *          'url' => app path, 'part' => 'flow' | 'money']
 */
declare(strict_types=1);

final class Overview
{
    /** @return list<array> */
    public static function buying(): array
    {
        $stages = [];
        if (Auth::canAny('purchasing.request', 'purchasing.approve', 'purchasing.order')) {
            [$n] = self::row("SELECT COUNT(*) FROM purchase_requests t WHERE %s AND t.status = 'requested'");
            $stages[] = self::stage('pr_approve', 'Requests to approve', 'Purchase requests waiting for a branch admin', $n, null, null,
                'pages/purchase-requests.php?status=requested');
            [$n] = self::row("SELECT COUNT(*) FROM purchase_requests t WHERE %s AND t.status = 'approved'");
            $stages[] = self::stage('pr_order', 'Approved, to order', 'Approved requests not on a PO yet', $n, null, null,
                'pages/purchase-requests.php?status=approved');
        }
        if (PurchaseOrders::canView()) {
            [$n, $amt] = self::row("SELECT COUNT(*), COALESCE(SUM(t.total_amount), 0) FROM purchase_orders t WHERE %s AND t.status = 'pending'");
            $stages[] = self::stage('po_approve', 'POs to approve', 'Purchase orders sent for approval', $n, $amt, null, 'pages/purchase-orders.php?status=pending');
            [$n, $late, $amt] = self::row(
                "SELECT COUNT(*), COALESCE(SUM(t.expected_date < CURDATE()), 0),
                        COALESCE(SUM((SELECT SUM((l.qty_ordered - l.qty_received) * l.unit_cost) FROM purchase_order_lines l WHERE l.po_id = t.id)), 0)
                   FROM purchase_orders t WHERE %s AND t.status IN ('approved', 'partial')"
            );
            $stages[] = self::stage('po_open', 'Awaiting delivery', 'Approved POs not fully received (value still due)', $n, $amt,
                $late > 0 ? "{$late} overdue" : null, 'pages/purchase-orders.php?status=open', $late > 0 ? 'danger' : '');
        }
        if (Auth::can('receiving.view')) {
            [$n] = self::row("SELECT COUNT(*) FROM receiving_reports t WHERE %s AND t.status = 'draft'");
            $stages[] = self::stage('rr_draft', 'Receiving to post', 'Receiving report drafts (stock not added yet)', $n, null, null, 'pages/receiving.php?status=draft');
        }
        if (Payables::canView()) {
            [$n, $amt] = self::row(
                "SELECT COUNT(*), COALESCE(SUM(t.total_cost), 0) FROM receiving_reports t WHERE %s AND t.status = 'posted'
                    AND NOT EXISTS (SELECT 1 FROM supplier_invoices i WHERE i.receiving_id = t.id AND i.status <> 'cancelled')"
            );
            $stages[] = self::stage('to_invoice', 'Not invoiced', 'Received, no supplier invoice recorded', $n, $amt, null, 'pages/payables.php', '', 'money');
            [$n, $amt, $over, $soon] = self::row(
                "SELECT COUNT(*), COALESCE(SUM(t.amount - t.paid_amount), 0), COALESCE(SUM(t.due_date < CURDATE()), 0),
                        COALESCE(SUM(t.due_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 7 DAY), 0)
                   FROM supplier_invoices t WHERE %s AND t.status = 'open'"
            );
            $note = implode(' · ', array_filter([$over > 0 ? "{$over} overdue" : null, $soon > 0 ? "{$soon} due in 7 days" : null]));
            $stages[] = self::stage('to_pay', 'To pay', 'Open supplier invoices (balance)', $n, $amt, $note ?: null,
                $over > 0 ? 'pages/payables.php?status=overdue' : 'pages/payables.php?status=open', $over > 0 ? 'danger' : ($soon > 0 ? 'warn' : ''), 'money');
            [$n, $amt] = self::row("SELECT COUNT(*), COALESCE(SUM(t.amount_paid), 0) FROM disbursements t WHERE %s AND t.status = 'posted' AND t.check_status = 'issued'");
            $stages[] = self::stage('checks_out', 'Checks not cleared', 'Checks issued to suppliers, not cleared by the bank', $n, $amt, null,
                'pages/disbursements.php?checks=issued', '', 'money');
        }
        return $stages;
    }

    /** @return list<array> */
    public static function selling(): array
    {
        $stages = [];
        if (Auth::canAny(...CustomerOrders::VIEW_PERMISSIONS)) {
            [$n, $amt, $exp] = self::row(
                "SELECT COUNT(*), COALESCE(SUM(t.subtotal), 0), COALESCE(SUM(t.valid_until < CURDATE()), 0) FROM quotations t WHERE %s AND t.status = 'sent'"
            );
            $stages[] = self::stage('quotes', 'Quotations out', 'Sent, waiting for the customer (before VAT)', $n, $amt,
                $exp > 0 ? "{$exp} expired" : null, 'pages/quotations.php?status=sent', $exp > 0 ? 'warn' : '');
            [$n, $amt] = self::row("SELECT COUNT(*), COALESCE(SUM(t.subtotal), 0) FROM customer_orders t WHERE %s AND t.status = 'pending'");
            $stages[] = self::stage('co_confirm', 'Orders to confirm', 'Customer POs waiting for a branch admin', $n, $amt, null, 'pages/customer-orders.php?status=pending');
            [$n, $late] = self::row(
                "SELECT COUNT(*), COALESCE(SUM(t.due_date < CURDATE()), 0) FROM customer_orders t WHERE %s AND t.status IN ('confirmed', 'partial')"
            );
            $stages[] = self::stage('co_deliver', 'To deliver', 'Confirmed orders with items still to deliver', $n, null,
                $late > 0 ? "{$late} past the deadline" : null, 'pages/customer-orders.php?status=open', $late > 0 ? 'danger' : '');
            [$n] = self::row(
                "SELECT COUNT(*) FROM customer_orders t WHERE %s
                    AND EXISTS (SELECT 1 FROM customer_deliveries d WHERE d.order_id = t.id AND d.status <> 'cancelled' AND d.sale_id IS NULL)"
            );
            $stages[] = self::stage('co_bill', 'To bill', 'Delivered, delivery receipts not billed yet', $n, null, null, 'pages/customer-orders.php?status=to_bill',
                $n > 0 ? 'warn' : '');
        }
        if (Collections::canView()) {
            [$n, $amt, $over] = self::row(
                "SELECT COUNT(*), COALESCE(SUM(t.total - t.settled_amount), 0),
                        COALESCE(SUM(COALESCE(t.due_date, DATE(t.created_at)) < CURDATE()), 0)
                   FROM sales t WHERE %s AND t.payment_type = 'charge' AND t.status = 'completed' AND t.settled_amount < t.total"
            );
            $stages[] = self::stage('unpaid', 'Unpaid bills', 'On-account bills not fully collected (balance)', $n, $amt,
                $over > 0 ? "{$over} overdue" : null, 'pages/collections.php', $over > 0 ? 'danger' : '', 'money');
            [$n, $amt, $dep] = self::row(
                "SELECT COUNT(*), COALESCE(SUM(t.amount_received), 0), COALESCE(SUM(t.check_status = 'deposited'), 0)
                   FROM collections t WHERE %s AND t.status = 'posted' AND t.check_status IN ('on_hand', 'deposited')"
            );
            $stages[] = self::stage('checks_in', 'Checks not cleared', 'Customer checks on hand or deposited', $n, $amt,
                $dep > 0 ? "{$dep} deposited, waiting to clear" : null, 'pages/checks.php?check=on_hand', $n - $dep > 0 ? 'warn' : '', 'money');
            [$n] = self::row("SELECT COUNT(*) FROM collections t WHERE %s AND t.status = 'posted' AND t.form_2307 = 'pending'");
            $stages[] = self::stage('forms', '2307 to receive', 'Withholding certificates still due from customers', $n, null, null,
                'pages/collection-receipts.php?forms=pending', '', 'money');
        }
        return $stages;
    }

    // ------------------------------------------------------------------

    /** One row of a scoped query: %s = Branch::scopeSql('t.branch_id'). @return list<int|string> */
    private static function row(string $sql): array
    {
        [$scope, $params] = Branch::scopeSql('t.branch_id');
        $stmt = db()->prepare(sprintf($sql, $scope));
        $stmt->execute($params);
        return array_map(static fn ($v) => is_numeric($v) && !str_contains((string) $v, '.') ? (int) $v : (string) $v, $stmt->fetch(PDO::FETCH_NUM) ?: []);
    }

    private static function stage(string $key, string $label, string $hint, int $count, int|string|null $amount, ?string $note, string $url,
                                  string $tone = '', string $part = 'flow'): array
    {
        return ['key' => $key, 'label' => $label, 'hint' => $hint, 'count' => $count,
            'amount' => $amount === null ? null : money((string) $amount), 'note' => $note, 'tone' => $count > 0 ? $tone : '',
            'url' => $url, 'part' => $part];
    }
}
