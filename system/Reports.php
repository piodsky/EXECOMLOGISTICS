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
        foreach (Sales::PAYMENT_TYPES as $type => $label) {
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
                    COALESCE(SUM(p.price * COALESCE(bs.qty, 0)), 0) AS value
               FROM categories c
               LEFT JOIN products p ON p.category_id = c.id AND p.is_active = ?
               {$join}
              GROUP BY c.id, c.name, c.sort_order
              ORDER BY value DESC, c.sort_order"
        );
        $stmt->execute([1, ...$params]);
        return $stmt->fetchAll();
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
