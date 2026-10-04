<?php
/**
 * The only writer of stock. Used by Products (opening stock, adjustments), Sales (sale, void),
 * Receiving (RR post / cancel), InventoryDocs (transfers, issues, write-offs, counts) and
 * Transfers (branch-to-branch release / receive) and JobParts (parts issued to / returned from job custody).
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
     *  job_issue / job_return: JobParts). */
    public const TYPES = ['initial', 'sale', 'restock', 'adjustment', 'void', 'receiving', 'transfer', 'issue', 'write_off', 'count',
                          'transfer_out', 'transfer_in', 'job_issue', 'job_return'];

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
     * @param array{id:int, warehouse_id:int, branch_id:int, branch_name?:string, label?:string} $location
     *        from Branch::defaultLocation() (or a storage location row; 'label' names it in the 409 message)
     * @param int    $delta  signed change (+ in, - out)
     * @param string $type   initial | sale | restock | adjustment | void | receiving | transfer | issue | write_off | count
     *                       | transfer_out | transfer_in
     * @param ?int   $receivingId receiving_reports.id (type 'receiving': RR post and cancel)
     * @param ?int   $docId  inventory_docs.id (types transfer / issue / write_off / count)
     * @param ?int   $transferId stock_transfers.id (types transfer_out / transfer_in)
     * @param ?int   $jobOrderId job_orders.id (types job_issue / job_return)
     * @return array{location_qty:int, stock:int} levels after the change
     * @throws HttpException 409 when the location would go below zero
     */
    public static function move(int $productId, array $location, int $delta, string $type, ?string $note,
                                ?int $saleId = null, ?int $userId = null, ?int $receivingId = null,
                                ?int $docId = null, ?int $transferId = null, ?int $jobOrderId = null): array
    {
        self::assertTransaction();
        if (!in_array($type, self::TYPES, true)) {
            throw new LogicException("Unknown stock movement type [{$type}]");
        }
        $pdo = db();

        // Normally already locked by the caller; re-locking our own row is a no-op, and it keeps the
        // "one writer per product at a time" guarantee even if a caller forgets.
        $stmt = $pdo->prepare('SELECT id FROM products WHERE id = ? FOR UPDATE');
        $stmt->execute([$productId]);
        if ($stmt->fetchColumn() === false) {
            throw new HttpException(404, 'Product not found.');
        }

        $stmt = $pdo->prepare('SELECT qty FROM stock_balances WHERE product_id = ? AND location_id = ? FOR UPDATE');
        $stmt->execute([$productId, $location['id']]);
        $current = $stmt->fetchColumn();
        $new     = ($current === false ? 0 : (int) $current) + $delta;

        if ($new < 0) {
            throw new HttpException(409, 'Not enough stock at ' . ($location['label'] ?? $location['branch_name'] ?? 'this branch') . '.');
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
                                          branch_id, warehouse_id, location_id, type, quantity, stock_after, location_qty_after, note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $productId, $userId ?? Auth::id(), $saleId, $receivingId, $docId, $transferId, $jobOrderId,
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
            "LEFT JOIN (SELECT sb.product_id, SUM(sb.qty) AS qty FROM stock_balances sb
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
