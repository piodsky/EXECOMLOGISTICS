<?php
/**
 * Delivery receipts (DR) of customer orders, audit module 'customer_orders'.
 *
 *   release()        customer_orders.deliver, order confirmed / partially delivered, working in its branch: quantities
 *                    0..still to deliver per line (serial lines: exactly those serials, in stock at the order's
 *                    location); numbered DR-<branch>-<year>-NNNNNN; stock leaves the location (movement 'delivery',
 *                    which may use the order's reserved units), cost = branch average snapshot, serials 'delivered'.
 *   markDelivered()  released -> delivered: who received it, the date, the acceptance / IAR reference.
 *   cancel()         released and not billed -> cancelled: the goods come back to stock ('delivery_return' at the
 *                    line cost), serials in stock again; the units are due (and reserved) on the order again.
 * Lock order: customer_orders row -> customer_order_lines -> customer_deliveries row -> document_sequences ->
 * products -> stock_balances -> product_branches -> product_serials.
 */
declare(strict_types=1);

final class CustomerDeliveries
{
    public const STATUSES = ['released' => 'Out for Delivery', 'delivered' => 'Delivered', 'cancelled' => 'Returned / Cancelled'];
    public const BADGES   = ['released' => 'badge--warning', 'delivered' => 'badge--success', 'cancelled' => 'badge--danger'];
    public const PREFIX   = 'DR';

    // ------------------------------------------------------------------
    // Listing / lookup
    // ------------------------------------------------------------------

    /** @param array{search?:string, status?:string, billed?:string} $f billed: yes | no */
    private static function where(array $f): array
    {
        [$scope, $params] = Branch::scopeSql('d.branch_id');
        $where  = [$scope];
        $status = (string) ($f['status'] ?? '');
        if (isset(self::STATUSES[$status])) {
            $where[]  = 'd.status = ?';
            $params[] = $status;
        }
        if (($f['billed'] ?? '') === 'no') {
            $where[] = "d.status <> 'cancelled' AND d.sale_id IS NULL";
        } elseif (($f['billed'] ?? '') === 'yes') {
            $where[] = 'd.sale_id IS NOT NULL';
        }
        $q = (string) ($f['search'] ?? '');
        if ($q !== '') {
            $like = like_pattern($q);
            $where[] = '(d.dr_no LIKE ? OR o.order_no LIKE ? OR o.customer_po_no LIKE ? OR o.customer_name LIKE ? OR d.received_by LIKE ? OR d.acceptance_ref LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like, $like);
        }
        return [implode(' AND ', $where), $params];
    }

