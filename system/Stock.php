<?php
/**
 * The only writer of stock. Used by Products (opening stock, adjustments) and Sales (sale, void).
 *
 * Stock::move() changes one product at one storage location:
 *   stock_balances.qty (per location)  +  products.stock (company total)  +  one stock_movements row.
 * The caller runs it inside its own transaction and has already locked the products row
 * (SELECT ... FOR UPDATE), which serialises every change to that product.
 */
declare(strict_types=1);

final class Stock
{
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
     * @param array{id:int, warehouse_id:int, branch_id:int, branch_name?:string} $location from Branch::defaultLocation()
     * @param int    $delta  signed change (+ in, - out)
     * @param string $type   initial | sale | restock | adjustment | void
     * @return array{location_qty:int, stock:int} levels after the change
     * @throws HttpException 409 when the location would go below zero
     */
    public static function move(int $productId, array $location, int $delta, string $type, ?string $note,
                                ?int $saleId = null, ?int $userId = null): array
    {
        self::assertTransaction();
        if (!in_array($type, ['initial', 'sale', 'restock', 'adjustment', 'void'], true)) {
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
            throw new HttpException(409, 'Not enough stock at ' . ($location['branch_name'] ?? 'this branch') . '.');
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
            'INSERT INTO stock_movements (product_id, user_id, sale_id, branch_id, warehouse_id, location_id,
                                          type, quantity, stock_after, location_qty_after, note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $productId, $userId ?? Auth::id(), $saleId,
            $location['branch_id'], $location['warehouse_id'], $location['id'],
            $type, $delta, $total, $new,
            $note !== null ? mb_substr($note, 0, 255) : null,
        ]);

        return ['location_qty' => $new, 'stock' => $total];
    }

    private static function assertTransaction(): void
    {
        if (!db()->inTransaction()) {
            throw new LogicException('Stock changes must run inside a transaction.');
        }
    }
}
