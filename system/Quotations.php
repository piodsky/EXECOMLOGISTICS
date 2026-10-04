<?php
/**
 * Quotations = EXECOM's price quotation for a customer's request for quotation (RFQ / canvass), audit module
 * 'customer_orders' (entity 'quotation'). Permission customer_orders.manage (view: any customer orders permission).
 *
 *   draft      numbered at create QT-<branch>-<year>-NNNNNN (never deleted: cancel instead), editable
 *   sent       given to the customer (printed / e-mailed); "revise" puts it back to draft
 *   won        a customer order (PO Outgoing) was made from it: co-form.php?quote=ID; order_id. Deleting that draft
 *              order makes the quotation sent again.
 *   lost       the customer chose another supplier (reason)
 *   cancelled  withdrawn (reason)
 * Prices are VAT-exclusive (VAT is shown on top) like the orders; below the suggested price needs a reason. No stock
 * is reserved by a quotation. Lock order: customer_orders -> quotations.
 */
declare(strict_types=1);

final class Quotations
{
    public const STATUSES = ['draft' => 'Draft', 'sent' => 'Sent', 'won' => 'Won', 'lost' => 'Lost', 'cancelled' => 'Cancelled'];
    public const BADGES   = ['draft' => '', 'sent' => 'badge--info', 'won' => 'badge--success', 'lost' => 'badge--warning', 'cancelled' => 'badge--danger'];
    public const PREFIX    = 'QT';
    public const MAX_LINES = 100;
    public const VALID_DAYS = 30;

    private static function requireManage(): void
    {
        if (!Auth::can('customer_orders.manage')) {
            throw new HttpException(403, 'You do not have permission to prepare quotations.');
        }
    }

    // ------------------------------------------------------------------
    // Listing / lookup
    // ------------------------------------------------------------------

