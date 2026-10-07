<?php
/**
 * Dashboard data (pages/dashboard.php, reports.view). Everything follows the current branch scope; "Needs you" work
 * lists only exist for a concrete branch and only for the permissions the user holds. Cost / profit figures only with
 * products.cost. Loaded once per page view (no polling).
 */
declare(strict_types=1);

final class Dashboard
{
    /** Net sales (incl. VAT) + transactions for [from, to] in scope. @return array{count:int, net:string} */
    public static function sales(string $from, string $to): array
    {
        $t = Reports::totals($from, $to);
        return ['count' => (int) $t['transactions'], 'net' => (string) $t['net']];
    }

    /**
     * Work waiting for the current user at the current branch: [label, hint, count, url, icon] (count > 0 only).
     * @return list<array{0:string, 1:string, 2:int, 3:string, 4:string}>
     */
    public static function needsYou(): array
    {
        if (!Branch::isConcrete()) {
            return [];
        }
        $cur = (int) Branch::current();
        $out = [];
        $add = static function (string $label, string $hint, int $count, string $path, string $icon) use (&$out): void {
            if ($count > 0) {
                $out[] = [$label, $hint, $count, url($path), $icon];
            }
        };
        $one = static function (string $sql, array $params): int {
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            return (int) $stmt->fetchColumn();
        };

        $t = Transfers::workCounts();
        if (Auth::can('transfers.approve')) {
            $add('Transfers to approve', 'Other branches ask for your stock', $t['approve'], 'pages/transfers.php?direction=outgoing&status=requested', 'check');
        }
        if (Auth::can('transfers.release')) {
            $add('Transfers to release', 'Approved, ready to send', $t['release'], 'pages/transfers.php?direction=outgoing&status=approved', 'truck');
        }
        if (Auth::can('transfers.receive')) {
            $add('Incoming transfers', 'In transit to this branch', $t['receive'], 'pages/transfers.php?direction=incoming&status=released', 'box');
        }
        if (Auth::can('counts.approve')) {
            $add('Stock counts to approve', 'Submitted counts', $one("SELECT COUNT(*) FROM inventory_docs WHERE branch_id = ? AND doc_type = 'count' AND status = 'submitted'", [$cur]),
                'pages/stock-docs.php?type=count&status=submitted', 'clipboard');
        }
        if (Auth::can('purchasing.approve')) {
            $pr = PurchaseRequests::workCounts();
            $add('Purchase requests to approve', 'Staff ask for items', $pr['approve'], 'pages/purchase-requests.php?status=requested', 'clipboard');
        }
        if (PurchaseOrders::canView()) {
            $po = PurchaseOrders::workCounts();
            if (Auth::can('purchasing.approve')) {
                $add('Purchase orders to approve', 'Ready to send to the supplier', $po['approve'], 'pages/purchase-orders.php?status=pending', 'cart');
            }
            $add('Overdue deliveries', 'Purchase orders past the expected date', $po['overdue'], 'pages/purchase-orders.php?status=overdue', 'clock');
        }
        if (Auth::canAny(...CustomerOrders::VIEW_PERMISSIONS)) {
            $co = CustomerOrders::workCounts();
            if (Auth::can('customer_orders.approve')) {
                $add('Customer orders to confirm', 'Confirming reserves the stock', $co['confirm'], 'pages/customer-orders.php?status=pending', 'file');
            }
            if (Auth::can('customer_orders.deliver')) {
                $add('Customer orders to deliver', 'Confirmed, stock reserved', $co['deliver'], 'pages/customer-orders.php?status=open', 'truck');
            }
            if (Auth::can('customer_orders.bill')) {
                $add('Deliveries to bill', 'Delivered, not billed yet', $co['bill'], 'pages/customer-orders.php?status=to_bill', 'receipt');
            }
            $add('Overdue customer orders', 'Past the delivery deadline', $co['overdue'], 'pages/customer-orders.php?status=overdue', 'clock');
        }
        if (Collections::canView()) {
            $ar = Collections::workCounts();
            $add('Bills to collect, over 30 days', 'On account, still unpaid', $ar['overdue'], 'pages/collections.php', 'wallet');
            $add('Withholding certificates', 'BIR 2307 / 2306 still to receive', $ar['forms'], 'pages/collection-receipts.php?forms=pending', 'file');
            $add('Checks to deposit', 'Check date reached, still on hand', $ar['checks'], 'pages/checks.php', 'wallet');
        }
        if (Payables::canView()) {
            $ap = Payables::workCounts();
            $add('Supplier invoices overdue', 'Past the due date, unpaid', $ap['overdue'], 'pages/payables.php?status=overdue', 'clipboard');
            $add('Supplier invoices due in 7 days', 'Prepare the payment', $ap['due_soon'], 'pages/payables.php', 'clock');
            $add('Receiving reports to invoice', "Enter the supplier's invoice", $ap['to_invoice'], 'pages/payables.php#toInvoice', 'truck');
        }
        if (Auth::can('receiving.post')) {
            $add('Receiving drafts', 'Not posted yet', $one("SELECT COUNT(*) FROM receiving_reports WHERE branch_id = ? AND status = 'draft'", [$cur]),
                'pages/receiving.php?status=draft', 'truck');
        }
        if (Auth::canAny(...JobOrders::VIEW_PERMISSIONS)) {
            $w = JobOrders::workCounts();
            if (Auth::can('job_parts.issue')) {
                $add('Parts to issue', 'Technicians are waiting for parts', $w['parts'], 'pages/job-orders.php?parts=pending', 'box');
            }
            if (Auth::can('job_orders.release')) {
                $add('Jobs ready to release', 'Completed: bill and return the device', $w['completed'], 'pages/job-orders.php?status=completed', 'check');
            }
            if (Auth::can('job_orders.assign')) {
                $add('Unassigned jobs', 'New jobs nobody has taken', $w['unassigned'], 'pages/job-orders.php?status=new&technician=none', 'wrench');
            }
            $add('Waiting for the customer', 'Quotations to approve', $w['for_approval'], 'pages/job-orders.php?status=for_approval', 'clock');
        }
        return $out;
    }

