<?php
/**
 * Stock integrity checks (inventory.integrity). Every check lists the rows that break a rule,
 * so a healthy database returns an empty 'rows' list for each one.
 * Scoped to the current branch (Branch::scopeSql); the company-total check only runs for "All branches".
 */
declare(strict_types=1);

final class Integrity
{
    public const MAX_ROWS = 100;

    /** @return list<array{key:string, label:string, rows:list<array>}> */
    public static function run(): array
    {
        if (!Auth::can('inventory.integrity')) {
            throw new HttpException(403, 'You do not have permission to run stock integrity checks.');
        }
        $checks = [];
        $add = static function (string $key, string $label, string $sql, string $scopeColumn) use (&$checks): void {
            [$scope, $params] = Branch::scopeSql($scopeColumn);
            $stmt = db()->prepare(str_replace('{scope}', $scope, $sql) . ' LIMIT ' . self::MAX_ROWS);
            $stmt->execute($params);
            $checks[] = ['key' => $key, 'label' => $label, 'rows' => $stmt->fetchAll()];
        };

        $add('balance_vs_movements', 'Location balance differs from the sum of its stock movements',
            'SELECT x.product_id, x.branch_id, x.location_id, x.balance, x.movements FROM (
                 SELECT sb.product_id, sb.branch_id, sb.location_id, sb.qty AS balance, COALESCE(m.total, 0) AS movements
                   FROM stock_balances sb
                   LEFT JOIN (SELECT product_id, location_id, SUM(quantity) AS total FROM stock_movements
                               GROUP BY product_id, location_id) m
                          ON m.product_id = sb.product_id AND m.location_id = sb.location_id
                  WHERE sb.qty <> COALESCE(m.total, 0)
                 UNION ALL
                 SELECT m.product_id, m.branch_id, m.location_id, 0, SUM(m.quantity)
                   FROM stock_movements m
                  WHERE NOT EXISTS (SELECT 1 FROM stock_balances sb WHERE sb.product_id = m.product_id AND sb.location_id = m.location_id)
                  GROUP BY m.product_id, m.branch_id, m.location_id
                 HAVING SUM(m.quantity) <> 0
             ) x WHERE {scope} ORDER BY x.product_id, x.location_id', 'x.branch_id');

        $add('last_movement', 'Last stock movement at a location does not match its balance',
            'SELECT sb.product_id, sb.branch_id, sb.location_id, sb.qty AS balance, m.location_qty_after
               FROM stock_balances sb
               JOIN stock_movements m ON m.id = (SELECT MAX(m2.id) FROM stock_movements m2
                                                  WHERE m2.product_id = sb.product_id AND m2.location_id = sb.location_id)
              WHERE m.location_qty_after IS NOT NULL AND m.location_qty_after <> sb.qty AND {scope}
              ORDER BY sb.product_id, sb.location_id', 'sb.branch_id');

        if (Branch::current() === Branch::ALL) {
            $stmt = db()->prepare(
                'SELECT p.id AS product_id, p.code, p.stock, COALESCE(SUM(sb.qty), 0) AS balances
                   FROM products p LEFT JOIN stock_balances sb ON sb.product_id = p.id
                  GROUP BY p.id, p.code, p.stock
                 HAVING p.stock <> COALESCE(SUM(sb.qty), 0)
                  ORDER BY p.id LIMIT ' . self::MAX_ROWS
            );
            $stmt->execute([]);
            $checks[] = ['key' => 'company_total', 'label' => 'Company stock differs from the sum of branch balances',
                         'rows' => $stmt->fetchAll()];
        }

        $add('serials_vs_balance', 'Serial-tracked item: in-stock serials at a location differ from its balance',
            "SELECT x.product_id, x.branch_id, x.location_id, x.balance, x.serials FROM (
                 SELECT sb.product_id, sb.branch_id, sb.location_id, sb.qty AS balance,
                        (SELECT COUNT(*) FROM product_serials ps WHERE ps.product_id = sb.product_id
                            AND ps.location_id = sb.location_id AND ps.status = 'in_stock') AS serials
                   FROM stock_balances sb JOIN products p ON p.id = sb.product_id AND p.track_serial = 1
                 UNION ALL
                 SELECT ps.product_id, ps.branch_id, ps.location_id, 0, COUNT(*)
                   FROM product_serials ps JOIN products p ON p.id = ps.product_id AND p.track_serial = 1
                  WHERE ps.status = 'in_stock'
                    AND NOT EXISTS (SELECT 1 FROM stock_balances sb WHERE sb.product_id = ps.product_id AND sb.location_id = ps.location_id)
                  GROUP BY ps.product_id, ps.branch_id, ps.location_id
             ) x WHERE x.balance <> x.serials AND {scope} ORDER BY x.product_id, x.location_id", 'x.branch_id');

        $add('serials_untracked', 'In-stock serials of an item that does not track serials',
            "SELECT ps.id AS serial_id, ps.serial_no, ps.product_id, ps.branch_id, ps.location_id
               FROM product_serials ps JOIN products p ON p.id = ps.product_id
              WHERE p.track_serial = 0 AND ps.status = 'in_stock' AND {scope}
              ORDER BY ps.id", 'ps.branch_id');

        $add('serial_sale_status', 'Serial status does not match its latest sale',
            "SELECT ps.id AS serial_id, ps.serial_no, ps.product_id, ps.branch_id, ps.status, s.id AS sale_id, s.status AS sale_status
               FROM product_serials ps
               LEFT JOIN sale_item_serials sis ON sis.serial_id = ps.id
                     AND sis.sale_item_id = (SELECT MAX(x.sale_item_id) FROM sale_item_serials x WHERE x.serial_id = ps.id)
               LEFT JOIN sale_items si ON si.id = sis.sale_item_id
               LEFT JOIN sales s ON s.id = si.sale_id
              WHERE ((ps.status = 'sold' AND (s.id IS NULL OR s.status <> 'completed'))
                  OR (ps.status = 'in_stock' AND s.status = 'completed'))
                AND {scope}
              ORDER BY ps.id", 'ps.branch_id');

        $add('receiving_movements', 'Receiving report stock movements do not match its items',
            "SELECT x.* FROM (
             SELECT r.id AS receiving_id, r.rr_no, r.branch_id, r.status,
                    (SELECT COALESCE(SUM(ri.quantity), 0) FROM receiving_items ri WHERE ri.receiving_id = r.id) AS item_qty,
                    (SELECT COALESCE(SUM(m.quantity), 0) FROM stock_movements m WHERE m.receiving_id = r.id) AS moved_qty
               FROM receiving_reports r
             ) x
              WHERE ((x.status = 'posted' AND x.moved_qty <> x.item_qty) OR (x.status <> 'posted' AND x.moved_qty <> 0))
                AND {scope}
              ORDER BY x.receiving_id", 'x.branch_id');

        $add('missing_cost_row', 'Branch stock without a cost row (product_branches)',
            'SELECT sb.product_id, sb.branch_id, SUM(sb.qty) AS qty
               FROM stock_balances sb
              WHERE NOT EXISTS (SELECT 1 FROM product_branches pb WHERE pb.product_id = sb.product_id AND pb.branch_id = sb.branch_id)
                AND {scope}
              GROUP BY sb.product_id, sb.branch_id
             HAVING SUM(sb.qty) > 0
              ORDER BY sb.product_id, sb.branch_id', 'sb.branch_id');

        return $checks;
    }

    /** Number of rows over all checks (0 = healthy). */
    public static function problemCount(array $checks): int
    {
        return array_sum(array_map(static fn (array $c): int => count($c['rows']), $checks));
    }
}
