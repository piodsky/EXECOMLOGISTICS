<?php
/**
 * Reports: sales figures for a date range (completed sales only; voided sales are excluded)
 * and a current inventory snapshot.
 * Dates are validated 'Y-m-d' strings; ranges are inclusive of both days.
 */
declare(strict_types=1);

final class Reports
{
    /** Longer ranges are grouped by month instead of by day. */
    public const DAILY_MAX_DAYS = 92;
    /** Longest range a report may cover (keeps the monthly chart readable). */
    public const MAX_DAYS = 3 * 366;

    /**
     * Normalise the requested range: default last 30 days, swap if reversed,
     * never in the future, at most MAX_DAYS long.
     * @return array{from:string, to:string, days:int, prev_from:string, prev_to:string, group:string}
     */
    public static function period(?string $from, ?string $to): array
    {
        $today = new DateTimeImmutable('today');
        $t = $to !== null ? new DateTimeImmutable($to) : $today;
        $f = $from !== null ? new DateTimeImmutable($from) : $t->modify('-29 days');
        if ($f > $t) {
            [$f, $t] = [$t, $f];
        }
        if ($t > $today) {
            $t = $today;
        }
        if ($f > $t) {
            $f = $t;
        }
        $days = (int) $f->diff($t)->days + 1;
        if ($days > self::MAX_DAYS) {
            $f    = $t->modify('-' . (self::MAX_DAYS - 1) . ' days');
            $days = self::MAX_DAYS;
        }
        // The previous period of the same length, for the deltas.
        $prevTo   = $f->modify('-1 day');
        $prevFrom = $prevTo->modify('-' . ($days - 1) . ' days');

        return [
            'from'      => $f->format('Y-m-d'),
            'to'        => $t->format('Y-m-d'),
            'days'      => $days,
            'prev_from' => $prevFrom->format('Y-m-d'),
            'prev_to'   => $prevTo->format('Y-m-d'),
            'group'     => $days > self::DAILY_MAX_DAYS ? 'month' : 'day',
        ];
    }