    public static function count(array $f): int
    {
        CustomerOrders::requireView();
        [$where, $params] = self::where($f);
        $stmt = db()->prepare("SELECT COUNT(*) FROM customer_deliveries d JOIN customer_orders o ON o.id = d.order_id WHERE {$where}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public static function search(array $f, int $limit, int $offset): array
    {
        CustomerOrders::requireView();
        [$where, $params] = self::where($f);
        $stmt = db()->prepare(
            "SELECT d.id, d.dr_no, d.status, d.released_at, d.total_qty, d.received_by, d.received_date, d.acceptance_ref, d.sale_id,
                    o.id AS order_id, o.order_no, o.customer_po_no, o.customer_name, b.code AS branch_code, b.name AS branch_name,
                    s.sale_no, u.full_name AS released_by_name
               FROM customer_deliveries d
               JOIN customer_orders o ON o.id = d.order_id
               JOIN branches b ON b.id = d.branch_id
               JOIN users u ON u.id = d.released_by
               LEFT JOIN sales s ON s.id = d.sale_id
              WHERE {$where}
              ORDER BY d.status = 'released' DESC, d.released_at DESC, d.id DESC
              LIMIT ? OFFSET ?"
        );
        $stmt->execute([...$params, $limit, $offset]);
        return $stmt->fetchAll();
    }

    /** DR with its order, lines (product, qty, cost only with products.cost) and serial numbers. */
    public static function find(int $id): ?array
    {
        CustomerOrders::requireView();
        $stmt = db()->prepare(
            'SELECT d.*, o.order_no, o.customer_id, o.customer_name, o.customer_address, o.customer_po_no, o.customer_po_date, o.end_user,
                    o.place_of_delivery, o.status AS order_status, o.award_ref, c.phone AS customer_phone, c.tin AS customer_tin,
                    b.code AS branch_code, b.name AS branch_name, w.code AS warehouse_code, l.code AS location_code,
                    ru.full_name AS released_by_name, cu.full_name AS confirmed_by_name, xu.full_name AS cancelled_by_name,
                    s.sale_no, s.status AS sale_status
               FROM customer_deliveries d
               JOIN customer_orders o ON o.id = d.order_id
               JOIN customers c ON c.id = o.customer_id
               JOIN branches b ON b.id = d.branch_id
               JOIN warehouses w ON w.id = d.warehouse_id
               JOIN storage_locations l ON l.id = d.location_id
               JOIN users ru ON ru.id = d.released_by
               LEFT JOIN users cu ON cu.id = d.confirmed_by
               LEFT JOIN users xu ON xu.id = d.cancelled_by
               LEFT JOIN sales s ON s.id = d.sale_id
              WHERE d.id = ?'
        );
        $stmt->execute([$id]);
        $d = $stmt->fetch();
        if (!$d) {
            return null;
        }
        Branch::assertAccess((int) $d['branch_id']);
        $stmt = db()->prepare(
            'SELECT dl.*, p.code AS product_code, p.name AS product_name, un.code AS unit_code, ol.unit_price
               FROM customer_delivery_lines dl
               JOIN products p ON p.id = dl.product_id
               JOIN customer_order_lines ol ON ol.id = dl.order_line_id
               LEFT JOIN units un ON un.id = p.unit_id
              WHERE dl.delivery_id = ? ORDER BY ol.sort_order, dl.id'
        );
        $stmt->execute([$id]);
        $lines = $stmt->fetchAll();
        $sn = db()->prepare('SELECT s.serial_no FROM customer_delivery_serials x JOIN product_serials s ON s.id = x.serial_id WHERE x.line_id = ? ORDER BY s.serial_no');
        $showCost = Auth::can('products.cost');
        foreach ($lines as &$l) {
            $sn->execute([(int) $l['id']]);
            $l['serials'] = array_map('strval', $sn->fetchAll(PDO::FETCH_COLUMN));
            if (!$showCost) {
                unset($l['unit_cost']);
            }
        }
        unset($l);
        if (!$showCost) {
            unset($d['total_cost']);
        }
        $d['lines'] = $lines;
        return $d;
    }

    public static function actions(array $d): array
    {
        $ok = Branch::current() === (int) $d['branch_id'] && Auth::can('customer_orders.deliver');
        return [
            'deliver' => $ok && $d['status'] === 'released',
            'cancel'  => $ok && $d['status'] === 'released' && $d['sale_id'] === null,
        ];
    }

    // ------------------------------------------------------------------
    // Release (stock out)
    // ------------------------------------------------------------------

    /**
     * Lines still to deliver for the DR form, with the serials in stock at the order's location.
     * @return array{order: array, lines: list<array>}
     */
    public static function releasePrefill(int $orderId): array
    {
        if (!Auth::can('customer_orders.deliver')) {
            throw new HttpException(403, 'You do not have permission to release deliveries.');
        }
        $o = CustomerOrders::find($orderId) ?? throw new HttpException(404, 'Customer order not found.');
        CustomerOrders::assertWorkingIn((int) $o['branch_id']);
        if (!in_array($o['status'], CustomerOrders::OPEN, true)) {
            throw new HttpException(409, CustomerOrders::label($o) . ' is ' . strtolower(CustomerOrders::STATUSES[$o['status']]) . ': there is nothing to deliver.');
        }
        $lines = [];
        foreach ($o['lines'] as $l) {
            if ($l['to_deliver'] > 0) {
                $l['available'] = (int) $l['track_serial'] === 1 ? Serials::available((int) $l['product_id'], (int) $o['location_id']) : [];
                $lines[] = $l;
            }
        }
        return ['order' => $o, 'lines' => $lines];
    }

    /**
     * Release a DR: $qtys = order line id => qty (0 = not now), $serials = order line id => serial ids.
     * @return array{id:int, dr_no:string}
     */
    public static function release(int $orderId, array $qtys, array $serials, ?string $deliveredBy, ?string $notes, int $userId): array
    {
        if (!Auth::can('customer_orders.deliver')) {
            throw new HttpException(403, 'You do not have permission to release deliveries.');
        }
        $deliveredBy = self::text($deliveredBy, 100, 'delivered_by');
        $notes       = self::text($notes, 255, 'notes');
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $o = CustomerOrders::lock($orderId);
            CustomerOrders::assertWorkingIn((int) $o['branch_id']);
            if (!in_array($o['status'], CustomerOrders::OPEN, true)) {
                throw new HttpException(409, CustomerOrders::label($o) . ' is ' . strtolower(CustomerOrders::STATUSES[$o['status']]) . ': there is nothing to deliver.');
            }
            $lines = CustomerOrders::lockedLines($orderId);
            $take  = [];
            foreach ($lines as $l) {
                $lid = (int) $l['id'];
                $due = (int) $l['qty_ordered'] - (int) $l['qty_delivered'];
                $raw = $qtys[$lid] ?? $qtys[(string) $lid] ?? '0';
                $qty = $raw === '' ? 0 : filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => max(0, $due)]]);
                if ($qty === false) {
                    throw new HttpException(422, "{$l['product_name']}: deliver a whole number from 0 to {$due}.", ['errors' => ["qty.{$lid}" => "0 to {$due}"]]);
                }
                if ($qty === 0) {
                    continue;
                }
                $ids = array_values(array_unique(array_filter(array_map('intval', is_array($serials[$lid] ?? null) ? $serials[$lid] : []))));
                if ((int) $l['track_serial'] === 1 && count($ids) !== $qty) {
                    throw new HttpException(422, "{$l['product_name']}: choose exactly {$qty} serial number" . ($qty === 1 ? '' : 's') . ' (' . count($ids) . ' chosen).',
                        ['errors' => ["serials.{$lid}" => "Choose {$qty}."]]);
                }
                $take[$lid] = ['line' => $l, 'qty' => $qty, 'serials' => (int) $l['track_serial'] === 1 ? $ids : []];
            }
            if (!$take) {
                throw new HttpException(422, 'Enter at least one quantity to deliver.');
            }
            $branchId = (int) $o['branch_id'];
            $no = DocNumber::next($branchId, self::PREFIX);
            $location = ['id' => (int) $o['location_id'], 'warehouse_id' => (int) $o['warehouse_id'], 'branch_id' => $branchId];
            $stmt = $pdo->prepare('SELECT name FROM branches WHERE id = ?');
            $stmt->execute([$branchId]);
            $location['branch_name'] = (string) $stmt->fetchColumn();
            $pdo->prepare(
                'INSERT INTO customer_deliveries (dr_no, order_id, branch_id, warehouse_id, location_id, status, delivered_by, notes, total_qty, released_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$no, $orderId, $branchId, $location['warehouse_id'], $location['id'], 'released', $deliveredBy, $notes,
                array_sum(array_column($take, 'qty')), $userId]);
            $drId = (int) $pdo->lastInsertId();

            CustomerOrders::lockProducts(array_map(static fn (array $t): int => (int) $t['line']['product_id'], $take));
            $insLine = $pdo->prepare('INSERT INTO customer_delivery_lines (delivery_id, order_line_id, product_id, qty, unit_cost) VALUES (?, ?, ?, ?, ?)');
            $upd     = $pdo->prepare('UPDATE customer_order_lines SET qty_delivered = qty_delivered + ? WHERE id = ?');
            $costCents = 0;
            $lineIds = [];
            foreach ($take as $lid => $t) { // product id order (lockedLines)
                $pid = (int) $t['line']['product_id'];
                Stock::move($pid, $location, -$t['qty'], 'delivery', "{$no} for " . CustomerOrders::label($o) . " (PO {$o['customer_po_no']})",
                    null, $userId, null, null, null, null, $drId);
                $cost = Costing::avg($pid, $branchId);
                $insLine->execute([$drId, $lid, $pid, $t['qty'], $cost]);
                $lineIds[$lid] = (int) $pdo->lastInsertId();
                $upd->execute([$t['qty'], $lid]);
                $costCents += Costing::lineCents($t['qty'], $cost);
            }
            // Serials last: in stock at the order's location, of the line's product.
            $serialLink = $pdo->prepare('INSERT INTO customer_delivery_serials (line_id, serial_id) VALUES (?, ?)');
            $markSerial = $pdo->prepare("UPDATE product_serials SET status = 'delivered' WHERE id = ? AND status = 'in_stock'");
            foreach ($take as $lid => $t) {
                if (!$t['serials']) {
                    continue;
                }
                $ids = $t['serials'];
                sort($ids);
                $in_  = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $pdo->prepare("SELECT id, product_id, location_id, status, serial_no FROM product_serials WHERE id IN ({$in_}) ORDER BY id FOR UPDATE");
                $stmt->execute($ids);
                $rows = $stmt->fetchAll();
                if (count($rows) !== count($ids)) {
                    throw new HttpException(409, "{$t['line']['product_name']}: a chosen serial number no longer exists. Reload the page.");
                }
                foreach ($rows as $r) {
                    if ((int) $r['product_id'] !== (int) $t['line']['product_id'] || (int) $r['location_id'] !== $location['id'] || $r['status'] !== 'in_stock') {
                        throw new HttpException(409, "Serial {$r['serial_no']} is not in stock here any more. Reload the page.");
                    }
                    $markSerial->execute([(int) $r['id']]);
                    $serialLink->execute([$lineIds[$lid], (int) $r['id']]);
                }
            }
            $pdo->prepare('UPDATE customer_deliveries SET total_cost = ? WHERE id = ?')->execute([from_cents($costCents), $drId]);
            CustomerOrders::refreshStatus($orderId);
            Audit::record('customer_orders', 'deliver', 'customer_delivery', $drId, $no, null, array_filter([
                'order' => CustomerOrders::label($o), 'customer_po' => $o['customer_po_no'],
                'items' => array_map(static fn (array $t): string => $t['line']['product_code'] . ' x' . $t['qty'], array_values($take)),
                'delivered_by' => $deliveredBy,
            ], static fn ($v) => $v !== null), $branchId);
            $pdo->commit();
            return ['id' => $drId, 'dr_no' => $no];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** released -> delivered: received_by (2-150), received_date (not in the future), acceptance_ref (IAR no., optional). */
    public static function markDelivered(int $id, array $in, int $userId): string
    {
        if (!Auth::can('customer_orders.deliver')) {
            throw new HttpException(403, 'You do not have permission to record deliveries.');
        }
        $by   = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', input_string($in, 'received_by', 151)) ?? '');
        $date = input_date($in, 'received_date');
        $ref  = self::text(input_string($in, 'acceptance_ref', 61), 60, 'acceptance_ref');
        $errs = [];
        if (mb_strlen($by) < 2 || mb_strlen($by) > 150) {
            $errs['received_by'] = 'Enter who received the items (2 to 150 characters).';
        }
        if ($date === null || $date > date('Y-m-d')) {
            $errs['received_date'] = 'Enter the date it was received (not in the future).';
        }
        if ($errs) {
            throw new HttpException(422, reset($errs), ['errors' => $errs]);
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            [$d] = self::lockWithOrder($id);
            if ($d['status'] !== 'released') {
                throw new HttpException(409, "{$d['dr_no']} is " . strtolower(self::STATUSES[$d['status']]) . '.');
            }
            $pdo->prepare('UPDATE customer_deliveries SET status = ?, received_by = ?, received_date = ?, acceptance_ref = ?, confirmed_by = ?, confirmed_at = NOW() WHERE id = ?')
                ->execute(['delivered', $by, $date, $ref, $userId, $id]);
            Audit::record('customer_orders', 'delivered', 'customer_delivery', $id, $d['dr_no'], ['status' => 'released'],
                array_filter(['status' => 'delivered', 'received_by' => $by, 'received_date' => $date, 'acceptance_ref' => $ref], static fn ($v) => $v !== null),
                (int) $d['branch_id']);
            $pdo->commit();
            return (string) $d['dr_no'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Released and not billed: the goods come back to stock; the units are due on the order again. */
    public static function cancel(int $id, ?string $reason, int $userId): string
    {
        if (!Auth::can('customer_orders.deliver')) {
            throw new HttpException(403, 'You do not have permission to cancel deliveries.');
        }
        $reason = CustomerOrders::cleanReason($reason, 'reason');
        $pdo = db();
        $pdo->beginTransaction();
        try {
            [$d, $o] = self::lockWithOrder($id);
            if ($d['status'] !== 'released' || $d['sale_id'] !== null) {
                throw new HttpException(409, $d['sale_id'] !== null
                    ? "{$d['dr_no']} is billed: void the bill first."
                    : "{$d['dr_no']} is " . strtolower(self::STATUSES[$d['status']]) . ', so it can no longer be returned to stock.');
            }
            CustomerOrders::lockedLines((int) $o['id']);
            $stmt = $pdo->prepare('SELECT * FROM customer_delivery_lines WHERE delivery_id = ? ORDER BY product_id');
            $stmt->execute([$id]);
            $lines = $stmt->fetchAll();
            CustomerOrders::lockProducts(array_column($lines, 'product_id'));
            $branchId = (int) $d['branch_id'];
            $stmt = $pdo->prepare('SELECT name FROM branches WHERE id = ?');
            $stmt->execute([$branchId]);
            $location = ['id' => (int) $d['location_id'], 'warehouse_id' => (int) $d['warehouse_id'], 'branch_id' => $branchId,
                         'branch_name' => (string) $stmt->fetchColumn()];
            $upd = $pdo->prepare('UPDATE customer_order_lines SET qty_delivered = qty_delivered - ? WHERE id = ?');
            foreach ($lines as $l) {
                $pid = (int) $l['product_id'];
                Costing::applyReturn($pid, $branchId, (int) $l['qty'], $l['unit_cost'] === null ? null : (string) $l['unit_cost']);
                Stock::move($pid, $location, (int) $l['qty'], 'delivery_return', "Returned {$d['dr_no']}: {$reason}", null, $userId,
                    null, null, null, null, $id);
                $upd->execute([(int) $l['qty'], (int) $l['order_line_id']]);
            }
            $stmt = $pdo->prepare(
                'SELECT s.id FROM customer_delivery_serials x JOIN customer_delivery_lines l ON l.id = x.line_id
                   JOIN product_serials s ON s.id = x.serial_id WHERE l.delivery_id = ? ORDER BY s.id FOR UPDATE'
            );
            $stmt->execute([$id]);
            $back = $pdo->prepare("UPDATE product_serials SET status = 'in_stock', branch_id = ?, warehouse_id = ?, location_id = ? WHERE id = ?");
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $sid) {
                $back->execute([$branchId, $location['warehouse_id'], $location['id'], (int) $sid]);
            }
            $pdo->prepare('UPDATE customer_deliveries SET status = ?, cancelled_by = ?, cancelled_at = NOW(), cancel_reason = ? WHERE id = ?')
                ->execute(['cancelled', $userId, $reason, $id]);
            CustomerOrders::refreshStatus((int) $o['id']);
            Audit::record('customer_orders', 'delivery_cancel', 'customer_delivery', $id, $d['dr_no'], ['status' => 'released'],
                ['status' => 'cancelled', 'reason' => $reason, 'total_qty' => (int) $d['total_qty']], $branchId);
            $pdo->commit();
            return (string) $d['dr_no'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** Lock the order first, then the DR (lock order); the session must work in its branch. @return array{0: array, 1: array} */
    private static function lockWithOrder(int $id): array
    {
        $stmt = db()->prepare('SELECT order_id FROM customer_deliveries WHERE id = ?');
        $stmt->execute([$id]);
        $orderId = $stmt->fetchColumn();
        if ($orderId === false) {
            throw new HttpException(404, 'Delivery receipt not found.');
        }
        $o = CustomerOrders::lock((int) $orderId);
        $stmt = db()->prepare('SELECT * FROM customer_deliveries WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $d = $stmt->fetch();
        CustomerOrders::assertWorkingIn((int) $d['branch_id']);
        return [$d, $o];
    }

    private static function text(?string $v, int $max, string $field): ?string
    {
        $v = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string) $v) ?? '');
        if ($v === '') {
            return null;
        }
        if (mb_strlen($v) > $max) {
            throw new HttpException(422, "Keep it under {$max} characters.", ['errors' => [$field => "Max {$max} characters."]]);
        }
        return $v;
    }
}
