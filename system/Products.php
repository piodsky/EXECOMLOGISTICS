<?php
/**
 * Products: validation, listing, CRUD and stock adjustments (with audit log).
 * The catalogue (products, prices) is company-wide; stock is per branch (stock_balances).
 * Stock only changes through sales or adjustStock() (both via Stock::move), never by editing
 * the product, so every change lands in stock_movements.
 * Stock figures shown here are the sum over the current branch scope ("All branches" = company total).
 */
declare(strict_types=1);

final class Products
{
    public const MAX_STOCK = 99999;

    /** Adjustment reasons: key => [label, allowed direction] */
    public const REASONS = [
        'restock'  => ['Restock / delivery', 'add'],
        'return'   => ['Customer return', 'add'],
        'damaged'  => ['Damaged / defective', 'remove'],
        'supplier' => ['Returned to supplier (RMA)', 'remove'],
        'count'    => ['Stock count correction', 'both'],
        'other'    => ['Other', 'both'],
    ];

    public static function categories(): array
    {
        $stmt = db()->prepare('SELECT id, name FROM categories WHERE is_active = ? ORDER BY sort_order, name');
        $stmt->execute([1]);
        return $stmt->fetchAll();
    }

    /**
     * Join adding `bs.qty` = stock in the current branch scope (NULL when none; use COALESCE).
     * @return array{0:string, 1:list<int>}
     */
    private static function stockJoin(): array
    {
        [$scope, $params] = Branch::scopeSql('sb.branch_id');
        return [
            "LEFT JOIN (SELECT sb.product_id, SUM(sb.qty) AS qty FROM stock_balances sb
                         WHERE {$scope} GROUP BY sb.product_id) bs ON bs.product_id = p.id",
            $params,
        ];
    }

    /** Product with `stock` = stock in the current scope and `total_stock` = company total. */
    public static function find(int $id): ?array
    {
        [$join, $params] = self::stockJoin();
        $stmt = db()->prepare(
            "SELECT p.*, p.stock AS total_stock, COALESCE(bs.qty, 0) AS stock, c.name AS category_name,
                    (SELECT COUNT(*) FROM sale_items si WHERE si.product_id = p.id) AS times_sold
               FROM products p JOIN categories c ON c.id = p.category_id
               {$join}
              WHERE p.id = ?"
        );
        $stmt->execute([...$params, $id]);
        return $stmt->fetch() ?: null;
    }

    // ------------------------------------------------------------------
    // Listing
    // ------------------------------------------------------------------

    /** @param array{q:string, category:?int, status:string} $f */
    public static function count(array $f): int
    {
        [$join, $joinParams] = self::stockJoin();
        [$where, $params] = self::where($f);
        $stmt = db()->prepare("SELECT COUNT(*) FROM products p {$join} WHERE {$where}");
        $stmt->execute([...$joinParams, ...$params]);
        return (int) $stmt->fetchColumn();
    }

    public static function search(array $f, int $limit, int $offset): array
    {
        [$join, $joinParams] = self::stockJoin();
        [$where, $params] = self::where($f);
        $stmt = db()->prepare(
            "SELECT p.id, p.code, p.barcode, p.name, p.price, COALESCE(bs.qty, 0) AS stock, p.stock AS total_stock,
                    p.reorder_level, p.image, p.is_active, c.name AS category_name,
                    (SELECT COUNT(*) FROM sale_items si WHERE si.product_id = p.id) AS times_sold
               FROM products p JOIN categories c ON c.id = p.category_id
               {$join}
              WHERE {$where}
              ORDER BY p.is_active DESC, c.sort_order, p.code
              LIMIT ? OFFSET ?"
        );
        $stmt->execute([...$joinParams, ...$params, $limit, $offset]);
        return $stmt->fetchAll();
    }

    private static function where(array $f): array
    {
        $where  = ['1 = 1'];
        $params = [];
        if ($f['q'] !== '') {
            $where[] = '(p.name LIKE ? OR p.code LIKE ? OR p.barcode LIKE ?)';
            $like = like_pattern($f['q']);
            array_push($params, $like, $like, $like);
        }
        if ($f['category'] !== null) {
            $where[]  = 'p.category_id = ?';
            $params[] = $f['category'];
        }
        $where[] = match ($f['status']) {
            'active'   => 'p.is_active = 1',
            'inactive' => 'p.is_active = 0',
            'low'      => 'p.is_active = 1 AND COALESCE(bs.qty, 0) > 0 AND COALESCE(bs.qty, 0) <= p.reorder_level',
            'out'      => 'p.is_active = 1 AND COALESCE(bs.qty, 0) = 0',
            default    => '1 = 1',
        };
        return [implode(' AND ', $where), $params];
    }

    /** Active products, stock value, low and out of stock — in the current branch scope. */
    public static function summary(): array
    {
        [$join, $params] = self::stockJoin();
        $stmt = db()->prepare(
            "SELECT COUNT(*) AS items,
                    COALESCE(SUM(p.price * COALESCE(bs.qty, 0)), 0) AS stock_value,
                    COALESCE(SUM(COALESCE(bs.qty, 0) > 0 AND COALESCE(bs.qty, 0) <= p.reorder_level), 0) AS low,
                    COALESCE(SUM(COALESCE(bs.qty, 0) = 0), 0) AS out_of_stock
               FROM products p
               {$join}
              WHERE p.is_active = ?"
        );
        $stmt->execute([...$params, 1]);
        return $stmt->fetch();
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    /**
     * @return array{0: array, 1: array<string,string>}  [clean data, field errors]
     */
    public static function validate(array $in, ?int $id): array
    {
        $errors = [];
        $data = [
            'category_id'   => input_int($in, 'category_id', 1),
            'code'          => strtoupper(input_string($in, 'code', 20)),
            'barcode'       => input_string($in, 'barcode', 50),
            'name'          => input_string($in, 'name', 100),
            'description'   => input_string($in, 'description', 255),
            'price'         => input_decimal($in, 'price', 0, 999999.99),
            'reorder_level' => input_int($in, 'reorder_level', 0, 9999),
            'is_active'     => isset($in['is_active']) ? 1 : 0,
        ];

        $categoryIds = array_map('intval', array_column(self::categories(), 'id'));
        if ($data['category_id'] === null || !in_array($data['category_id'], $categoryIds, true)) {
            $errors['category_id'] = 'Choose a category.';
        }
        if (!preg_match('/^[A-Z0-9][A-Z0-9-]{1,19}$/', $data['code'])) {
            $errors['code'] = 'Use 2–20 letters, numbers or dashes (e.g. ITM-0013).';
        } elseif (self::taken('code', $data['code'], $id)) {
            $errors['code'] = 'Another product already uses this code.';
        }
        if ($data['barcode'] !== '') {
            if (!preg_match('/^[A-Za-z0-9-]{4,50}$/', $data['barcode'])) {
                $errors['barcode'] = 'Barcode must be 4–50 letters, numbers or dashes.';
            } elseif (self::taken('barcode', $data['barcode'], $id)) {
                $errors['barcode'] = 'Another product already uses this barcode.';
            }
        }
        if (mb_strlen($data['name']) < 2) {
            $errors['name'] = 'Enter the product name.';
        }
        if ($data['price'] === null) {
            $errors['price'] = 'Enter a price from 0.00 to 999,999.99.';
        }
        if ($data['reorder_level'] === null) {
            $errors['reorder_level'] = 'Enter a whole number from 0 to 9999.';
        }

        // Opening stock is only set when creating (at the current branch); afterwards use Adjust stock.
        if ($id === null) {
            $data['stock'] = input_int($in, 'stock', 0, self::MAX_STOCK);
            if ($data['stock'] === null) {
                $errors['stock'] = 'Enter a whole number from 0 to ' . number_format(self::MAX_STOCK) . '.';
            } elseif ($data['stock'] > 0 && !Branch::isConcrete()) {
                $errors['stock'] = 'Choose a branch first (top bar): opening stock is added to that branch.';
            }
        }

        $data['barcode']     = $data['barcode'] !== '' ? $data['barcode'] : null;
        $data['description'] = $data['description'] !== '' ? $data['description'] : null;

        return [$data, $errors];
    }

    private static function taken(string $column, string $value, ?int $exceptId): bool
    {
        $column = $column === 'barcode' ? 'barcode' : 'code'; // whitelist — never interpolate input
        $stmt = db()->prepare("SELECT 1 FROM products WHERE {$column} = ? AND id <> ? LIMIT 1");
        $stmt->execute([$value, $exceptId ?? 0]);
        return (bool) $stmt->fetchColumn();
    }

    private static function requireManage(): void
    {
        if (!Auth::can('products.manage')) {
            throw new HttpException(403, 'You do not have permission to manage products.');
        }
    }

    /** Fields recorded in the audit log. */
    private static function auditFields(array $p): array
    {
        return array_intersect_key($p, array_flip(
            ['category_id', 'code', 'barcode', 'name', 'description', 'price', 'reorder_level', 'image', 'is_active']
        ));
    }

    // ------------------------------------------------------------------
    // Create / update / status / delete  (products.manage)
    // ------------------------------------------------------------------

    public static function create(array $d, ?string $image, int $userId): int
    {
        self::requireManage();
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO products (category_id, code, barcode, name, description, price, stock, reorder_level, image, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $d['category_id'], $d['code'], $d['barcode'], $d['name'], $d['description'],
                number_format($d['price'], 2, '.', ''), 0, $d['reorder_level'], $image, $d['is_active'],
            ]);
            $id = (int) $pdo->lastInsertId();

            // Opening stock goes to the current branch's default location (Stock::move sets products.stock).
            if ($d['stock'] > 0 || Branch::isConcrete()) {
                Stock::move($id, Branch::defaultLocation(Branch::forWrite()), $d['stock'], 'initial', 'Opening stock', null, $userId);
            }
            Audit::record('products', 'create', 'product', $id, $d['code'], null,
                self::auditFields($d + ['image' => $image]) + ['opening_stock' => $d['stock'], 'branch' => Branch::label()]);
            $pdo->commit();
            return $id;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function update(int $id, array $d, ?string $image): void
    {
        self::requireManage();
        $before = self::find($id) ?? throw new HttpException(404, 'Product not found.');
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'UPDATE products SET category_id = ?, code = ?, barcode = ?, name = ?, description = ?, price = ?,
                                     reorder_level = ?, image = ?, is_active = ?
                  WHERE id = ?'
            )->execute([
                $d['category_id'], $d['code'], $d['barcode'], $d['name'], $d['description'],
                number_format($d['price'], 2, '.', ''), $d['reorder_level'], $image, $d['is_active'], $id,
            ]);
            $after = ['price' => number_format($d['price'], 2, '.', ''), 'image' => $image] + $d;
            [$old, $new] = Audit::diff(self::auditFields($before), self::auditFields($after));
            if ($new) {
                Audit::record('products', 'update', 'product', $id, $d['code'], $old, $new);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return array the product after the change */
    public static function toggleActive(int $id): array
    {
        self::requireManage();
        $product = self::find($id) ?? throw new HttpException(404, 'Product not found.');
        $next = (int) $product['is_active'] === 1 ? 0 : 1;
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE products SET is_active = ? WHERE id = ?')->execute([$next, $id]);
            Audit::record('products', $next ? 'activate' : 'deactivate', 'product', $id, $product['code'],
                ['is_active' => (int) $product['is_active']], ['is_active' => $next]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $product['is_active'] = $next;
        return $product;
    }

    /** Hard delete — only for products that were never sold (sales history must keep its link). */
    public static function delete(int $id): array
    {
        self::requireManage();
        $product = self::find($id) ?? throw new HttpException(404, 'Product not found.');
        if ((int) $product['times_sold'] > 0) {
            throw new HttpException(409, "{$product['name']} has sales history, so it can't be deleted. Deactivate it instead to hide it from the POS.");
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
            Audit::record('products', 'delete', 'product', $id, $product['code'],
                self::auditFields($product) + ['company_stock' => (int) $product['total_stock']], null);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        ImageUpload::delete($product['image']);
        return $product;
    }

    // ------------------------------------------------------------------
    // Stock (inventory.adjust) — always at the current branch
    // ------------------------------------------------------------------

    /**
     * Add or remove stock with a reason at the current branch's default location.
     * Returns [product name, new stock at the branch, branch name].
     * @param int $change  positive = add, negative = remove
     */
    public static function adjustStock(int $id, int $change, string $reason, string $note, int $userId): array
    {
        if (!Auth::can('inventory.adjust')) {
            throw new HttpException(403, 'You do not have permission to adjust stock.');
        }
        if ($change === 0) {
            throw new HttpException(422, 'Enter a quantity greater than zero.');
        }
        [$label, $direction] = self::REASONS[$reason] ?? throw new HttpException(422, 'Choose a reason.');
        if (($direction === 'add' && $change < 0) || ($direction === 'remove' && $change > 0)) {
            throw new HttpException(422, "“{$label}” can't be used when " . ($change > 0 ? 'adding' : 'removing') . ' stock.');
        }
        $branchId = Branch::forWrite();

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT code, name FROM products WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $product = $stmt->fetch() ?: throw new HttpException(404, 'Product not found.');

            $location = Branch::defaultLocation($branchId);
            $before   = Stock::balance($id, $location['id']);
            $new      = $before + $change;
            if ($new < 0) {
                throw new HttpException(422, "You can't remove " . abs($change) . " — only {$before} {$product['name']} in stock at {$location['branch_name']}.");
            }
            if ($new > self::MAX_STOCK) {
                throw new HttpException(422, 'Stock can be at most ' . number_format(self::MAX_STOCK) . '.');
            }

            $type = $reason === 'restock' ? 'restock' : 'adjustment';
            Stock::move($id, $location, $change, $type, $label . ($note !== '' ? ' — ' . $note : ''), null, $userId);
            Audit::record('inventory', 'stock_adjust', 'product', $id, $product['code'],
                ['qty' => $before], ['qty' => $new, 'change' => $change, 'reason' => $reason, 'note' => $note], $branchId);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return [$product['name'], $new, $location['branch_name']];
    }

    /** Stock history of a product in the current branch scope. */
    public static function movements(int $productId, int $limit = 15): array
    {
        [$scope, $params] = Branch::scopeSql('m.branch_id');
        $stmt = db()->prepare(
            "SELECT m.type, m.quantity, m.stock_after, m.location_qty_after, m.note, m.created_at, m.sale_id,
                    u.username, s.sale_no, b.code AS branch_code, b.name AS branch_name
               FROM stock_movements m
               LEFT JOIN users u ON u.id = m.user_id
               LEFT JOIN sales s ON s.id = m.sale_id
               LEFT JOIN branches b ON b.id = m.branch_id
              WHERE m.product_id = ? AND {$scope}
              ORDER BY m.id DESC
              LIMIT ?"
        );
        $stmt->execute([$productId, ...$params, $limit]);
        return $stmt->fetchAll();
    }
}