    /** Open jobs by status in scope (OPEN statuses + completed). @return array<string,int> */
    public static function jobsByStatus(): array
    {
        [$scope, $params] = Branch::scopeSql('j.branch_id');
        $stmt = db()->prepare(
            "SELECT j.status, COUNT(*) FROM job_orders j WHERE j.status IN ('" . implode("','", [...JobOrders::OPEN, 'completed']) . "') AND {$scope} GROUP BY j.status"
        );
        $stmt->execute($params);
        $found = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $out = [];
        foreach ([...JobOrders::OPEN, 'completed'] as $s) {
            $out[$s] = (int) ($found[$s] ?? 0);
        }
        return $out;
    }

    /** Open jobs per technician in scope (workload), most first; 'overdue' = past the expected date. */
    public static function workload(): array
    {
        [$scope, $params] = Branch::scopeSql('j.branch_id');
        $stmt = db()->prepare(
            "SELECT COALESCE(u.full_name, 'Unassigned') AS name, COUNT(*) AS open_jobs,
                    SUM(j.expected_at IS NOT NULL AND j.expected_at < CURDATE()) AS overdue
               FROM job_orders j LEFT JOIN users u ON u.id = j.technician_id
              WHERE j.status IN ('" . implode("','", JobOrders::OPEN) . "') AND {$scope}
              GROUP BY u.id, u.full_name ORDER BY open_jobs DESC, name"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Lowered prices + discounts given today in scope: count and amount. @return array{count:int, amount:string} */
    public static function overridesToday(): array
    {
        $today = date('Y-m-d');
        $s = Reports::overrideSummary($today, $today)['totals'];
        return ['count' => $s['lines'] + $s['discounts'], 'amount' => from_cents(to_cents($s['given']) + to_cents($s['discount_total']))];
    }

    /** Units of every branch in transit (released, not received) and transfers released more than 3 days ago. */
    public static function inTransit(): array
    {
        [$from, $p1] = Branch::scopeSql('t.from_branch_id');
        [$to, $p2]   = Branch::scopeSql('t.to_branch_id');
        $stmt = db()->prepare(
            "SELECT COUNT(*) AS transfers, COALESCE(SUM(t.total_qty), 0) AS units, COALESCE(SUM(t.released_at < NOW() - INTERVAL 3 DAY), 0) AS late
               FROM stock_transfers t WHERE t.status = 'released' AND ({$from} OR {$to})"
        );
        $stmt->execute([...$p1, ...$p2]);
        $r = $stmt->fetch();
        return ['transfers' => (int) $r['transfers'], 'units' => (int) $r['units'], 'late' => (int) $r['late']];
    }

    /**
     * Per active branch the user may open (All branches view): sales today / this month, low stock, open jobs,
     * jobs ready to release.
     */
    public static function branchRows(): array
    {
        if (Branch::current() !== Branch::ALL) {
            return [];
        }
        $today = date('Y-m-d');
        $month = date('Y-m-01');
        $rows = [];
        foreach (Reports::branchComparison($month, $today) as $b) {
            $rows[(int) $b['id']] = $b + ['today_net' => '0', 'today_sales' => 0];
        }
        $stmt = db()->prepare(
            "SELECT s.branch_id, COUNT(*) AS sales, SUM(s.total) AS net FROM sales s
              WHERE s.status = 'completed' AND s.created_at >= ? GROUP BY s.branch_id"
        );
        $stmt->execute([$today . ' 00:00:00']);
        foreach ($stmt->fetchAll() as $r) {
            if (isset($rows[(int) $r['branch_id']])) {
                $rows[(int) $r['branch_id']]['today_net'] = $r['net'];
                $rows[(int) $r['branch_id']]['today_sales'] = (int) $r['sales'];
            }
        }
        $stmt = db()->prepare("SELECT branch_id, COUNT(*) FROM job_orders WHERE status = 'completed' GROUP BY branch_id");
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $bid => $n) {
            if (isset($rows[(int) $bid])) {
                $rows[(int) $bid]['ready'] = (int) $n;
            }
        }
        return array_values($rows);
    }

    /** Latest audit entries in scope (audit_logs.view only). */
    public static function recentActivity(int $limit = 8): array
    {
        if (!Auth::can('audit_logs.view')) {
            return [];
        }
        return Audit::search(['q' => '', 'module' => '', 'user' => null, 'from' => null, 'to' => null], $limit, 0);
    }

    // ------------------------------------------------------------------
    // Money position, sales pace, coming up, stock health, service, people (all in the branch scope)
    // ------------------------------------------------------------------

    /** One scalar row of a query with %s = Branch::scopeSql($col). */
    private static function scoped(string $sql, string $col, array $before = [], array $after = []): array
    {
        [$scope, $params] = Branch::scopeSql($col);
        $stmt = db()->prepare(sprintf($sql, $scope));
        $stmt->execute([...$before, ...$params, ...$after]);
        return $stmt->fetch(PDO::FETCH_NUM) ?: [];
    }

    /**
     * Receivables (Collections::canView) and payables (Payables::canView): balances, overdue, aging buckets, checks
     * not cleared, collected this week / month (by method). Null parts when the user may not see them.
     */
    public static function money(): array
    {
        $out = ['receivable' => null, 'payable' => null];
        $monday = date('Y-m-d', strtotime('monday this week'));
        $month  = date('Y-m-01');
        if (Collections::canView()) {
            $aging = Collections::aging([]);
            $overdue = $aging['cents'] - $aging['buckets']['current']['cents'];
            [$chkN, $chkAmt, $depN] = self::scoped(
                "SELECT COUNT(*), COALESCE(SUM(c.amount_received), 0), COALESCE(SUM(c.check_status = 'deposited'), 0) FROM collections c
                  WHERE c.status = 'posted' AND c.check_status IN ('on_hand', 'deposited') AND %s", 'c.branch_id');
            [$week] = self::scoped("SELECT COALESCE(SUM(c.total_credited), 0) FROM collections c WHERE c.status = 'posted' AND c.collection_date >= ? AND %s",
                'c.branch_id', [$monday]);
            [$scope, $params] = Branch::scopeSql('c.branch_id');
            $stmt = db()->prepare("SELECT c.method, COALESCE(SUM(c.total_credited), 0) FROM collections c
                                    WHERE c.status = 'posted' AND c.collection_date >= ? AND {$scope} GROUP BY c.method");
            $stmt->execute([$month, ...$params]);
            $methods = array_map('strval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
            $out['receivable'] = ['aging' => $aging, 'overdue_cents' => $overdue, 'checks' => (int) $chkN, 'checks_cents' => to_cents((string) $chkAmt),
                'deposited' => (int) $depN, 'week_cents' => to_cents((string) $week),
                'month_cents' => array_sum(array_map(static fn ($v): int => to_cents($v), $methods)), 'methods' => $methods];
        }
        if (Payables::canView()) {
            $aging = Payables::aging([]);
            [$dueN, $dueAmt] = self::scoped(
                "SELECT COUNT(*), COALESCE(SUM(i.amount - i.paid_amount), 0) FROM supplier_invoices i
                  WHERE i.status = 'open' AND i.due_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 7 DAY AND %s", 'i.branch_id');
            [$chkN, $chkAmt] = self::scoped(
                "SELECT COUNT(*), COALESCE(SUM(d.amount_paid), 0) FROM disbursements d WHERE d.status = 'posted' AND d.check_status = 'issued' AND %s", 'd.branch_id');
            [$paid] = self::scoped("SELECT COALESCE(SUM(d.total_settled), 0) FROM disbursements d WHERE d.status = 'posted' AND d.payment_date >= ? AND %s",
                'd.branch_id', [$month]);
            $out['payable'] = ['aging' => $aging, 'overdue_cents' => $aging['cents'] - $aging['buckets']['current']['cents'],
                'due_week' => (int) $dueN, 'due_week_cents' => to_cents((string) $dueAmt), 'checks' => (int) $chkN,
                'checks_cents' => to_cents((string) $chkAmt), 'paid_month_cents' => to_cents((string) $paid)];
        }
        return $out;
    }

    /**
     * Sales pace: this month so far vs the same days of last month, and the monthly target of the branches in scope
     * (branches.monthly_target; null when none is set).
     */
    public static function pace(): array
    {
        $today = date('Y-m-d');
        $day   = (int) date('j');
        $lastStart = date('Y-m-01', strtotime('first day of last month'));
        $lastEnd   = date('Y-m-d', min(strtotime($lastStart . ' +' . ($day - 1) . ' days'), strtotime('last day of last month')));
        $now  = Reports::totals(date('Y-m-01'), $today);
        $prev = Reports::totals($lastStart, $lastEnd);
        [$scope, $params] = Branch::scopeSql('b.id');
        $stmt = db()->prepare("SELECT COUNT(*), COALESCE(SUM(b.monthly_target), 0) FROM branches b WHERE b.is_active = 1 AND b.monthly_target IS NOT NULL AND {$scope}");
        $stmt->execute($params);
        [$withTarget, $target] = $stmt->fetch(PDO::FETCH_NUM);
        $daysInMonth = (int) date('t');
        return [
            'now_cents' => to_cents((string) $now['net']), 'prev_cents' => to_cents((string) $prev['net']),
            'prev_label' => date('M j', strtotime($lastStart)) . '–' . date('j', strtotime($lastEnd)),
            'target_cents' => (int) $withTarget > 0 ? to_cents((string) $target) : null,
            'day' => $day, 'days' => $daysInMonth,
        ];
    }

    /**
     * Next 7 days (and what is already late): supplier deliveries, customer order deadlines, supplier invoices and
     * customer bills falling due, jobs promised. Each list only with its permission.
     * @return array{items: list<array>, late: list<array{0:string, 1:int, 2:string}>}
     */
    public static function upcoming(): array
    {
        $items = [];
        $late  = [];
        $week  = date('Y-m-d', strtotime('+7 days'));
        $run = static function (string $sql, string $col) use ($week): array {
            [$scope, $params] = Branch::scopeSql($col);
            $stmt = db()->prepare(sprintf($sql, $scope));
            $stmt->execute([$week, ...$params]);
            return $stmt->fetchAll();
        };
        $lateCount = static function (string $sql, string $col): int {
            [$scope, $params] = Branch::scopeSql($col);
            $stmt = db()->prepare(sprintf($sql, $scope));
            $stmt->execute($params);
            return (int) $stmt->fetchColumn();
        };
        if (PurchaseOrders::canView()) {
            foreach ($run("SELECT o.id, o.po_no AS ref, o.expected_date AS d, s.name AS who FROM purchase_orders o JOIN suppliers s ON s.id = o.supplier_id
                            WHERE o.status IN ('approved', 'partial') AND o.expected_date BETWEEN CURDATE() AND ? AND %s", 'o.branch_id') as $r) {
                $items[] = ['date' => $r['d'], 'kind' => 'Delivery from supplier', 'icon' => 'truck', 'ref' => $r['ref'], 'who' => $r['who'], 'url' => 'pages/po-view.php?id=' . $r['id']];
            }
            $late[] = ['Supplier deliveries late', $lateCount("SELECT COUNT(*) FROM purchase_orders o WHERE o.status IN ('approved', 'partial') AND o.expected_date < CURDATE() AND %s", 'o.branch_id'), 'pages/purchase-orders.php?status=overdue'];
        }
        if (Auth::canAny(...CustomerOrders::VIEW_PERMISSIONS)) {
            foreach ($run("SELECT o.id, o.order_no AS ref, o.due_date AS d, o.customer_name AS who FROM customer_orders o
                            WHERE o.status IN ('confirmed', 'partial') AND o.due_date BETWEEN CURDATE() AND ? AND %s", 'o.branch_id') as $r) {
                $items[] = ['date' => $r['d'], 'kind' => 'Deliver to customer', 'icon' => 'file', 'ref' => $r['ref'], 'who' => $r['who'], 'url' => 'pages/co-view.php?id=' . $r['id']];
            }
            $late[] = ['Customer deliveries past the deadline', $lateCount("SELECT COUNT(*) FROM customer_orders o WHERE o.status IN ('confirmed', 'partial') AND o.due_date < CURDATE() AND %s", 'o.branch_id'), 'pages/customer-orders.php?status=overdue'];
        }
        if (Payables::canView()) {
            foreach ($run("SELECT i.id, i.ap_no AS ref, i.due_date AS d, s.name AS who FROM supplier_invoices i JOIN suppliers s ON s.id = i.supplier_id
                            WHERE i.status = 'open' AND i.due_date BETWEEN CURDATE() AND ? AND %s", 'i.branch_id') as $r) {
                $items[] = ['date' => $r['d'], 'kind' => 'Pay supplier invoice', 'icon' => 'clipboard', 'ref' => $r['ref'], 'who' => $r['who'], 'url' => 'pages/ap-view.php?id=' . $r['id']];
            }
            $late[] = ['Supplier invoices overdue', $lateCount("SELECT COUNT(*) FROM supplier_invoices i WHERE i.status = 'open' AND i.due_date < CURDATE() AND %s", 'i.branch_id'), 'pages/payables.php?status=overdue'];
        }
        if (Collections::canView()) {
            foreach ($run("SELECT s.id, s.sale_no AS ref, s.due_date AS d, COALESCE(c.name, 'Walk-in') AS who FROM sales s LEFT JOIN customers c ON c.id = s.customer_id
                            WHERE s.payment_type = 'charge' AND s.status = 'completed' AND s.settled_amount < s.total AND s.due_date BETWEEN CURDATE() AND ? AND %s", 's.branch_id') as $r) {
                $items[] = ['date' => $r['d'], 'kind' => 'Customer bill due', 'icon' => 'wallet', 'ref' => 'Bill No. ' . $r['ref'], 'who' => $r['who'], 'url' => 'pages/sale-view.php?id=' . $r['id']];
            }
            $late[] = ['Customer bills overdue', $lateCount("SELECT COUNT(*) FROM sales s WHERE s.payment_type = 'charge' AND s.status = 'completed' AND s.settled_amount < s.total AND s.due_date < CURDATE() AND %s", 's.branch_id'), 'pages/collections.php?aging=d1'];
        }
        if (Auth::canAny(...JobOrders::VIEW_PERMISSIONS)) {
            $open = "'" . implode("','", JobOrders::OPEN) . "'";
            foreach ($run("SELECT j.id, j.job_no AS ref, j.expected_at AS d, j.customer_name AS who FROM job_orders j
                            WHERE j.status IN ({$open}) AND j.expected_at BETWEEN CURDATE() AND ? AND %s", 'j.branch_id') as $r) {
                $items[] = ['date' => $r['d'], 'kind' => 'Job promised', 'icon' => 'wrench', 'ref' => $r['ref'], 'who' => $r['who'], 'url' => 'pages/job-view.php?id=' . $r['id']];
            }
            $late[] = ['Jobs past the promised date', $lateCount("SELECT COUNT(*) FROM job_orders j WHERE j.status IN ({$open}) AND j.expected_at < CURDATE() AND %s", 'j.branch_id'), 'pages/job-orders.php'];
        }
        usort($items, static fn (array $a, array $b): int => [$a['date'], $a['kind']] <=> [$b['date'], $b['kind']]);
        return ['items' => array_slice($items, 0, 14), 'more' => max(0, count($items) - 14),
            'late' => array_values(array_filter($late, static fn (array $l): bool => $l[1] > 0))];
    }

    /**
     * Slow-moving stock: products on hand in scope with no completed sale in scope for $days days (or never),
     * value at the branch average cost (products.cost) or else at the suggested price. Biggest value first.
     */
    public static function slowMovers(int $days = 60, int $limit = 8): array
    {
        $cost = Auth::can('products.cost');
        [$scope, $params] = Branch::scopeSql('sb.branch_id');
        [$sScope, $sParams] = Branch::scopeSql('s.branch_id');
        $valueExpr = $cost ? 'SUM(sb.qty * COALESCE(pb.avg_cost, p.unit_cost))' : 'SUM(sb.qty * COALESCE(bpp.price, p.price))';
        $stmt = db()->prepare(
            "SELECT p.id, p.code, p.name, SUM(sb.qty) AS qty, {$valueExpr} AS value,
                    (SELECT MAX(s.created_at) FROM sale_items si JOIN sales s ON s.id = si.sale_id
                      WHERE si.product_id = p.id AND s.status = 'completed' AND {$sScope}) AS last_sold
               FROM stock_balances sb
               JOIN products p ON p.id = sb.product_id
               LEFT JOIN product_branches pb ON pb.product_id = sb.product_id AND pb.branch_id = sb.branch_id
               LEFT JOIN product_branch_prices bpp ON bpp.product_id = sb.product_id AND bpp.branch_id = sb.branch_id
              WHERE sb.qty > 0 AND p.is_active = 1 AND {$scope}
              GROUP BY p.id, p.code, p.name
             HAVING last_sold IS NULL OR last_sold < NOW() - INTERVAL ? DAY
              ORDER BY value DESC
              LIMIT ?"
        );
        $stmt->execute([...$sParams, ...$params, $days, $limit]);
        return ['rows' => $stmt->fetchAll(), 'at_cost' => $cost, 'days' => $days];
    }

    /** Service + quotations this month: avg turnaround, back-job rate, quotation win rate, value still quoted. */
    public static function service(): array
    {
        $month = date('Y-m-01');
        $today = date('Y-m-d');
        $out = ['jobs' => null, 'quotes' => null];
        if (Auth::canAny(...JobOrders::VIEW_PERMISSIONS)) {
            $t = Reports::jobTotals($month, $today);
            $out['jobs'] = ['avg_days' => $t['avg_days'], 'completed' => $t['completed'], 'released' => $t['released'], 'back_jobs' => $t['back_jobs'],
                'back_rate' => $t['released'] > 0 ? $t['back_jobs'] / $t['released'] * 100 : null];
        }
        if (Auth::canAny(...CustomerOrders::VIEW_PERMISSIONS)) {
            [$won, $lost, $openN, $openVal] = self::scoped(
                "SELECT COALESCE(SUM(q.status = 'won' AND q.closed_at >= ?), 0), COALESCE(SUM(q.status = 'lost' AND q.closed_at >= ?), 0),
                        COALESCE(SUM(q.status = 'sent'), 0), COALESCE(SUM(IF(q.status = 'sent', q.subtotal, 0)), 0)
                   FROM quotations q WHERE %s", 'q.branch_id', [$month . ' 00:00:00', $month . ' 00:00:00']);
            $decided = (int) $won + (int) $lost;
            $out['quotes'] = ['won' => (int) $won, 'lost' => (int) $lost, 'rate' => $decided > 0 ? (int) $won / $decided * 100 : null,
                'open' => (int) $openN, 'open_cents' => to_cents((string) $openVal)];
        }
        return $out;
    }

    /** Best customers this month (registered customers; net sales incl. VAT). */
    public static function topCustomers(int $limit = 5): array
    {
        [$scope, $params] = Branch::scopeSql('s.branch_id');
        $stmt = db()->prepare(
            "SELECT c.id, c.name, COUNT(*) AS sales, SUM(s.total) AS total
               FROM sales s JOIN customers c ON c.id = s.customer_id
              WHERE s.status = 'completed' AND s.created_at >= ? AND {$scope}
              GROUP BY c.id, c.name ORDER BY total DESC LIMIT ?"
        );
        $stmt->execute([date('Y-m-01') . ' 00:00:00', ...$params, $limit]);
        return $stmt->fetchAll();
    }
}
