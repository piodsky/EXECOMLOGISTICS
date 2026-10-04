<?php
/**
 * PO Internal: purchase orders to suppliers (purchase_orders + lines), audit module 'purchasing'.
 *
 *   draft      no number, editable / deletable (purchasing.order + products.cost, working in the branch)
 *   pending    sent for approval; returned to draft with a note (approver or orderer)
 *   approved   purchasing.approve, never its creator: numbered PO-<branch>-<year>-NNNNNN, printable, sent
 *   partial    some units received through posted receiving reports (Receiving::post -> addReceived())
 *   received   every line fully received
 *   closed     partially received and the rest will not come (reason)
 *   cancelled  approved but nothing received (reason); its request lines are free to order again
 * A PO line may order units of approved purchase request lines (purchase_order_request_lines), never more than
 * the request still needs. Receiving is done with a normal receiving report made from the PO (same supplier,
 * PO lines only, at most what is still due); a cancelled RR gives the units back to the PO.
 * Everything here shows cost, so the pages need canView() (products.cost). Delivery = the branch POS location.
 * Lock order: receiving_reports row -> purchase_orders row -> purchase_order_lines -> purchase_requests rows ->
 * document_sequences -> products ...
 */
declare(strict_types=1);

final class PurchaseOrders
{
    public const STATUSES = [
        'draft'    => 'Draft',    'pending'  => 'For Approval', 'approved' => 'Approved',
        'partial'  => 'Partially Received', 'received' => 'Received', 'closed' => 'Closed', 'cancelled' => 'Cancelled',
    ];
    public const BADGES = [
        'draft'   => '', 'pending' => 'badge--info', 'approved' => 'badge--warning', 'partial' => 'badge--warning',
        'received' => 'badge--success', 'closed' => 'badge--success', 'cancelled' => 'badge--danger',
    ];
    /** Still waiting for the supplier. */
    public const OPEN      = ['approved', 'partial'];
    public const PREFIX    = 'PO';
    public const MAX_LINES = 100;
    public const MAX_COST  = 999999.9999;

    // ------------------------------------------------------------------
    // Permissions / branch
    // ------------------------------------------------------------------

    /** View purchase orders (they show cost). */
    public static function canView(): bool
    {
        return Auth::canAny('purchasing.order', 'purchasing.approve') && Auth::can('products.cost');
    }

    /** Prepare / edit / close / cancel purchase orders. */
    public static function canManage(): bool
    {
        return Auth::can('purchasing.order') && Auth::can('products.cost');
    }

    private static function requireView(): void
    {
        if (!self::canView()) {
            throw new HttpException(403, 'You do not have permission to view purchase orders.');
        }
    }

    private static function requireManage(): void
    {
        if (!self::canManage()) {
            throw new HttpException(403, 'You do not have permission to prepare purchase orders.');
        }
    }

    private static function assertWorkingIn(int $branchId): void
    {
        if (Branch::current() !== $branchId) {
            throw new HttpException(422, 'Switch to branch ' . self::branchName($branchId) . ' first.');
        }
    }

    private static function branchName(int $branchId): string
    {
        $stmt = db()->prepare('SELECT name FROM branches WHERE id = ?');
        $stmt->execute([$branchId]);
        return (string) $stmt->fetchColumn();
    }

    // ------------------------------------------------------------------
    // Listing / lookup
    // ------------------------------------------------------------------

