<?php
/**
 * Branch selling prices (migration 020, table product_branch_prices).
 * The suggested price of a product at a branch = its branch price when one is set, else the company price
 * (products.price). Every selling path (POS, job bills, quotations, customer orders) reads it through
 * sql() / map() / of(); sales keep their own price snapshots, so past documents never change.
 * Editing: products.branch_price, only for branches the user may open (super admin / access_all = every
 * branch); a blank price removes the branch price (back to the company price). Any price >= 0 is allowed.
 * Audit module 'products', action 'branch_price' (one record per branch per save, old / new per product code).
 */
declare(strict_types=1);

final class BranchPrices
{
    public const MAX_PRICE = 999999.99;   // same range as products.price
    public const MAX_ROWS  = 500;         // per save

    /**
     * SQL expression: the suggested price of product alias $alias at branch $branchId (an int, inlined) or at a
     * branch column (a code literal like 'sb.branch_id').
     */
    public static function sql(string $alias, int|string $branch): string
    {
        if (!preg_match('/^[a-z_]+$/', $alias) || (is_string($branch) && !preg_match('/^[a-z_]+\.[a-z_]+$/', $branch))) {
            throw new LogicException('Invalid branch price SQL arguments.');
        }
        $b = is_int($branch) ? (string) $branch : $branch;
        return "COALESCE((SELECT bpp.price FROM product_branch_prices bpp WHERE bpp.product_id = {$alias}.id AND bpp.branch_id = {$b}), {$alias}.price)";
    }

