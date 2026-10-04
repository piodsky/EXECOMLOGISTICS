<?php
/**
 * Sales: numbering, checkout (transaction + stock deduction), lookup.
 * Prices and totals are always recomputed here from the database; the browser sends product IDs, quantities
 * and (Phase 9) an actual price per line, which Pricing rules check against the suggested price, the role
 * limits and the branch cost before anything is saved.
 */
declare(strict_types=1);

final class Sales
{
    /** Paid at the till (POS, job bills). */
    public const PAYMENT_TYPES = ['cash' => 'Cash', 'gcash' => 'GCash', 'card' => 'Card'];
    /** Every payment type a sale can have: + 'charge' = on account (customer order bills, collected later). */
    public const ALL_PAYMENT_TYPES = self::PAYMENT_TYPES + ['charge' => 'On account'];
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
     * @param array<int, list<int>> $serialsById product_id => product_serials ids (track_serial products only;
     *                                           their count must equal the quantity)
     * @param array<int, array{price?:?int, reason?:?string, approval?:?string}> $pricing product_id => actual price in
     *        cents (null/missing = suggested), reason (required when lower), approval token from Pricing::approve()
     * @param ?string $discountApproval approval token for a discount above the role limit
     * @throws HttpException 409 when stock / a serial is not available, 422 on invalid data / no branch chosen /
     *         approval needed (details.approval = {lines: [{product_id, name, price}], discount: ?string}),
     *         403 when the user may not change prices / give discounts
     */
    public static function complete(
        int $userId,
        array $qtyById,
        ?int $customerId,
        string $paymentType,
        float $discountPercent,
        ?int $paidCents,
        array $serialsById = [],
        array $pricing = [],
        ?string $discountApproval = null,
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
                "SELECT id, code, name, price, is_active, track_serial FROM products WHERE id IN ({$in}) ORDER BY id FOR UPDATE"
            );
            $stmt->execute($ids);
            $products = [];
            foreach ($stmt->fetchAll() as $row) {
                $products[(int) $row['id']] = $row;
            }
            // Products of a deactivated category are hidden from the POS, so they are not for sale either.
            $stmt = $pdo->prepare(
                "SELECT p.id FROM products p JOIN categories c ON c.id = p.category_id WHERE p.id IN ({$in}) AND c.is_active = 0"
            );
            $stmt->execute($ids);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $hiddenId) {
                $products[(int) $hiddenId]['is_active'] = 0;
            }

            // Serial numbers: only for track_serial products, exactly one per unit, no repeats.
            foreach ($serialsById as $id => $list) {
                if (!isset($qtyById[$id])) {
                    throw new HttpException(422, 'The cart has serial numbers for an item that is not in it.');
                }
            }
            foreach ($qtyById as $id => $qty) {
                $p = $products[$id] ?? null;
                if ($p === null || (int) $p['is_active'] !== 1) {
                    continue; // reported as unavailable below
                }
                $list = $serialsById[$id] ?? [];
                if ((int) $p['track_serial'] === 1) {
                    if (count($list) !== $qty || count(array_unique($list)) !== count($list)) {
                        throw new HttpException(422, sprintf('Choose one serial number for each unit of %s.', $p['name']));
                    }
                } elseif ($list !== []) {
                    throw new HttpException(422, sprintf('%s does not use serial numbers.', $p['name']));
                }
            }

            $lines    = [];
            $problems = [];
            $subtotal = 0;
            $costTotal = 0;
            $stockById = [];
            $sellSerials = []; // product_id => serial ids, for lines without a stock problem
            foreach ($qtyById as $id => $qty) {
                $p = $products[$id] ?? null;
                if ($p === null || (int) $p['is_active'] !== 1) {
                    $problems[] = ['product_id' => $id, 'stock' => 0, 'message' => 'An item in the cart is no longer available.'];
                    continue;
                }
                // Locked with the product row; units reserved for customer orders are not for sale (Phase 13b).
                $available = max(0, Stock::balance($id, $location['id']) - Stock::reserved($id, $location['id']));
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
                // Cost snapshot = the branch moving average (locks product_branches after stock_balances).
                $cost       = Costing::avg($id, $branchId);
                $costTotal += Costing::lineCents($qty, $cost);
                $suggested  = to_cents($p['price']);
                $price      = isset($pricing[$id]['price']) ? (int) $pricing[$id]['price'] : $suggested;
                $lines[]    = ['id' => $id, 'code' => $p['code'], 'name' => $p['name'], 'price' => $price, 'qty' => $qty,
                               'suggested' => $suggested, 'reason' => $pricing[$id]['reason'] ?? null,
                               'approval' => $pricing[$id]['approval'] ?? null, 'approved_by' => null,
                               'cost' => $cost, 'serials' => (int) $p['track_serial'] === 1 ? $serialsById[$id] : []];
                $subtotal  += $price * $qty;
                if ((int) $p['track_serial'] === 1) {
                    $sellSerials[$id] = $serialsById[$id];
                }
                $stockById[$id] = $available;
            }

            // Serials last in the lock order (products -> stock_balances -> product_branches -> product_serials).
            if ($sellSerials) {
                $checked = Serials::lockForSale($sellSerials, $location['id']);
                foreach ($checked['problems'] as $sp) {
                    $problems[] = ['product_id' => $sp['product_id'], 'serial_id' => $sp['serial_id'],
                                   'stock' => $stockById[$sp['product_id']] ?? 0, 'message' => $sp['message']];
                }
            }

            if ($problems) {
                throw new HttpException(
                    409,
                    'Please update the cart. ' . implode(' ', array_column($problems, 'message')),
                    ['problems' => $problems]
                );
            }

            // Pricing rules: price changes, limits, below cost, discount (all before anything is written).
            $discountBp = Pricing::bp($discountPercent);
            [$needLines, $needDiscount, $discountApprovedBy] = self::checkPricing($lines, $discountBp, $discountApproval, $userId);
            if ($needLines || $needDiscount !== null) {
                throw new HttpException(422, 'Admin approval needed: ' . implode(', ', array_merge(
                    array_map(static fn (array $l): string => $l['name'] . ' at ' . money($l['price']), $needLines),
                    $needDiscount !== null ? [$needDiscount . '% discount'] : []
                )) . '.', ['approval' => ['lines' => $needLines, 'discount' => $needDiscount]]);
            }

            // VAT is applied on the discounted amount.
            $vatRate  = (float) setting('vat_rate', '12');
            $discount = (int) round($subtotal * $discountPercent / 100);
            $vat      = (int) round(($subtotal - $discount) * $vatRate / 100);
            $total    = $subtotal - $discount + $vat;

            $dueDate = null;
            if ($paymentType === 'cash') {
                if ($paidCents === null || $paidCents < $total) {
                    throw new HttpException(422, 'Amount received is less than the total of ' . money(from_cents($total)) . '.');
                }
            } elseif ($paymentType === 'charge') { // on account: a credit customer within the limit (Collections::chargeTerms)
                if (!Auth::can('sales.charge')) {
                    throw new HttpException(403, 'You do not have permission to sell on account.');
                }
                $dueDate   = Collections::chargeTerms($customerId, $total, true);
                $paidCents = $total; // change 0; amount_paid is set to 0 below
            } else {
                $paidCents = $total; // GCash / card are charged the exact amount
            }
            $change = $paidCents - $total;
            if ($paymentType === 'charge') {
                $paidCents = 0;
            }

            $pdo->prepare(
                'INSERT INTO sales (sale_no, branch_id, user_id, customer_id, payment_type, status, subtotal, discount_percent,
                                    discount_amount, discount_approved_by, vat_rate, vat_amount, total, cost_total, amount_paid,
                                    change_amount, created_at, completed_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
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
                $discountApprovedBy === 0 ? null : $discountApprovedBy,
                number_format($vatRate, 2, '.', ''),
                from_cents($vat),
                from_cents($total),
                from_cents($costTotal),
                from_cents($paidCents),
                from_cents($change),
            ]);
            $saleId = (int) $pdo->lastInsertId();
            $saleNo = self::formatNumber($saleId);
            $pdo->prepare('UPDATE sales SET sale_no = ?, due_date = ? WHERE id = ?')->execute([$saleNo, $dueDate, $saleId]);

            // Approvals typed at the till are used now (one use each; the rollback frees them on any failure).
            $lines = self::useApprovals($lines, $discountBp, $discountApproval, $discountApprovedBy, $branchId, $userId, $saleId);

            $insertItem = $pdo->prepare(
                'INSERT INTO sale_items (sale_id, product_id, product_code, product_name, unit_price, suggested_price, price_reason,
                                         price_approved_by, unit_cost, quantity, line_total)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($lines as $line) {
                $changed = $line['price'] !== $line['suggested'];
                $insertItem->execute([
                    $saleId, $line['id'], $line['code'], $line['name'],
                    from_cents($line['price']), from_cents($line['suggested']), $changed ? $line['reason'] : null,
                    $line['approved_by'], $line['cost'], $line['qty'], from_cents($line['price'] * $line['qty']),
                ]);
                if ($line['serials']) {
                    Serials::markSold((int) $pdo->lastInsertId(), $line['serials']);
                }
                // Deducts the branch location + company total and writes the ledger row (409 if short).
                Stock::move($line['id'], $location, -$line['qty'], 'sale', null, $saleId, $userId);
            }

            // Audit lowered prices and discounts (who, how much, reason, approver); plain sales are their own record.
            $lowered = array_values(array_filter($lines, static fn (array $l): bool => $l['price'] < $l['suggested']));
            if ($lowered || $discount > 0) {
                $ids = array_filter([...array_column($lowered, 'approved_by'), $discountApprovedBy]);
                $names = [];
                if ($ids) {
                    $in_  = implode(',', array_fill(0, count($ids), '?'));
                    $stmt = $pdo->prepare("SELECT id, full_name FROM users WHERE id IN ({$in_})");
                    $stmt->execute(array_values($ids));
                    $names = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
                }
                Audit::record('sales', 'price_override', 'sale', $saleId, $saleNo, null, array_filter([
                    'lines' => array_map(static fn (array $l): string => "{$l['code']} x{$l['qty']}: " . from_cents($l['suggested']) . ' -> '
                        . from_cents($l['price']) . ($l['reason'] !== null ? " ({$l['reason']})" : '')
                        . ($l['approved_by'] ? ' approved by ' . ($names[$l['approved_by']] ?? $l['approved_by']) : ''), $lowered) ?: null,
                    'discount' => $discount > 0 ? number_format($discountPercent, 2, '.', '') . '% = ' . from_cents($discount)
                        . ($discountApprovedBy ? ' approved by ' . ($names[$discountApprovedBy] ?? $discountApprovedBy) : '') : null,
                ], static fn ($v) => $v !== null), $branchId);
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

    /**
     * Apply the pricing rules to the sale lines (Pricing). Sets each line's 'reason' (cleaned) and 'approved_by'
     * (null = no approval needed, the seller's id = self-approved with pos.price_override, 0 = approval token to use).
     * @return array{0: list<array{product_id:int, name:string, price:string}>, 1: ?string, 2: ?int}
     *         [lines that still need approval, discount % that still needs approval, discount approved by (as above)]
     */
    private static function checkPricing(array &$lines, int $discountBp, ?string $discountApproval, int $userId): array
    {
        $limits = Pricing::limits();
        if ($discountBp > 0 && !$limits['give_discount']) {
            throw new HttpException(403, 'You do not have permission to give discounts.');
        }
        $need = [];
        foreach ($lines as &$line) {
            if ($line['price'] < 0 || $line['price'] > Pricing::MAX_PRICE_CENTS) {
                throw new HttpException(422, "{$line['name']}: enter a valid price.");
            }
            $changed = $line['price'] !== $line['suggested'];
            if ($changed && !$limits['change_price']) {
                throw new HttpException(403, "You do not have permission to change prices ({$line['name']}).");
            }
            $reason = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string) ($line['reason'] ?? '')) ?? '');
            $line['reason'] = $reason === '' ? null : mb_substr($reason, 0, 255);
            if ($line['price'] < $line['suggested'] && ($line['reason'] === null || mb_strlen($line['reason']) < 3)) {
                throw new HttpException(422, "{$line['name']}: enter the reason for the lower price.");
            }
            $needs = Pricing::beyondLimit($line['suggested'], $line['price'], Pricing::bp($limits['price_drop']))
                  || Pricing::belowCost($line['price'], $discountBp, Costing::toUnits((string) $line['cost']));
            if (!$needs) {
                continue;
            }
            if ($limits['override']) {
                $line['approved_by'] = $userId;
            } elseif (is_string($line['approval']) && $line['approval'] !== '') {
                $line['approved_by'] = 0;
            } else {
                $need[] = ['product_id' => $line['id'], 'name' => $line['name'], 'price' => from_cents($line['price'])];
            }
        }
        unset($line);

        $needDiscount = null;
        $discountBy   = null;
        if ($discountBp > Pricing::bp($limits['discount'])) {
            if ($limits['override']) {
                $discountBy = $userId;
            } elseif (is_string($discountApproval) && $discountApproval !== '') {
                $discountBy = 0;
            } else {
                $needDiscount = number_format($discountBp / 100, 2, '.', '');
            }
        }
        return [$need, $needDiscount, $discountBy];
    }

    /** Use the approval tokens (inside the sale transaction); 422 with details.approval when one no longer fits. */
    private static function useApprovals(array $lines, int $discountBp, ?string $discountApproval, ?int $discountBy,
                                         int $branchId, int $userId, int $saleId): array
    {
        $failed = [];
        foreach ($lines as &$line) {
            if ($line['approved_by'] !== 0) {
                continue;
            }
            $by = Pricing::consume((string) $line['approval'], $branchId, $userId, $line['id'], $line['price'], null, $saleId);
            if ($by === null) {
                $failed[] = ['product_id' => $line['id'], 'name' => $line['name'], 'price' => from_cents($line['price'])];
            } else {
                $line['approved_by'] = $by;
            }
        }
        unset($line);
        $discountFailed = null;
        if ($discountBy === 0) {
            $by = Pricing::consume((string) $discountApproval, $branchId, $userId, null, null, $discountBp, $saleId);
            if ($by === null) {
                $discountFailed = number_format($discountBp / 100, 2, '.', '');
            } else {
                db()->prepare('UPDATE sales SET discount_approved_by = ? WHERE id = ?')->execute([$by, $saleId]);
            }
        }
        if ($failed || $discountFailed !== null) {
            throw new HttpException(422, 'The approval has expired or no longer matches the cart. Ask for approval again.',
                ['approval' => ['lines' => $failed, 'discount' => $discountFailed]]);
        }
        return $lines;
    }

    /** Sale header + items (receipts, sale details). Null if not found or outside the branch scope. */
    public static function find(int $id): ?array
    {
        [$scope, $params] = Branch::scopeSql('s.branch_id');
        $stmt = db()->prepare(
            "SELECT s.*, u.full_name AS cashier_name, COALESCE(c.name, 'Walk-in Customer') AS customer_name,
                    v.full_name AS voided_by_name, da.full_name AS discount_approved_by_name, jo.job_no, co.order_no, co.customer_po_no,
                    b.code AS branch_code, b.name AS branch_name, b.address AS branch_address,
                    b.contact_no AS branch_contact, b.tin_branch_code AS branch_tin
               FROM sales s
               JOIN users u ON u.id = s.user_id
               JOIN branches b ON b.id = s.branch_id
               LEFT JOIN customers c ON c.id = s.customer_id
               LEFT JOIN users v ON v.id = s.voided_by
               LEFT JOIN users da ON da.id = s.discount_approved_by
               LEFT JOIN job_orders jo ON jo.id = s.job_order_id
               LEFT JOIN customer_orders co ON co.id = s.customer_order_id
              WHERE s.id = ? AND {$scope}"
        );
        $stmt->execute([$id, ...$params]);
        $sale = $stmt->fetch();
        if (!$sale) {
            return null;
        }
        unset($sale['cost_total']); // receipts / sale view never carry cost: see costs()

        $stmt = db()->prepare(
            'SELECT si.id, si.product_id, si.line_type, si.product_code, si.product_name, si.unit_price, si.suggested_price, si.price_reason,
                    si.quantity, si.line_total, pa.full_name AS price_approved_by_name
               FROM sale_items si LEFT JOIN users pa ON pa.id = si.price_approved_by
              WHERE si.sale_id = ? ORDER BY si.id'
        );
        $stmt->execute([$id]);
        $serials = Serials::forSale($id);
        $sale['items'] = array_map(
            static fn (array $item): array => $item + ['serials' => $serials[(int) $item['id']] ?? []],
            $stmt->fetchAll()
        );

        return $sale;
    }

    /**
     * Cost of a sale (products.cost only): cost_total and the unit_cost snapshot per sale_items.id.
     * Null values = sold before costing existed. Null when the sale is not found / outside the branch scope.
     * @return array{cost_total:?string, items:array<int,?string>}|null
     */
    public static function costs(int $saleId): ?array
    {
        if (!Auth::can('products.cost')) {
            throw new HttpException(403, 'You do not have permission to see costs.');
        }
        [$scope, $params] = Branch::scopeSql('s.branch_id');
        $stmt = db()->prepare("SELECT s.cost_total FROM sales s WHERE s.id = ? AND {$scope}");
        $stmt->execute([$saleId, ...$params]);
        $sale = $stmt->fetch();
        if (!$sale) {
            return null;
        }
        $stmt = db()->prepare('SELECT id, unit_cost FROM sale_items WHERE sale_id = ? ORDER BY id');
        $stmt->execute([$saleId]);
        return [
            'cost_total' => $sale['cost_total'],
            'items'      => array_map(static fn ($c) => $c === null ? null : (string) $c, $stmt->fetchAll(PDO::FETCH_KEY_PAIR)),
        ];
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
        if (isset(self::ALL_PAYMENT_TYPES[$f['payment']])) {
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
     * at the sale's branch (logged as 'void' in stock_movements) — all or nothing. A job order bill restocks
     * nothing (the parts are installed) and re-opens the job.
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
            $stmt = $pdo->prepare("SELECT s.id, s.sale_no, s.status, s.branch_id, s.total, s.job_order_id, s.customer_order_id, s.settled_amount FROM sales s WHERE s.id = ? AND {$scope} FOR UPDATE");
            $stmt->execute([$id, ...$scopeParams]);
            $sale = $stmt->fetch() ?: throw new HttpException(404, 'Sale not found.');
            if ($sale['status'] === 'cancelled') {
                throw new HttpException(409, "Sale No. {$sale['sale_no']} is already voided.");
            }
            if ($sale['status'] !== 'completed') {
                throw new HttpException(409, 'Only completed sales can be voided.');
            }
            if (to_cents((string) $sale['settled_amount']) > 0) { // payments were collected on this on-account bill
                throw new HttpException(409, "Sale No. {$sale['sale_no']} has collections. Cancel them first (Collections), then void the bill.");
            }

            // A job order bill: the parts left stock when they were issued and stay installed, so nothing is
            // restocked; the job goes back to completed (JobBilling::onSaleVoid).
            $jobNo = $sale['job_order_id'] !== null
                ? JobBilling::onSaleVoid($id, (int) $sale['job_order_id'], $userId, $reason) : null;
            // A customer order bill: the goods left on delivery receipts and stay with the customer; the receipts
            // can be billed again (CustomerOrders::onSaleVoid).
            $orderNo = $sale['customer_order_id'] !== null
                ? CustomerOrders::onSaleVoid($id, (int) $sale['customer_order_id'], $userId, $reason) : null;
            $noRestock = $sale['job_order_id'] !== null || $sale['customer_order_id'] !== null ? 1 : null;

            // Units to return per product (products deleted since then have product_id NULL),
            // with the cost snapshots (NULL = sold before costing existed).
            $stmt = $pdo->prepare(
                'SELECT si.product_id, si.product_name, si.quantity, si.unit_cost,
                        EXISTS (SELECT 1 FROM sale_item_serials sis WHERE sis.sale_item_id = si.id) AS has_serials
                   FROM sale_items si
                  WHERE si.sale_id = ? AND si.product_id IS NOT NULL AND ? IS NULL ORDER BY si.product_id, si.id'
            );
            $stmt->execute([$id, $noRestock]);
            $returns = [];
            $costs   = []; // product_id => list of {qty, cost}, or null when any snapshot is missing
            $lines   = []; // product_id => list of {name, has_serials} (serial-tracking check)
            foreach ($stmt->fetchAll() as $row) {
                $pid = (int) $row['product_id'];
                $returns[$pid] = ($returns[$pid] ?? 0) + (int) $row['quantity'];
                $lines[$pid][] = ['name' => (string) $row['product_name'], 'has_serials' => (bool) $row['has_serials']];
                if (!array_key_exists($pid, $costs) || $costs[$pid] !== null) {
                    $costs[$pid] = $row['unit_cost'] === null
                        ? null
                        : [...($costs[$pid] ?? []), ['qty' => (int) $row['quantity'], 'cost' => (string) $row['unit_cost']]];
                }
            }

            $units   = 0;
            $serials = [];
            if ($returns) {
                $location = Branch::defaultLocation((int) $sale['branch_id']);
                $in   = implode(',', array_fill(0, count($returns), '?'));
                $stmt = $pdo->prepare("SELECT id, track_serial FROM products WHERE id IN ({$in}) ORDER BY id FOR UPDATE");
                $stmt->execute(array_keys($returns));
                $tracked = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // id => track_serial
                // Refuse before any write when serial tracking no longer matches how the line was sold.
                foreach ($tracked as $productId => $track) {
                    foreach ($lines[(int) $productId] as $line) {
                        if ((bool) (int) $track !== $line['has_serials']) {
                            throw new HttpException(409, $line['name'] . ': serial tracking changed since this sale, '
                                . "so it can't be voided automatically. Ask an administrator.");
                        }
                    }
                }
                foreach (array_keys($tracked) as $productId) {
                    $productId = (int) $productId;
                    $qty = $returns[$productId];
                    // Re-average with the sale's cost snapshot before the stock comes back.
                    Costing::applyReturn($productId, (int) $sale['branch_id'], $qty,
                        $costs[$productId] === null ? null : Costing::weighted($costs[$productId]));
                    Stock::move($productId, $location, $qty, 'void',
                        "Voided sale No. {$sale['sale_no']}: {$reason}", $id, $userId);
                    $units += $qty;
                }
                $serials = Serials::restore($id, $location); // last in the lock order
            }

            $pdo->prepare(
                "UPDATE sales SET status = 'cancelled', voided_at = NOW(), voided_by = ?, void_reason = ? WHERE id = ?"
            )->execute([$userId, $reason, $id]);

            Audit::record('sales', 'void', 'sale', $id, $sale['sale_no'],
                ['status' => 'completed', 'total' => $sale['total']],
                ['status' => 'cancelled', 'reason' => $reason, 'units_returned' => $units]
                    + ($serials ? ['serials_returned' => $serials] : [])
                    + ($sale['job_order_id'] !== null ? ['job_reopened' => $jobNo] : [])
                    + ($sale['customer_order_id'] !== null ? ['order_rebill' => $orderNo] : []),
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
