<?php
/**
 * Customer orders = PO Outgoing (the customer's purchase order: government, company, school ...), audit module
 * 'customer_orders'. Deliveries are in CustomerDeliveries.
 *
 *   draft      no number, editable / deletable (customer_orders.manage, working in the branch)
 *   pending    sent for confirmation; returned to draft with a note
 *   confirmed  customer_orders.approve, never its preparer: numbered CO-<branch>-<year>-NNNNNN. Every line is
 *              RESERVED at the branch POS location (Stock::reserved / the guard in Stock::move): the POS, job parts,
 *              transfers, write-offs ... can no longer use those units. Confirming needs enough free stock, and a
 *              price beyond the confirmer's POS limit or below cost needs pos.price_override.
 *   partial / delivered   delivery receipts released (CustomerDeliveries); delivered = everything delivered
 *   completed  everything delivered and billed
 *   closed     partially delivered and the rest will not go (reason; frees the reservation)
 *   cancelled  confirmed, nothing delivered (reason)
 * Billing (customer_orders.bill): one sale (sales.customer_order_id) for chosen delivery receipts that are not billed
 * yet, at the order prices, VAT on top, paid now (cash / GCash / card) or on account ('charge', collected later).
 * The goods already left stock on the receipts, so the sale writes NO stock movement. Voiding the bill makes the
 * receipts billable again (onSaleVoid).
 * Lock order: customer_orders row -> customer_order_lines -> customer_deliveries rows -> document_sequences ->
 * products (ORDER BY id) -> stock_balances -> product_branches -> product_serials.
 */
declare(strict_types=1);

final class CustomerOrders
{
    public const STATUSES = [
        'draft' => 'Draft', 'pending' => 'For Confirmation', 'confirmed' => 'Confirmed', 'partial' => 'Partially Delivered',
        'delivered' => 'Delivered', 'completed' => 'Completed', 'closed' => 'Closed', 'cancelled' => 'Cancelled',
    ];
    public const BADGES = [
        'draft' => '', 'pending' => 'badge--info', 'confirmed' => 'badge--warning', 'partial' => 'badge--warning',
        'delivered' => 'badge--info', 'completed' => 'badge--success', 'closed' => 'badge--success', 'cancelled' => 'badge--danger',
    ];
    /** Holding a reservation / waiting for deliveries. */
    public const OPEN = ['confirmed', 'partial'];
    public const VIEW_PERMISSIONS = ['customer_orders.manage', 'customer_orders.approve', 'customer_orders.deliver', 'customer_orders.bill'];
    public const PREFIX    = 'CO';
    public const MAX_LINES = 100;
    public const PROCUREMENT_MODES = ['Public Bidding', 'Small Value Procurement', 'Shopping', 'Direct Contracting',
                                      'Negotiated Procurement', 'Direct purchase (private)'];

    // ------------------------------------------------------------------
    // Permissions / branch
    // ------------------------------------------------------------------

    public static function requireView(): void
    {
        if (!Auth::canAny(...self::VIEW_PERMISSIONS)) {
            throw new HttpException(403, 'You do not have permission to view customer orders.');
        }
    }

    private static function requirePermission(string $permission, string $message): void
    {
        if (!Auth::can($permission)) {
            throw new HttpException(403, $message);
        }
    }

    public static function assertWorkingIn(int $branchId): void
    {
        if (Branch::current() !== $branchId) {
            $stmt = db()->prepare('SELECT name FROM branches WHERE id = ?');
            $stmt->execute([$branchId]);
            throw new HttpException(422, 'Switch to branch ' . (string) $stmt->fetchColumn() . ' first.');
        }
    }

    // ------------------------------------------------------------------
    // Listing / lookup
    // ------------------------------------------------------------------

