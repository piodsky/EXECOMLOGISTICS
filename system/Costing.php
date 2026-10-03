<?php
/**
 * Branch moving-average cost (product_branches.avg_cost, 4 decimals).
 *
 *  - A missing product_branches row means "not costed yet": it is created from products.unit_cost
 *    (the product's default cost) the first time it is read.
 *  - Inbound stock with a known cost (receiving, sale void) re-averages:
 *        after = (qty_before x avg + qty x cost) / (qty_before + qty), half-up to 4 dp;
 *    qty_before <= 0 -> after = cost. Inbound stock without a cost (opening stock, restock,
 *    customer return, count correction) leaves the average unchanged (call ensure()).
 *  - All math is in integers of 1/10000 (no floats).
 *
 * Every method must run inside the caller's transaction, and the caller already holds the
 * products row lock (SELECT ... FOR UPDATE). Lock order: products -> stock_balances -> product_branches.
 */
declare(strict_types=1);

final class Costing
{
    /** 1 unit of cost = 10000 internal units (4 decimals). */
    public const SCALE = 10000;

    /** Quantity of a product over all locations of a branch, locking those balance rows. */
    public static function branchQty(int $productId, int $branchId): int
    {
        self::assertTransaction();
        $stmt = db()->prepare(
            'SELECT qty FROM stock_balances WHERE product_id = ? AND branch_id = ? ORDER BY location_id FOR UPDATE'
        );
        $stmt->execute([$productId, $branchId]);
        return array_sum(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    /**
     * Current average cost at a branch ("1234.5000"), locking the product_branches row.
     * Creates the row from products.unit_cost when it is missing.
     */
    public static function avg(int $productId, int $branchId): string
    {
        self::assertTransaction();
        $pdo  = db();
        $stmt = $pdo->prepare('SELECT avg_cost FROM product_branches WHERE product_id = ? AND branch_id = ? FOR UPDATE');
        $stmt->execute([$productId, $branchId]);
        $avg = $stmt->fetchColumn();
        if ($avg !== false) {
            return self::format(self::toUnits((string) $avg));
        }
        $stmt = $pdo->prepare('SELECT unit_cost FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        $default = $stmt->fetchColumn();
        if ($default === false) {
            throw new HttpException(404, 'Product not found.');
        }
        $avg = self::format(self::toUnits((string) $default));
        $pdo->prepare('INSERT INTO product_branches (product_id, branch_id, avg_cost) VALUES (?, ?, ?)')
            ->execute([$productId, $branchId, $avg]);
        return $avg;
    }

    /** Make sure the branch has a cost row (inbound stock without a cost: average unchanged). */
    public static function ensure(int $productId, int $branchId): string
    {
        return self::avg($productId, $branchId);
    }

    /**
     * Re-average for $qty units coming in at $unitCost. Call BEFORE Stock::move (qty_before is
     * the branch quantity before the stock arrives).
     * @return array{qty_before:int, before:string, after:string}
     */
    public static function inbound(int $productId, int $branchId, int $qty, string $unitCost): array
    {
        if ($qty <= 0) {
            throw new LogicException('Inbound quantity must be positive.');
        }
        $qb     = self::branchQty($productId, $branchId);
        $before = self::avg($productId, $branchId);
        $a      = self::toUnits($before);
        $c      = self::toUnits($unitCost);
        if ($c < 0) {
            throw new LogicException('Cost must not be negative.');
        }
        if ($qb <= 0) {
            $after = $c;
        } else {
            $n     = $qb + $qty;
            $after = intdiv(2 * ($qb * $a + $qty * $c) + $n, 2 * $n); // half-up
        }
        $afterStr = self::format($after);
        self::write($productId, $branchId, $afterStr);
        return ['qty_before' => $qb, 'before' => $before, 'after' => $afterStr];
    }

    /**
     * Stock coming back from a voided sale. $unitCost = the sale's cost snapshot (weighted per product,
     * 4 dp) or null for sales made before costing existed (average unchanged).
     * Call BEFORE Stock::move. @return array{qty_before:int, before:string, after:string}
     */
    public static function applyReturn(int $productId, int $branchId, int $qty, ?string $unitCost): array
    {
        if ($unitCost === null) {
            $qb  = self::branchQty($productId, $branchId); // lock order: stock_balances, then product_branches
            $avg = self::ensure($productId, $branchId);
            return ['qty_before' => $qb, 'before' => $avg, 'after' => $avg];
        }
        return self::inbound($productId, $branchId, $qty, $unitCost);
    }

    /** Overwrite the average (RR cancel restores avg_cost_before). */
    public static function set(int $productId, int $branchId, string $avg): void
    {
        self::avg($productId, $branchId); // lock / create the row
        self::write($productId, $branchId, self::format(self::toUnits($avg)));
    }

    /**
     * Weighted cost of several snapshots: sum(qty x cost) / sum(qty), half-up 4 dp.
     * @param list<array{qty:int, cost:string}> $parts
     */
    public static function weighted(array $parts): string
    {
        $num = 0;
        $den = 0;
        foreach ($parts as $p) {
            $num += $p['qty'] * self::toUnits($p['cost']);
            $den += $p['qty'];
        }
        if ($den <= 0) {
            throw new LogicException('Weighted cost needs a positive quantity.');
        }
        return self::format(intdiv(2 * $num + $den, 2 * $den));
    }

    /** Line value in centavos: round(qty x cost x 100), half-up. */
    public static function lineCents(int $qty, string $unitCost): int
    {
        // units are 1/10000, cents 1/100 -> divide by 100 with half-up
        return intdiv(2 * $qty * self::toUnits($unitCost) + 100, 200);
    }

    /** "1234.5678" / "12.3" / "7" -> integer units of 1/10000 (half-up beyond 4 decimals). */
    public static function toUnits(string $value): int
    {
        $value = trim($value);
        if (!preg_match('/^(-?)(\d+)(?:\.(\d*))?$/', $value, $m)) {
            throw new LogicException('Invalid cost value.');
        }
        $frac  = ($m[3] ?? '') . '00000';
        $units = (int) $m[2] * self::SCALE + (int) substr($frac, 0, 4);
        if ((int) $frac[4] >= 5) {
            $units++;
        }
        return $m[1] === '-' ? -$units : $units;
    }

    /** Integer units -> DECIMAL(12,4) string. */
    public static function format(int $units): string
    {
        $sign = $units < 0 ? '-' : '';
        $units = abs($units);
        return sprintf('%s%d.%04d', $sign, intdiv($units, self::SCALE), $units % self::SCALE);
    }

    private static function write(int $productId, int $branchId, string $avg): void
    {
        db()->prepare('UPDATE product_branches SET avg_cost = ? WHERE product_id = ? AND branch_id = ?')
            ->execute([$avg, $productId, $branchId]);
    }

    private static function assertTransaction(): void
    {
        if (!db()->inTransaction()) {
            throw new LogicException('Costing must run inside a transaction.');
        }
    }
}
