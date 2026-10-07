<?php
/**
 * The only writer of stock. Used by Products (opening stock, adjustments), Sales (sale, void),
 * Receiving (RR post / cancel), InventoryDocs (transfers, issues, write-offs, counts) and
 * Transfers (branch-to-branch release / receive), JobParts (parts issued to / returned from job custody) and
 * CustomerOrders (delivery receipts to customers / goods back to stock).
 *
 * Hard reservation (Phase 13b): units of confirmed customer orders that are not delivered yet are reserved at
 * the order's location. Any stock-out there except the delivery itself (and a stock count, which records what
 * is really there) must leave at least the reserved quantity: POS sales, job parts, transfers, write-offs, ...
 *
 * Stock::move() changes one product at one storage location:
 *   stock_balances.qty (per location)  +  products.stock (company total)  +  one stock_movements row.
 * The caller runs it inside its own transaction and has already locked the products row
 * (SELECT ... FOR UPDATE), which serialises every change to that product.
 */
declare(strict_types=1);

final class Stock
{
    /** stock_movements.type values (transfer / issue / write_off / count: InventoryDocs; transfer_out / transfer_in: Transfers;
     *  job_issue / job_return: JobParts; delivery / delivery_return: CustomerOrders). */
    public const TYPES = ['initial', 'sale', 'restock', 'adjustment', 'void', 'receiving', 'transfer', 'issue', 'write_off', 'count',
                          'transfer_out', 'transfer_in', 'job_issue', 'job_return', 'delivery', 'delivery_return'];

    /** Stock-outs allowed to use reserved units. */
    private const IGNORE_RESERVATION = ['delivery', 'count'];

    /** Quantity at a location, locking the balance row (0 when there is none yet). */
    public static function balance(int $productId, int $locationId): int
    {
        self::assertTransaction();
        $stmt = db()->prepare('SELECT qty FROM stock_balances WHERE product_id = ? AND location_id = ? FOR UPDATE');
        $stmt->execute([$productId, $locationId]);
        $qty = $stmt->fetchColumn();
        return $qty === false ? 0 : (int) $qty;
    }

