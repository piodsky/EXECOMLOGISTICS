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

        $add('inventory_doc_movements', 'Stock document movements do not match the document',
            "SELECT x.* FROM (
             SELECT d.id AS doc_id, d.doc_no, d.doc_type, d.status, d.branch_id, d.total_qty,
                    (SELECT COUNT(*) FROM stock_movements m WHERE m.inventory_doc_id = d.id) AS movements,
                    (SELECT COALESCE(SUM(m.quantity), 0) FROM stock_movements m WHERE m.inventory_doc_id = d.id) AS moved_net,
                    (SELECT COALESCE(SUM(GREATEST(m.quantity, 0)), 0) FROM stock_movements m WHERE m.inventory_doc_id = d.id) AS moved_in,
                    (SELECT COALESCE(SUM(dl.adjust_qty), 0) FROM inventory_doc_lines dl WHERE dl.doc_id = d.id) AS line_adjust
               FROM inventory_docs d
             ) x
              WHERE ((x.status <> 'posted' AND x.movements > 0)
                  OR (x.status = 'posted' AND x.doc_type = 'transfer' AND (x.moved_net <> 0 OR x.moved_in <> x.total_qty))
                  OR (x.status = 'posted' AND x.doc_type <> 'transfer' AND x.moved_net <> x.line_adjust))
                AND {scope}
              ORDER BY x.doc_id", 'x.branch_id');

        $add('removed_serials', 'Removed serial without a posted issue, write-off or count, or a transfer that arrived without it',
            "SELECT ps.id AS serial_id, ps.serial_no, ps.product_id, ps.branch_id, ps.location_id
               FROM product_serials ps
              WHERE ps.status = 'removed'
                AND NOT EXISTS (SELECT 1 FROM inventory_doc_serials ds
                                  JOIN inventory_doc_lines dl ON dl.id = ds.line_id
                                  JOIN inventory_docs d ON d.id = dl.doc_id
                                 WHERE ds.serial_id = ps.id AND d.status = 'posted'
                                   AND (d.doc_type IN ('issue', 'writeoff') OR (d.doc_type = 'count' AND ds.found = 0)))
                AND NOT EXISTS (SELECT 1 FROM stock_transfer_serials ts
                                  JOIN stock_transfer_lines tl ON tl.id = ts.line_id
                                  JOIN stock_transfers t ON t.id = tl.transfer_id
                                 WHERE ts.serial_id = ps.id AND ts.received = 0 AND t.status = 'received')
                AND {scope}
              ORDER BY ps.id", 'ps.branch_id');

        $add('inactive_location_stock', 'Inactive location or warehouse still holds stock',
            'SELECT sb.branch_id, sb.warehouse_id, w.code AS warehouse_code, sb.location_id, l.code AS location_code,
                    SUM(sb.qty) AS qty
               FROM stock_balances sb
               JOIN storage_locations l ON l.id = sb.location_id
               JOIN warehouses w ON w.id = sb.warehouse_id
              WHERE (l.is_active = 0 OR w.is_active = 0) AND sb.qty > 0 AND {scope}
              GROUP BY sb.branch_id, sb.warehouse_id, w.code, sb.location_id, l.code
              ORDER BY sb.location_id', 'sb.branch_id');

        $add('location_setup', 'Warehouse without DAMAGED / DISPLAY, or active branch without a default sellable location',
            "SELECT x.* FROM (
             SELECT w.branch_id, w.id AS warehouse_id, w.code AS warehouse_code, 'Missing DAMAGED or DISPLAY location' AS problem
               FROM warehouses w
              WHERE NOT EXISTS (SELECT 1 FROM storage_locations l WHERE l.warehouse_id = w.id AND l.kind = 'damaged')
                 OR NOT EXISTS (SELECT 1 FROM storage_locations l WHERE l.warehouse_id = w.id AND l.kind = 'display')
             UNION ALL
             SELECT b.id, NULL, NULL, 'No default sellable location'
               FROM branches b
              WHERE b.is_active = 1
                AND NOT EXISTS (SELECT 1 FROM storage_locations l JOIN warehouses w ON w.id = l.warehouse_id
                                 WHERE l.branch_id = b.id AND w.is_default = 1 AND w.is_active = 1
                                   AND l.is_default = 1 AND l.is_sellable = 1 AND l.is_active = 1)
             ) x WHERE {scope}
              ORDER BY x.branch_id, x.warehouse_id", 'x.branch_id');

        // Branch transfers: released qty = transfer_out movements, received qty = transfer_in movements.
        $add('transfer_movements', 'Branch transfer quantities differ from their stock movements',
            "SELECT x.transfer_no, x.branch_id, x.product_id, x.direction, x.qty, x.movements FROM (
                 SELECT t.transfer_no, t.from_branch_id AS branch_id, tl.product_id, 'out' AS direction, tl.qty_released AS qty,
                        -COALESCE((SELECT SUM(m.quantity) FROM stock_movements m WHERE m.stock_transfer_id = t.id
                                     AND m.product_id = tl.product_id AND m.type = 'transfer_out'), 0) AS movements
                   FROM stock_transfers t JOIN stock_transfer_lines tl ON tl.transfer_id = t.id
                  WHERE t.status IN ('released', 'received')
                 UNION ALL
                 SELECT t.transfer_no, t.to_branch_id, tl.product_id, 'in', tl.qty_received,
                        COALESCE((SELECT SUM(m.quantity) FROM stock_movements m WHERE m.stock_transfer_id = t.id
                                    AND m.product_id = tl.product_id AND m.type = 'transfer_in'), 0)
                   FROM stock_transfers t JOIN stock_transfer_lines tl ON tl.transfer_id = t.id
                  WHERE t.status = 'received'
             ) x WHERE COALESCE(x.qty, 0) <> x.movements AND {scope} ORDER BY x.transfer_no, x.product_id", 'x.branch_id');

        // A serial is in transit exactly while its transfer is released (not yet received).
        $add('transit_serials', 'Serial in transit without an open branch transfer (or the reverse)',
            "SELECT ps.id AS serial_id, ps.serial_no, ps.product_id, ps.branch_id, ps.status, t.transfer_no, t.status AS transfer_status
               FROM product_serials ps
               LEFT JOIN stock_transfer_serials ts ON ts.serial_id = ps.id AND ts.received IS NULL
               LEFT JOIN stock_transfer_lines tl ON tl.id = ts.line_id
               LEFT JOIN stock_transfers t ON t.id = tl.transfer_id AND t.status = 'released'
              WHERE ((ps.status = 'in_transit') <> (t.id IS NOT NULL)) AND (ps.status = 'in_transit' OR ts.serial_id IS NOT NULL)
                AND {scope}
              ORDER BY ps.id", 'ps.branch_id');

        // Job parts: issued = -job_issue movements, returned = job_return movements (per job and product).
        $add('job_part_movements', 'Job order parts differ from their stock movements',
            "SELECT x.job_no, x.branch_id, x.product_id, x.issued, x.issue_moves, x.returned, x.return_moves FROM (
                 SELECT j.job_no, j.branch_id, jp.product_id, SUM(COALESCE(jp.qty_issued, 0)) AS issued, SUM(jp.qty_returned) AS returned,
                        -COALESCE((SELECT SUM(m.quantity) FROM stock_movements m WHERE m.job_order_id = j.id
                                     AND m.product_id = jp.product_id AND m.type = 'job_issue'), 0) AS issue_moves,
                        COALESCE((SELECT SUM(m.quantity) FROM stock_movements m WHERE m.job_order_id = j.id
                                    AND m.product_id = jp.product_id AND m.type = 'job_return'), 0) AS return_moves
                   FROM job_orders j JOIN job_order_parts jp ON jp.job_order_id = j.id
                  GROUP BY j.id, jp.product_id
             ) x WHERE (x.issued <> x.issue_moves OR x.returned <> x.return_moves) AND {scope} ORDER BY x.job_no, x.product_id", 'x.branch_id');

        // A serial is with a technician exactly while it is 'issued' on a job parts line.
        $add('custody_serials', 'Serial with a technician without an open job part (or the reverse)',
            "SELECT ps.id AS serial_id, ps.serial_no, ps.product_id, ps.branch_id, ps.status, j.job_no
               FROM product_serials ps
               LEFT JOIN job_order_part_serials js ON js.serial_id = ps.id AND js.state = 'issued'
               LEFT JOIN job_order_parts jp ON jp.id = js.part_id
               LEFT JOIN job_orders j ON j.id = jp.job_order_id
              WHERE ((ps.status = 'in_custody') <> (js.serial_id IS NOT NULL)) AND (ps.status = 'in_custody' OR js.serial_id IS NOT NULL)
                AND {scope}
              ORDER BY ps.id", 'ps.branch_id');

        // A purchase order line has received exactly what the posted receiving reports made from it say.
        $add('po_received', 'Purchase order received quantity differs from its posted receiving reports',
            "SELECT x.po_no, x.branch_id, x.product_id, x.qty_received, x.rr_qty FROM (
                 SELECT o.po_no, o.branch_id, ol.product_id, ol.qty_received,
                        COALESCE((SELECT SUM(ri.quantity) FROM receiving_items ri
                                    JOIN receiving_reports r ON r.id = ri.receiving_id
                                   WHERE ri.po_line_id = ol.id AND r.status = 'posted'), 0) AS rr_qty
                   FROM purchase_orders o JOIN purchase_order_lines ol ON ol.po_id = o.id
             ) x WHERE x.qty_received <> x.rr_qty AND {scope} ORDER BY x.po_no, x.product_id", 'x.branch_id');

        // Delivery receipts: released qty = -'delivery' movements; a cancelled DR also has the same 'delivery_return'.
        $add('delivery_movements', 'Delivery receipt quantities differ from their stock movements',
            "SELECT x.dr_no, x.branch_id, x.product_id, x.qty, x.out_moves, x.back_moves, x.status FROM (
                 SELECT d.dr_no, d.branch_id, d.status, dl.product_id, SUM(dl.qty) AS qty,
                        -COALESCE((SELECT SUM(m.quantity) FROM stock_movements m WHERE m.customer_delivery_id = d.id
                                     AND m.product_id = dl.product_id AND m.type = 'delivery'), 0) AS out_moves,
                        COALESCE((SELECT SUM(m.quantity) FROM stock_movements m WHERE m.customer_delivery_id = d.id
                                    AND m.product_id = dl.product_id AND m.type = 'delivery_return'), 0) AS back_moves
                   FROM customer_deliveries d JOIN customer_delivery_lines dl ON dl.delivery_id = d.id
                  GROUP BY d.id, dl.product_id
             ) x WHERE (x.qty <> x.out_moves OR x.back_moves <> IF(x.status = 'cancelled', x.qty, 0)) AND {scope}
             ORDER BY x.dr_no, x.product_id", 'x.branch_id');

        // A serial is 'delivered' exactly while it is on a delivery receipt that is not cancelled.
        $add('delivered_serials', 'Delivered serial without a delivery receipt (or the reverse)',
            "SELECT ps.id AS serial_id, ps.serial_no, ps.product_id, ps.branch_id, ps.status, d.dr_no
               FROM product_serials ps
               LEFT JOIN customer_delivery_serials x ON x.serial_id = ps.id
                     AND x.line_id IN (SELECT dl.id FROM customer_delivery_lines dl JOIN customer_deliveries dd ON dd.id = dl.delivery_id
                                        WHERE dd.status <> 'cancelled')
               LEFT JOIN customer_delivery_lines l ON l.id = x.line_id
               LEFT JOIN customer_deliveries d ON d.id = l.delivery_id
              WHERE ((ps.status = 'delivered') <> (x.serial_id IS NOT NULL)) AND (ps.status = 'delivered' OR x.serial_id IS NOT NULL)
                AND {scope}
              ORDER BY ps.id", 'ps.branch_id');

        // Customer order lines: delivered = live delivery receipts, billed = receipts on a bill.
        $add('customer_order_lines', 'Customer order delivered / billed quantities differ from its delivery receipts',
            "SELECT x.order_no, x.branch_id, x.product_id, x.qty_delivered, x.dr_qty, x.qty_billed, x.billed_qty FROM (
                 SELECT o.order_no, o.branch_id, l.product_id, l.qty_delivered, l.qty_billed,
                        COALESCE((SELECT SUM(dl.qty) FROM customer_delivery_lines dl JOIN customer_deliveries d ON d.id = dl.delivery_id
                                   WHERE dl.order_line_id = l.id AND d.status <> 'cancelled'), 0) AS dr_qty,
                        COALESCE((SELECT SUM(dl.qty) FROM customer_delivery_lines dl JOIN customer_deliveries d ON d.id = dl.delivery_id
                                   WHERE dl.order_line_id = l.id AND d.status <> 'cancelled' AND d.sale_id IS NOT NULL), 0) AS billed_qty
                   FROM customer_orders o JOIN customer_order_lines l ON l.order_id = o.id
             ) x WHERE (x.qty_delivered <> x.dr_qty OR x.qty_billed <> x.billed_qty) AND {scope} ORDER BY x.order_no, x.product_id", 'x.branch_id');

        // On-account bills: settled_amount = posted collections (cash + taxes withheld), never above the total.
        $add('collections', 'Bill settled amount differs from its collections (or exceeds the bill)',
            "SELECT x.sale_no, x.branch_id, x.total, x.settled_amount, x.collected FROM (
                 SELECT s.sale_no, s.branch_id, s.total, s.settled_amount,
                        COALESCE((SELECT SUM(cl.amount + cl.ewt_amount + cl.vat_withheld) FROM collection_lines cl
                                    JOIN collections c ON c.id = cl.collection_id WHERE cl.sale_id = s.id AND c.status = 'posted'), 0) AS collected
                   FROM sales s WHERE s.settled_amount <> 0 OR EXISTS (SELECT 1 FROM collection_lines cl WHERE cl.sale_id = s.id)
             ) x WHERE (x.settled_amount <> x.collected OR x.settled_amount > x.total) AND {scope} ORDER BY x.sale_no", 'x.branch_id');

        return $checks;
    }

    /** Number of rows over all checks (0 = healthy). */
    public static function problemCount(array $checks): int
    {
        return array_sum(array_map(static fn (array $c): int => count($c['rows']), $checks));
    }
}