    /** [SQL, params] limiting `s` to completed sales inside the range and the current branch scope. */
    private static function range(string $from, string $to): array
    {
        $end = (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d 00:00:00');
        [$scope, $params] = Branch::scopeSql('s.branch_id');
        return [
            "s.status = 'completed' AND s.created_at >= ? AND s.created_at < ? AND {$scope}",
            [$from . ' 00:00:00', $end, ...$params],
        ];
    }

    // ------------------------------------------------------------------
    // Sales
    // ------------------------------------------------------------------

    /** Headline totals: transactions, net sales (incl. VAT), average, items, discounts, VAT. */
    public static function totals(string $from, string $to): array
    {
        [$where, $params] = self::range($from, $to);
        $stmt = db()->prepare(
            "SELECT COUNT(*) AS transactions,
                    COALESCE(SUM(s.total), 0) AS net,
                    COALESCE(AVG(s.total), 0) AS average,
                    COALESCE(SUM(s.discount_amount), 0) AS discounts,
                    COALESCE(SUM(s.vat_amount), 0) AS vat,
                    COALESCE(SUM((SELECT SUM(si.quantity) FROM sale_items si WHERE si.sale_id = s.id)), 0) AS items
               FROM sales s
              WHERE {$where}"
        );
        $stmt->execute($params);
        return $stmt->fetch();
    }

    /** Voided sales in the range (by sale date), for the footnote. */
    public static function voided(string $from, string $to): array
    {
        [$where, $params] = self::range($from, $to);
        $where = str_replace("s.status = 'completed'", "s.status = 'cancelled'", $where);
        $stmt  = db()->prepare("SELECT COUNT(*) AS count, COALESCE(SUM(s.total), 0) AS total FROM sales s WHERE {$where}");
        $stmt->execute($params);
        return $stmt->fetch();
    }

    /**
     * Net sales per day (or per month), with empty buckets filled in.
     * @return list<array{key:string, label:string, long:string, count:int, cents:int}>
     */
    public static function series(string $from, string $to, string $group): array
    {
        [$where, $params] = self::range($from, $to);
        $bucket = $group === 'month' ? "DATE_FORMAT(s.created_at, '%Y-%m-01')" : 'DATE(s.created_at)';
        $stmt = db()->prepare(
            "SELECT {$bucket} AS bucket, COUNT(*) AS count, SUM(s.total) AS total
               FROM sales s
              WHERE {$where}
              GROUP BY bucket"
        );
        $stmt->execute($params);
        $found = [];
        foreach ($stmt->fetchAll() as $row) {
            $found[$row['bucket']] = $row;
        }

        $out  = [];
        $step = $group === 'month' ? '+1 month' : '+1 day';
        $cur  = new DateTimeImmutable($group === 'month' ? substr($from, 0, 8) . '01' : $from);
        $end  = new DateTimeImmutable($to);
        for (; $cur <= $end; $cur = $cur->modify($step)) {
            $key = $cur->format('Y-m-d');
            $row = $found[$key] ?? null;
            $out[] = [
                'key'   => $key,
                'label' => $group === 'month' ? $cur->format('M Y') : $cur->format('M j'),
                'long'  => $group === 'month' ? $cur->format('F Y') : $cur->format('D, M j, Y'),
                'count' => $row ? (int) $row['count'] : 0,
                'cents' => $row ? to_cents($row['total']) : 0,
            ];
        }
        return $out;
    }

    /**
     * Best sellers. Revenue is the item's line total (before sale discount and VAT).
     * Grouped by product; lines of since-deleted products group by their saved code.
     */
    public static function topItems(string $from, string $to, string $sort = 'revenue', int $limit = 10): array
    {
        [$where, $params] = self::range($from, $to);
        $order = $sort === 'qty' ? 'qty DESC, revenue DESC' : 'revenue DESC, qty DESC';
        $stmt = db()->prepare(
            "SELECT MAX(si.product_code) AS code, MAX(si.product_name) AS name,
                    SUM(si.quantity) AS qty, SUM(si.line_total) AS revenue, COUNT(DISTINCT s.id) AS sales
               FROM sale_items si
               JOIN sales s ON s.id = si.sale_id
              WHERE {$where}
              GROUP BY COALESCE(CAST(si.product_id AS CHAR), CONCAT('code:', si.product_code))
              ORDER BY {$order}
              LIMIT ?"
        );
        $stmt->execute([...$params, $limit]);
        return $stmt->fetchAll();
    }

    /** Item revenue (before discount/VAT) and units per category. */
    public static function byCategory(string $from, string $to): array
    {
        [$where, $params] = self::range($from, $to);
        $stmt = db()->prepare(
            "SELECT COALESCE(c.name, 'Deleted products') AS name,
                    SUM(si.quantity) AS qty, SUM(si.line_total) AS revenue
               FROM sale_items si
               JOIN sales s ON s.id = si.sale_id
               LEFT JOIN products p ON p.id = si.product_id
               LEFT JOIN categories c ON c.id = p.category_id
              WHERE {$where}
              GROUP BY c.id, c.name, c.sort_order
              ORDER BY revenue DESC"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Net sales and transactions per payment type (all types listed, even with no sales). */
    public static function byPayment(string $from, string $to): array
    {
        [$where, $params] = self::range($from, $to);
        $stmt = db()->prepare(
            "SELECT s.payment_type AS type, COUNT(*) AS count, SUM(s.total) AS total
               FROM sales s WHERE {$where} GROUP BY s.payment_type"
        );
        $stmt->execute($params);
        $found = [];
        foreach ($stmt->fetchAll() as $row) {
            $found[$row['type']] = $row;
        }
        $out = [];
        foreach (Sales::ALL_PAYMENT_TYPES as $type => $label) {
            if (!isset(Sales::PAYMENT_TYPES[$type]) && !isset($found[$type])) {
                continue; // "On account" only when the period has such bills
            }
            $out[] = ['name' => $label, 'count' => (int) ($found[$type]['count'] ?? 0), 'total' => $found[$type]['total'] ?? '0'];
        }
        usort($out, static fn ($a, $b) => (float) $b['total'] <=> (float) $a['total']);
        return $out;
    }

    /** Net sales and transactions per cashier. */
    public static function byCashier(string $from, string $to): array
    {
        [$where, $params] = self::range($from, $to);
        $stmt = db()->prepare(
            "SELECT u.full_name AS name, COUNT(*) AS count, SUM(s.total) AS total
               FROM sales s JOIN users u ON u.id = s.user_id
              WHERE {$where}
              GROUP BY u.id, u.full_name
              ORDER BY total DESC"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // ------------------------------------------------------------------
    // Inventory (right now, not date-bound)
    // ------------------------------------------------------------------

    /** Stock value (price × stock) and units per category, active products only (current branch scope). */
    public static function stockByCategory(): array
    {
        [$join, $params] = Stock::scopeJoin();
        $stmt = db()->prepare(
            "SELECT c.name, COUNT(p.id) AS products, COALESCE(SUM(COALESCE(bs.qty, 0)), 0) AS units,
                    COALESCE(SUM(bs.price_value), 0) AS value
               FROM categories c
               LEFT JOIN products p ON p.category_id = c.id AND p.is_active = ?
               {$join}
              GROUP BY c.id, c.name, c.sort_order
              ORDER BY value DESC, c.sort_order"
        );
        $stmt->execute([1, ...$params]);
        return $stmt->fetchAll();
    }

    /** Stock value at the branch average cost (products.cost only), current branch scope. */
    public static function stockValueAtCost(): string
    {
        self::requireCost();
        [$scope, $params] = Branch::scopeSql('sb.branch_id');
        $stmt = db()->prepare(
            "SELECT COALESCE(SUM(sb.qty * COALESCE(pb.avg_cost, p.unit_cost)), 0)
               FROM stock_balances sb
               JOIN products p ON p.id = sb.product_id
               LEFT JOIN product_branches pb ON pb.product_id = sb.product_id AND pb.branch_id = sb.branch_id
              WHERE sb.qty > 0 AND {$scope}"
        );
        $stmt->execute($params);
        return number_format((float) $stmt->fetchColumn(), 2, '.', '');
    }

    // ------------------------------------------------------------------
    // Profit (products.cost only). Sale level: net revenue = subtotal - discount (before VAT) - cost_total.
    // Sales without a cost snapshot (made before costing existed) are left out and counted separately.
    // Item level: line totals before the sale discount; labour lines have no cost (all profit).
    // ------------------------------------------------------------------

    private static function requireCost(): void
    {
        if (!Auth::can('products.cost')) {
            throw new HttpException(403, 'You do not have permission to see costs and profit.');
        }
    }

    /** @return array{sales:int, revenue:string, cost:string, profit:string, margin:?float, uncosted:int} */
    public static function profitTotals(string $from, string $to): array
    {
        self::requireCost();
        [$where, $params] = self::range($from, $to);
        $stmt = db()->prepare(
            "SELECT COALESCE(SUM(s.cost_total IS NOT NULL), 0) AS sales,
                    COALESCE(SUM(CASE WHEN s.cost_total IS NOT NULL THEN s.subtotal - s.discount_amount END), 0) AS revenue,
                    COALESCE(SUM(s.cost_total), 0) AS cost,
                    COALESCE(SUM(s.cost_total IS NULL), 0) AS uncosted
               FROM sales s WHERE {$where}"
        );
        $stmt->execute($params);
        $r = $stmt->fetch();
        $profit = to_cents($r['revenue']) - to_cents($r['cost']);
        return [
            'sales' => (int) $r['sales'], 'revenue' => $r['revenue'], 'cost' => $r['cost'], 'profit' => from_cents($profit),
            'margin' => to_cents($r['revenue']) > 0 ? $profit / to_cents($r['revenue']) * 100 : null, 'uncosted' => (int) $r['uncosted'],
        ];
    }

    /** Net revenue (before VAT) and gross profit per day / month (costed sales). @return list<array{key, label, long, revenue_cents, profit_cents}> */
    public static function profitSeries(string $from, string $to, string $group): array
    {
        self::requireCost();
        [$where, $params] = self::range($from, $to);
        $bucket = $group === 'month' ? "DATE_FORMAT(s.created_at, '%Y-%m-01')" : 'DATE(s.created_at)';
        $stmt = db()->prepare(
            "SELECT {$bucket} AS bucket, SUM(s.subtotal - s.discount_amount) AS revenue, SUM(s.cost_total) AS cost
               FROM sales s WHERE {$where} AND s.cost_total IS NOT NULL GROUP BY bucket"
        );
        $stmt->execute($params);
        $found = array_column($stmt->fetchAll(), null, 'bucket');
        $out = [];
        foreach (self::series($from, $to, $group) as $p) {
            $row = $found[$p['key']] ?? null;
            $rev = $row ? to_cents($row['revenue']) : 0;
            $out[] = ['key' => $p['key'], 'label' => $p['label'], 'long' => $p['long'], 'revenue_cents' => $rev,
                      'profit_cents' => $row ? $rev - to_cents($row['cost']) : 0];
        }
        return $out;
    }

    /**
     * Profit per item (line totals before the sale discount): revenue, cost, profit, margin. Labour lines are one row
     * with no cost; lines without a cost snapshot are left out. $by: 'item' | 'category'.
     */
    public static function profitBy(string $by, string $from, string $to, int $limit = 20): array
    {
        self::requireCost();
        [$where, $params] = self::range($from, $to);
        $cost = "CASE WHEN si.line_type = 'labor' THEN 0 ELSE si.unit_cost * si.quantity END";
        $costed = "(si.unit_cost IS NOT NULL OR si.line_type = 'labor')";
        if ($by === 'category') {
            $sql = "SELECT CASE WHEN si.line_type = 'labor' THEN 'Labour / service' ELSE COALESCE(c.name, 'Deleted products') END AS name,
                           SUM(si.quantity) AS qty, SUM(si.line_total) AS revenue, SUM({$cost}) AS cost
                      FROM sale_items si JOIN sales s ON s.id = si.sale_id
                      LEFT JOIN products p ON p.id = si.product_id LEFT JOIN categories c ON c.id = p.category_id
                     WHERE {$where} AND {$costed}
                     GROUP BY name ORDER BY SUM(si.line_total) - SUM({$cost}) DESC LIMIT ?";
        } else {
            $sql = "SELECT MAX(si.product_code) AS code, MAX(CASE WHEN si.line_type = 'labor' THEN 'Labour / service' ELSE si.product_name END) AS name,
                           SUM(si.quantity) AS qty, SUM(si.line_total) AS revenue, SUM({$cost}) AS cost
                      FROM sale_items si JOIN sales s ON s.id = si.sale_id
                     WHERE {$where} AND {$costed}
                     GROUP BY CASE WHEN si.line_type = 'labor' THEN 'labor' ELSE COALESCE(CAST(si.product_id AS CHAR), CONCAT('code:', si.product_code)) END
                     ORDER BY SUM(si.line_total) - SUM({$cost}) DESC LIMIT ?";
        }
        $stmt = db()->prepare($sql);
        $stmt->execute([...$params, $limit]);
        return array_map(static function (array $r): array {
            $rev = to_cents($r['revenue']);
            $profit = $rev - to_cents((string) round((float) $r['cost'], 2));
            return $r + ['profit' => from_cents($profit), 'margin' => $rev > 0 ? $profit / $rev * 100 : null];
        }, $stmt->fetchAll());
    }

    /** Sale-level profit per cashier (costed sales). */
    public static function profitByCashier(string $from, string $to): array
    {
        self::requireCost();
        [$where, $params] = self::range($from, $to);
        $stmt = db()->prepare(
            "SELECT u.full_name AS name, COUNT(*) AS count, SUM(s.subtotal - s.discount_amount) AS revenue, SUM(s.cost_total) AS cost
               FROM sales s JOIN users u ON u.id = s.user_id
              WHERE {$where} AND s.cost_total IS NOT NULL
              GROUP BY u.id, u.full_name ORDER BY SUM(s.subtotal - s.discount_amount - s.cost_total) DESC"
        );
        $stmt->execute($params);
        return array_map(static function (array $r): array {
            $rev = to_cents($r['revenue']);
            $profit = $rev - to_cents($r['cost']);
            return $r + ['profit' => from_cents($profit), 'margin' => $rev > 0 ? $profit / $rev * 100 : null];
        }, $stmt->fetchAll());
    }

    // ------------------------------------------------------------------
    // Job orders & technicians (period = by the date of each event)
    // ------------------------------------------------------------------

    /** [SQL, params] for a job date column inside the range + the branch scope (alias j). */
    private static function jobRange(string $column, string $from, string $to): array
    {
        $end = (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d 00:00:00');
        [$scope, $params] = Branch::scopeSql('j.branch_id');
        return ["j.{$column} >= ? AND j.{$column} < ? AND {$scope}", [$from . ' 00:00:00', $end, ...$params]];
    }

    /**
     * received, completed, released, cancelled, back_jobs (opened in the period), avg_days (received -> completed),
     * warranty (released under warranty), open (now), revenue / parts / labour (job bills completed in the period).
     */
    public static function jobTotals(string $from, string $to): array
    {
        $count = static function (string $col, string $extra = '') use ($from, $to): array {
            [$w, $p] = self::jobRange($col, $from, $to);
            return ["SELECT COUNT(*) FROM job_orders j WHERE {$w}{$extra}", $p];
        };
        $out = [];
        foreach (['received' => ['created_at', ''], 'completed' => ['completed_at', ''], 'released' => ['released_at', ''],
                  'cancelled' => ['cancelled_at', ''], 'back_jobs' => ['created_at', ' AND j.parent_job_id IS NOT NULL'],
                  'warranty' => ['released_at', " AND j.release_type = 'warranty'"]] as $k => [$col, $extra]) {
            [$sql, $p] = $count($col, $extra);
            $stmt = db()->prepare($sql);
            $stmt->execute($p);
            $out[$k] = (int) $stmt->fetchColumn();
        }
        [$w, $p] = self::jobRange('completed_at', $from, $to);
        $stmt = db()->prepare("SELECT AVG(TIMESTAMPDIFF(MINUTE, j.created_at, j.completed_at)) / 1440 FROM job_orders j WHERE {$w}");
        $stmt->execute($p);
        $avg = $stmt->fetchColumn();
        $out['avg_days'] = $avg === null ? null : (float) $avg;
        [$scope, $sp] = Branch::scopeSql('j.branch_id');
        $stmt = db()->prepare("SELECT COUNT(*) FROM job_orders j WHERE j.status IN ('" . implode("','", JobOrders::OPEN) . "') AND {$scope}");
        $stmt->execute($sp);
        $out['open'] = (int) $stmt->fetchColumn();
        [$where, $params] = self::range($from, $to);
        $stmt = db()->prepare(
            "SELECT COUNT(*) AS bills, COALESCE(SUM(s.total), 0) AS revenue,
                    COALESCE(SUM((SELECT SUM(si.line_total) FROM sale_items si WHERE si.sale_id = s.id AND si.line_type = 'part')), 0) AS parts,
                    COALESCE(SUM((SELECT SUM(si.line_total) FROM sale_items si WHERE si.sale_id = s.id AND si.line_type = 'labor')), 0) AS labor
               FROM sales s WHERE {$where} AND s.job_order_id IS NOT NULL"
        );
        $stmt->execute($params);
        return $out + $stmt->fetch();
    }

    /** Per technician: open now, completed in the period, average days, back-jobs on their released jobs, labour billed. */
    public static function jobTechnicians(string $from, string $to): array
    {
        [$scope, $sp] = Branch::scopeSql('j.branch_id');
        [$cw, $cp] = self::jobRange('completed_at', $from, $to);
        [$bw, $bp] = self::jobRange('created_at', $from, $to);
        [$sw, $spp] = self::range($from, $to);
        $open = "'" . implode("','", JobOrders::OPEN) . "'";
        $stmt = db()->prepare(
            "SELECT u.id, u.full_name AS name,
                    (SELECT COUNT(*) FROM job_orders j WHERE j.technician_id = u.id AND j.status IN ({$open}) AND {$scope}) AS open_now,
                    (SELECT COUNT(*) FROM job_orders j WHERE j.technician_id = u.id AND {$cw}) AS completed,
                    (SELECT COUNT(*) FROM job_orders j JOIN job_order_technicians h ON h.job_order_id = j.id WHERE h.user_id = u.id AND {$cw}) AS helped,
                    (SELECT AVG(TIMESTAMPDIFF(MINUTE, j.created_at, j.completed_at)) / 1440 FROM job_orders j WHERE j.technician_id = u.id AND {$cw}) AS avg_days,
                    (SELECT COUNT(*) FROM job_orders j JOIN job_orders pj ON pj.id = j.parent_job_id WHERE pj.technician_id = u.id AND {$bw}) AS back_jobs,
                    (SELECT COALESCE(SUM(si.line_total), 0) FROM sales s JOIN job_orders jj ON jj.id = s.job_order_id
                       JOIN sale_items si ON si.sale_id = s.id AND si.line_type = 'labor' WHERE jj.technician_id = u.id AND {$sw}) AS labor
               FROM users u
              WHERE EXISTS (SELECT 1 FROM job_orders j WHERE j.technician_id = u.id AND {$scope})
                 OR EXISTS (SELECT 1 FROM job_order_technicians h JOIN job_orders j ON j.id = h.job_order_id WHERE h.user_id = u.id AND {$scope})
              ORDER BY completed DESC, open_now DESC, u.full_name"
        );
        $stmt->execute([...$sp, ...$cp, ...$cp, ...$cp, ...$bp, ...$spp, ...$sp, ...$sp]);
        return $stmt->fetchAll();
    }

    /** Jobs received in the period per device type. */
    public static function jobsByDevice(string $from, string $to): array
    {
        [$w, $p] = self::jobRange('created_at', $from, $to);
        $stmt = db()->prepare(
            "SELECT COALESCE(l.name, 'Not set') AS name, COUNT(*) AS count FROM job_orders j LEFT JOIN lookups l ON l.id = j.device_type_id
              WHERE {$w} GROUP BY l.id, l.name ORDER BY count DESC, name"
        );
        $stmt->execute($p);
        return $stmt->fetchAll();
    }

    /** Open jobs, oldest first, with days open (now). */
    public static function openJobs(int $limit = 20): array
    {
        [$scope, $params] = Branch::scopeSql('j.branch_id');
        $stmt = db()->prepare(
            "SELECT j.id, j.job_no, j.status, j.customer_name, j.expected_at, j.created_at, b.code AS branch_code, u.full_name AS technician_name,
                    TIMESTAMPDIFF(DAY, j.created_at, NOW()) AS days_open
               FROM job_orders j JOIN branches b ON b.id = j.branch_id LEFT JOIN users u ON u.id = j.technician_id
              WHERE j.status IN ('" . implode("','", JobOrders::OPEN) . "') AND {$scope}
              ORDER BY j.created_at, j.id LIMIT ?"
        );
        $stmt->execute([...$params, $limit]);
        return $stmt->fetchAll();
    }

    // ------------------------------------------------------------------
    // Price overrides & discounts (completed sales in the period)
    // ------------------------------------------------------------------

    /** Lines sold below the suggested price: who, approver, reason, amount given away. */
    public static function priceOverrides(string $from, string $to, int $limit = 200): array
    {
        [$where, $params] = self::range($from, $to);
        $stmt = db()->prepare(
            "SELECT s.id AS sale_id, s.sale_no, s.created_at, si.product_code, si.product_name, si.quantity, si.suggested_price, si.unit_price,
                    (si.suggested_price - si.unit_price) * si.quantity AS given, si.price_reason, u.full_name AS cashier, a.full_name AS approver,
                    b.code AS branch_code
               FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN users u ON u.id = s.user_id
               JOIN branches b ON b.id = s.branch_id LEFT JOIN users a ON a.id = si.price_approved_by
              WHERE {$where} AND si.suggested_price IS NOT NULL AND si.unit_price < si.suggested_price
              ORDER BY s.created_at DESC, si.id DESC LIMIT ?"
        );
        $stmt->execute([...$params, $limit]);
        return $stmt->fetchAll();
    }

    /** Sales with a discount: percent, amount, cashier, approver. */
    public static function discounts(string $from, string $to, int $limit = 200): array
    {
        [$where, $params] = self::range($from, $to);
        $stmt = db()->prepare(
            "SELECT s.id AS sale_id, s.sale_no, s.created_at, s.discount_percent, s.discount_amount, s.total, s.job_order_id,
                    u.full_name AS cashier, a.full_name AS approver, b.code AS branch_code
               FROM sales s JOIN users u ON u.id = s.user_id JOIN branches b ON b.id = s.branch_id
               LEFT JOIN users a ON a.id = s.discount_approved_by
              WHERE {$where} AND s.discount_amount > 0
              ORDER BY s.created_at DESC, s.id DESC LIMIT ?"
        );
        $stmt->execute([...$params, $limit]);
        return $stmt->fetchAll();
    }

    /** Totals + per cashier: lowered lines / amount, discounts / amount, how many needed an approver. */
    public static function overrideSummary(string $from, string $to): array
    {
        [$where, $params] = self::range($from, $to);
        $stmt = db()->prepare(
            "SELECT u.full_name AS name,
                    COALESCE(SUM(x.`lines`), 0) AS `lines`, COALESCE(SUM(x.given), 0) AS given, COALESCE(SUM(x.line_approved), 0) AS line_approved,
                    COALESCE(SUM(s.discount_amount > 0), 0) AS discounts, COALESCE(SUM(s.discount_amount), 0) AS discount_total,
                    COALESCE(SUM(s.discount_approved_by IS NOT NULL AND s.discount_approved_by <> s.user_id AND s.discount_amount > 0), 0) AS discount_approved
               FROM sales s JOIN users u ON u.id = s.user_id
               LEFT JOIN (SELECT si.sale_id, COUNT(*) AS `lines`, SUM((si.suggested_price - si.unit_price) * si.quantity) AS given,
                                 SUM(si.price_approved_by IS NOT NULL) AS line_approved
                            FROM sale_items si WHERE si.suggested_price IS NOT NULL AND si.unit_price < si.suggested_price
                           GROUP BY si.sale_id) x ON x.sale_id = s.id
              WHERE {$where} AND (x.sale_id IS NOT NULL OR s.discount_amount > 0)
              GROUP BY u.id, u.full_name ORDER BY SUM(COALESCE(x.given, 0)) + SUM(s.discount_amount) DESC"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $tot = ['lines' => 0, 'given' => 0, 'line_approved' => 0, 'discounts' => 0, 'discount_total' => 0, 'discount_approved' => 0];
        foreach ($rows as $r) {
            foreach (['lines', 'line_approved', 'discounts', 'discount_approved'] as $k) {
                $tot[$k] += (int) $r[$k];
            }
            $tot['given'] += to_cents($r['given']);
            $tot['discount_total'] += to_cents($r['discount_total']);
        }
        $tot['given'] = from_cents($tot['given']);
        $tot['discount_total'] = from_cents($tot['discount_total']);
        return ['totals' => $tot, 'cashiers' => $rows];
    }

    // ------------------------------------------------------------------
    // Branch comparison (branches.access_all): every active branch side by side
    // ------------------------------------------------------------------

    /**
     * Per active branch: sales, net, profit (with products.cost), stock value (cost with products.cost, else price),
     * units, low stock, open jobs, jobs released, job revenue, transfers sent. Ignores the current branch scope.
     */
    public static function branchComparison(string $from, string $to): array
    {
        if (!Branch::canSeeAll()) {
            throw new HttpException(403, 'Only users with access to all branches can compare branches.');
        }
        $cost = Auth::can('products.cost');
        $end  = (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d 00:00:00');
        $rng  = [$from . ' 00:00:00', $end];
        $map = static function (string $sql, array $params): array {
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            $out = [];
            foreach ($stmt->fetchAll() as $r) {
                $out[(int) $r['branch_id']] = $r;
            }
            return $out;
        };
        $stmt = db()->prepare('SELECT id, code, name FROM branches WHERE is_active = 1 ORDER BY is_main DESC, name');
        $stmt->execute();
        $branches = $stmt->fetchAll();
        $sales = $map("SELECT s.branch_id, COUNT(*) AS sales, SUM(s.total) AS net, SUM(CASE WHEN s.cost_total IS NOT NULL THEN s.subtotal - s.discount_amount - s.cost_total END) AS profit,
                              SUM(s.job_order_id IS NOT NULL) AS job_bills, SUM(CASE WHEN s.job_order_id IS NOT NULL THEN s.total END) AS job_revenue
                         FROM sales s WHERE s.status = 'completed' AND s.created_at >= ? AND s.created_at < ? GROUP BY s.branch_id", $rng);
        $valueExpr = $cost ? 'sb.qty * COALESCE(pb.avg_cost, p.unit_cost)' : 'sb.qty * COALESCE(bpp.price, p.price)';
        $stock = $map("SELECT sb.branch_id, SUM(sb.qty) AS units, SUM({$valueExpr}) AS value
                         FROM stock_balances sb JOIN products p ON p.id = sb.product_id
                         LEFT JOIN product_branches pb ON pb.product_id = sb.product_id AND pb.branch_id = sb.branch_id
                         LEFT JOIN product_branch_prices bpp ON bpp.product_id = sb.product_id AND bpp.branch_id = sb.branch_id
                        WHERE sb.qty > 0 GROUP BY sb.branch_id", []);
        $low = $map("SELECT x.branch_id, COUNT(*) AS low FROM (
                         SELECT b.id AS branch_id, p.id, COALESCE((SELECT SUM(sb.qty) FROM stock_balances sb WHERE sb.product_id = p.id AND sb.branch_id = b.id), 0) AS qty, p.reorder_level
                           FROM branches b CROSS JOIN products p WHERE b.is_active = 1 AND p.is_active = 1) x
                      WHERE x.qty <= x.reorder_level GROUP BY x.branch_id", []);
        $jobs = $map("SELECT j.branch_id, SUM(j.status IN ('" . implode("','", JobOrders::OPEN) . "')) AS open_jobs,
                             SUM(j.released_at >= ? AND j.released_at < ?) AS released
                        FROM job_orders j GROUP BY j.branch_id", $rng);
        $tr = $map("SELECT t.from_branch_id AS branch_id, COUNT(*) AS sent FROM stock_transfers t
                     WHERE t.released_at >= ? AND t.released_at < ? GROUP BY t.from_branch_id", $rng);
        $out = [];
        foreach ($branches as $b) {
            $id = (int) $b['id'];
            $out[] = [
                'id' => $id, 'code' => $b['code'], 'name' => $b['name'],
                'sales' => (int) ($sales[$id]['sales'] ?? 0), 'net' => $sales[$id]['net'] ?? '0',
                'profit' => $cost ? ($sales[$id]['profit'] ?? '0') : null,
                'job_bills' => (int) ($sales[$id]['job_bills'] ?? 0), 'job_revenue' => $sales[$id]['job_revenue'] ?? '0',
                'units' => (int) ($stock[$id]['units'] ?? 0), 'stock_value' => number_format((float) ($stock[$id]['value'] ?? 0), 2, '.', ''),
                'low' => (int) ($low[$id]['low'] ?? 0), 'open_jobs' => (int) ($jobs[$id]['open_jobs'] ?? 0),
                'released' => (int) ($jobs[$id]['released'] ?? 0), 'transfers_sent' => (int) ($tr[$id]['sent'] ?? 0),
            ];
        }
        return $out;
    }

    /** Active products at or below their low-stock level in the current branch scope, emptiest first. */
    public static function lowStock(int $limit = 20): array
    {
        [$join, $params] = Stock::scopeJoin();
        $stmt = db()->prepare(
            "SELECT p.id, p.code, p.name, COALESCE(bs.qty, 0) AS stock, p.reorder_level, c.name AS category
               FROM products p JOIN categories c ON c.id = p.category_id
               {$join}
              WHERE p.is_active = ? AND COALESCE(bs.qty, 0) <= p.reorder_level
              ORDER BY stock, (COALESCE(bs.qty, 0) - p.reorder_level), p.name
              LIMIT ?"
        );
        $stmt->execute([...$params, 1, $limit]);
        return $stmt->fetchAll();
    }
}