    /** @param array{search?:string, status?:string} $f status also: open (draft / sent), expired (sent, past valid until) */
    private static function where(array $f): array
    {
        [$scope, $params] = Branch::scopeSql('q.branch_id');
        $where  = [$scope];
        $status = (string) ($f['status'] ?? '');
        if (isset(self::STATUSES[$status])) {
            $where[]  = 'q.status = ?';
            $params[] = $status;
        } elseif ($status === 'open') {
            $where[] = "q.status IN ('draft', 'sent')";
        } elseif ($status === 'expired') {
            $where[]  = "q.status = 'sent' AND q.valid_until < ?";
            $params[] = date('Y-m-d');
        }
        $q = (string) ($f['search'] ?? '');
        if ($q !== '') {
            $like = like_pattern($q);
            $where[] = '(q.quote_no LIKE ? OR q.customer_name LIKE ? OR q.rfq_no LIKE ? OR q.end_user LIKE ?
                         OR EXISTS (SELECT 1 FROM quotation_lines l JOIN products p ON p.id = l.product_id
                                     WHERE l.quotation_id = q.id AND (p.code LIKE ? OR p.name LIKE ?)))';
            array_push($params, $like, $like, $like, $like, $like, $like);
        }
        return [implode(' AND ', $where), $params];
    }

    public static function count(array $f): int
    {
        CustomerOrders::requireView();
        [$where, $params] = self::where($f);
        $stmt = db()->prepare("SELECT COUNT(*) FROM quotations q WHERE {$where}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public static function search(array $f, int $limit, int $offset): array
    {
        CustomerOrders::requireView();
        [$where, $params] = self::where($f);
        $stmt = db()->prepare(
            "SELECT q.id, q.quote_no, q.status, q.customer_name, q.rfq_no, q.end_user, q.quote_date, q.valid_until, q.total_qty, q.subtotal,
                    q.created_at, q.branch_id, q.order_id, b.code AS branch_code, b.name AS branch_name, u.full_name AS created_by_name,
                    co.order_no
               FROM quotations q
               JOIN branches b ON b.id = q.branch_id
               JOIN users u ON u.id = q.created_by
               LEFT JOIN customer_orders co ON co.id = q.order_id
              WHERE {$where}
              ORDER BY q.status IN ('draft', 'sent') DESC, q.created_at DESC, q.id DESC
              LIMIT ? OFFSET ?"
        );
        $stmt->execute([...$params, $limit, $offset]);
        return $stmt->fetchAll();
    }

    /** {drafts, sent, expired} of the current branch. */
    public static function workCounts(): array
    {
        $out = ['drafts' => 0, 'sent' => 0, 'expired' => 0];
        if (!Branch::isConcrete() || !Auth::canAny(...CustomerOrders::VIEW_PERMISSIONS)) {
            return $out;
        }
        $stmt = db()->prepare(
            "SELECT SUM(status = 'draft'), SUM(status = 'sent'), SUM(status = 'sent' AND valid_until < ?)
               FROM quotations WHERE branch_id = ? AND status IN ('draft', 'sent')"
        );
        $stmt->execute([date('Y-m-d'), (int) Branch::current()]);
        [$d, $s, $x] = $stmt->fetch(PDO::FETCH_NUM) ?: [0, 0, 0];
        return ['drafts' => (int) $d, 'sent' => (int) $s, 'expired' => (int) $x];
    }

    public static function find(int $id): ?array
    {
        CustomerOrders::requireView();
        $stmt = db()->prepare(
            'SELECT q.*, b.code AS branch_code, b.name AS branch_name, c.tin AS customer_tin, c.phone AS customer_phone,
                    c.is_active AS customer_active, ct.name AS customer_type, co.order_no, co.status AS order_status,
                    cu.full_name AS created_by_name, su.full_name AS sent_by_name, xu.full_name AS closed_by_name
               FROM quotations q
               JOIN branches b ON b.id = q.branch_id
               JOIN customers c ON c.id = q.customer_id
               LEFT JOIN customer_types ct ON ct.id = c.customer_type_id
               LEFT JOIN customer_orders co ON co.id = q.order_id
               JOIN users cu ON cu.id = q.created_by
               LEFT JOIN users su ON su.id = q.sent_by
               LEFT JOIN users xu ON xu.id = q.closed_by
              WHERE q.id = ?'
        );
        $stmt->execute([$id]);
        $q = $stmt->fetch();
        if (!$q) {
            return null;
        }
        Branch::assertAccess((int) $q['branch_id']);
        $stmt = db()->prepare(
            'SELECT l.*, p.code AS product_code, p.name AS product_name, p.specs, p.warranty_days, p.is_active AS product_active,
                    un.code AS unit_code, br.name AS brand_name
               FROM quotation_lines l
               JOIN products p ON p.id = l.product_id
               LEFT JOIN units un ON un.id = p.unit_id
               LEFT JOIN brands br ON br.id = p.brand_id
              WHERE l.quotation_id = ?
              ORDER BY l.sort_order, l.id'
        );
        $stmt->execute([$id]);
        $q['lines'] = $stmt->fetchAll();
        $q['expired'] = $q['status'] === 'sent' && $q['valid_until'] !== null && $q['valid_until'] < date('Y-m-d');
        return $q;
    }

    public static function actions(array $q): array
    {
        $here   = Branch::current() === (int) $q['branch_id'];
        $manage = $here && Auth::can('customer_orders.manage');
        $s      = $q['status'];
        return [
            'edit'   => $manage && $s === 'draft',
            'send'   => $manage && $s === 'draft',
            'revise' => $manage && $s === 'sent',
            'order'  => $manage && $s === 'sent' && (int) $q['customer_active'] === 1,
            'lost'   => $manage && $s === 'sent',
            'cancel' => $manage && in_array($s, ['draft', 'sent'], true),
        ];
    }

    // ------------------------------------------------------------------
    // Validation / save
    // ------------------------------------------------------------------

    /**
     * Header: customer_id, attention, rfq_no, rfq_date, end_user, quote_date, valid_until, delivery_term, payment_term,
     * warranty, notes. Lines: items[i][product_id|quantity|unit_price|price_reason].
     * @return array{0: array, 1: array<string,string>}
     */
    public static function validate(array $in): array
    {
        self::requireManage();
        $errors = [];
        $text = static function (string $key, int $max) use ($in, &$errors): ?string {
            $v = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', input_string($in, $key, $max + 1)) ?? '');
            if (mb_strlen($v) > $max) {
                $errors[$key] = "Keep it under {$max} characters.";
            }
            return $v !== '' ? $v : null;
        };
        $data = [
            'customer_id'   => input_int($in, 'customer_id', 1),
            'attention'     => $text('attention', 100),
            'rfq_no'        => $text('rfq_no', 60),
            'rfq_date'      => input_date($in, 'rfq_date'),
            'end_user'      => $text('end_user', 150),
            'quote_date'    => input_date($in, 'quote_date'),
            'valid_until'   => input_date($in, 'valid_until'),
            'delivery_term' => $text('delivery_term', 100),
            'payment_term'  => $text('payment_term', 100),
            'warranty'      => $text('warranty', 100),
            'notes'         => $text('notes', 500),
            'items'         => [],
        ];
        $customerIds = array_map('intval', array_column(CustomerOrders::customers(), 'id'));
        if ($data['customer_id'] === null || !in_array($data['customer_id'], $customerIds, true)) {
            $errors['customer_id'] = 'Choose an active customer of this branch.';
        }
        if ($data['quote_date'] === null) {
            $errors['quote_date'] = 'Enter the quotation date.';
        }
        foreach (['rfq_date' => 'RFQ date', 'valid_until' => 'date'] as $k => $label) {
            if (is_string($in[$k] ?? null) && trim($in[$k]) !== '' && $data[$k] === null) {
                $errors[$k] = "Enter a valid {$label}.";
            }
        }
        if ($data['valid_until'] !== null && $data['quote_date'] !== null && $data['valid_until'] < $data['quote_date']) {
            $errors['valid_until'] = 'The quotation cannot expire before its date.';
        }

        $lines = [];
        foreach (is_array($in['items'] ?? null) ? array_values($in['items']) : [] as $i => $line) {
            if (!is_array($line)) {
                continue;
            }
            $blank = static fn (string $k): bool => !isset($line[$k]) || (is_string($line[$k]) && trim($line[$k]) === '');
            if ($blank('product_id') && $blank('quantity') && $blank('unit_price') && $blank('price_reason')) {
                continue;
            }
            $lines[$i] = $line;
        }
        if (!$lines) {
            $errors['items'] = 'Add at least one item.';
        } elseif (count($lines) > self::MAX_LINES) {
            $errors['items'] = 'A quotation can have at most ' . self::MAX_LINES . ' items.';
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
            $stmt = db()->prepare("SELECT id, name, price, is_active FROM products WHERE id IN ({$in_})");
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
                $errors[$key . 'product_id'] = "{$p['name']} is already on this quotation. Use one line per product.";
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
                $errors[$key . 'unit_price'] = 'Enter the quoted unit price (0.00 to 9,999,999.99).';
            } else {
                $item['price'] = to_cents((string) $price);
            }
            $reason = trim(input_string($line, 'price_reason', 256));
            $item['reason'] = $reason !== '' ? $reason : null;
            if ($p !== null) {
                $item['suggested'] = to_cents($p['price']);
                if ($item['price'] !== null && $item['price'] < $item['suggested']) {
                    if ($item['reason'] === null || mb_strlen($item['reason']) < 3 || mb_strlen($item['reason']) > 255) {
                        $errors[$key . 'price_reason'] = 'Below the suggested price ' . money($p['price']) . ': enter the reason (e.g. volume / government price).';
                    }
                } else {
                    $item['reason'] = null;
                }
            }
            $data['items'][] = $item;
        }
        return [$data, $errors];
    }

    /** Create (id null: numbered now) or replace a draft at the current branch. @return int id */
    public static function save(?int $id, array $d, int $userId): int
    {
        self::requireManage();
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT name, address FROM customers WHERE id = ?');
            $stmt->execute([(int) $d['customer_id']]);
            $customer = $stmt->fetch() ?: throw new HttpException(422, 'Choose an active customer of this branch.');
            $header = [$d['customer_id'], mb_substr((string) $customer['name'], 0, 100), $customer['address'], $d['attention'], $d['rfq_no'],
                $d['rfq_date'], $d['end_user'], $d['quote_date'], $d['valid_until'], $d['delivery_term'], $d['payment_term'], $d['warranty'], $d['notes']];
            if ($id === null) {
                $branchId = Branch::forWrite();
                $no = DocNumber::next($branchId, self::PREFIX);
                $pdo->prepare(
                    'INSERT INTO quotations (customer_id, customer_name, customer_address, attention, rfq_no, rfq_date, end_user, quote_date,
                                             valid_until, delivery_term, payment_term, warranty, notes, quote_no, branch_id, status, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([...$header, $no, $branchId, 'draft', $userId]);
                $id = (int) $pdo->lastInsertId();
                $before = null;
            } else {
                $q = self::lock($id);
                CustomerOrders::assertWorkingIn((int) $q['branch_id']);
                if ($q['status'] !== 'draft') {
                    throw new HttpException(409, "{$q['quote_no']} is " . strtolower(self::STATUSES[$q['status']]) . ' and can no longer be edited.');
                }
                $branchId = (int) $q['branch_id'];
                $no       = (string) $q['quote_no'];
                $before   = self::auditValues($q, self::auditLines($id));
                $pdo->prepare(
                    'UPDATE quotations SET customer_id = ?, customer_name = ?, customer_address = ?, attention = ?, rfq_no = ?, rfq_date = ?,
                            end_user = ?, quote_date = ?, valid_until = ?, delivery_term = ?, payment_term = ?, warranty = ?, notes = ? WHERE id = ?'
                )->execute([...$header, $id]);
                $pdo->prepare('DELETE FROM quotation_lines WHERE quotation_id = ?')->execute([$id]);
            }
            $ins = $pdo->prepare(
                'INSERT INTO quotation_lines (quotation_id, product_id, quantity, unit_price, suggested_price, price_reason, line_total, sort_order)
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
            $pdo->prepare('UPDATE quotations SET total_qty = ?, subtotal = ? WHERE id = ?')->execute([$qty, from_cents($sub), $id]);
            $after = self::auditValues(['customer_id' => $d['customer_id'], 'rfq_no' => $d['rfq_no'], 'valid_until' => $d['valid_until'],
                'total_qty' => $qty, 'subtotal' => from_cents($sub)], self::auditLines($id));
            if ($before === null) {
                Audit::record('customer_orders', 'quote_create', 'quotation', $id, $no, null, $after, $branchId);
            } else {
                [$old, $new] = Audit::diff($before, $after);
                if ($new) {
                    Audit::record('customer_orders', 'quote_update', 'quotation', $id, $no, $old, $new, $branchId);
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

    // ------------------------------------------------------------------
    // Status changes
    // ------------------------------------------------------------------

    public static function send(int $id, int $userId): string
    {
        self::requireManage();
        return self::transition($id, static function (array $q) use ($id, $userId): string {
            if ($q['status'] !== 'draft') {
                throw new HttpException(409, "{$q['quote_no']} is " . strtolower(self::STATUSES[$q['status']]) . '.');
            }
            $stmt = db()->prepare('SELECT COUNT(*) FROM quotation_lines WHERE quotation_id = ?');
            $stmt->execute([$id]);
            if ((int) $stmt->fetchColumn() === 0) {
                throw new HttpException(422, 'Add at least one item first.');
            }
            db()->prepare('UPDATE quotations SET status = ?, sent_by = ?, sent_at = NOW() WHERE id = ?')->execute(['sent', $userId, $id]);
            Audit::record('customer_orders', 'quote_send', 'quotation', $id, (string) $q['quote_no'], ['status' => 'draft'], ['status' => 'sent'], (int) $q['branch_id']);
            return (string) $q['quote_no'];
        });
    }

    public static function revise(int $id, int $userId): string
    {
        self::requireManage();
        return self::transition($id, static function (array $q) use ($id): string {
            if ($q['status'] !== 'sent') {
                throw new HttpException(409, "{$q['quote_no']} is " . strtolower(self::STATUSES[$q['status']]) . ', so it can no longer be revised.');
            }
            db()->prepare('UPDATE quotations SET status = ? WHERE id = ?')->execute(['draft', $id]);
            Audit::record('customer_orders', 'quote_revise', 'quotation', $id, (string) $q['quote_no'], ['status' => 'sent'], ['status' => 'draft'], (int) $q['branch_id']);
            return (string) $q['quote_no'];
        });
    }

    /** $to = lost (sent only) or cancelled (draft / sent), with a reason. */
    public static function close(int $id, string $to, ?string $reason, int $userId): string
    {
        self::requireManage();
        if (!in_array($to, ['lost', 'cancelled'], true)) {
            throw new HttpException(400, 'Unknown action.');
        }
        $reason = CustomerOrders::cleanReason($reason, 'reason');
        return self::transition($id, static function (array $q) use ($id, $to, $reason, $userId): string {
            $allowed = $to === 'lost' ? ['sent'] : ['draft', 'sent'];
            if (!in_array($q['status'], $allowed, true)) {
                throw new HttpException(409, "{$q['quote_no']} is " . strtolower(self::STATUSES[$q['status']]) . '.');
            }
            db()->prepare('UPDATE quotations SET status = ?, closed_by = ?, closed_at = NOW(), close_reason = ? WHERE id = ?')
                ->execute([$to, $userId, $reason, $id]);
            Audit::record('customer_orders', 'quote_' . ($to === 'lost' ? 'lost' : 'cancel'), 'quotation', $id, (string) $q['quote_no'],
                ['status' => $q['status']], ['status' => $to, 'reason' => $reason], (int) $q['branch_id']);
            return (string) $q['quote_no'];
        });
    }

    /**
     * Inside CustomerOrders::saveDraft (its transaction, the new order inserted): the quotation is won by that order.
     * Must be sent, same branch and customer.
     */
    public static function markWon(int $quoteId, int $orderId, int $branchId, int $customerId): string
    {
        $q = self::lock($quoteId);
        if ($q['status'] !== 'sent') {
            throw new HttpException(409, "{$q['quote_no']} is " . strtolower(self::STATUSES[$q['status']]) . ', so no order can be made from it.');
        }
        if ((int) $q['branch_id'] !== $branchId || (int) $q['customer_id'] !== $customerId) {
            throw new HttpException(422, "The order must be for the customer of {$q['quote_no']} at the same branch.", ['errors' => ['customer_id' => 'Use the customer of the quotation.']]);
        }
        db()->prepare('UPDATE quotations SET status = ?, order_id = ?, closed_at = NOW() WHERE id = ?')->execute(['won', $orderId, $quoteId]);
        Audit::record('customer_orders', 'quote_won', 'quotation', $quoteId, (string) $q['quote_no'], ['status' => 'sent'],
            ['status' => 'won', 'order' => 'Draft Order #' . $orderId], $branchId);
        return (string) $q['quote_no'];
    }

    /** Inside CustomerOrders::deleteDraft: the order made from the quotation is gone; it is sent again. */
    public static function reopen(int $quoteId): void
    {
        $q = self::lock($quoteId);
        if ($q['status'] === 'won') {
            db()->prepare('UPDATE quotations SET status = ?, order_id = NULL, closed_at = NULL WHERE id = ?')->execute(['sent', $quoteId]);
            Audit::record('customer_orders', 'quote_reopen', 'quotation', $quoteId, (string) $q['quote_no'], ['status' => 'won'],
                ['status' => 'sent'], (int) $q['branch_id']);
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private static function lock(int $id): array
    {
        $stmt = db()->prepare('SELECT * FROM quotations WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $q = $stmt->fetch() ?: throw new HttpException(404, 'Quotation not found.');
        Branch::assertAccess((int) $q['branch_id']);
        return $q;
    }

    private static function transition(int $id, callable $fn): string
    {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $q = self::lock($id);
            CustomerOrders::assertWorkingIn((int) $q['branch_id']);
            $out = $fn($q);
            $pdo->commit();
            return $out;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function auditLines(int $id): array
    {
        $stmt = db()->prepare(
            'SELECT p.code, l.quantity, l.unit_price FROM quotation_lines l JOIN products p ON p.id = l.product_id
              WHERE l.quotation_id = ? ORDER BY l.sort_order, l.id'
        );
        $stmt->execute([$id]);
        return array_map(static fn (array $r): string => $r['code'] . ' x' . $r['quantity'] . ' @ ' . $r['unit_price'], $stmt->fetchAll());
    }

    private static function auditValues(array $q, array $lines): array
    {
        return [
            'customer_id' => (int) $q['customer_id'],
            'rfq_no'      => $q['rfq_no'],
            'valid_until' => $q['valid_until'],
            'total_qty'   => (int) $q['total_qty'],
            'subtotal'    => (string) $q['subtotal'],
            'items'       => $lines,
        ];
    }
}