    /** @param array{search?:string, status?:string, supplier?:?int} $f status also 'open' (approved + partial) or 'overdue' */
    private static function where(array $f): array
    {
        [$scope, $params] = Branch::scopeSql('o.branch_id');
        $where  = [$scope];
        $status = (string) ($f['status'] ?? '');
        if (isset(self::STATUSES[$status])) {
            $where[]  = 'o.status = ?';
            $params[] = $status;
        } elseif ($status === 'active') {
            $where[] = "o.status IN ('pending', 'approved', 'partial')";
        } elseif ($status === 'open' || $status === 'overdue') {
            $where[] = "o.status IN ('approved', 'partial')";
            if ($status === 'overdue') {
                $where[]  = 'o.expected_date < ?';
                $params[] = date('Y-m-d');
            }
        }
        if (($f['supplier'] ?? null) !== null) {
            $where[]  = 'o.supplier_id = ?';
            $params[] = (int) $f['supplier'];
        }
        $q = (string) ($f['search'] ?? '');
        if ($q !== '') {
            $like = like_pattern($q);
            $where[] = '(o.po_no LIKE ? OR sp.name LIKE ? OR o.notes LIKE ? OR EXISTS (SELECT 1 FROM purchase_order_lines ol
                            JOIN products p ON p.id = ol.product_id
                           WHERE ol.po_id = o.id AND (p.code LIKE ? OR p.name LIKE ? OR ol.end_user LIKE ?)))';
            array_push($params, $like, $like, $like, $like, $like, $like);
        }
        return [implode(' AND ', $where), $params];
    }

    public static function count(array $f): int
    {
        self::requireView();
        [$where, $params] = self::where($f);
        $stmt = db()->prepare("SELECT COUNT(*) FROM purchase_orders o JOIN suppliers sp ON sp.id = o.supplier_id WHERE {$where}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public static function search(array $f, int $limit, int $offset): array
    {
        self::requireView();
        [$where, $params] = self::where($f);
        $stmt = db()->prepare(
            "SELECT o.id, o.po_no, o.status, o.order_date, o.expected_date, o.total_qty, o.total_amount, o.branch_id,
                    sp.name AS supplier_name, sp.code AS supplier_code, b.code AS branch_code, b.name AS branch_name,
                    u.full_name AS created_by_name,
                    (SELECT COALESCE(SUM(ol.qty_received), 0) FROM purchase_order_lines ol WHERE ol.po_id = o.id) AS qty_received
               FROM purchase_orders o
               JOIN suppliers sp ON sp.id = o.supplier_id
               JOIN branches b ON b.id = o.branch_id
               JOIN users u ON u.id = o.created_by
              WHERE {$where}
              ORDER BY o.status IN ('draft', 'pending', 'approved', 'partial') DESC, o.order_date DESC, o.id DESC
              LIMIT ? OFFSET ?"
        );
        $stmt->execute([...$params, $limit, $offset]);
        return $stmt->fetchAll();
    }

    /** Work lists for the current branch: {draft, approve, open, overdue}. */
    public static function workCounts(): array
    {
        $out = ['draft' => 0, 'approve' => 0, 'open' => 0, 'overdue' => 0];
        if (!Branch::isConcrete() || !self::canView()) {
            return $out;
        }
        $stmt = db()->prepare(
            "SELECT SUM(status = 'draft'), SUM(status = 'pending'), SUM(status IN ('approved', 'partial')),
                    SUM(status IN ('approved', 'partial') AND expected_date < ?)
               FROM purchase_orders WHERE branch_id = ? AND status IN ('draft', 'pending', 'approved', 'partial')"
        );
        $stmt->execute([date('Y-m-d'), (int) Branch::current()]);
        [$d, $a, $o, $l] = $stmt->fetch(PDO::FETCH_NUM) ?: [0, 0, 0, 0];
        return ['draft' => (int) $d, 'approve' => (int) $a, 'open' => (int) $o, 'overdue' => (int) $l];
    }

    /**
     * PO with supplier / branch / people details, 'lines' (product, ordered / received / remaining, cost,
     * 'requests' = PR numbers it orders) and 'receipts' (receiving reports made from it). Null when missing.
     */
    public static function find(int $id): ?array
    {
        self::requireView();
        $stmt = db()->prepare(
            'SELECT o.*, sp.code AS supplier_code, sp.name AS supplier_name, sp.address AS supplier_address,
                    sp.phone AS supplier_phone, sp.tin AS supplier_tin, sp.is_active AS supplier_active,
                    b.code AS branch_code, b.name AS branch_name, b.address AS branch_address, b.contact_no AS branch_contact,
                    w.code AS warehouse_code, l.code AS location_code,
                    cu.full_name AS created_by_name, su.full_name AS submitted_by_name, au.full_name AS approved_by_name,
                    xu.full_name AS closed_by_name
               FROM purchase_orders o
               JOIN suppliers sp ON sp.id = o.supplier_id
               JOIN branches b ON b.id = o.branch_id
               JOIN warehouses w ON w.id = o.warehouse_id
               JOIN storage_locations l ON l.id = o.location_id
               JOIN users cu ON cu.id = o.created_by
               LEFT JOIN users su ON su.id = o.submitted_by
               LEFT JOIN users au ON au.id = o.approved_by
               LEFT JOIN users xu ON xu.id = o.closed_by
              WHERE o.id = ?'
        );
        $stmt->execute([$id]);
        $po = $stmt->fetch();
        if (!$po) {
            return null;
        }
        Branch::assertAccess((int) $po['branch_id']);

        $stmt = db()->prepare(
            'SELECT ol.*, p.code AS product_code, p.name AS product_name, p.track_serial, p.is_active AS product_active,
                    un.code AS unit_code
               FROM purchase_order_lines ol
               JOIN products p ON p.id = ol.product_id
               LEFT JOIN units un ON un.id = p.unit_id
              WHERE ol.po_id = ?
              ORDER BY ol.sort_order, ol.id'
        );
        $stmt->execute([$id]);
        $lines = [];
        foreach ($stmt->fetchAll() as $l) {
            $l['remaining'] = (int) $l['qty_ordered'] - (int) $l['qty_received'];
            $l['requests']  = [];
            $lines[(int) $l['id']] = $l;
        }
        if ($lines) {
            $in_  = implode(',', array_fill(0, count($lines), '?'));
            $stmt = db()->prepare(
                "SELECT x.po_line_id, x.request_line_id, x.qty, r.id AS request_id, r.pr_no
                   FROM purchase_order_request_lines x
                   JOIN purchase_request_lines rl ON rl.id = x.request_line_id
                   JOIN purchase_requests r ON r.id = rl.request_id
                  WHERE x.po_line_id IN ({$in_}) ORDER BY r.id"
            );
            $stmt->execute(array_keys($lines));
            foreach ($stmt->fetchAll() as $x) {
                $lines[(int) $x['po_line_id']]['requests'][] = [
                    'line_id' => (int) $x['request_line_id'], 'request_id' => (int) $x['request_id'],
                    'pr_no' => $x['pr_no'], 'qty' => (int) $x['qty'],
                ];
            }
        }
        $po['lines'] = array_values($lines);

        $stmt = db()->prepare(
            'SELECT r.id, r.rr_no, r.status, r.received_date, r.reference_no, r.total_qty, r.posted_at
               FROM receiving_reports r WHERE r.po_id = ? ORDER BY r.received_date, r.id'
        );
        $stmt->execute([$id]);
        $po['receipts'] = $stmt->fetchAll();
        return $po;
    }

    /** "PO-MLB-2026-000001" or "Draft PO #12". */
    public static function label(array $po): string
    {
        return $po['po_no'] ?? ('Draft PO #' . $po['id']);
    }

    /** Which buttons the current user gets. */
    public static function actions(array $po): array
    {
        $here     = Branch::current() === (int) $po['branch_id'];
        $uid      = (int) Auth::id();
        $s        = $po['status'];
        $manage   = $here && self::canManage();
        $received = array_sum(array_map(static fn (array $l): int => (int) $l['qty_received'], $po['lines'] ?? []));
        $drafts   = count(array_filter($po['receipts'] ?? [], static fn (array $r): bool => $r['status'] === 'draft'));
        return [
            'edit'    => $manage && $s === 'draft',
            'delete'  => $manage && $s === 'draft',
            'submit'  => $manage && $s === 'draft',
            'approve' => $here && $s === 'pending' && Auth::can('purchasing.approve') && Auth::can('products.cost')
                         && $uid !== (int) $po['created_by'],
            'return'  => $here && $s === 'pending' && ($manage || (Auth::can('purchasing.approve') && Auth::can('products.cost'))),
            'receive' => $here && in_array($s, self::OPEN, true) && Auth::can('receiving.manage'),
            'close'   => $manage && $s === 'partial',
            'cancel'  => $manage && $s === 'approved' && $received === 0 && $drafts === 0,
        ];
    }

    // ------------------------------------------------------------------
    // Form data
    // ------------------------------------------------------------------

    /**
     * Lines for a new PO from approved purchase requests of the current branch: one line per product, quantity =
     * what the requests still need, cost = the branch average (else the default cost), links "lineId:qty,...".
     * @param list<int> $requestIds @return list<array<string,string>> form rows (strings)
     */
    public static function prefillFromRequests(array $requestIds): array
    {
        self::requireManage();
        $branchId = Branch::forWrite();
        $byProduct = [];
        foreach (PurchaseRequests::openLines($branchId, $requestIds) as $l) {
            $row = &$byProduct[$l['product_id']];
            $row ??= ['qty' => 0, 'users' => [], 'links' => []];
            $row['qty'] += $l['remaining'];
            if ($l['end_user'] !== null && $l['end_user'] !== '') {
                $row['users'][$l['end_user']] = true;
            }
            $row['links'][] = $l['line_id'] . ':' . $l['remaining'];
            unset($row);
        }
        if (!$byProduct) {
            return [];
        }
        $in_  = implode(',', array_fill(0, count($byProduct), '?'));
        $stmt = db()->prepare(
            "SELECT p.id, COALESCE(pb.avg_cost, p.unit_cost) AS cost FROM products p
               LEFT JOIN product_branches pb ON pb.product_id = p.id AND pb.branch_id = ?
              WHERE p.id IN ({$in_})"
        );
        $stmt->execute([$branchId, ...array_keys($byProduct)]);
        $costs = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $rows = [];
        foreach ($byProduct as $pid => $row) {
            $rows[] = [
                'product_id' => (string) $pid,
                'quantity'   => (string) min($row['qty'], Products::MAX_STOCK),
                'unit_cost'  => self::costInput(isset($costs[$pid]) ? (string) $costs[$pid] : null),
                'end_user'   => mb_substr(implode(', ', array_keys($row['users'])), 0, 100),
                'links'      => implode(',', $row['links']),
            ];
        }
        return $rows;
    }

    /** "30000.5000" -> "30000.50" (form value; at least 2 decimals). */
    public static function costInput(?string $v): string
    {
        if ($v === null || $v === '') {
            return '';
        }
        return preg_match('/^(\d+)\.(\d{2})(\d*)$/', $v, $m) ? $m[1] . '.' . $m[2] . rtrim($m[3], '0') : $v;
    }

    /** Defaults for a new PO at the current branch: ship-to, contact. */
    public static function defaults(): array
    {
        $b = Branch::currentBranch() ?? [];
        $stmt = db()->prepare('SELECT address, contact_no FROM branches WHERE id = ?');
        $stmt->execute([(int) ($b['id'] ?? 0)]);
        $row = $stmt->fetch() ?: ['address' => null, 'contact_no' => null];
        $user = Auth::user();
        return [
            'order_date'     => date('Y-m-d'),
            'contact_person' => (string) ($user['full_name'] ?? ''),
            'contact_number' => (string) ($row['contact_no'] ?? ''),
            'ship_to'        => trim(setting('shop_name', 'EXECOM') . ' / ' . (string) ($row['address'] ?? ''), ' /'),
        ];
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    /**
     * Header: supplier_id, order_date, expected_date, payment_terms, contact_person, contact_number, ship_to,
     * forwarder, notes. Lines: items[i][product_id|quantity|unit_cost|end_user|links] (links = "lineId:qty,...").
     * @return array{0: array, 1: array<string,string>}
     */
    public static function validate(array $in): array
    {
        self::requireManage();
        $errors = [];
        $text = static function (string $key, int $max) use ($in, &$errors): ?string {
            $v = input_string($in, $key, $max + 1);
            if (mb_strlen($v) > $max) {
                $errors[$key] = "Keep it under {$max} characters.";
            }
            return $v !== '' ? $v : null;
        };
        $data = [
            'supplier_id'    => input_int($in, 'supplier_id', 1),
            'order_date'     => input_date($in, 'order_date'),
            'expected_date'  => input_date($in, 'expected_date'),
            'payment_terms'  => $text('payment_terms', 60),
            'contact_person' => $text('contact_person', 100),
            'contact_number' => $text('contact_number', 60),
            'ship_to'        => $text('ship_to', 255),
            'forwarder'      => $text('forwarder', 100),
            'notes'          => $text('notes', 500),
            'items'          => [],
        ];
        if ($data['supplier_id'] === null) {
            $errors['supplier_id'] = 'Choose a supplier.';
        } else {
            $stmt = db()->prepare('SELECT 1 FROM suppliers WHERE id = ? AND is_active = ?');
            $stmt->execute([$data['supplier_id'], 1]);
            if (!$stmt->fetchColumn()) {
                $errors['supplier_id'] = 'Choose an active supplier.';
            }
        }
        if ($data['order_date'] === null) {
            $errors['order_date'] = 'Enter the PO date.';
        }
        if (is_string($in['expected_date'] ?? null) && trim($in['expected_date']) !== '' && $data['expected_date'] === null) {
            $errors['expected_date'] = 'Enter a valid date.';
        } elseif ($data['expected_date'] !== null && $data['order_date'] !== null && $data['expected_date'] < $data['order_date']) {
            $errors['expected_date'] = 'The expected delivery cannot be before the PO date.';
        }

        $lines = [];
        foreach (is_array($in['items'] ?? null) ? array_values($in['items']) : [] as $i => $line) {
            if (!is_array($line)) {
                continue;
            }
            $blank = static fn (string $k): bool => !isset($line[$k]) || (is_string($line[$k]) && trim($line[$k]) === '');
            if ($blank('product_id') && $blank('quantity') && $blank('unit_cost') && $blank('end_user')) {
                continue; // empty template row
            }
            $lines[$i] = $line;
        }
        if (!$lines) {
            $errors['items'] = 'Add at least one item.';
        } elseif (count($lines) > self::MAX_LINES) {
            $errors['items'] = 'A purchase order can have at most ' . self::MAX_LINES . ' items.';
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
            $stmt = db()->prepare("SELECT id, name, is_active FROM products WHERE id IN ({$in_})");
            $stmt->execute(array_keys($ids));
            $products = array_column($stmt->fetchAll(), null, 'id');
        }
        $seen = [];
        foreach ($lines as $i => $line) {
            $key  = "items.{$i}.";
            $pid  = input_int($line, 'product_id', 1);
            $qty  = input_int($line, 'quantity', 1, Products::MAX_STOCK);
            $p    = $pid !== null ? ($products[$pid] ?? null) : null;
            $user = input_string($line, 'end_user', 101);
            $item = ['product_id' => $pid, 'quantity' => $qty, 'unit_cost' => null, 'end_user' => $user !== '' ? $user : null, 'links' => []];
            if ($p === null || (int) $p['is_active'] !== 1) {
                $errors[$key . 'product_id'] = 'Choose an active product.';
            } elseif (isset($seen[$pid])) {
                $errors[$key . 'product_id'] = "{$p['name']} is already on this purchase order. Use one line per product.";
            }
            if ($pid !== null) {
                $seen[$pid] = true;
            }
            if ($qty === null) {
                $errors[$key . 'quantity'] = 'Enter a whole number from 1 to ' . number_format(Products::MAX_STOCK) . '.';
            }
            $rawCost = is_string($line['unit_cost'] ?? null) ? str_replace(',', '', trim($line['unit_cost'])) : '';
            $cost = $rawCost === '' ? null : input_decimal(['c' => $rawCost], 'c', 0, self::MAX_COST, 4);
            if ($cost === null) {
                $errors[$key . 'unit_cost'] = 'Enter a cost from 0.00 to 999,999.9999 (up to 4 decimals).';
            } else {
                $item['unit_cost'] = Costing::format(Costing::toUnits($rawCost));
            }
            if (mb_strlen($user) > 100) {
                $errors[$key . 'end_user'] = 'Keep the end-user under 100 characters.';
            }
            $links = is_string($line['links'] ?? null) ? trim($line['links']) : '';
            if ($links !== '') {
                foreach (explode(',', $links) as $pair) {
                    if (!preg_match('/^(\d{1,10}):(\d{1,6})$/', trim($pair), $m) || (int) $m[1] < 1 || (int) $m[2] < 1) {
                        $errors[$key . 'product_id'] = 'The purchase request link of this line is invalid. Reload the requests.';
                        break;
                    }
                    $item['links'][(int) $m[1]] = ($item['links'][(int) $m[1]] ?? 0) + (int) $m[2];
                }
            }
            $data['items'][] = $item;
        }
        return [$data, $errors];
    }

    // ------------------------------------------------------------------
    // Drafts
    // ------------------------------------------------------------------

    /** Create (id null) or replace a draft at the current branch. $d comes from validate(). @return int id */
    public static function saveDraft(?int $id, array $d, int $userId): int
    {
        self::requireManage();
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $oldRequests = [];
            if ($id === null) {
                $branchId = Branch::forWrite();
                $location = Branch::defaultLocation($branchId);
                $pdo->prepare(
                    'INSERT INTO purchase_orders (branch_id, warehouse_id, location_id, supplier_id, status, order_date, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                )->execute([$branchId, $location['warehouse_id'], $location['id'], $d['supplier_id'], 'draft', $d['order_date'], $userId]);
                $id = (int) $pdo->lastInsertId();
                $before = null;
            } else {
                $po = self::lock($id);
                self::assertWorkingIn((int) $po['branch_id']);
                if ($po['status'] !== 'draft') {
                    throw new HttpException(409, self::label($po) . ' is ' . strtolower(self::STATUSES[$po['status']]) . ' and can no longer be edited.');
                }
                $branchId    = (int) $po['branch_id'];
                $oldRequests = self::linkedRequests($id);
                $before      = self::auditValues($po, self::auditLines($id));
                $pdo->prepare('DELETE FROM purchase_order_lines WHERE po_id = ?')->execute([$id]); // links cascade
            }

            // Request links: lock the requests, check what each line may still order.
            $allLinks = [];
            foreach ($d['items'] as $item) {
                foreach ($item['links'] as $lid => $qty) {
                    $allLinks[$lid] = ($allLinks[$lid] ?? 0) + $qty;
                }
            }
            $free = PurchaseRequests::lockLines(array_keys($allLinks), $id);
            foreach ($d['items'] as $item) {
                $linked = 0;
                foreach ($item['links'] as $lid => $qty) {
                    $l = $free[$lid] ?? null;
                    if ($l === null || $l['branch_id'] !== $branchId || !in_array($l['status'], ['approved', 'ordered'], true)
                        || $l['product_id'] !== (int) $item['product_id']) {
                        throw new HttpException(409, 'A purchase request on this order is no longer open. Reload the requests and try again.');
                    }
                    if ($allLinks[$lid] > $l['free']) {
                        throw new HttpException(409, "{$l['pr_no']} needs only {$l['free']} more of an item on this order. Reload the requests.");
                    }
                    $linked += $qty;
                }
                if ($linked > (int) $item['quantity']) {
                    throw new HttpException(422, "A line orders fewer units ({$item['quantity']}) than its purchase requests need ({$linked}). Raise the quantity or remove the request.");
                }
            }

            $pdo->prepare(
                'UPDATE purchase_orders SET supplier_id = ?, order_date = ?, expected_date = ?, payment_terms = ?, contact_person = ?,
                        contact_number = ?, ship_to = ?, forwarder = ?, notes = ? WHERE id = ?'
            )->execute([$d['supplier_id'], $d['order_date'], $d['expected_date'], $d['payment_terms'], $d['contact_person'],
                $d['contact_number'], $d['ship_to'], $d['forwarder'], $d['notes'], $id]);

            $insLine = $pdo->prepare(
                'INSERT INTO purchase_order_lines (po_id, product_id, end_user, qty_ordered, unit_cost, line_total, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $insLink = $pdo->prepare('INSERT INTO purchase_order_request_lines (po_line_id, request_line_id, qty) VALUES (?, ?, ?)');
            $totalQty = 0;
            $totalCents = 0;
            foreach (array_values($d['items']) as $n => $item) {
                $cents = Costing::lineCents((int) $item['quantity'], (string) $item['unit_cost']);
                $insLine->execute([$id, $item['product_id'], $item['end_user'], $item['quantity'], $item['unit_cost'], from_cents($cents), $n + 1]);
                $lineId = (int) $pdo->lastInsertId();
                foreach ($item['links'] as $lid => $qty) {
                    $insLink->execute([$lineId, $lid, $qty]);
                }
                $totalQty   += (int) $item['quantity'];
                $totalCents += $cents;
            }
            $pdo->prepare('UPDATE purchase_orders SET total_qty = ?, total_amount = ? WHERE id = ?')
                ->execute([$totalQty, from_cents($totalCents), $id]);

            $newRequests = array_values(array_unique(array_map(static fn (array $l): int => $l['request_id'], $free)));
            PurchaseRequests::refreshStatus([...$oldRequests, ...$newRequests]);

            $after = self::auditValues(['supplier_id' => $d['supplier_id'], 'order_date' => $d['order_date'],
                'expected_date' => $d['expected_date'], 'total_qty' => $totalQty], self::auditLines($id));
            if ($before === null) {
                Audit::record('purchasing', 'po_create', 'purchase_order', $id, 'Draft PO #' . $id, null, $after, $branchId);
            } else {
                [$old, $new] = Audit::diff($before, $after);
                if ($new) {
                    Audit::record('purchasing', 'po_update', 'purchase_order', $id, 'Draft PO #' . $id, $old, $new, $branchId);
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
        self::requireManage();
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $po = self::lock($id);
            self::assertWorkingIn((int) $po['branch_id']);
            if ($po['status'] !== 'draft') {
                throw new HttpException(409, 'Only drafts can be deleted. ' . self::label($po) . ' is ' . strtolower(self::STATUSES[$po['status']]) . '.');
            }
            $requests = self::linkedRequests($id);
            $before   = self::auditValues($po, self::auditLines($id));
            $pdo->prepare('DELETE FROM purchase_order_lines WHERE po_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM purchase_orders WHERE id = ?')->execute([$id]);
            PurchaseRequests::refreshStatus($requests);
            Audit::record('purchasing', 'po_delete', 'purchase_order', $id, 'Draft PO #' . $id, $before, null, (int) $po['branch_id']);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // ------------------------------------------------------------------
    // Submit / approve / return / close / cancel
    // ------------------------------------------------------------------

    public static function submit(int $id, int $userId): string
    {
        self::requireManage();
        return self::transition($id, static function (array $po) use ($id, $userId): string {
            if ($po['status'] !== 'draft') {
                throw new HttpException(409, self::label($po) . ' is ' . strtolower(self::STATUSES[$po['status']]) . ', so it can no longer be sent for approval.');
            }
            $stmt = db()->prepare(
                'SELECT COUNT(*), SUM(p.is_active = 0) FROM purchase_order_lines ol JOIN products p ON p.id = ol.product_id WHERE ol.po_id = ?'
            );
            $stmt->execute([$id]);
            [$lines, $inactive] = $stmt->fetch(PDO::FETCH_NUM);
            if ((int) $lines === 0) {
                throw new HttpException(422, 'Add at least one item first.');
            }
            if ((int) $inactive > 0) {
                throw new HttpException(422, 'An item on this purchase order is no longer active. Edit the draft first.');
            }
            $stmt = db()->prepare('SELECT is_active FROM suppliers WHERE id = ?');
            $stmt->execute([(int) $po['supplier_id']]);
            if ((int) $stmt->fetchColumn() !== 1) {
                throw new HttpException(422, 'The supplier is no longer active. Edit the draft first.');
            }
            db()->prepare('UPDATE purchase_orders SET status = ?, submitted_by = ?, submitted_at = NOW(), return_note = NULL WHERE id = ?')
                ->execute(['pending', $userId, $id]);
            Audit::record('purchasing', 'po_submit', 'purchase_order', $id, self::label($po), ['status' => 'draft'],
                ['status' => 'pending', 'total_qty' => (int) $po['total_qty']], (int) $po['branch_id']);
            return self::label($po);
        });
    }

    /** Approve and number it (never by its creator). @return string po_no */
    public static function approve(int $id, int $userId): string
    {
        if (!Auth::can('purchasing.approve') || !Auth::can('products.cost')) {
            throw new HttpException(403, 'You do not have permission to approve purchase orders.');
        }
        return self::transition($id, static function (array $po) use ($id, $userId): string {
            if ($po['status'] !== 'pending') {
                throw new HttpException(409, self::label($po) . ' is ' . strtolower(self::STATUSES[$po['status']]) . ', so it can no longer be approved.');
            }
            if ($userId === (int) $po['created_by']) {
                throw new HttpException(403, "You can't approve a purchase order you prepared.");
            }
            $no = DocNumber::next((int) $po['branch_id'], self::PREFIX);
            db()->prepare('UPDATE purchase_orders SET po_no = ?, status = ?, approved_by = ?, approved_at = NOW() WHERE id = ?')
                ->execute([$no, 'approved', $userId, $id]);
            Audit::record('purchasing', 'po_approve', 'purchase_order', $id, $no, ['status' => 'pending'],
                ['status' => 'approved', 'po_no' => $no], (int) $po['branch_id']);
            return $no;
        });
    }

    /** Send a pending PO back to draft with a note. */
    public static function returnToDraft(int $id, ?string $note, int $userId): string
    {
        if (!self::canManage() && !(Auth::can('purchasing.approve') && Auth::can('products.cost'))) {
            throw new HttpException(403, 'You do not have permission to return purchase orders.');
        }
        $note = self::cleanReason($note, 'note');
        return self::transition($id, static function (array $po) use ($id, $note): string {
            if ($po['status'] !== 'pending') {
                throw new HttpException(409, self::label($po) . ' is ' . strtolower(self::STATUSES[$po['status']]) . ', so it can no longer be returned.');
            }
            db()->prepare('UPDATE purchase_orders SET status = ?, return_note = ? WHERE id = ?')->execute(['draft', $note, $id]);
            Audit::record('purchasing', 'po_return', 'purchase_order', $id, self::label($po), ['status' => 'pending'],
                ['status' => 'draft', 'note' => $note], (int) $po['branch_id']);
            return self::label($po);
        });
    }

    /** Partially received: the rest will not come. */
    public static function close(int $id, ?string $reason, int $userId): string
    {
        self::requireManage();
        $reason = self::cleanReason($reason, 'reason');
        return self::transition($id, static function (array $po) use ($id, $reason, $userId): string {
            if ($po['status'] !== 'partial') {
                throw new HttpException(409, $po['status'] === 'approved'
                    ? 'Nothing was received on ' . self::label($po) . ' yet: cancel it instead.'
                    : self::label($po) . ' is ' . strtolower(self::STATUSES[$po['status']]) . ', so it can no longer be closed.');
            }
            self::assertNoDraftReceipts($id, $po);
            db()->prepare('UPDATE purchase_orders SET status = ?, closed_by = ?, closed_at = NOW(), close_reason = ? WHERE id = ?')
                ->execute(['closed', $userId, $reason, $id]);
            Audit::record('purchasing', 'po_close', 'purchase_order', $id, self::label($po), ['status' => 'partial'],
                ['status' => 'closed', 'reason' => $reason], (int) $po['branch_id']);
            return self::label($po);
        });
    }

    /** Approved, nothing received: the order is off; its request lines can be ordered again. */
    public static function cancel(int $id, ?string $reason, int $userId): string
    {
        self::requireManage();
        $reason = self::cleanReason($reason, 'reason');
        return self::transition($id, static function (array $po) use ($id, $reason, $userId): string {
            if ($po['status'] !== 'approved') {
                throw new HttpException(409, $po['status'] === 'partial'
                    ? 'Items were already received on ' . self::label($po) . ': close it instead.'
                    : self::label($po) . ' is ' . strtolower(self::STATUSES[$po['status']]) . ', so it can no longer be cancelled.');
            }
            $stmt = db()->prepare('SELECT COALESCE(SUM(qty_received), 0) FROM purchase_order_lines WHERE po_id = ?');
            $stmt->execute([$id]);
            if ((int) $stmt->fetchColumn() > 0) {
                throw new HttpException(409, 'Items were already received on ' . self::label($po) . ': close it instead.');
            }
            self::assertNoDraftReceipts($id, $po);
            $requests = self::linkedRequests($id);
            db()->prepare('UPDATE purchase_orders SET status = ?, closed_by = ?, closed_at = NOW(), close_reason = ? WHERE id = ?')
                ->execute(['cancelled', $userId, $reason, $id]);
            PurchaseRequests::refreshStatus($requests);
            Audit::record('purchasing', 'po_cancel', 'purchase_order', $id, self::label($po), ['status' => 'approved'],
                ['status' => 'cancelled', 'reason' => $reason], (int) $po['branch_id']);
            return self::label($po);
        });
    }

    // ------------------------------------------------------------------
    // Receiving (called by Receiving inside its transaction)
    // ------------------------------------------------------------------

    /**
     * Lines still due on an open PO of the current branch for a new receiving report (form rows).
     * @return array{po: array, rows: list<array<string,string>>}
     */
    public static function receivingPrefill(int $id): array
    {
        if (!Auth::can('receiving.manage')) {
            throw new HttpException(403, 'You do not have permission to receive items.');
        }
        $po = self::findForReceiving($id);
        $rows = [];
        foreach ($po['lines'] as $l) {
            if ($l['remaining'] > 0) {
                $rows[] = ['product_id' => (string) $l['product_id'], 'quantity' => (string) $l['remaining'],
                           'unit_cost' => Auth::can('products.cost') ? self::costInput((string) $l['unit_cost']) : ''];
            }
        }
        return ['po' => $po, 'rows' => $rows];
    }

    /**
     * Open PO for receiving (no cost permission needed: receiving clerks see only quantities).
     * 404 outside the scope; 422 at another branch; 409 unless approved / partially received.
     * @return array{id:int, po_no:string, branch_id:int, supplier_id:int, supplier_name:string, status:string,
     *               lines: array<int, array{id:int, product_id:int, qty_ordered:int, qty_received:int, remaining:int, unit_cost:string}>}
     */
    public static function findForReceiving(int $id, bool $lock = false): array
    {
        $stmt = db()->prepare(
            'SELECT o.id, o.po_no, o.branch_id, o.supplier_id, o.status, sp.name AS supplier_name
               FROM purchase_orders o JOIN suppliers sp ON sp.id = o.supplier_id WHERE o.id = ?' . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$id]);
        $po = $stmt->fetch() ?: throw new HttpException(404, 'Purchase order not found.');
        Branch::assertAccess((int) $po['branch_id']);
        if (Branch::current() !== (int) $po['branch_id']) {
            throw new HttpException(422, 'Switch to branch ' . self::branchName((int) $po['branch_id']) . ' first.');
        }
        if (!in_array($po['status'], self::OPEN, true)) {
            throw new HttpException(409, "{$po['po_no']} is " . strtolower(self::STATUSES[$po['status']] ?? $po['status']) . ', so nothing more can be received on it.');
        }
        $stmt = db()->prepare(
            'SELECT id, product_id, qty_ordered, qty_received, unit_cost FROM purchase_order_lines WHERE po_id = ? ORDER BY sort_order, id'
            . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$id]);
        $lines = [];
        foreach ($stmt->fetchAll() as $l) {
            $lines[(int) $l['id']] = [
                'id' => (int) $l['id'], 'product_id' => (int) $l['product_id'], 'qty_ordered' => (int) $l['qty_ordered'],
                'qty_received' => (int) $l['qty_received'], 'remaining' => (int) $l['qty_ordered'] - (int) $l['qty_received'],
                'unit_cost' => (string) $l['unit_cost'],
            ];
        }
        $po['lines'] = $lines;
        return $po;
    }

    /**
     * Add (or, for a cancelled RR, take back) received units; status follows: approved / partial / received.
     * A closed PO stays closed. Caller holds the PO row lock. @param array<int,int> $qtyByLine po line id => signed qty
     */
    public static function addReceived(int $id, array $qtyByLine, string $rrNo): void
    {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $po = $stmt->fetch() ?: throw new HttpException(404, 'Purchase order not found.');
        $upd = $pdo->prepare(
            'UPDATE purchase_order_lines SET qty_received = qty_received + ? WHERE id = ? AND po_id = ?
                AND qty_received + ? BETWEEN 0 AND qty_ordered'
        );
        foreach ($qtyByLine as $lineId => $qty) {
            $upd->execute([$qty, $lineId, $id, $qty]);
            if ($upd->rowCount() !== 1) {
                throw new HttpException(409, "{$rrNo}: a quantity is more than what is still due on {$po['po_no']}.");
            }
        }
        $stmt = $pdo->prepare('SELECT SUM(qty_ordered), SUM(qty_received) FROM purchase_order_lines WHERE po_id = ?');
        $stmt->execute([$id]);
        [$ordered, $received] = array_map('intval', $stmt->fetch(PDO::FETCH_NUM));
        if (in_array($po['status'], ['approved', 'partial', 'received'], true)) {
            $new = $received === 0 ? 'approved' : ($received >= $ordered ? 'received' : 'partial');
            if ($new !== $po['status']) {
                $pdo->prepare('UPDATE purchase_orders SET status = ? WHERE id = ?')->execute([$new, $id]);
                Audit::record('purchasing', 'po_' . $new, 'purchase_order', $id, (string) $po['po_no'], ['status' => $po['status']],
                    ['status' => $new, 'by' => $rrNo, 'received' => $received . ' of ' . $ordered], (int) $po['branch_id']);
            }
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private static function lock(int $id): array
    {
        $stmt = db()->prepare('SELECT * FROM purchase_orders WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $po = $stmt->fetch() ?: throw new HttpException(404, 'Purchase order not found.');
        Branch::assertAccess((int) $po['branch_id']);
        return $po;
    }

    /** Lock, check the branch, run $fn in one transaction. */
    private static function transition(int $id, callable $fn): string
    {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $po = self::lock($id);
            self::assertWorkingIn((int) $po['branch_id']);
            $out = $fn($po);
            $pdo->commit();
            return $out;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function assertNoDraftReceipts(int $id, array $po): void
    {
        $stmt = db()->prepare("SELECT COUNT(*) FROM receiving_reports WHERE po_id = ? AND status = 'draft'");
        $stmt->execute([$id]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new HttpException(409, 'There is a receiving draft for ' . self::label($po) . '. Post or delete it first.');
        }
    }

    /** @return list<int> purchase request ids linked to the PO's lines */
    private static function linkedRequests(int $id): array
    {
        $stmt = db()->prepare(
            'SELECT DISTINCT rl.request_id FROM purchase_order_request_lines x
               JOIN purchase_order_lines ol ON ol.id = x.po_line_id
               JOIN purchase_request_lines rl ON rl.id = x.request_line_id
              WHERE ol.po_id = ?'
        );
        $stmt->execute([$id]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private static function cleanReason(?string $text, string $field): string
    {
        $text = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string) $text) ?? '');
        $len = mb_strlen($text);
        if ($len < 3 || $len > 255) {
            throw new HttpException(422, 'Enter a ' . ($field === 'note' ? 'note' : 'reason') . ' (3–255 characters).', ['errors' => [$field => 'Required.']]);
        }
        return $text;
    }

    /** Lines for the audit log: product, qty (never cost). */
    private static function auditLines(int $id): array
    {
        $stmt = db()->prepare(
            'SELECT p.code, ol.qty_ordered FROM purchase_order_lines ol JOIN products p ON p.id = ol.product_id
              WHERE ol.po_id = ? ORDER BY ol.sort_order, ol.id'
        );
        $stmt->execute([$id]);
        return array_map(static fn (array $r): string => $r['code'] . ' x' . $r['qty_ordered'], $stmt->fetchAll());
    }

    private static function auditValues(array $po, array $lines): array
    {
        return [
            'supplier_id'   => (int) $po['supplier_id'],
            'order_date'    => $po['order_date'],
            'expected_date' => $po['expected_date'],
            'total_qty'     => (int) $po['total_qty'],
            'items'         => $lines,
        ];
    }
}