    /**
     * Units of a product reserved at a location by confirmed customer orders (ordered - delivered of orders that
     * are confirmed or partially delivered). Read while the caller holds the product row lock, which every
     * reservation change also takes.
     */
    public static function reserved(int $productId, int $locationId): int
    {
        $stmt = db()->prepare(
            "SELECT COALESCE(SUM(l.qty_ordered - l.qty_delivered), 0)
               FROM customer_order_lines l JOIN customer_orders o ON o.id = l.order_id
              WHERE l.product_id = ? AND o.location_id = ? AND o.status IN ('confirmed', 'partial')"
        );
        $stmt->execute([$productId, $locationId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array{id:int, warehouse_id:int, branch_id:int, branch_name?:string, label?:string} $location
     *        from Branch::defaultLocation() (or a storage location row; 'label' names it in the 409 message)
     * @param int    $delta  signed change (+ in, - out)
     * @param string $type   initial | sale | restock | adjustment | void | receiving | transfer | issue | write_off | count
     *                       | transfer_out | transfer_in
     * @param ?int   $receivingId receiving_reports.id (type 'receiving': RR post and cancel)
     * @param ?int   $docId  inventory_docs.id (types transfer / issue / write_off / count)
     * @param ?int   $transferId stock_transfers.id (types transfer_out / transfer_in)
     * @param ?int   $jobOrderId job_orders.id (types job_issue / job_return)
     * @param ?int   $deliveryId customer_deliveries.id (types delivery / delivery_return)
     * @return array{location_qty:int, stock:int} levels after the change
     * @throws HttpException 409 when the location would go below zero or into units reserved for customer orders
     */
    public static function move(int $productId, array $location, int $delta, string $type, ?string $note,
                                ?int $saleId = null, ?int $userId = null, ?int $receivingId = null,
                                ?int $docId = null, ?int $transferId = null, ?int $jobOrderId = null,
                                ?int $deliveryId = null): array
    {
        self::assertTransaction();
        if (!in_array($type, self::TYPES, true)) {
            throw new LogicException("Unknown stock movement type [{$type}]");
        }
        $pdo = db();

        // Normally already locked by the caller; re-locking our own row is a no-op, and it keeps the
        // "one writer per product at a time" guarantee even if a caller forgets.
        $stmt = $pdo->prepare('SELECT name FROM products WHERE id = ? FOR UPDATE');
        $stmt->execute([$productId]);
        $name = $stmt->fetchColumn();
        if ($name === false) {
            throw new HttpException(404, 'Product not found.');
        }

        $stmt = $pdo->prepare('SELECT qty FROM stock_balances WHERE product_id = ? AND location_id = ? FOR UPDATE');
        $stmt->execute([$productId, $location['id']]);
        $current = $stmt->fetchColumn();
        $new     = ($current === false ? 0 : (int) $current) + $delta;

        if ($new < 0) {
            throw new HttpException(409, 'Not enough stock at ' . ($location['label'] ?? $location['branch_name'] ?? 'this branch') . '.');
        }
        if ($delta < 0 && !in_array($type, self::IGNORE_RESERVATION, true)) {
            $reserved = self::reserved($productId, (int) $location['id']);
            if ($reserved > 0 && $new < $reserved) {
                $free = max(0, $new - $delta - $reserved);
                throw new HttpException(409, "{$name}: {$reserved} " . ($reserved === 1 ? 'unit is' : 'units are')
                    . ' reserved for customer orders at ' . ($location['label'] ?? $location['branch_name'] ?? 'this branch')
                    . ", so only {$free} can be used.");
            }
        }

        if ($current === false) {
            $pdo->prepare(
                'INSERT INTO stock_balances (product_id, location_id, warehouse_id, branch_id, qty) VALUES (?, ?, ?, ?, ?)'
            )->execute([$productId, $location['id'], $location['warehouse_id'], $location['branch_id'], $new]);
        } else {
            $pdo->prepare('UPDATE stock_balances SET qty = ? WHERE product_id = ? AND location_id = ?')
                ->execute([$new, $productId, $location['id']]);
        }

        $pdo->prepare('UPDATE products SET stock = stock + ? WHERE id = ?')->execute([$delta, $productId]);
        $stmt = $pdo->prepare('SELECT stock FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        $total = (int) $stmt->fetchColumn();

        $pdo->prepare(
            'INSERT INTO stock_movements (product_id, user_id, sale_id, receiving_id, inventory_doc_id, stock_transfer_id, job_order_id,
                                          customer_delivery_id, branch_id, warehouse_id, location_id, type, quantity, stock_after,
                                          location_qty_after, note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $productId, $userId ?? Auth::id(), $saleId, $receivingId, $docId, $transferId, $jobOrderId, $deliveryId,
            $location['branch_id'], $location['warehouse_id'], $location['id'],
            $type, $delta, $total, $new,
            $note !== null ? mb_substr($note, 0, 255) : null,
        ]);

        return ['location_qty' => $new, 'stock' => $total];
    }

    /**
     * [JOIN, params] adding `bs.qty` = stock of product `p` in the current branch scope
     * (NULL when there is none; use COALESCE(bs.qty, 0)). Shared by Products and Reports.
     * $locationId narrows it to one storage location (the caller checks the location is in scope).
     * @return array{0:string, 1:list<int>}
     */
    public static function scopeJoin(?int $locationId = null): array
    {
        [$scope, $params] = Branch::scopeSql('sb.branch_id');
        if ($locationId !== null) {
            $scope   .= ' AND sb.location_id = ?';
            $params[] = $locationId;
        }
        return [
            // price_value = units x the suggested price of each unit's branch (branch price, else company price).
            "LEFT JOIN (SELECT sb.product_id, SUM(sb.qty) AS qty,
                               SUM(sb.qty * COALESCE(bpp.price, sp.price)) AS price_value
                          FROM stock_balances sb
                          JOIN products sp ON sp.id = sb.product_id
                          LEFT JOIN product_branch_prices bpp ON bpp.product_id = sb.product_id AND bpp.branch_id = sb.branch_id
                         WHERE {$scope} GROUP BY sb.product_id) bs ON bs.product_id = p.id",
            $params,
        ];
    }

    private static function assertTransaction(): void
    {
        if (!db()->inTransaction()) {
            throw new LogicException('Stock changes must run inside a transaction.');
        }
    }
}
