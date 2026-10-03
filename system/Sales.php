<?php
/**
 * Sales: numbering, checkout (transaction + stock deduction), lookup.
 * Prices and totals are always recomputed here from the database;
 * the browser only sends product IDs and quantities.
 */
declare(strict_types=1);

final class Sales
{
    public const PAYMENT_TYPES = ['cash' => 'Cash', 'gcash' => 'GCash', 'card' => 'Card'];
    /** Statuses shown in Sales History ('held' is unused). */
    public const STATUSES  = ['completed' => 'Completed', 'cancelled' => 'Voided'];
    public const MAX_LINES = 100;
    public const MAX_QTY   = 999;

    public static function formatNumber(int $id): string
    {
        return str_pad((string) $id, 7, '0', STR_PAD_LEFT);
    }

    /** Preview of the next sale number (the real one is assigned on save). */
    public static function nextNumber(): string
    {
        $stmt = db()->prepare('SELECT COALESCE(MAX(id), 0) + 1 FROM sales');
        $stmt->execute();
        return self::formatNumber((int) $stmt->fetchColumn());
    }

    /**
     * Complete a sale at the current branch: validate stock at the branch's default location,
     * save sale + items, deduct stock — all or nothing.
     *
     * @param array<int,int> $qtyById    product_id => quantity
     * @param int|null       $paidCents  cash received (required for cash, ignored otherwise)
     * @throws HttpException 409 when stock is insufficient, 422 on invalid data / no branch chosen
     */
    public static function complete(
        int $userId,
        array $qtyById,
        ?int $customerId,
        string $paymentType,
        float $discountPercent,
        ?int $paidCents,
    ): array {
        $branchId = Branch::forWrite();
        $location = Branch::defaultLocation($branchId);

        $pdo = db();
        $pdo->beginTransaction();

        try {
            if ($customerId !== null) {
                // Active and visible at this branch (customers are shared through customer_branches).
                $stmt = $pdo->prepare(
                    'SELECT c.id FROM customers c
                      WHERE c.id = ? AND c.is_active = ?
                        AND EXISTS (SELECT 1 FROM customer_branches cb WHERE cb.customer_id = c.id AND cb.branch_id = ?)'
                );
                $stmt->execute([$customerId, 1, $branchId]);
                if (!$stmt->fetch()) {
                    throw new HttpException(422, 'The selected customer no longer exists. Please choose another.');
                }
            }

            // Lock the product rows so two tills can't sell the last item twice.
            $ids  = array_keys($qtyById);
            $in   = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare(
                "SELECT id, code, name, price, is_active FROM products WHERE id IN ({$in}) ORDER BY id FOR UPDATE"
            );
            $stmt->execute($ids);
            $products = [];
            foreach ($stmt->fetchAll() as $row) {
                $products[(int) $row['id']] = $row;
            }

            $lines    = [];
            $problems = [];
            $subtotal = 0;
            foreach ($qtyById as $id => $qty) {
                $p = $products[$id] ?? null;
                if ($p === null || (int) $p['is_active'] !== 1) {
                    $problems[] = ['product_id' => $id, 'stock' => 0, 'message' => 'An item in the cart is no longer available.'];
                    continue;
                }
                $available = Stock::balance($id, $location['id']); // locked with the product row
                if ($available < $qty) {
                    $problems[] = [
                        'product_id' => $id,
                        'stock'      => $available,
                        'message'    => $available === 0
                            ? sprintf('%s is out of stock at %s.', $p['name'], $location['branch_name'])
                            : sprintf('%s: only %d left at %s.', $p['name'], $available, $location['branch_name']),
                    ];
                    continue;
                }
                $price     = to_cents($p['price']);
                $lines[]   = ['id' => $id, 'code' => $p['code'], 'name' => $p['name'], 'price' => $price, 'qty' => $qty];
                $subtotal += $price * $qty;
            }

            if ($problems) {
                throw new HttpException(
                    409,
                    'Please update the cart. ' . implode(' ', array_column($problems, 'message')),
                    ['problems' => $problems]
                );
            }

            // VAT is applied on the discounted amount.
            $vatRate  = (float) setting('vat_rate', '12');
            $discount = (int) round($subtotal * $discountPercent / 100);
            $vat      = (int) round(($subtotal - $discount) * $vatRate / 100);
            $total    = $subtotal - $discount + $vat;

            if ($paymentType === 'cash') {
                if ($paidCents === null || $paidCents < $total) {
                    throw new HttpException(422, 'Amount received is less than the total of ' . money(from_cents($total)) . '.');
                }
            } else {
                $paidCents = $total; // GCash / card are charged the exact amount
            }
            $change = $paidCents - $total;

            $pdo->prepare(
                'INSERT INTO sales (sale_no, branch_id, user_id, customer_id, payment_type, status, subtotal, discount_percent,
                                    discount_amount, vat_rate, vat_amount, total, amount_paid, change_amount,
                                    created_at, completed_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
            )->execute([
                'TMP' . bin2hex(random_bytes(8)), // replaced with the padded ID below
                $branchId,
                $userId,
                $customerId,
                $paymentType,
                'completed',
                from_cents($subtotal),
                number_format($discountPercent, 2, '.', ''),
                from_cents($discount),
                number_format($vatRate, 2, '.', ''),
                from_cents($vat),
                from_cents($total),
                from_cents($paidCents),
                from_cents($change),
            ]);
            $saleId = (int) $pdo->lastInsertId();
            $saleNo = self::formatNumber($saleId);
            $pdo->prepare('UPDATE sales SET sale_no = ? WHERE id = ?')->execute([$saleNo, $saleId]);

            $insertItem = $pdo->prepare(
                'INSERT INTO sale_items (sale_id, product_id, product_code, product_name, unit_price, quantity, line_total)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($lines as $line) {
                $insertItem->execute([
                    $saleId, $line['id'], $line['code'], $line['name'],
                    from_cents($line['price']), $line['qty'], from_cents($line['price'] * $line['qty']),
                ]);
                // Deducts the branch location + company total and writes the ledger row (409 if short).
                Stock::move($line['id'], $location, -$line['qty'], 'sale', null, $saleId, $userId);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return [
            'id'           => $saleId,
            'sale_no'      => $saleNo,
            'total_cents'  => $total,
            'paid_cents'   => $paidCents,
            'change_cents' => $change,
            'payment_type' => $paymentType,
            'receipt_url'  => url('pages/receipt.php?id=' . $saleId),
        ];
    }

    /** Sale header + items (receipts, sale details). Null if not found or outside the branch scope. */
    public static function find(int $id): ?array
    {
        [$scope, $params] = Branch::scopeSql('s.branch_id');
        $stmt = db()->prepare(
            "SELECT s.*, u.full_name AS cashier_name, COALESCE(c.name, 'Walk-in Customer') AS customer_name,
                    v.full_name AS voided_by_name,
                    b.code AS branch_code, b.name AS branch_name, b.address AS branch_address,
                    b.contact_no AS branch_contact, b.tin_branch_code AS branch_tin
               FROM sales s
               JOIN users u ON u.id = s.user_id
               JOIN branches b ON b.id = s.branch_id
               LEFT JOIN customers c ON c.id = s.customer_id
               LEFT JOIN users v ON v.id = s.voided_by
              WHERE s.id = ? AND {$scope}"
        );
        $stmt->execute([$id, ...$params]);
        $sale = $stmt->fetch();
        if (!$sale) {
            return null;
        }

        $stmt = db()->prepare(
            'SELECT product_id, product_code, product_name, unit_price, quantity, line_total
               FROM sale_items WHERE sale_id = ? ORDER BY id'
        );
        $stmt->execute([$id]);
        $sale['items'] = $stmt->fetchAll();

        return $sale;
    }

    // ------------------------------------------------------------------
    // Sales History
    // ------------------------------------------------------------------

    /**
     * @param array{q:string, from:?string, to:?string, status:string, payment:string, cashier:?int} $f
     *        from/to are validated 'Y-m-d' dates (inclusive) or null.
     */
    public static function count(array $f): int
    {
        [$where, $params] = self::where($f);
        $stmt = db()->prepare("SELECT COUNT(*) FROM sales s LEFT JOIN customers c ON c.id = s.customer_id WHERE {$where}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public static function search(array $f, int $limit, int $offset): array
    {
        [$where, $params] = self::where($f);
        $stmt = db()->prepare(
            "SELECT s.id, s.sale_no, s.customer_id, s.payment_type, s.status, s.total, s.created_at, s.branch_id,
                    COALESCE(c.name, 'Walk-in Customer') AS customer_name, u.full_name AS cashier_name,
                    b.code AS branch_code, b.name AS branch_name,
                    (SELECT COALESCE(SUM(si.quantity), 0) FROM sale_items si WHERE si.sale_id = s.id) AS items
               FROM sales s
               JOIN users u ON u.id = s.user_id
               JOIN branches b ON b.id = s.branch_id
               LEFT JOIN customers c ON c.id = s.customer_id
              WHERE {$where}
              ORDER BY s.created_at DESC, s.id DESC
              LIMIT ? OFFSET ?"
        );
        $stmt->execute([...$params, $limit, $offset]);
        return $stmt->fetchAll();
    }

    /** Totals for the filtered period. The status filter is ignored so voids are always counted. */
    public static function summary(array $f): array
    {
        [$where, $params] = self::where(['status' => 'all'] + $f);
        $stmt = db()->prepare(
            "SELECT COALESCE(SUM(s.status = 'completed'), 0) AS sales,
                    COALESCE(SUM(CASE WHEN s.status = 'completed' THEN s.total END), 0) AS revenue,
                    COALESCE(AVG(CASE WHEN s.status = 'completed' THEN s.total END), 0) AS average,
                    COALESCE(SUM(s.status = 'cancelled'), 0) AS voided,
                    COALESCE(SUM(CASE WHEN s.status = 'cancelled' THEN s.total END), 0) AS voided_total
               FROM sales s LEFT JOIN customers c ON c.id = s.customer_id
              WHERE {$where}"
        );
        $stmt->execute($params);
        return $stmt->fetch();
    }

    private static function where(array $f): array
    {
        [$scope, $params] = Branch::scopeSql('s.branch_id');
        $where = ["s.status IN ('completed', 'cancelled')", $scope];
        if ($f['q'] !== '') {
            // Sale number, customer, or any item on the sale (name / code)
            $where[] = '(s.sale_no LIKE ? OR c.name LIKE ? OR EXISTS (
                            SELECT 1 FROM sale_items si
                             WHERE si.sale_id = s.id AND (si.product_name LIKE ? OR si.product_code LIKE ?)))';
            $like = like_pattern($f['q']);
            array_push($params, $like, $like, $like, $like);
        }
        if ($f['from'] !== null) {
            $where[]  = 's.created_at >= ?';
            $params[] = $f['from'] . ' 00:00:00';
        }
        if ($f['to'] !== null) {
            $where[]  = 's.created_at < ?'; // before the start of the next day
            $params[] = (new DateTimeImmutable($f['to']))->modify('+1 day')->format('Y-m-d 00:00:00');
        }
        if (isset(self::STATUSES[$f['status']])) {
            $where[]  = 's.status = ?';
            $params[] = $f['status'];
        }
        if (isset(self::PAYMENT_TYPES[$f['payment']])) {
            $where[]  = 's.payment_type = ?';
            $params[] = $f['payment'];
        }
        if ($f['cashier'] !== null) {
            $where[]  = 's.user_id = ?';
            $params[] = $f['cashier'];
        }
        return [implode(' AND ', $where), $params];
    }

    /** Users who rang up sales in the current branch scope (for the filter). */
    public static function cashiers(): array
    {
        [$scope, $params] = Branch::scopeSql('s.branch_id');
        $stmt = db()->prepare(
            "SELECT u.id, u.full_name, u.username FROM users u
              WHERE EXISTS (SELECT 1 FROM sales s WHERE s.user_id = u.id AND {$scope})
              ORDER BY u.full_name"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Void a completed sale (sales.cancel): mark it cancelled and put every item back in stock
     * at the sale's branch (logged as 'void' in stock_movements) — all or nothing.
     * Returns [sale_no, units returned].
     */
    public static function void(int $id, int $userId, string $reason): array
    {
        if (!Auth::can('sales.cancel')) {
            throw new HttpException(403, 'You do not have permission to void sales.');
        }
        $len = mb_strlen($reason);
        if ($len < 3 || $len > 255) {
            throw new HttpException(422, 'Enter the reason for voiding (3–255 characters).');
        }

        [$scope, $scopeParams] = Branch::scopeSql('s.branch_id');
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT s.id, s.sale_no, s.status, s.branch_id, s.total FROM sales s WHERE s.id = ? AND {$scope} FOR UPDATE");
            $stmt->execute([$id, ...$scopeParams]);
            $sale = $stmt->fetch() ?: throw new HttpException(404, 'Sale not found.');
            if ($sale['status'] === 'cancelled') {
                throw new HttpException(409, "Sale No. {$sale['sale_no']} is already voided.");
            }
            if ($sale['status'] !== 'completed') {
                throw new HttpException(409, 'Only completed sales can be voided.');
            }

            // Units to return per product (products deleted since then have product_id NULL).
            $stmt = $pdo->prepare(
                'SELECT product_id, SUM(quantity) AS qty FROM sale_items
                  WHERE sale_id = ? AND product_id IS NOT NULL GROUP BY product_id ORDER BY product_id'
            );
            $stmt->execute([$id]);
            $returns = [];
            foreach ($stmt->fetchAll() as $row) {
                $returns[(int) $row['product_id']] = (int) $row['qty'];
            }

            $units = 0;
            if ($returns) {
                $location = Branch::defaultLocation((int) $sale['branch_id']);
                $in   = implode(',', array_fill(0, count($returns), '?'));
                $stmt = $pdo->prepare("SELECT id FROM products WHERE id IN ({$in}) ORDER BY id FOR UPDATE");
                $stmt->execute(array_keys($returns));
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $productId) {
                    $qty = $returns[(int) $productId];
                    Stock::move((int) $productId, $location, $qty, 'void',
                        "Voided sale No. {$sale['sale_no']}: {$reason}", $id, $userId);
                    $units += $qty;
                }
            }

            $pdo->prepare(
                "UPDATE sales SET status = 'cancelled', voided_at = NOW(), voided_by = ?, void_reason = ? WHERE id = ?"
            )->execute([$userId, $reason, $id]);

            Audit::record('sales', 'void', 'sale', $id, $sale['sale_no'],
                ['status' => 'completed', 'total' => $sale['total']],
                ['status' => 'cancelled', 'reason' => $reason, 'units_returned' => $units],
                (int) $sale['branch_id']);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return [$sale['sale_no'], $units];
    }
}
