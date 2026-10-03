<?php
/**
 * Receiving reports (RR): supplier deliveries that add stock at a branch and set its moving-average cost.
 *
 *   draft     -> editable, no number, no stock effect (receiving.manage)
 *   posted    -> rr_no RR-<branch>-<posting year>-<6 digits>, stock in through Stock::move (type 'receiving'),
 *                Costing::inbound per line, serials created in_stock (receiving.post + products.cost)
 *   cancelled -> posted RR reversed while its stock/cost/serials are untouched (receiving.cancel)
 *
 * Global lock order (deadlock-free with Sales): receiving_reports row -> document_sequences row ->
 * products (one SELECT ... ORDER BY id FOR UPDATE) -> per product in id order: stock_balances ->
 * product_branches -> product_serials last.
 * Cost (unit_cost, line_total, avg_cost_*, total_cost) only leaves this class with products.cost,
 * and is never written to the audit log.
 */
declare(strict_types=1);

final class Receiving
{
    public const STATUSES  = ['draft' => 'Draft', 'posted' => 'Posted', 'cancelled' => 'Cancelled'];
    public const MAX_LINES = 100;
    public const DOC_TYPE  = 'RR';
    public const MAX_COST  = 999999.9999;

    private const COST_KEYS = ['unit_cost', 'line_total', 'avg_cost_before', 'avg_cost_after', 'total_cost'];

    // ------------------------------------------------------------------
    // Permissions
    // ------------------------------------------------------------------

    private static function requirePermission(string $permission, string $message): void
    {
        if (!Auth::can($permission)) {
            throw new HttpException(403, $message);
        }
    }

    // ------------------------------------------------------------------
    // Listing / lookup (receiving.view, current branch scope)
    // ------------------------------------------------------------------