    /** @param list<int> $productIds @return array<int,string> product id => branch price (only products that have one) */
    public static function map(array $productIds, int $branchId): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if (!$productIds) {
            return [];
        }
        $in   = implode(',', array_fill(0, count($productIds), '?'));
        $stmt = db()->prepare("SELECT product_id, price FROM product_branch_prices WHERE branch_id = ? AND product_id IN ({$in})");
        $stmt->execute([$branchId, ...$productIds]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['product_id']] = (string) $r['price'];
        }
        return $out;
    }

    /** Suggested price of one product at a branch (company price when none is set). */
    public static function of(int $productId, int $branchId): ?string
    {
        $stmt = db()->prepare('SELECT ' . self::sql('p', $branchId) . ' FROM products p WHERE p.id = ?');
        $stmt->execute([$productId]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    /** Overwrite 'price' (keeping 'company_price') in product rows keyed or listed with 'id'. */
    public static function apply(array $rows, int $branchId, string $idKey = 'id'): array
    {
        $map = self::map(array_map(static fn (array $r): int => (int) $r[$idKey], $rows), $branchId);
        foreach ($rows as $k => $r) {
            $rows[$k]['company_price'] = $r['price'];
            $rows[$k]['branch_price']  = $map[(int) $r[$idKey]] ?? null;
            if (isset($map[(int) $r[$idKey]])) {
                $rows[$k]['price'] = $map[(int) $r[$idKey]];
            }
        }
        return $rows;
    }

    /** May the signed-in user set prices for this branch? */
    public static function canEdit(int $branchId): bool
    {
        return Auth::can('products.branch_price') && in_array($branchId, Branch::allowedIds(), true);
    }

    /** Branches whose prices the user may set (id => row). */
    public static function editableBranches(): array
    {
        return Auth::can('products.branch_price') ? Branch::allowed() : [];
    }

    /** Every active branch with this product's branch price (or null) — the product form card. */
    public static function forProduct(int $productId): array
    {
        $stmt = db()->prepare(
            'SELECT b.id, b.code, b.name, bpp.price, bpp.updated_at, u.full_name AS updated_by_name
               FROM branches b
               LEFT JOIN product_branch_prices bpp ON bpp.branch_id = b.id AND bpp.product_id = ?
               LEFT JOIN users u ON u.id = bpp.updated_by
              WHERE b.is_active = 1 OR bpp.price IS NOT NULL
              ORDER BY b.is_main DESC, b.name'
        );
        $stmt->execute([$productId]);
        return $stmt->fetchAll();
    }

    /**
     * Parse a price box: '' = no branch price (null), else a 0..MAX_PRICE amount ("1,250.50" allowed).
     * @return array{0:bool, 1:?string} [valid, normalized price or null]
     */
    public static function parse(mixed $raw): array
    {
        $raw = is_string($raw) ? str_replace([',', ' '], '', trim($raw)) : '';
        if ($raw === '') {
            return [true, null];
        }
        $v = input_decimal(['v' => $raw], 'v', 0, self::MAX_PRICE, 2);
        return $v === null ? [false, null] : [true, number_format($v, 2, '.', '')];
    }

    /**
     * Save branch prices of ONE branch: $prices = product id => price string ('' = remove).
     * Only rows that change are written. @return int number of products changed
     * @throws HttpException 403 without permission for the branch, 422 with field errors (key = product id)
     */
    public static function saveForBranch(int $branchId, array $prices): int
    {
        if (!self::canEdit($branchId)) {
            throw new HttpException(403, 'You may not set prices for this branch.');
        }
        if (count($prices) > self::MAX_ROWS) {
            throw new HttpException(422, 'Too many prices in one save (max ' . self::MAX_ROWS . ').');
        }
        $clean = [];
        $errors = [];
        foreach ($prices as $pid => $raw) {
            $pid = (int) $pid;
            if ($pid < 1) {
                continue;
            }
            [$ok, $price] = self::parse($raw);
            if (!$ok) {
                $errors[(string) $pid] = 'Enter a price from 0.00 to 999,999.99, or leave it blank for the company price.';
                continue;
            }
            $clean[$pid] = $price;
        }
        if ($errors) {
            throw new HttpException(422, 'Check the highlighted prices.', ['errors' => $errors]);
        }
        if (!$clean) {
            return 0;
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT code FROM branches WHERE id = ? FOR UPDATE');
            $stmt->execute([$branchId]);
            $branchCode = $stmt->fetchColumn();
            if ($branchCode === false) {
                throw new HttpException(404, 'Branch not found.');
            }
            $ids = array_keys($clean);
            $in  = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare(
                "SELECT p.id, p.code, p.price AS company_price, bpp.price AS branch_price
                   FROM products p
                   LEFT JOIN product_branch_prices bpp ON bpp.product_id = p.id AND bpp.branch_id = ?
                  WHERE p.id IN ({$in}) ORDER BY p.id FOR UPDATE"
            );
            $stmt->execute([$branchId, ...$ids]);
            $rows = $stmt->fetchAll();
            if (count($rows) !== count($ids)) {
                throw new HttpException(422, 'A product in the list no longer exists. Reload the page.');
            }
            $upsert = $pdo->prepare(
                'INSERT INTO product_branch_prices (product_id, branch_id, price, updated_by) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE price = VALUES(price), updated_by = VALUES(updated_by)'
            );
            $delete = $pdo->prepare('DELETE FROM product_branch_prices WHERE product_id = ? AND branch_id = ?');
            $old = [];
            $new = [];
            foreach ($rows as $r) {
                $pid    = (int) $r['id'];
                $before = $r['branch_price'] !== null ? (string) $r['branch_price'] : null;
                $after  = $clean[$pid];
                if ($before === $after) {
                    continue;
                }
                if ($after === null) {
                    $delete->execute([$pid, $branchId]);
                } else {
                    $upsert->execute([$pid, $branchId, $after, Auth::id()]);
                }
                $label = static fn (?string $v): string => $v === null ? 'company price ' . $r['company_price'] : $v;
                $old[$r['code']] = $label($before);
                $new[$r['code']] = $label($after);
            }
            if ($new) {
                Audit::record('products', 'branch_price', 'branch_price', $branchId, (string) $branchCode, $old, $new, $branchId);
            }
            $pdo->commit();
            return count($new);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Branch prices of ONE product from the product form: $prices = branch id => price string ('' = remove).
     * Branches the user may not edit are ignored (their price is kept). @return int branches changed
     */
    public static function saveForProduct(int $productId, array $prices): int
    {
        $changed = 0;
        $errors = [];
        foreach ($prices as $bid => $raw) {
            [$ok] = self::parse($raw);
            if (!$ok) {
                $errors['branch_price_' . (int) $bid] = 'Enter a price from 0.00 to 999,999.99, or leave it blank.';
            }
        }
        if ($errors) {
            throw new HttpException(422, 'Check the branch prices.', ['errors' => $errors]);
        }
        foreach ($prices as $bid => $raw) {
            if (self::canEdit((int) $bid)) {
                $changed += self::saveForBranch((int) $bid, [$productId => $raw]) > 0 ? 1 : 0;
            }
        }
        return $changed;
    }

    /**
     * Branch Prices page list: active products with the company and the branch price.
     * @param array{q:string, category:?int, only:bool} $f
     */
    public static function search(int $branchId, array $f, int $limit, int $offset): array
    {
        [$where, $params] = self::where($f);
        $stmt = db()->prepare(
            "SELECT p.id, p.code, p.name, p.price AS company_price, c.name AS category_name, un.code AS unit_code,
                    bpp.price AS branch_price, bpp.updated_at, u.full_name AS updated_by_name
               FROM products p
               JOIN categories c ON c.id = p.category_id
               LEFT JOIN units un ON un.id = p.unit_id
               LEFT JOIN product_branch_prices bpp ON bpp.product_id = p.id AND bpp.branch_id = ?
               LEFT JOIN users u ON u.id = bpp.updated_by
              WHERE {$where}
              ORDER BY c.sort_order, p.code
              LIMIT ? OFFSET ?"
        );
        $stmt->execute([$branchId, ...$params, $limit, $offset]);
        return $stmt->fetchAll();
    }

    public static function count(int $branchId, array $f): int
    {
        [$where, $params] = self::where($f);
        $stmt = db()->prepare(
            "SELECT COUNT(*) FROM products p
               LEFT JOIN product_branch_prices bpp ON bpp.product_id = p.id AND bpp.branch_id = ?
              WHERE {$where}"
        );
        $stmt->execute([$branchId, ...$params]);
        return (int) $stmt->fetchColumn();
    }

    /** How many products have a branch price at this branch. */
    public static function countSet(int $branchId): int
    {
        $stmt = db()->prepare('SELECT COUNT(*) FROM product_branch_prices bpp JOIN products p ON p.id = bpp.product_id WHERE bpp.branch_id = ? AND p.is_active = ?');
        $stmt->execute([$branchId, 1]);
        return (int) $stmt->fetchColumn();
    }

    private static function where(array $f): array
    {
        $where  = ['p.is_active = ?'];
        $params = [1];
        if ($f['q'] !== '') {
            $where[] = '(p.name LIKE ? OR p.code LIKE ? OR p.barcode LIKE ?)';
            $like = like_pattern($f['q']);
            array_push($params, $like, $like, $like);
        }
        if ($f['category'] !== null) {
            $where[]  = 'p.category_id = ?';
            $params[] = $f['category'];
        }
        if ($f['only']) {
            $where[] = 'bpp.price IS NOT NULL';
        }
        return [implode(' AND ', $where), $params];
    }
}