    /** @param array{search?:string, status?:string, customer?:?int} $f status also: open, active, to_bill, overdue */
    private static function where(array $f): array
    {
        [$scope, $params] = Branch::scopeSql('o.branch_id');
        $where  = [$scope];
        $status = (string) ($f['status'] ?? '');
        if (isset(self::STATUSES[$status])) {
            $where[]  = 'o.status = ?';
            $params[] = $status;
        } elseif ($status === 'open' || $status === 'overdue') {
            $where[] = "o.status IN ('confirmed', 'partial')";
            if ($status === 'overdue') {
                $where[]  = 'o.due_date < ?';
                $params[] = date('Y-m-d');
            }
        } elseif ($status === 'active') {
            $where[] = "o.status IN ('pending', 'confirmed', 'partial', 'delivered')";
        } elseif ($status === 'to_bill') {
            $where[] = "EXISTS (SELECT 1 FROM customer_deliveries d WHERE d.order_id = o.id AND d.status <> 'cancelled' AND d.sale_id IS NULL)";
        }
        if (($f['customer'] ?? null) !== null) {
            $where[]  = 'o.customer_id = ?';
            $params[] = (int) $f['customer'];
        }
        $payment = PaymentStatus::orderWhere((string) ($f['payment'] ?? ''));
        if ($payment !== null) {
            $where[] = $payment;
        }
        $q = (string) ($f['search'] ?? '');
        if ($q !== '') {
            $like = like_pattern($q);
            $where[] = '(o.order_no LIKE ? OR o.customer_po_no LIKE ? OR o.customer_name LIKE ? OR o.end_user LIKE ?
                         OR EXISTS (SELECT 1 FROM customer_order_lines l JOIN products p ON p.id = l.product_id
                                     WHERE l.order_id = o.id AND (p.code LIKE ? OR p.name LIKE ?)))';
            array_push($params, $like, $like, $like, $like, $like, $like);
        }
        return [implode(' AND ', $where), $params];
    }

    public static function count(array $f): int
    {
        self::requireView();
        [$where, $params] = self::where($f);
        $stmt = db()->prepare("SELECT COUNT(*) FROM customer_orders o WHERE {$where}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public static function search(array $f, int $limit, int $offset): array
    {
        self::requireView();
        [$where, $params] = self::where($f);
        $stmt = db()->prepare(
            "SELECT o.id, o.order_no, o.status, o.customer_name, o.customer_po_no, o.customer_po_date, o.due_date, o.total_qty,
                    o.subtotal, o.created_at, o.branch_id, b.code AS branch_code, b.name AS branch_name, u.full_name AS created_by_name,
                    ct.name AS customer_type,
                    (SELECT COALESCE(SUM(l.qty_delivered), 0) FROM customer_order_lines l WHERE l.order_id = o.id) AS qty_delivered,
                    (SELECT COALESCE(SUM(l.qty_billed), 0) FROM customer_order_lines l WHERE l.order_id = o.id) AS qty_billed,
                    " . PaymentStatus::CO_COLUMNS . "
               FROM customer_orders o
               JOIN branches b ON b.id = o.branch_id
               JOIN users u ON u.id = o.created_by
               JOIN customers c ON c.id = o.customer_id
               LEFT JOIN customer_types ct ON ct.id = c.customer_type_id
              WHERE {$where}
              ORDER BY o.status IN ('draft', 'pending', 'confirmed', 'partial', 'delivered') DESC, o.created_at DESC, o.id DESC
              LIMIT ? OFFSET ?"
        );
        $stmt->execute([...$params, $limit, $offset]);
        return $stmt->fetchAll();
    }

    /** Work lists for the current branch: {confirm, deliver, bill, overdue}. */
    public static function workCounts(): array
    {
        $out = ['confirm' => 0, 'deliver' => 0, 'bill' => 0, 'overdue' => 0];
        if (!Branch::isConcrete() || !Auth::canAny(...self::VIEW_PERMISSIONS)) {
            return $out;
        }
        $cur  = (int) Branch::current();
        $stmt = db()->prepare(
            "SELECT SUM(status = 'pending'), SUM(status IN ('confirmed', 'partial')), SUM(status IN ('confirmed', 'partial') AND due_date < ?)
               FROM customer_orders WHERE branch_id = ? AND status IN ('pending', 'confirmed', 'partial')"
        );
        $stmt->execute([date('Y-m-d'), $cur]);
        [$c, $d, $o] = $stmt->fetch(PDO::FETCH_NUM) ?: [0, 0, 0];
        $stmt = db()->prepare("SELECT COUNT(DISTINCT order_id) FROM customer_deliveries WHERE branch_id = ? AND status <> 'cancelled' AND sale_id IS NULL");
        $stmt->execute([$cur]);
        return ['confirm' => (int) $c, 'deliver' => (int) $d, 'bill' => (int) $stmt->fetchColumn(), 'overdue' => (int) $o];
    }

    /**
     * Order with customer / branch / people, 'lines' (product, ordered / delivered / billed / to deliver, prices,
     * 'free' = units free at the branch POS location for a draft / pending order) and 'deliveries' + 'bills'.
     */
    public static function find(int $id): ?array
    {
        self::requireView();
        $stmt = db()->prepare(
            'SELECT o.*, b.code AS branch_code, b.name AS branch_name, c.phone AS customer_phone, c.tin AS customer_tin,
                    c.is_active AS customer_active, ct.name AS customer_type,
                    cu.full_name AS created_by_name, su.full_name AS submitted_by_name, au.full_name AS confirmed_by_name,
                    xu.full_name AS closed_by_name, qt.quote_no
               FROM customer_orders o
               LEFT JOIN quotations qt ON qt.id = o.quotation_id
               JOIN branches b ON b.id = o.branch_id
               JOIN customers c ON c.id = o.customer_id
               LEFT JOIN customer_types ct ON ct.id = c.customer_type_id
               JOIN users cu ON cu.id = o.created_by
               LEFT JOIN users su ON su.id = o.submitted_by
               LEFT JOIN users au ON au.id = o.confirmed_by
               LEFT JOIN users xu ON xu.id = o.closed_by
              WHERE o.id = ?'
        );
        $stmt->execute([$id]);
        $o = $stmt->fetch();
        if (!$o) {
            return null;
        }
        Branch::assertAccess((int) $o['branch_id']);

        $stmt = db()->prepare(
            'SELECT l.*, p.code AS product_code, p.name AS product_name, p.track_serial, p.is_active AS product_active, un.code AS unit_code,
                    COALESCE(sb.qty, 0) AS on_hand
               FROM customer_order_lines l
               JOIN products p ON p.id = l.product_id
               LEFT JOIN units un ON un.id = p.unit_id
               LEFT JOIN stock_balances sb ON sb.product_id = l.product_id AND sb.location_id = ?
              WHERE l.order_id = ?
              ORDER BY l.sort_order, l.id'
        );
        $stmt->execute([(int) $o['location_id'], $id]);
        $lines = $stmt->fetchAll();
        $holds = in_array($o['status'], self::OPEN, true);
        foreach ($lines as &$l) {
            $l['to_deliver'] = (int) $l['qty_ordered'] - (int) $l['qty_delivered'];
            $l['to_bill']    = (int) $l['qty_delivered'] - (int) $l['qty_billed'];
            $reserved = Stock::reserved((int) $l['product_id'], (int) $o['location_id']);
            // Free for this order: on hand minus what OTHER orders reserve.
            $l['free'] = max(0, (int) $l['on_hand'] - $reserved + ($holds ? $l['to_deliver'] : 0));
        }
        unset($l);
        $o['lines'] = $lines;

        $stmt = db()->prepare(
            'SELECT d.id, d.dr_no, d.status, d.released_at, d.received_by, d.received_date, d.acceptance_ref, d.total_qty, d.sale_id,
                    s.sale_no, s.status AS sale_status, s.payment_type
               FROM customer_deliveries d LEFT JOIN sales s ON s.id = d.sale_id
              WHERE d.order_id = ? ORDER BY d.id'
        );
        $stmt->execute([$id]);
        $o['deliveries'] = $stmt->fetchAll();

        $stmt = db()->prepare(
            'SELECT id, sale_no, status, total, payment_type, amount_paid, created_at FROM sales WHERE customer_order_id = ? ORDER BY id'
        );
        $stmt->execute([$id]);
        $o['bills'] = $stmt->fetchAll();
        return $o;
    }

    public static function label(array $o): string
    {
        return $o['order_no'] ?? ('Draft Order #' . $o['id']);
    }

    /** Which buttons the current user gets. */
    public static function actions(array $o): array
    {
        $here     = Branch::current() === (int) $o['branch_id'];
        $uid      = (int) Auth::id();
        $s        = $o['status'];
        $manage   = $here && Auth::can('customer_orders.manage');
        $approve  = $here && Auth::can('customer_orders.approve');
        $live     = array_filter($o['deliveries'] ?? [], static fn (array $d): bool => $d['status'] !== 'cancelled');
        $toBill   = array_filter($live, static fn (array $d): bool => $d['sale_id'] === null);
        return [
            'edit'    => $manage && $s === 'draft',
            'delete'  => $manage && $s === 'draft',
            'submit'  => $manage && $s === 'draft',
            'confirm' => $approve && $s === 'pending' && $uid !== (int) $o['created_by'],
            'return'  => $here && $s === 'pending' && (Auth::can('customer_orders.manage') || Auth::can('customer_orders.approve')),
            'deliver' => $here && in_array($s, self::OPEN, true) && Auth::can('customer_orders.deliver'),
            'bill'    => $here && $toBill !== [] && Auth::can('customer_orders.bill'),
            'close'   => $approve && $s === 'partial',
            'cancel'  => $approve && $s === 'confirmed' && $live === [],
        ];
    }

    /** Active customers visible at the current branch (order form), with their type. */
    public static function customers(?int $include = null): array
    {
        if (!Branch::isConcrete()) {
            return [];
        }
        $stmt = db()->prepare(
            'SELECT c.id, c.name, c.address, c.is_active, ct.name AS type_name
               FROM customers c LEFT JOIN customer_types ct ON ct.id = c.customer_type_id
              WHERE (c.is_active = 1 AND EXISTS (SELECT 1 FROM customer_branches cb WHERE cb.customer_id = c.id AND cb.branch_id = ?))
                 OR c.id = ?
              ORDER BY c.name LIMIT 1000'
        );
        $stmt->execute([(int) Branch::current(), $include ?? 0]);
        return $stmt->fetchAll();
    }

    // ------------------------------------------------------------------
    // Validation / drafts
    // ------------------------------------------------------------------

    /**
     * Header: customer_id, customer_po_no, customer_po_date, end_user, place_of_delivery, delivery_term, due_date,
     * payment_term, procurement_mode, award_ref, notes. Lines: items[i][product_id|quantity|unit_price|price_reason].
     * @return array{0: array, 1: array<string,string>}
     */
    public static function validate(array $in): array
    {
        self::requirePermission('customer_orders.manage', 'You do not have permission to enter customer orders.');
        $errors = [];
        $text = static function (string $key, int $max) use ($in, &$errors): ?string {
            $v = input_string($in, $key, $max + 1);
            if (mb_strlen($v) > $max) {
                $errors[$key] = "Keep it under {$max} characters.";
            }
            return $v !== '' ? $v : null;
        };
        $data = [
            'customer_id'       => input_int($in, 'customer_id', 1),
            'customer_po_no'    => $text('customer_po_no', 60),
            'customer_po_date'  => input_date($in, 'customer_po_date'),
            'end_user'          => $text('end_user', 150),
            'place_of_delivery' => $text('place_of_delivery', 255),
            'delivery_term'     => $text('delivery_term', 100),
            'due_date'          => input_date($in, 'due_date'),
            'payment_term'      => $text('payment_term', 100),
            'procurement_mode'  => $text('procurement_mode', 60),
            'award_ref'         => $text('award_ref', 100),
            'notes'             => $text('notes', 500),
            'quotation_id'      => input_int($in, 'quotation_id', 1),
            'items'             => [],
        ];
        $customerIds = array_map('intval', array_column(self::customers(), 'id'));
        if ($data['customer_id'] === null || !in_array($data['customer_id'], $customerIds, true)) {
            $errors['customer_id'] = 'Choose an active customer of this branch.';
        }
        if ($data['customer_po_no'] === null) {
            $errors['customer_po_no'] ??= "Enter the customer's PO number.";
        }
        foreach (['customer_po_date' => 'PO date', 'due_date' => 'delivery deadline'] as $k => $label) {
            if (is_string($in[$k] ?? null) && trim($in[$k]) !== '' && $data[$k] === null) {
                $errors[$k] = "Enter a valid {$label}.";
            }
        }

        $lines = [];
        foreach (is_array($in['items'] ?? null) ? array_values($in['items']) : [] as $i => $line) {
            if (!is_array($line)) {
                continue;
            }
            $blank = static fn (string $k): bool => !isset($line[$k]) || (is_string($line[$k]) && trim($line[$k]) === '');
            if ($blank('product_id') && $blank('quantity') && $blank('unit_price') && $blank('price_reason')) {
                continue; // empty template row
            }
            $lines[$i] = $line;
        }
        if (!$lines) {
            $errors['items'] = 'Add at least one item.';
        } elseif (count($lines) > self::MAX_LINES) {
            $errors['items'] = 'An order can have at most ' . self::MAX_LINES . ' items.';
        }
        $ids = [];
        foreach ($lines as $line) {
            $pid = input_int($line, 'product_id', 1);
            if ($pid !== null) {
                $ids[$pid] = true;
            }
        }
        $products = [];
        if ($ids) {
            $in_  = implode(',', array_fill(0, count($ids), '?'));
            // Suggested = the branch price of the working branch (drafts are saved in it), else the company price.
            $priceSql = Branch::isConcrete() ? BranchPrices::sql('p', (int) Branch::current()) : 'p.price';
            $stmt = db()->prepare("SELECT p.id, p.name, {$priceSql} AS price, p.is_active FROM products p WHERE p.id IN ({$in_})");
            $stmt->execute(array_keys($ids));
            $products = array_column($stmt->fetchAll(), null, 'id');
        }
        $seen = [];
        foreach ($lines as $i => $line) {
            $key = "items.{$i}.";
            $pid = input_int($line, 'product_id', 1);
            $qty = input_int($line, 'quantity', 1, Products::MAX_STOCK);
            $p   = $pid !== null ? ($products[$pid] ?? null) : null;
            $item = ['product_id' => $pid, 'quantity' => $qty, 'price' => null, 'suggested' => null, 'reason' => null];
            if ($p === null || (int) $p['is_active'] !== 1) {
                $errors[$key . 'product_id'] = 'Choose an active product.';
            } elseif (isset($seen[$pid])) {
                $errors[$key . 'product_id'] = "{$p['name']} is already on this order. Use one line per product.";
            }
            if ($pid !== null) {
                $seen[$pid] = true;
            }
            if ($qty === null) {
                $errors[$key . 'quantity'] = 'Enter a whole number from 1 to ' . number_format(Products::MAX_STOCK) . '.';
            }
            $raw   = is_string($line['unit_price'] ?? null) ? str_replace(',', '', trim($line['unit_price'])) : '';
            $price = $raw === '' ? null : input_decimal(['v' => $raw], 'v', 0, 9999999.99, 2);
            if ($price === null) {
                $errors[$key . 'unit_price'] = 'Enter the agreed unit price (0.00 to 9,999,999.99).';
            } else {
                $item['price'] = to_cents((string) $price);
            }
            $reason = input_string($line, 'price_reason', 256);
            $item['reason'] = $reason !== '' ? $reason : null;
            if ($p !== null) {
                $item['suggested'] = to_cents($p['price']);
                if ($item['price'] !== null && $item['price'] < $item['suggested']) {
                    if ($item['reason'] === null || mb_strlen($item['reason']) < 3 || mb_strlen($item['reason']) > 255) {
                        $errors[$key . 'price_reason'] = 'Below the suggested price ' . money($p['price']) . ': enter the reason (e.g. the awarded bid price).';
                    }
                } else {
                    $item['reason'] = null;
                }
            }
            $data['items'][] = $item;
        }
        return [$data, $errors];
    }

    /** Create (id null) or replace a draft at the current branch. @return int id */
    public static function saveDraft(?int $id, array $d, int $userId): int
    {
        self::requirePermission('customer_orders.manage', 'You do not have permission to enter customer orders.');
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT name, address FROM customers WHERE id = ?');
            $stmt->execute([(int) $d['customer_id']]);
            $customer = $stmt->fetch() ?: throw new HttpException(422, 'Choose an active customer of this branch.');
            $header = [$d['customer_id'], mb_substr((string) $customer['name'], 0, 100), $customer['address'], $d['customer_po_no'],
                $d['customer_po_date'], $d['end_user'], $d['place_of_delivery'], $d['delivery_term'], $d['due_date'], $d['payment_term'],
                $d['procurement_mode'], $d['award_ref'], $d['notes']];
            if ($id === null) {
                $branchId = Branch::forWrite();
                $location = Branch::defaultLocation($branchId);
                $pdo->prepare(
                    'INSERT INTO customer_orders (customer_id, customer_name, customer_address, customer_po_no, customer_po_date, end_user,
                                                  place_of_delivery, delivery_term, due_date, payment_term, procurement_mode, award_ref, notes,
                                                  branch_id, warehouse_id, location_id, status, created_by, quotation_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([...$header, $branchId, $location['warehouse_id'], $location['id'], 'draft', $userId, $d['quotation_id'] ?? null]);
                $id = (int) $pdo->lastInsertId();
                if (($d['quotation_id'] ?? null) !== null) { // made from a sent quotation: it is won
                    Quotations::markWon((int) $d['quotation_id'], $id, $branchId, (int) $d['customer_id']);
                }
                $before = null;
            } else {
                $o = self::lock($id);
                self::assertWorkingIn((int) $o['branch_id']);
                if ($o['status'] !== 'draft') {
                    throw new HttpException(409, self::label($o) . ' is ' . strtolower(self::STATUSES[$o['status']]) . ' and can no longer be edited.');
                }
                $branchId = (int) $o['branch_id'];
                $before   = self::auditValues($o, self::auditLines($id));
                $pdo->prepare(
                    'UPDATE customer_orders SET customer_id = ?, customer_name = ?, customer_address = ?, customer_po_no = ?, customer_po_date = ?,
                            end_user = ?, place_of_delivery = ?, delivery_term = ?, due_date = ?, payment_term = ?, procurement_mode = ?,
                            award_ref = ?, notes = ? WHERE id = ?'
                )->execute([...$header, $id]);
                $pdo->prepare('DELETE FROM customer_order_lines WHERE order_id = ?')->execute([$id]);
            }
            $ins = $pdo->prepare(
                'INSERT INTO customer_order_lines (order_id, product_id, qty_ordered, unit_price, suggested_price, price_reason, line_total, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $qty = 0;
            $sub = 0;
            foreach (array_values($d['items']) as $n => $item) {
                $line = $item['price'] * (int) $item['quantity'];
                $ins->execute([$id, $item['product_id'], $item['quantity'], from_cents($item['price']), from_cents((int) $item['suggested']),
                    $item['reason'], from_cents($line), $n + 1]);
                $qty += (int) $item['quantity'];
                $sub += $line;
            }
            $pdo->prepare('UPDATE customer_orders SET total_qty = ?, subtotal = ? WHERE id = ?')->execute([$qty, from_cents($sub), $id]);

            $after = self::auditValues(['customer_id' => $d['customer_id'], 'customer_po_no' => $d['customer_po_no'],
                'due_date' => $d['due_date'], 'total_qty' => $qty, 'subtotal' => from_cents($sub)], self::auditLines($id));
            if ($before === null) {
                Audit::record('customer_orders', 'create', 'customer_order', $id, 'Draft Order #' . $id, null, $after, $branchId);
            } else {
                [$old, $new] = Audit::diff($before, $after);
                if ($new) {
                    Audit::record('customer_orders', 'update', 'customer_order', $id, 'Draft Order #' . $id, $old, $new, $branchId);
                }
            }
            $pdo->commit();
            return $id;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function deleteDraft(int $id): void
    {
        self::requirePermission('customer_orders.manage', 'You do not have permission to enter customer orders.');
        self::transition($id, static function (array $o) use ($id): string {
            if ($o['status'] !== 'draft') {
                throw new HttpException(409, 'Only drafts can be deleted. ' . self::label($o) . ' is ' . strtolower(self::STATUSES[$o['status']]) . '.');
            }
            $before = self::auditValues($o, self::auditLines($id));
            db()->prepare('DELETE FROM customer_order_lines WHERE order_id = ?')->execute([$id]);
            db()->prepare('DELETE FROM customer_orders WHERE id = ?')->execute([$id]);
            if ($o['quotation_id'] !== null) {
                Quotations::reopen((int) $o['quotation_id']);
            }
            Audit::record('customer_orders', 'delete', 'customer_order', $id, 'Draft Order #' . $id, $before, null, (int) $o['branch_id']);
            return '';
        });
    }

    // ------------------------------------------------------------------
    // Submit / confirm / return / close / cancel
    // ------------------------------------------------------------------

    public static function submit(int $id, int $userId): string
    {
        self::requirePermission('customer_orders.manage', 'You do not have permission to enter customer orders.');
        return self::transition($id, static function (array $o) use ($id, $userId): string {
            if ($o['status'] !== 'draft') {
                throw new HttpException(409, self::label($o) . ' is ' . strtolower(self::STATUSES[$o['status']]) . ', so it can no longer be sent for confirmation.');
            }
            $stmt = db()->prepare('SELECT COUNT(*), SUM(p.is_active = 0) FROM customer_order_lines l JOIN products p ON p.id = l.product_id WHERE l.order_id = ?');
            $stmt->execute([$id]);
            [$lines, $inactive] = $stmt->fetch(PDO::FETCH_NUM);
            if ((int) $lines === 0) {
                throw new HttpException(422, 'Add at least one item first.');
            }
            if ((int) $inactive > 0) {
                throw new HttpException(422, 'An item on this order is no longer active. Edit the draft first.');
            }
            db()->prepare('UPDATE customer_orders SET status = ?, submitted_by = ?, submitted_at = NOW(), return_note = NULL WHERE id = ?')
                ->execute(['pending', $userId, $id]);
            Audit::record('customer_orders', 'submit', 'customer_order', $id, self::label($o), ['status' => 'draft'],
                ['status' => 'pending', 'customer_po_no' => $o['customer_po_no']], (int) $o['branch_id']);
            return self::label($o);
        });
    }

    /**
     * Confirm (never by its preparer): enough free stock for every line (then it is reserved), prices within the
     * confirmer's POS limit and not below cost unless they have pos.price_override. Numbers it. @return string order no
     */
    public static function confirm(int $id, int $userId): string
    {
        self::requirePermission('customer_orders.approve', 'You do not have permission to confirm customer orders.');
        return self::transition($id, static function (array $o) use ($id, $userId): string {
            if ($o['status'] !== 'pending') {
                throw new HttpException(409, self::label($o) . ' is ' . strtolower(self::STATUSES[$o['status']]) . ', so it can no longer be confirmed.');
            }
            if ($userId === (int) $o['created_by']) {
                throw new HttpException(403, "You can't confirm an order you prepared.");
            }
            $stmt = db()->prepare('SELECT is_active FROM customers WHERE id = ?');
            $stmt->execute([(int) $o['customer_id']]);
            if ((int) $stmt->fetchColumn() !== 1) {
                throw new HttpException(422, 'The customer is no longer active. Return the order to draft and choose the customer again.');
            }
            $lines = self::lockedLines($id);
            $products = self::lockProducts(array_column($lines, 'product_id')); // reservation changes take the product locks
            $lim  = Pricing::limits();
            $loc  = (int) $o['location_id'];
            $problems = [];
            foreach ($lines as $l) {
                $pid = (int) $l['product_id'];
                $p   = $products[$pid] ?? null;
                if ($p === null || (int) $p['is_active'] !== 1) {
                    $problems[] = "{$l['product_name']} is no longer active.";
                    continue;
                }
                $free = Stock::balance($pid, $loc) - Stock::reserved($pid, $loc);
                if ($free < (int) $l['qty_ordered']) {
                    $problems[] = "{$l['product_name']}: only " . max(0, $free) . " free at the branch (needs {$l['qty_ordered']}).";
                }
                $price = to_cents($l['unit_price']);
                $sugg  = to_cents($l['suggested_price']);
                if (!$lim['override']) {
                    if (Pricing::beyondLimit($sugg, $price, Pricing::bp($lim['price_drop']))) {
                        $problems[] = "{$l['product_name']}: the price is more than your limit below the suggested price; a branch admin who can approve prices must confirm it.";
                    } elseif (Pricing::belowCost($price, 0, Costing::toUnits(Costing::avg($pid, (int) $o['branch_id'])))) {
                        $problems[] = "{$l['product_name']}: the price needs approval; a branch admin who can approve prices must confirm it.";
                    }
                }
            }
            if ($problems) {
                throw new HttpException(409, implode(' ', $problems), ['problems' => $problems]);
            }
            $no = DocNumber::next((int) $o['branch_id'], self::PREFIX);
            db()->prepare('UPDATE customer_orders SET order_no = ?, status = ?, confirmed_by = ?, confirmed_at = NOW() WHERE id = ?')
                ->execute([$no, 'confirmed', $userId, $id]);
            Audit::record('customer_orders', 'confirm', 'customer_order', $id, $no, ['status' => 'pending'],
                ['status' => 'confirmed', 'order_no' => $no, 'reserved' => self::auditLines($id)], (int) $o['branch_id']);
            return $no;
        });
    }

    public static function returnToDraft(int $id, ?string $note, int $userId): string
    {
        if (!Auth::canAny('customer_orders.manage', 'customer_orders.approve')) {
            throw new HttpException(403, 'You do not have permission to return customer orders.');
        }
        $note = self::cleanReason($note, 'note');
        return self::transition($id, static function (array $o) use ($id, $note): string {
            if ($o['status'] !== 'pending') {
                throw new HttpException(409, self::label($o) . ' is ' . strtolower(self::STATUSES[$o['status']]) . ', so it can no longer be returned.');
            }
            db()->prepare('UPDATE customer_orders SET status = ?, return_note = ? WHERE id = ?')->execute(['draft', $note, $id]);
            Audit::record('customer_orders', 'return', 'customer_order', $id, self::label($o), ['status' => 'pending'],
                ['status' => 'draft', 'note' => $note], (int) $o['branch_id']);
            return self::label($o);
        });
    }

    /** Partially delivered: the rest will not go; the reservation ends. */
    public static function close(int $id, ?string $reason, int $userId): string
    {
        self::requirePermission('customer_orders.approve', 'You do not have permission to close customer orders.');
        $reason = self::cleanReason($reason, 'reason');
        return self::transition($id, static function (array $o) use ($id, $reason, $userId): string {
            if ($o['status'] !== 'partial') {
                throw new HttpException(409, $o['status'] === 'confirmed'
                    ? 'Nothing was delivered on ' . self::label($o) . ' yet: cancel it instead.'
                    : self::label($o) . ' is ' . strtolower(self::STATUSES[$o['status']]) . ', so it can no longer be closed.');
            }
            self::lockProducts(array_column(self::lockedLines($id), 'product_id'));
            db()->prepare('UPDATE customer_orders SET status = ?, closed_by = ?, closed_at = NOW(), close_reason = ? WHERE id = ?')
                ->execute(['closed', $userId, $reason, $id]);
            Audit::record('customer_orders', 'close', 'customer_order', $id, self::label($o), ['status' => 'partial'],
                ['status' => 'closed', 'reason' => $reason], (int) $o['branch_id']);
            return self::label($o);
        });
    }

    /** Confirmed with no delivery receipt: the order is off and its stock is free again. */
    public static function cancel(int $id, ?string $reason, int $userId): string
    {
        self::requirePermission('customer_orders.approve', 'You do not have permission to cancel customer orders.');
        $reason = self::cleanReason($reason, 'reason');
        return self::transition($id, static function (array $o) use ($id, $reason, $userId): string {
            if ($o['status'] !== 'confirmed') {
                throw new HttpException(409, $o['status'] === 'partial'
                    ? 'Items were already delivered on ' . self::label($o) . ': close it instead.'
                    : self::label($o) . ' is ' . strtolower(self::STATUSES[$o['status']]) . ', so it can no longer be cancelled.');
            }
            $stmt = db()->prepare("SELECT COUNT(*) FROM customer_deliveries WHERE order_id = ? AND status <> 'cancelled'");
            $stmt->execute([$id]);
            if ((int) $stmt->fetchColumn() > 0) {
                throw new HttpException(409, self::label($o) . ' has delivery receipts: close it instead.');
            }
            self::lockProducts(array_column(self::lockedLines($id), 'product_id'));
            db()->prepare('UPDATE customer_orders SET status = ?, closed_by = ?, closed_at = NOW(), close_reason = ? WHERE id = ?')
                ->execute(['cancelled', $userId, $reason, $id]);
            Audit::record('customer_orders', 'cancel', 'customer_order', $id, self::label($o), ['status' => 'confirmed'],
                ['status' => 'cancelled', 'reason' => $reason], (int) $o['branch_id']);
            return self::label($o);
        });
    }

    /**
     * Status after deliveries / bills changed (caller holds the order row lock): confirmed -> partial -> delivered ->
     * completed and back. Closed / cancelled stay.
     */
    public static function refreshStatus(int $id): void
    {
        $stmt = db()->prepare('SELECT order_no, status, branch_id FROM customer_orders WHERE id = ?');
        $stmt->execute([$id]);
        $o = $stmt->fetch();
        if (!$o || !in_array($o['status'], ['confirmed', 'partial', 'delivered', 'completed'], true)) {
            return;
        }
        $stmt = db()->prepare('SELECT SUM(qty_ordered), SUM(qty_delivered), SUM(qty_billed) FROM customer_order_lines WHERE order_id = ?');
        $stmt->execute([$id]);
        [$ordered, $delivered, $billed] = array_map('intval', $stmt->fetch(PDO::FETCH_NUM));
        $new = $delivered === 0 ? 'confirmed' : ($delivered < $ordered ? 'partial' : ($billed < $delivered ? 'delivered' : 'completed'));
        if ($new !== $o['status']) {
            db()->prepare('UPDATE customer_orders SET status = ? WHERE id = ?')->execute([$new, $id]);
            Audit::record('customer_orders', $new, 'customer_order', $id, (string) $o['order_no'], ['status' => $o['status']],
                ['status' => $new, 'delivered' => "{$delivered} of {$ordered}", 'billed' => $billed], (int) $o['branch_id']);
        }
    }

    // ------------------------------------------------------------------
    // Billing
    // ------------------------------------------------------------------

    /**
     * Bill chosen delivery receipts of an order (not billed yet, not cancelled): one sale at the order prices,
     * VAT on top, no stock movement. payment_type cash / gcash / card (paid now) or charge (on account).
     * @param list<int> $deliveryIds @return array{sale_id:int, sale_no:string, total_cents:int, change_cents:int}
     */
    public static function bill(int $id, array $deliveryIds, array $in, int $userId): array
    {
        self::requirePermission('customer_orders.bill', 'You do not have permission to bill customer orders.');
        $deliveryIds = array_values(array_unique(array_filter(array_map('intval', $deliveryIds), static fn (int $v): bool => $v > 0)));
        if (!$deliveryIds) {
            throw new HttpException(422, 'Tick the delivery receipts to bill.', ['errors' => ['deliveries' => 'Tick at least one.']]);
        }
        $payment = input_string($in, 'payment_type', 10);
        if (!isset(Sales::ALL_PAYMENT_TYPES[$payment])) {
            throw new HttpException(422, 'Choose the payment type.', ['errors' => ['payment_type' => 'Choose the payment type.']]);
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $o = self::lock($id);
            self::assertWorkingIn((int) $o['branch_id']);
            if (in_array($o['status'], ['draft', 'pending', 'cancelled'], true)) {
                throw new HttpException(409, self::label($o) . ' has nothing to bill.');
            }
            $lines = array_column(self::lockedLines($id), null, 'id');
            sort($deliveryIds);
            $in_  = implode(',', array_fill(0, count($deliveryIds), '?'));
            $stmt = $pdo->prepare("SELECT id, dr_no, order_id, status, sale_id FROM customer_deliveries WHERE id IN ({$in_}) ORDER BY id FOR UPDATE");
            $stmt->execute($deliveryIds);
            $drs = $stmt->fetchAll();
            if (count($drs) !== count($deliveryIds)) {
                throw new HttpException(404, 'Delivery receipt not found.');
            }
            foreach ($drs as $dr) {
                if ((int) $dr['order_id'] !== $id || $dr['status'] === 'cancelled' || $dr['sale_id'] !== null) {
                    throw new HttpException(409, "{$dr['dr_no']} cannot be billed (already billed or cancelled). Reload the page.");
                }
            }
            // Delivered quantities, costs and serials per order line.
            $stmt = $pdo->prepare(
                "SELECT dl.id, dl.order_line_id, dl.qty, dl.unit_cost FROM customer_delivery_lines dl WHERE dl.delivery_id IN ({$in_}) ORDER BY dl.order_line_id, dl.id"
            );
            $stmt->execute($deliveryIds);
            $byLine = [];
            foreach ($stmt->fetchAll() as $dl) {
                $lid = (int) $dl['order_line_id'];
                $byLine[$lid] ??= ['qty' => 0, 'costs' => [], 'dl' => [], 'costKnown' => true];
                $byLine[$lid]['qty'] += (int) $dl['qty'];
                $byLine[$lid]['dl'][] = (int) $dl['id'];
                if ($dl['unit_cost'] === null) {
                    $byLine[$lid]['costKnown'] = false;
                } else {
                    $byLine[$lid]['costs'][] = ['qty' => (int) $dl['qty'], 'cost' => (string) $dl['unit_cost']];
                }
            }
            $subtotal = 0;
            $costCents = 0;
            $costKnown = true;
            $saleLines = [];
            foreach ($byLine as $lid => $b) {
                $l = $lines[$lid] ?? throw new HttpException(409, 'A delivery line no longer matches the order.');
                if ((int) $l['qty_billed'] + $b['qty'] > (int) $l['qty_delivered']) {
                    throw new HttpException(409, "{$l['product_name']}: more than what was delivered would be billed.");
                }
                $price = to_cents($l['unit_price']);
                $cost  = $b['costKnown'] && $b['costs'] ? Costing::weighted($b['costs']) : null;
                if ($cost === null) {
                    $costKnown = false;
                } else {
                    $costCents += Costing::lineCents($b['qty'], $cost);
                }
                $subtotal   += $price * $b['qty'];
                $saleLines[] = ['line' => $l, 'qty' => $b['qty'], 'price' => $price, 'cost' => $cost, 'dl' => $b['dl']];
            }
            $vatRate = (float) setting('vat_rate', '12');
            $vat     = (int) round($subtotal * $vatRate / 100);
            $total   = $subtotal + $vat;
            if ($payment === 'cash') {
                $raw  = is_string($in['amount_paid'] ?? null) ? str_replace(',', '', $in['amount_paid']) : '';
                $paid = input_decimal(['v' => $raw], 'v', 0, 99999999.99, 2);
                if ($paid === null || to_cents((string) $paid) < $total) {
                    throw new HttpException(422, 'Amount received is less than the total of ' . money(from_cents($total)) . '.',
                        ['errors' => ['amount_paid' => 'At least ' . money(from_cents($total)) . '.']]);
                }
                $paidCents = to_cents((string) $paid);
            } else {
                $paidCents = $payment === 'charge' ? 0 : $total;
            }
            $dueDate = $payment === 'charge' ? Collections::chargeTerms((int) $o['customer_id'], $total, false) : null; // orders: terms or 30 days
            $change = $payment === 'cash' ? $paidCents - $total : 0;

            $pdo->prepare(
                'INSERT INTO sales (sale_no, branch_id, user_id, customer_id, customer_order_id, payment_type, status, subtotal, discount_percent,
                                    discount_amount, vat_rate, vat_amount, total, cost_total, amount_paid, change_amount, created_at, completed_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
            )->execute([
                'TMP' . bin2hex(random_bytes(8)), (int) $o['branch_id'], $userId, (int) $o['customer_id'], $id, $payment, 'completed',
                from_cents($subtotal), '0.00', '0.00', number_format($vatRate, 2, '.', ''), from_cents($vat), from_cents($total),
                $costKnown ? from_cents($costCents) : null, from_cents($paidCents), from_cents($change),
            ]);
            $saleId = (int) $pdo->lastInsertId();
            $saleNo = Sales::formatNumber($saleId);
            $pdo->prepare('UPDATE sales SET sale_no = ?, due_date = ? WHERE id = ?')->execute([$saleNo, $dueDate, $saleId]);

            $item = $pdo->prepare(
                'INSERT INTO sale_items (sale_id, product_id, line_type, product_code, product_name, unit_price, suggested_price, price_reason,
                                         unit_cost, quantity, line_total)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $serialsOf = $pdo->prepare('SELECT serial_id FROM customer_delivery_serials WHERE line_id = ? ORDER BY serial_id');
            $link = $pdo->prepare('INSERT INTO sale_item_serials (sale_item_id, serial_id) VALUES (?, ?)');
            $billed = $pdo->prepare('UPDATE customer_order_lines SET qty_billed = qty_billed + ? WHERE id = ?');
            foreach ($saleLines as $sl) {
                $l = $sl['line'];
                $item->execute([$saleId, (int) $l['product_id'], 'item', mb_substr((string) $l['product_code'], 0, 20), mb_substr((string) $l['product_name'], 0, 100),
                    from_cents($sl['price']), $l['suggested_price'], $l['price_reason'], $sl['cost'], $sl['qty'], from_cents($sl['price'] * $sl['qty'])]);
                $itemId = (int) $pdo->lastInsertId();
                foreach ($sl['dl'] as $dlId) {
                    $serialsOf->execute([$dlId]);
                    foreach ($serialsOf->fetchAll(PDO::FETCH_COLUMN) as $sid) {
                        $link->execute([$itemId, (int) $sid]); // S/N on the receipt; the serial stays 'delivered'
                    }
                }
                $billed->execute([$sl['qty'], (int) $l['id']]);
            }
            $pdo->prepare("UPDATE customer_deliveries SET sale_id = ? WHERE id IN ({$in_})")->execute([$saleId, ...$deliveryIds]);
            self::refreshStatus($id);
            Audit::record('customer_orders', 'bill', 'customer_order', $id, self::label($o), null,
                ['sale_no' => $saleNo, 'deliveries' => array_column($drs, 'dr_no'), 'total' => from_cents($total),
                 'payment' => Sales::ALL_PAYMENT_TYPES[$payment]], (int) $o['branch_id']);
            $pdo->commit();
            return ['sale_id' => $saleId, 'sale_no' => $saleNo, 'total_cents' => $total, 'change_cents' => $change];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Inside Sales::void() (its transaction, sale row locked): the bill's delivery receipts become billable again.
     * Nothing is restocked (the goods are with the customer). @return ?string order no
     */
    public static function onSaleVoid(int $saleId, int $orderId, int $userId, string $reason): ?string
    {
        $o = self::lock($orderId);
        $stmt = db()->prepare('SELECT id, dr_no FROM customer_deliveries WHERE sale_id = ? ORDER BY id FOR UPDATE');
        $stmt->execute([$saleId]);
        $drs = $stmt->fetchAll();
        if (!$drs) {
            return (string) $o['order_no'];
        }
        $ids  = array_map('intval', array_column($drs, 'id'));
        $in_  = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare("SELECT order_line_id, SUM(qty) FROM customer_delivery_lines WHERE delivery_id IN ({$in_}) GROUP BY order_line_id");
        $stmt->execute($ids);
        $upd = db()->prepare('UPDATE customer_order_lines SET qty_billed = qty_billed - ? WHERE id = ? AND order_id = ?');
        foreach ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $lineId => $qty) {
            $upd->execute([(int) $qty, (int) $lineId, $orderId]);
        }
        db()->prepare("UPDATE customer_deliveries SET sale_id = NULL WHERE id IN ({$in_})")->execute($ids);
        self::refreshStatus($orderId);
        Audit::record('customer_orders', 'bill_voided', 'customer_order', $orderId, (string) $o['order_no'], null,
            ['sale_id' => $saleId, 'deliveries' => array_column($drs, 'dr_no'), 'reason' => $reason], (int) $o['branch_id']);
        return (string) $o['order_no'];
    }

    // ------------------------------------------------------------------
    // Helpers (also used by CustomerDeliveries)
    // ------------------------------------------------------------------

    /** Lock the order row (first in the lock order); 404 when missing or out of scope. */
    public static function lock(int $id): array
    {
        $stmt = db()->prepare('SELECT * FROM customer_orders WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $o = $stmt->fetch() ?: throw new HttpException(404, 'Customer order not found.');
        Branch::assertAccess((int) $o['branch_id']);
        return $o;
    }

    /** Lines with product info, locked, keyed by position (order of product id). */
    public static function lockedLines(int $id): array
    {
        $stmt = db()->prepare(
            'SELECT l.*, p.code AS product_code, p.name AS product_name, p.track_serial
               FROM customer_order_lines l JOIN products p ON p.id = l.product_id
              WHERE l.order_id = ? ORDER BY l.product_id FOR UPDATE'
        );
        $stmt->execute([$id]);
        return $stmt->fetchAll();
    }

    /** One SELECT ... ORDER BY id FOR UPDATE over the products. @return array<int,array> */
    public static function lockProducts(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        sort($ids);
        $in_  = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare("SELECT id, code, name, is_active, track_serial FROM products WHERE id IN ({$in_}) ORDER BY id FOR UPDATE");
        $stmt->execute($ids);
        return array_column($stmt->fetchAll(), null, 'id');
    }

    private static function transition(int $id, callable $fn): string
    {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $o = self::lock($id);
            self::assertWorkingIn((int) $o['branch_id']);
            $out = $fn($o);
            $pdo->commit();
            return $out;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function cleanReason(?string $text, string $field): string
    {
        $text = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string) $text) ?? '');
        $len = mb_strlen($text);
        if ($len < 3 || $len > 255) {
            throw new HttpException(422, 'Enter a ' . ($field === 'note' ? 'note' : 'reason') . ' (3–255 characters).', ['errors' => [$field => 'Required.']]);
        }
        return $text;
    }

    private static function auditLines(int $id): array
    {
        $stmt = db()->prepare(
            'SELECT p.code, l.qty_ordered FROM customer_order_lines l JOIN products p ON p.id = l.product_id
              WHERE l.order_id = ? ORDER BY l.sort_order, l.id'
        );
        $stmt->execute([$id]);
        return array_map(static fn (array $r): string => $r['code'] . ' x' . $r['qty_ordered'], $stmt->fetchAll());
    }

    private static function auditValues(array $o, array $lines): array
    {
        return [
            'customer_id'    => (int) $o['customer_id'],
            'customer_po_no' => $o['customer_po_no'],
            'due_date'       => $o['due_date'],
            'total_qty'      => (int) $o['total_qty'],
            'subtotal'       => (string) $o['subtotal'],
            'items'          => $lines,
        ];
    }
}
