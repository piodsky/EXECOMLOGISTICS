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
}