    /** @param array{search?:string, status?:string, from?:?string, to?:?string, supplier?:?int} $f */
    public static function count(array $f): int
    {
        self::requirePermission('receiving.view', 'You do not have permission to view receiving reports.');
        [$where, $params] = self::where($f);
        $stmt = db()->prepare(
            "SELECT COUNT(*) FROM receiving_reports r JOIN suppliers sp ON sp.id = r.supplier_id WHERE {$where}"
        );
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public static function search(array $f, int $limit, int $offset): array
    {
        self::requirePermission('receiving.view', 'You do not have permission to view receiving reports.');
        [$where, $params] = self::where($f);
        $cost = Auth::can('products.cost') ? 'r.total_cost, ' : '';
        $stmt = db()->prepare(
            "SELECT r.id, r.rr_no, r.status, r.received_date, r.reference_no, r.total_qty, {$cost}
                    r.created_at, r.posted_at, r.cancelled_at, r.supplier_id,
                    sp.code AS supplier_code, sp.name AS supplier_name,
                    b.code AS branch_code, b.name AS branch_name, u.full_name AS created_by_name,
                    (SELECT COUNT(*) FROM receiving_items ri WHERE ri.receiving_id = r.id) AS line_count
               FROM receiving_reports r
               JOIN suppliers sp ON sp.id = r.supplier_id
               JOIN branches b ON b.id = r.branch_id
               JOIN users u ON u.id = r.created_by
              WHERE {$where}
              ORDER BY r.status = 'draft' DESC, r.received_date DESC, r.id DESC
              LIMIT ? OFFSET ?"
        );
        $stmt->execute([...$params, $limit, $offset]);
        return $stmt->fetchAll();
    }

    private static function where(array $f): array
    {
        [$scope, $params] = Branch::scopeSql('r.branch_id');
        $where = [$scope];
        $q = (string) ($f['search'] ?? '');
        if ($q !== '') {
            $where[] = '(r.rr_no LIKE ? OR r.reference_no LIKE ? OR sp.name LIKE ? OR sp.code LIKE ?)';
            $like = like_pattern($q);
            array_push($params, $like, $like, $like, $like);
        }
        $status = (string) ($f['status'] ?? '');
        if (isset(self::STATUSES[$status])) {
            $where[]  = 'r.status = ?';
            $params[] = $status;
        }
        if (($f['from'] ?? null) !== null) {
            $where[]  = 'r.received_date >= ?';
            $params[] = $f['from'];
        }
        if (($f['to'] ?? null) !== null) {
            $where[]  = 'r.received_date <= ?';
            $params[] = $f['to'];
        }
        if (($f['supplier'] ?? null) !== null) {
            $where[]  = 'r.supplier_id = ?';
            $params[] = (int) $f['supplier'];
        }
        return [implode(' AND ', $where), $params];
    }

    /**
     * RR with 'items' (each with 'serials' = list of serial_no). Null when it doesn't exist,
     * 404 when it belongs to a branch outside the scope. Cost fields only with products.cost.
     */
    public static function find(int $id): ?array
    {
        self::requirePermission('receiving.view', 'You do not have permission to view receiving reports.');
        $stmt = db()->prepare(
            'SELECT r.*, sp.code AS supplier_code, sp.name AS supplier_name, sp.is_active AS supplier_active,
                    b.code AS branch_code, b.name AS branch_name, w.code AS warehouse_code, w.name AS warehouse_name,
                    l.code AS location_code, l.name AS location_name,
                    cu.full_name AS created_by_name, pu.full_name AS posted_by_name, xu.full_name AS cancelled_by_name
               FROM receiving_reports r
               JOIN suppliers sp ON sp.id = r.supplier_id
               JOIN branches b ON b.id = r.branch_id
               JOIN warehouses w ON w.id = r.warehouse_id
               JOIN storage_locations l ON l.id = r.location_id
               JOIN users cu ON cu.id = r.created_by
               LEFT JOIN users pu ON pu.id = r.posted_by
               LEFT JOIN users xu ON xu.id = r.cancelled_by
              WHERE r.id = ?'
        );
        $stmt->execute([$id]);
        $rr = $stmt->fetch();
        if (!$rr) {
            return null;
        }
        Branch::assertAccess((int) $rr['branch_id']);

        $stmt = db()->prepare(
            'SELECT ri.*, p.code AS product_code, p.name AS product_name, p.track_serial, p.is_active AS product_active,
                    un.code AS unit_code
               FROM receiving_items ri
               JOIN products p ON p.id = ri.product_id
               LEFT JOIN units un ON un.id = p.unit_id
              WHERE ri.receiving_id = ?
              ORDER BY ri.sort_order, ri.id'
        );
        $stmt->execute([$id]);
        $items = $stmt->fetchAll();

        $serials = [];
        if ($items) {
            $stmt = db()->prepare(
                'SELECT s.receiving_item_id, s.serial_no FROM receiving_item_serials s
                   JOIN receiving_items ri ON ri.id = s.receiving_item_id
                  WHERE ri.receiving_id = ?
                  ORDER BY s.id'
            );
            $stmt->execute([$id]);
            foreach ($stmt->fetchAll() as $s) {
                $serials[(int) $s['receiving_item_id']][] = (string) $s['serial_no'];
            }
        }
        $showCost = Auth::can('products.cost');
        foreach ($items as &$item) {
            $item['serials'] = $serials[(int) $item['id']] ?? [];
            if (!$showCost) {
                $item = array_diff_key($item, array_flip(self::COST_KEYS));
            }
        }
        unset($item);
        if (!$showCost) {
            $rr = array_diff_key($rr, array_flip(self::COST_KEYS));
        }
        $rr['items'] = $items;
        return $rr;
    }

    /** "RR-MAR-2026-000001" or "Draft #12" (audit refs, messages). */
    public static function label(array $rr): string
    {
        return $rr['rr_no'] ?? ('Draft #' . $rr['id']);
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    /**
     * Header: supplier_id, received_date, reference_no, notes.
     * Lines: items[i][product_id], items[i][quantity], items[i][unit_cost] (products.cost only),
     * items[i][serials] (textarea text, one per line, or a list). Fully blank lines are skipped.
     *
     * @return array{0: array, 1: array<string,string>} [clean data, errors keyed 'field' or 'items.i.field']
     *         clean items: list<array{product_id:int, quantity:int, unit_cost:?string, serials:list<string>}>
     *         unit_cost null = not entered / not allowed (saveDraft keeps the stored value without products.cost)
     */
    public static function validate(array $in): array
    {
        $errors = [];
        $data = [
            'supplier_id'   => input_int($in, 'supplier_id', 1),
            'received_date' => input_date($in, 'received_date'),
            'reference_no'  => input_string($in, 'reference_no', 50),
            'notes'         => input_string($in, 'notes', 500),
            'items'         => [],
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
        if ($data['received_date'] === null) {
            $errors['received_date'] = 'Enter the date the items were received.';
        } elseif ($data['received_date'] > date('Y-m-d')) {
            $errors['received_date'] = 'The received date cannot be in the future.';
        }
        $data['reference_no'] = $data['reference_no'] !== '' ? $data['reference_no'] : null;
        $data['notes']        = $data['notes'] !== '' ? $data['notes'] : null;

        $canCost = Auth::can('products.cost');
        $raw     = is_array($in['items'] ?? null) ? array_values($in['items']) : [];
        $lines   = [];
        foreach ($raw as $i => $line) {
            if (!is_array($line)) {
                continue;
            }
            $serialText = $line['serials'] ?? '';
            $serialList = is_array($serialText)
                ? array_filter($serialText, 'is_string')
                : (is_string($serialText) ? preg_split('/\R/', $serialText) : []);
            $serialList = array_values(array_filter(array_map('trim', $serialList), static fn ($s) => $s !== ''));
            $blank = static fn (string $k): bool => !isset($line[$k]) || (is_string($line[$k]) && trim($line[$k]) === '');
            if ($blank('product_id') && $blank('quantity') && $blank('unit_cost') && !$serialList) {
                continue; // empty template row
            }
            $lines[$i] = [$line, $serialList];
        }
        if (!$lines) {
            $errors['items'] = 'Add at least one item.';
        } elseif (count($lines) > self::MAX_LINES) {
            $errors['items'] = 'A receiving report can have at most ' . self::MAX_LINES . ' items.';
        }

        // Products of all lines in one query.
        $ids = [];
        foreach ($lines as [$line]) {
            $pid = input_int($line, 'product_id', 1);
            if ($pid !== null) {
                $ids[$pid] = true;
            }
        }
        $products = [];
        if ($ids) {
            $in_  = implode(',', array_fill(0, count($ids), '?'));
            $stmt = db()->prepare("SELECT id, name, is_active, track_serial FROM products WHERE id IN ({$in_})");
            $stmt->execute(array_keys($ids));
            foreach ($stmt->fetchAll() as $p) {
                $products[(int) $p['id']] = $p;
            }
        }

        $seen = [];
        foreach ($lines as $i => [$line, $serialList]) {
            $key  = "items.{$i}.";
            $pid  = input_int($line, 'product_id', 1);
            $p    = $pid !== null ? ($products[$pid] ?? null) : null;
            $qty  = input_int($line, 'quantity', 1, Products::MAX_STOCK);
            $item = ['product_id' => $pid, 'quantity' => $qty, 'unit_cost' => null, 'serials' => []];

            if ($p === null || (int) $p['is_active'] !== 1) {
                $errors[$key . 'product_id'] = 'Choose an active product.';
            } elseif (isset($seen[$pid])) {
                $errors[$key . 'product_id'] = "{$p['name']} is already on this report. Use one line per product.";
            }
            if ($pid !== null) {
                $seen[$pid] = true;
            }
            if ($qty === null) {
                $errors[$key . 'quantity'] = 'Enter a whole number from 1 to ' . number_format(Products::MAX_STOCK) . '.';
            }
            if ($canCost) {
                $rawCost = $line['unit_cost'] ?? '';
                if (is_string($rawCost)) {
                    $rawCost = str_replace(',', '', $rawCost); // "30,000.50" (thousands separators)
                }
                if (!(is_string($rawCost) && trim($rawCost) === '') && $rawCost !== null) {
                    $cost = input_decimal(['unit_cost' => $rawCost], 'unit_cost', 0, self::MAX_COST, 4);
                    if ($cost === null) {
                        $errors[$key . 'unit_cost'] = 'Enter a cost from 0.00 to 999,999.9999 (up to 4 decimals).';
                    } else {
                        $item['unit_cost'] = Costing::format(Costing::toUnits(trim((string) $rawCost)));
                    }
                }
            }

            if ($serialList) {
                if ($p !== null && (int) $p['track_serial'] !== 1) {
                    $errors[$key . 'serials'] = "{$p['name']} does not use serial numbers. Remove the serials.";
                } else {
                    $clean = [];
                    $bad   = [];
                    foreach ($serialList as $s) {
                        $n = Serials::normalize($s);
                        if ($n === null) {
                            $bad[] = mb_substr($s, 0, 60);
                        } elseif (isset($clean[$n])) {
                            $errors[$key . 'serials'] = "Serial {$n} is entered twice.";
                        } else {
                            $clean[$n] = true;
                        }
                    }
                    if ($bad) {
                        $errors[$key . 'serials'] = 'Invalid serial number: ' . implode(', ', array_slice($bad, 0, 5))
                            . '. Use letters, numbers, dot, dash, slash or underscore (max 60).';
                    } elseif ($qty !== null && count($clean) > $qty) {
                        $errors[$key . 'serials'] = 'There are ' . count($clean) . " serial numbers for a quantity of {$qty}.";
                    }
                    $item['serials'] = array_keys($clean);
                }
            }
            $data['items'][] = $item;
        }

        return [$data, $errors];
    }

    // ------------------------------------------------------------------
    // Drafts (receiving.manage)
    // ------------------------------------------------------------------

    /**
     * Lock an RR row (FOR UPDATE): 404 when missing or out of scope,
     * 422 unless the session is working in the RR's own branch.
     */
    private static function lock(int $id): array
    {
        $stmt = db()->prepare('SELECT * FROM receiving_reports WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $rr = $stmt->fetch() ?: throw new HttpException(404, 'Receiving report not found.');
        Branch::assertAccess((int) $rr['branch_id']);
        if (Branch::current() !== (int) $rr['branch_id']) {
            throw new HttpException(422, 'Switch to branch ' . self::branchName((int) $rr['branch_id']) . ' first.');
        }
        return $rr;
    }

    /**
     * Create (id null) or replace a draft at the current branch. $d comes from validate().
     * Without products.cost, stored line costs are kept per product.
     */
    public static function saveDraft(?int $id, array $d, int $userId): int
    {
        self::requirePermission('receiving.manage', 'You do not have permission to edit receiving reports.');
        $branchId = $id === null ? Branch::forWrite() : 0; // update: the RR's own branch (checked by lock())
        $canCost  = Auth::can('products.cost');
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $oldCosts = [];
            if ($id === null) {
                $location = Branch::defaultLocation($branchId);
                $pdo->prepare(
                    'INSERT INTO receiving_reports (branch_id, warehouse_id, location_id, supplier_id, reference_no,
                                                    received_date, notes, status, total_qty, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $branchId, $location['warehouse_id'], $location['id'], $d['supplier_id'], $d['reference_no'],
                    $d['received_date'], $d['notes'], 'draft', 0, $userId,
                ]);
                $id = (int) $pdo->lastInsertId();
                $before = null;
            } else {
                $rr = self::lock($id);
                if ($rr['status'] !== 'draft') {
                    throw new HttpException(409, self::label($rr) . ' is ' . strtolower(self::STATUSES[$rr['status']]) . ' and can no longer be edited.');
                }
                $branchId = (int) $rr['branch_id'];
                $stmt = $pdo->prepare('SELECT product_id, unit_cost FROM receiving_items WHERE receiving_id = ?');
                $stmt->execute([$id]);
                $oldCosts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
                $before = self::auditValues($rr, self::auditLines($id));
                $pdo->prepare(
                    'UPDATE receiving_reports SET supplier_id = ?, reference_no = ?, received_date = ?, notes = ? WHERE id = ?'
                )->execute([$d['supplier_id'], $d['reference_no'], $d['received_date'], $d['notes'], $id]);
                $pdo->prepare('DELETE FROM receiving_items WHERE receiving_id = ?')->execute([$id]); // serials cascade
            }

            $insItem   = $pdo->prepare(
                'INSERT INTO receiving_items (receiving_id, product_id, quantity, unit_cost, sort_order) VALUES (?, ?, ?, ?, ?)'
            );
            $insSerial = $pdo->prepare('INSERT INTO receiving_item_serials (receiving_item_id, serial_no) VALUES (?, ?)');
            $totalQty  = 0;
            foreach (array_values($d['items']) as $n => $item) {
                $cost = $canCost ? $item['unit_cost'] : ($oldCosts[$item['product_id']] ?? null);
                $insItem->execute([$id, $item['product_id'], $item['quantity'], $cost, $n + 1]);
                $itemId = (int) $pdo->lastInsertId();
                foreach ($item['serials'] as $serial) {
                    $insSerial->execute([$itemId, $serial]);
                }
                $totalQty += $item['quantity'];
            }
            $pdo->prepare('UPDATE receiving_reports SET total_qty = ? WHERE id = ?')->execute([$totalQty, $id]);

            $after = self::auditValues(['supplier_id' => $d['supplier_id'], 'reference_no' => $d['reference_no'],
                'received_date' => $d['received_date'], 'notes' => $d['notes'], 'total_qty' => $totalQty], self::auditLines($id));
            if ($before === null) {
                Audit::record('receiving', 'create', 'receiving', $id, 'Draft #' . $id, null, $after, $branchId);
            } else {
                [$old, $new] = Audit::diff($before, $after);
                if ($new) {
                    Audit::record('receiving', 'update', 'receiving', $id, 'Draft #' . $id, $old, $new, $branchId);
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

    /** @return array the deleted draft header */
    public static function deleteDraft(int $id): array
    {
        self::requirePermission('receiving.manage', 'You do not have permission to edit receiving reports.');
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $rr = self::lock($id);
            if ($rr['status'] !== 'draft') {
                throw new HttpException(409, 'Only drafts can be deleted. ' . self::label($rr) . ' is ' . strtolower(self::STATUSES[$rr['status']]) . '.');
            }
            $before = self::auditValues($rr, self::auditLines($id));
            $pdo->prepare('DELETE FROM receiving_reports WHERE id = ?')->execute([$id]); // items + serials cascade
            Audit::record('receiving', 'delete', 'receiving', $id, 'Draft #' . $id, $before, null, (int) $rr['branch_id']);
            $pdo->commit();
            return $rr;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // ------------------------------------------------------------------
    // Post (receiving.post + products.cost)
    // ------------------------------------------------------------------

    /** Post a draft: number it, add the stock, re-average the cost, create the serials. @return string rr_no */
    public static function post(int $id, int $userId): string
    {
        if (!Auth::can('receiving.post') || !Auth::can('products.cost')) {
            throw new HttpException(403, 'You do not have permission to post receiving reports.');
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $rr = self::lock($id);
            if ($rr['status'] !== 'draft') {
                throw new HttpException(409, self::label($rr) . ' is already ' . strtolower(self::STATUSES[$rr['status']]) . '.');
            }
            $branchId = (int) $rr['branch_id'];
            $items    = self::lockedItems($id);
            if (!$items) {
                throw new HttpException(422, 'Add at least one item before posting.');
            }
            $problems = [];
            foreach ($items as $item) {
                if ($item['unit_cost'] === null) {
                    $problems[] = "Enter the cost of {$item['product_name']}.";
                }
            }
            if ($problems) {
                throw new HttpException(422, implode(' ', $problems), ['problems' => $problems]);
            }

            // Number: locked sequence row (a rollback releases the number).
            $year = (int) date('Y');
            $pdo->prepare(
                'INSERT INTO document_sequences (branch_id, doc_type, year, last_no) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE last_no = last_no'
            )->execute([$branchId, self::DOC_TYPE, $year, 0]);
            $stmt = $pdo->prepare('SELECT last_no FROM document_sequences WHERE branch_id = ? AND doc_type = ? AND year = ? FOR UPDATE');
            $stmt->execute([$branchId, self::DOC_TYPE, $year]);
            $next = (int) $stmt->fetchColumn() + 1;
            $pdo->prepare('UPDATE document_sequences SET last_no = ? WHERE branch_id = ? AND doc_type = ? AND year = ?')
                ->execute([$next, $branchId, self::DOC_TYPE, $year]);
            $stmt = $pdo->prepare('SELECT code FROM branches WHERE id = ?');
            $stmt->execute([$branchId]);
            $rrNo = sprintf('RR-%s-%04d-%06d', (string) $stmt->fetchColumn(), $year, $next);

            $products = self::lockProducts(array_column($items, 'product_id'));
            foreach ($items as $item) {
                $p   = $products[(int) $item['product_id']] ?? null;
                $qty = (int) $item['quantity'];
                if ($p === null || (int) $p['is_active'] !== 1) {
                    $problems[] = "{$item['product_name']} is no longer active.";
                } elseif ((int) $p['track_serial'] === 1 && count($item['serials']) !== $qty) {
                    $problems[] = "{$p['name']}: enter exactly {$qty} serial number" . ($qty === 1 ? '' : 's') . ' (' . count($item['serials']) . ' entered).';
                } elseif ((int) $p['track_serial'] !== 1 && $item['serials']) {
                    $problems[] = "{$p['name']} does not use serial numbers. Remove the serials.";
                }
            }
            if ($problems) {
                throw new HttpException(422, implode(' ', $problems), ['problems' => $problems]);
            }

            $location = [
                'id' => (int) $rr['location_id'], 'warehouse_id' => (int) $rr['warehouse_id'],
                'branch_id' => $branchId, 'branch_name' => self::branchName($branchId),
            ];
            $updItem = $pdo->prepare(
                'UPDATE receiving_items SET qty_before = ?, avg_cost_before = ?, avg_cost_after = ?, line_total = ? WHERE id = ?'
            );
            $totalQty   = 0;
            $totalCents = 0;
            foreach ($items as $item) { // already in product id order
                $pid = (int) $item['product_id'];
                $qty = (int) $item['quantity'];
                if (Costing::branchQty($pid, $branchId) + $qty > Products::MAX_STOCK) {
                    throw new HttpException(422, "{$item['product_name']}: stock at the branch can be at most " . number_format(Products::MAX_STOCK) . '.');
                }
                $cost = Costing::format(Costing::toUnits((string) $item['unit_cost']));
                $avg  = Costing::inbound($pid, $branchId, $qty, $cost);
                Stock::move($pid, $location, $qty, 'receiving', $rrNo, null, $userId, $id);
                $cents = Costing::lineCents($qty, $cost);
                $updItem->execute([$avg['qty_before'], $avg['before'], $avg['after'], from_cents($cents), (int) $item['id']]);
                $totalQty   += $qty;
                $totalCents += $cents;
            }

            // Serials last (lock order). Pre-check for a friendly message; the UNIQUE key is the real guard.
            $serialCount = 0;
            $insSerial = $pdo->prepare(
                'INSERT INTO product_serials (product_id, serial_no, branch_id, warehouse_id, location_id, status, receiving_item_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($items as $item) {
                if (!$item['serials']) {
                    continue;
                }
                $in_  = implode(',', array_fill(0, count($item['serials']), '?'));
                $stmt = $pdo->prepare("SELECT serial_no FROM product_serials WHERE product_id = ? AND serial_no IN ({$in_}) ORDER BY serial_no");
                $stmt->execute([(int) $item['product_id'], ...$item['serials']]);
                $dupes = $stmt->fetchAll(PDO::FETCH_COLUMN);
                if ($dupes) {
                    throw new HttpException(409, "{$item['product_name']}: serial number" . (count($dupes) === 1 ? ' ' : 's ')
                        . implode(', ', array_slice($dupes, 0, 10)) . (count($dupes) === 1 ? ' is' : ' are') . ' already registered.',
                        ['duplicates' => $dupes]);
                }
                try {
                    foreach ($item['serials'] as $serial) {
                        $insSerial->execute([(int) $item['product_id'], $serial, $branchId, $location['warehouse_id'],
                            $location['id'], 'in_stock', (int) $item['id']]);
                        $serialCount++;
                    }
                } catch (PDOException $e) {
                    if ($e->getCode() === '23000') {
                        throw new HttpException(409, "{$item['product_name']}: a serial number on this report is already registered.");
                    }
                    throw $e;
                }
            }

            $pdo->prepare(
                'UPDATE receiving_reports SET rr_no = ?, status = ?, total_qty = ?, total_cost = ?, posted_at = NOW(), posted_by = ?
                  WHERE id = ?'
            )->execute([$rrNo, 'posted', $totalQty, from_cents($totalCents), $userId, $id]);

            Audit::record('receiving', 'post', 'receiving', $id, $rrNo, ['status' => 'draft'],
                ['status' => 'posted', 'rr_no' => $rrNo, 'lines' => count($items), 'total_qty' => $totalQty, 'serials' => $serialCount],
                $branchId);
            $pdo->commit();
            return $rrNo;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // ------------------------------------------------------------------
    // Cancel (receiving.cancel)
    // ------------------------------------------------------------------

    /** Reverse a posted RR whose stock, cost and serials are untouched since it was posted. */
    public static function cancel(int $id, int $userId, string $reason): void
    {
        self::requirePermission('receiving.cancel', 'You do not have permission to cancel receiving reports.');
        $reason = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $reason) ?? '');
        $len = mb_strlen($reason);
        if ($len < 3 || $len > 255) {
            throw new HttpException(422, 'Enter a reason (3–255 characters).');
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $rr = self::lock($id);
            if ($rr['status'] !== 'posted') {
                throw new HttpException(409, $rr['status'] === 'cancelled'
                    ? self::label($rr) . ' is already cancelled.'
                    : 'Only posted receiving reports can be cancelled.');
            }
            $rrNo     = (string) $rr['rr_no'];
            $branchId = (int) $rr['branch_id'];
            $items    = self::lockedItems($id);
            self::lockProducts(array_column($items, 'product_id'));

            $moved = static fn (string $name): HttpException =>
                new HttpException(409, "Stock of {$name} has moved since {$rrNo}, so it cannot be cancelled.");
            $lastMove = $pdo->prepare(
                'SELECT MAX(id) FROM stock_movements WHERE receiving_id = ? AND product_id = ? AND type = ?'
            );
            $later = $pdo->prepare('SELECT 1 FROM stock_movements WHERE product_id = ? AND branch_id = ? AND id > ? LIMIT 1');
            foreach ($items as $item) {
                $pid = (int) $item['product_id'];
                Costing::branchQty($pid, $branchId); // lock stock_balances before product_branches
                $lastMove->execute([$id, $pid, 'receiving']);
                $moveId = $lastMove->fetchColumn();
                if ($moveId === false || $moveId === null) {
                    throw $moved($item['product_name']);
                }
                $later->execute([$pid, $branchId, (int) $moveId]);
                if ($later->fetchColumn()) {
                    throw $moved($item['product_name']);
                }
                if ($item['avg_cost_after'] === null
                    || Costing::toUnits(Costing::avg($pid, $branchId)) !== Costing::toUnits((string) $item['avg_cost_after'])) {
                    throw $moved($item['product_name']);
                }
            }

            // Serials last: all still in stock at the RR location.
            $itemIds = array_map('intval', array_column($items, 'id'));
            $in_     = implode(',', array_fill(0, count($itemIds), '?'));
            $stmt    = $pdo->prepare(
                "SELECT id, receiving_item_id, status, location_id FROM product_serials
                  WHERE receiving_item_id IN ({$in_}) ORDER BY id FOR UPDATE"
            );
            $stmt->execute($itemIds);
            $perItem = [];
            foreach ($stmt->fetchAll() as $s) {
                $perItem[(int) $s['receiving_item_id']] = ($perItem[(int) $s['receiving_item_id']] ?? 0) + 1;
                if ($s['status'] !== 'in_stock' || (int) $s['location_id'] !== (int) $rr['location_id']) {
                    $name = '';
                    foreach ($items as $item) {
                        if ((int) $item['id'] === (int) $s['receiving_item_id']) {
                            $name = $item['product_name'];
                        }
                    }
                    throw $moved($name);
                }
            }
            foreach ($items as $item) {
                if (count($item['serials']) !== ($perItem[(int) $item['id']] ?? 0)) {
                    throw $moved($item['product_name']);
                }
            }

            $location = [
                'id' => (int) $rr['location_id'], 'warehouse_id' => (int) $rr['warehouse_id'],
                'branch_id' => $branchId, 'branch_name' => self::branchName($branchId),
            ];
            $note = "Cancelled {$rrNo}: {$reason}";
            foreach ($items as $item) {
                $pid = (int) $item['product_id'];
                Stock::move($pid, $location, -(int) $item['quantity'], 'receiving', $note, null, $userId, $id);
                Costing::set($pid, $branchId, (string) $item['avg_cost_before']);
            }
            try {
                $pdo->prepare("DELETE FROM product_serials WHERE receiving_item_id IN ({$in_})")->execute($itemIds);
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') { // a serial is linked to a sale
                    throw new HttpException(409, "Serials of {$rrNo} have been sold since it was posted, so it cannot be cancelled.");
                }
                throw $e;
            }
            $pdo->prepare(
                'UPDATE receiving_reports SET status = ?, cancelled_at = NOW(), cancelled_by = ?, cancel_reason = ? WHERE id = ?'
            )->execute(['cancelled', $userId, $reason, $id]);

            Audit::record('receiving', 'cancel', 'receiving', $id, $rrNo, ['status' => 'posted'],
                ['status' => 'cancelled', 'reason' => $reason, 'total_qty' => (int) $rr['total_qty']], $branchId);
            $pdo->commit();
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

    /** Items of an RR in product id order with their serials (header already locked). */
    private static function lockedItems(int $id): array
    {
        $stmt = db()->prepare(
            'SELECT ri.*, p.name AS product_name FROM receiving_items ri JOIN products p ON p.id = ri.product_id
              WHERE ri.receiving_id = ? ORDER BY ri.product_id'
        );
        $stmt->execute([$id]);
        $items = $stmt->fetchAll();
        $stmt  = db()->prepare('SELECT serial_no FROM receiving_item_serials WHERE receiving_item_id = ? ORDER BY id');
        foreach ($items as &$item) {
            $stmt->execute([(int) $item['id']]);
            $item['serials'] = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }
        unset($item);
        return $items;
    }

    /** One SELECT ... ORDER BY id FOR UPDATE over the products. @return array<int,array> */
    private static function lockProducts(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        sort($ids);
        $in_  = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare("SELECT id, code, name, is_active, track_serial FROM products WHERE id IN ({$in_}) ORDER BY id FOR UPDATE");
        $stmt->execute($ids);
        $out = [];
        foreach ($stmt->fetchAll() as $p) {
            $out[(int) $p['id']] = $p;
        }
        return $out;
    }

    private static function branchName(int $branchId): string
    {
        $stmt = db()->prepare('SELECT name FROM branches WHERE id = ?');
        $stmt->execute([$branchId]);
        return (string) $stmt->fetchColumn();
    }

    /** Lines for the audit log: product, qty, serial count (never cost). */
    private static function auditLines(int $id): array
    {
        $stmt = db()->prepare(
            'SELECT p.code, ri.quantity, (SELECT COUNT(*) FROM receiving_item_serials s WHERE s.receiving_item_id = ri.id) AS serials
               FROM receiving_items ri JOIN products p ON p.id = ri.product_id
              WHERE ri.receiving_id = ? ORDER BY ri.sort_order, ri.id'
        );
        $stmt->execute([$id]);
        return array_map(
            static fn (array $r): string => $r['code'] . ' x' . $r['quantity'] . ((int) $r['serials'] > 0 ? ' (' . $r['serials'] . ' S/N)' : ''),
            $stmt->fetchAll()
        );
    }

    private static function auditValues(array $rr, array $lines): array
    {
        return [
            'supplier_id'   => (int) $rr['supplier_id'],
            'reference_no'  => $rr['reference_no'],
            'received_date' => $rr['received_date'],
            'notes'         => $rr['notes'],
            'total_qty'     => (int) $rr['total_qty'],
            'items'         => $lines,
        ];
    }
}
