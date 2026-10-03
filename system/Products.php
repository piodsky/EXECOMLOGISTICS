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
    public const MAX_WARRANTY_DAYS = 3650;

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

    /** Product with `stock` = stock in the current scope and `total_stock` = company total. */
    public static function find(int $id): ?array
    {
        [$join, $params] = Stock::scopeJoin();
        $stmt = db()->prepare(
            "SELECT p.*, p.stock AS total_stock, COALESCE(bs.qty, 0) AS stock, c.name AS category_name,
                    br.name AS brand_name, pm.name AS model_name, un.code AS unit_code, un.name AS unit_name,
                    (SELECT COUNT(*) FROM sale_items si WHERE si.product_id = p.id) AS times_sold,
                    (SELECT COUNT(*) FROM receiving_items ri WHERE ri.product_id = p.id) AS times_received,
                    (SELECT COUNT(*) FROM product_serials ps WHERE ps.product_id = p.id) AS serial_count
               FROM products p JOIN categories c ON c.id = p.category_id
               LEFT JOIN brands br ON br.id = p.brand_id
               LEFT JOIN product_models pm ON pm.id = p.model_id
               LEFT JOIN units un ON un.id = p.unit_id
               {$join}
              WHERE p.id = ?"
        );
        $stmt->execute([...$params, $id]);
        $product = $stmt->fetch() ?: null;
        if ($product !== null && !Auth::can('products.cost')) {
            unset($product['unit_cost']); // never leaves the server without products.cost
        }
        return $product;
    }

    /** Id of the default unit (PC = Piece) while it is active, else null. */
    public static function defaultUnitId(): ?int
    {
        $stmt = db()->prepare('SELECT id FROM units WHERE code = ? AND is_active = ?');
        $stmt->execute(['PC', 1]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    // ------------------------------------------------------------------
    // Listing
    // ------------------------------------------------------------------

    /** Brand / model / unit joins used by the list (aliases br, pm, un). */
    private const LIST_JOINS = 'LEFT JOIN brands br ON br.id = p.brand_id
               LEFT JOIN product_models pm ON pm.id = p.model_id
               LEFT JOIN units un ON un.id = p.unit_id';

    /** @param array{q:string, category:?int, brand?:?int, status:string, location?:?int} $f (location: one storage location, checked by the caller) */
    public static function count(array $f): int
    {
        [$join, $joinParams] = Stock::scopeJoin($f['location'] ?? null);
        [$where, $params] = self::where($f);
        $stmt = db()->prepare("SELECT COUNT(*) FROM products p " . self::LIST_JOINS . " {$join} WHERE {$where}");
        $stmt->execute([...$joinParams, ...$params]);
        return (int) $stmt->fetchColumn();
    }

    public static function search(array $f, int $limit, int $offset): array
    {
        [$join, $joinParams] = Stock::scopeJoin($f['location'] ?? null);
        [$where, $params] = self::where($f);
        $cost = Auth::can('products.cost') ? 'p.unit_cost, ' : ''; // cost only with products.cost
        $stmt = db()->prepare(
            "SELECT p.id, p.code, p.barcode, p.name, p.price, {$cost}COALESCE(bs.qty, 0) AS stock, p.stock AS total_stock, p.track_serial,
                    p.reorder_level, p.image, p.is_active, c.name AS category_name,
                    br.name AS brand_name, pm.name AS model_name, un.code AS unit_code, un.name AS unit_name,
                    (SELECT COUNT(*) FROM sale_items si WHERE si.product_id = p.id) AS times_sold
               FROM products p JOIN categories c ON c.id = p.category_id
               " . self::LIST_JOINS . "
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
            $where[] = '(p.name LIKE ? OR p.code LIKE ? OR p.barcode LIKE ? OR br.name LIKE ? OR pm.name LIKE ?)';
            $like = like_pattern($f['q']);
            array_push($params, $like, $like, $like, $like, $like);
        }
        if ($f['category'] !== null) {
            $where[]  = 'p.category_id = ?';
            $params[] = $f['category'];
        }
        if (($f['brand'] ?? null) !== null) {
            $where[]  = 'p.brand_id = ?';
            $params[] = $f['brand'];
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
        [$join, $params] = Stock::scopeJoin();
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
            'brand_id'      => input_int($in, 'brand_id', 1),
            'model_id'      => input_int($in, 'model_id', 1),
            'unit_id'       => input_int($in, 'unit_id', 1),
            'track_serial'  => isset($in['track_serial']) ? 1 : 0,
            'specs'         => input_string($in, 'specs', 500),
        ];

        // Current master data values stay valid even after they were deactivated.
        $current = ['category_id' => null, 'brand_id' => null, 'model_id' => null, 'unit_id' => null];
        if ($id !== null) {
            $stmt = db()->prepare('SELECT category_id, brand_id, model_id, unit_id FROM products WHERE id = ?');
            $stmt->execute([$id]);
            $current = array_map(static fn ($v) => $v === null ? null : (int) $v, $stmt->fetch() ?: $current);
        }
        if (!MasterData::isChoice('categories', $data['category_id'], $current['category_id'])) {
            $errors['category_id'] = 'Choose a category.';
        }
        if (is_string($in['brand_id'] ?? null) && trim($in['brand_id']) !== '' && !MasterData::isChoice('brands', $data['brand_id'], $current['brand_id'])) {
            $errors['brand_id'] = 'Choose a brand from the list.';
        }
        if ($data['model_id'] !== null || (is_string($in['model_id'] ?? null) && trim($in['model_id']) !== '')) {
            $models = MasterData::options('models', $current['model_id']);
            if ($data['brand_id'] === null) {
                $errors['model_id'] = 'Choose the brand first.';
            } elseif ($data['model_id'] === null || !isset($models[$data['model_id']])
                || (int) $models[$data['model_id']]['brand_id'] !== $data['brand_id']) {
                $errors['model_id'] = 'Choose a model of the selected brand.';
            }
        }
        if (!MasterData::isChoice('units', $data['unit_id'], $current['unit_id'])) {
            $errors['unit_id'] = 'Choose a unit.';
        }
        $raw = $in['warranty_days'] ?? '';
        $data['warranty_days'] = is_string($raw) && trim($raw) === '' ? 0 : input_int($in, 'warranty_days', 0, self::MAX_WARRANTY_DAYS);
        if ($data['warranty_days'] === null) {
            $errors['warranty_days'] = 'Enter the warranty in days, 0 to ' . number_format(self::MAX_WARRANTY_DAYS) . '.';
        }
        // Unit cost: only with products.cost; otherwise null = keep the stored value (0 for a new product).
        $data['unit_cost'] = null;
        if (Auth::can('products.cost')) {
            $raw = $in['unit_cost'] ?? '';
            $data['unit_cost'] = is_string($raw) && trim($raw) === '' ? 0.0 : input_decimal($in, 'unit_cost', 0, 999999.99);
            if ($data['unit_cost'] === null) {
                $errors['unit_cost'] = 'Enter a cost from 0.00 to 999,999.99.';
            }
        }
        $data['specs'] = $data['specs'] !== '' ? $data['specs'] : null;
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
            } elseif ($data['stock'] > 0 && $data['track_serial'] === 1) {
                $errors['stock'] = 'Serial-tracked items start at 0: add their stock through Receiving with the serial numbers.';
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
            ['category_id', 'code', 'barcode', 'name', 'description', 'price', 'reorder_level', 'image', 'is_active',
             'brand_id', 'model_id', 'unit_id', 'unit_cost', 'track_serial', 'warranty_days', 'specs']
        ));
    }

    // ------------------------------------------------------------------
    // Create / update / status / delete  (products.manage)
    // ------------------------------------------------------------------

    public static function create(array $d, ?string $image, int $userId): int
    {
        self::requireManage();
        if (!Auth::can('products.cost')) {
            $d['unit_cost'] = null; // re-checked here, not only in validate()
        }
        $d['unit_cost'] ??= 0.0; // no products.cost: a new product starts at cost 0
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO products (category_id, code, barcode, name, description, price, stock, reorder_level, image, is_active,
                                       brand_id, model_id, unit_id, unit_cost, track_serial, warranty_days, specs)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $d['category_id'], $d['code'], $d['barcode'], $d['name'], $d['description'],
                number_format($d['price'], 2, '.', ''), 0, $d['reorder_level'], $image, $d['is_active'],
                $d['brand_id'], $d['model_id'], $d['unit_id'], number_format((float) ($d['unit_cost'] ?? 0), 2, '.', ''),
                $d['track_serial'], $d['warranty_days'], $d['specs'],
            ]);
            $id = (int) $pdo->lastInsertId();

            // Opening stock goes to the current branch's default location (Stock::move sets products.stock).
            if ($d['stock'] > 0 || Branch::isConcrete()) {
                if ((int) $d['track_serial'] === 1 && $d['stock'] > 0) {
                    throw new HttpException(422, 'Serial-tracked items start at 0: add their stock through Receiving.');
                }
                Costing::ensure($id, Branch::forWrite()); // branch cost row = default cost
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
        if (!Auth::can('products.cost')) {
            $d['unit_cost'] = null; // re-checked here, not only in validate()
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            // Serial tracking may only change while the product has no stock anywhere (serials = stock).
            $stmt = $pdo->prepare('SELECT stock, track_serial FROM products WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $locked = $stmt->fetch() ?: throw new HttpException(404, 'Product not found.');
            if ((int) $locked['track_serial'] !== (int) $d['track_serial']) {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM product_serials WHERE product_id = ? AND status = ?');
                $stmt->execute([$id, 'in_stock']);
                if ((int) $locked['stock'] > 0 || (int) $stmt->fetchColumn() > 0) {
                    throw new HttpException(422, 'Serial tracking can only change when the product has no stock in any branch.');
                }
                // Units in transit between branches are in no branch's stock, but they are still stock.
                $stmt = $pdo->prepare(
                    "SELECT 1 FROM stock_transfer_lines tl JOIN stock_transfers t ON t.id = tl.transfer_id
                      WHERE tl.product_id = ? AND t.status = 'released' AND tl.qty_released > 0 LIMIT 1"
                );
                $stmt->execute([$id]);
                if ($stmt->fetchColumn()) {
                    throw new HttpException(422, 'Serial tracking can only change when no units of this product are in transit between branches.');
                }
                // Turning it off once serials exist (any status) would break voids/cancels of those documents.
                if ((int) $d['track_serial'] === 0) {
                    $stmt = $pdo->prepare('SELECT COUNT(*) FROM product_serials WHERE product_id = ?');
                    $stmt->execute([$id]);
                    if ((int) $stmt->fetchColumn() > 0) {
                        throw new HttpException(422, 'Serial tracking can\'t be turned off: this product already has serial numbers on record.');
                    }
                }
            }
            // unit_cost === null (no products.cost): the stored cost is kept.
            $cost = $d['unit_cost'] !== null ? number_format((float) $d['unit_cost'], 2, '.', '') : null;
            $pdo->prepare(
                'UPDATE products SET category_id = ?, code = ?, barcode = ?, name = ?, description = ?, price = ?,
                                     reorder_level = ?, image = ?, is_active = ?, brand_id = ?, model_id = ?, unit_id = ?,
                                     unit_cost = COALESCE(?, unit_cost), track_serial = ?, warranty_days = ?, specs = ?
                  WHERE id = ?'
            )->execute([
                $d['category_id'], $d['code'], $d['barcode'], $d['name'], $d['description'],
                number_format($d['price'], 2, '.', ''), $d['reorder_level'], $image, $d['is_active'],
                $d['brand_id'], $d['model_id'], $d['unit_id'], $cost, $d['track_serial'], $d['warranty_days'], $d['specs'], $id,
            ]);
            $after = ['price' => number_format($d['price'], 2, '.', ''), 'image' => $image] + $d;
            if ($cost === null) {
                unset($after['unit_cost']); // unchanged (and $before has no cost without products.cost)
            } else {
                $after['unit_cost'] = $cost;
            }
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
        if ((int) $product['times_received'] > 0 || (int) $product['serial_count'] > 0) {
            throw new HttpException(409, "{$product['name']} has receiving history, so it can't be deleted. Deactivate it instead to hide it from the POS.");
        }
        $stmt = db()->prepare('SELECT 1 FROM inventory_doc_lines WHERE product_id = ?
                               UNION ALL SELECT 1 FROM stock_transfer_lines WHERE product_id = ? LIMIT 1');
        $stmt->execute([$id, $id]);
        if ($stmt->fetchColumn()) {
            throw new HttpException(409, "{$product['name']} has stock documents (transfers, counts, write-offs or branch transfers), so it can't be deleted. Deactivate it instead to hide it from the POS.");
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
            $stmt = $pdo->prepare('SELECT code, name, track_serial FROM products WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $product = $stmt->fetch() ?: throw new HttpException(404, 'Product not found.');
            if ((int) $product['track_serial'] === 1) {
                throw new HttpException(422, 'Serial-tracked items are added through Receiving.');
            }

            $location = Branch::defaultLocation($branchId);
            $before   = Stock::balance($id, $location['id']);
            $new      = $before + $change;
            if ($new < 0) {
                throw new HttpException(422, "You can't remove " . abs($change) . " — only {$before} {$product['name']} in stock at {$location['branch_name']}.");
            }
            if ($new > self::MAX_STOCK) {
                throw new HttpException(422, 'Stock can be at most ' . number_format(self::MAX_STOCK) . '.');
            }

            if ($change > 0) {
                Costing::ensure($id, $branchId); // stock in without a cost: average unchanged
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
                    m.receiving_id, r.rr_no, u.username, s.sale_no, b.code AS branch_code, b.name AS branch_name,
                    m.inventory_doc_id, d.doc_no, d.doc_type, w.code AS warehouse_code, l.code AS location_code
               FROM stock_movements m
               LEFT JOIN users u ON u.id = m.user_id
               LEFT JOIN sales s ON s.id = m.sale_id
               LEFT JOIN receiving_reports r ON r.id = m.receiving_id
               LEFT JOIN inventory_docs d ON d.id = m.inventory_doc_id
               LEFT JOIN warehouses w ON w.id = m.warehouse_id
               LEFT JOIN storage_locations l ON l.id = m.location_id
               LEFT JOIN branches b ON b.id = m.branch_id
              WHERE m.product_id = ? AND {$scope}
              ORDER BY m.id DESC
              LIMIT ?"
        );
        $stmt->execute([$productId, ...$params, $limit]);
        return $stmt->fetchAll();
    }

    /**
     * Stock of a product per storage location in the current branch scope (locations holding stock,
     * plus inactive ones only while they still hold some). No cost.
     */
    public static function locations(int $productId): array
    {
        [$scope, $params] = Branch::scopeSql('sb.branch_id');
        $stmt = db()->prepare(
            "SELECT sb.branch_id, b.code AS branch_code, b.name AS branch_name, sb.warehouse_id, w.code AS warehouse_code,
                    w.name AS warehouse_name, sb.location_id, l.code AS location_code, l.name AS location_name, l.kind,
                    l.is_default AND w.is_default AS is_pos_location, l.is_active AND w.is_active AS is_active, sb.qty
               FROM stock_balances sb
               JOIN branches b ON b.id = sb.branch_id
               JOIN warehouses w ON w.id = sb.warehouse_id
               JOIN storage_locations l ON l.id = sb.location_id
              WHERE sb.product_id = ? AND sb.qty > 0 AND {$scope}
              ORDER BY b.is_main DESC, b.name, w.is_default DESC, w.code, l.is_default DESC, l.kind = 'stock' DESC, l.kind, l.code"
        );
        $stmt->execute([$productId, ...$params]);
        return $stmt->fetchAll();
    }
}
