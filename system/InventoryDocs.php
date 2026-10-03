<?php
/**
 * Stock documents of a branch (inventory_docs + lines + serials), audit module 'inventory'.
 *
 *   transfer  TRF  location -> location of the same branch, posted at once. Purpose comes from the
 *                  location kinds (never from the browser):
 *                    stock -> stock (different location)              = move     (inventory.transfer)
 *                    stock/display -> DAMAGED of the same warehouse    = damage   (inventory.damage, reason)
 *                    stock -> DISPLAY of the same warehouse            = display  (inventory.issue, reason)
 *                    damaged/display -> a stock location               = restore  (inventory.transfer)
 *                  Branch qty and average cost are unchanged; serials follow the stock.
 *   issue     ISS  internal use from a stock/display location (inventory.issue, reason), posted at once.
 *   writeoff  WOF  write-off from any location, normally DAMAGED (inventory.damage, reason), posted at once.
 *                  Issue / write-off: unit_cost = branch average snapshot, serials -> 'removed'.
 *   count     CNT  open -> submitted -> posted | cancelled (counts.create / counts.approve).
 *                  system_qty is frozen at creation; posting moves counted - frozen, so sales made
 *                  during the count stay correct. Serial items: expected serials not found -> 'removed'.
 *
 * Writes happen in the current branch (Branch::forWrite); an existing document needs the session in
 * its branch (other branch -> 404, "All branches" -> 422).
 * Lock order: inventory_docs row (counts) or storage_locations rows -> warehouses -> document_sequences ->
 * products (one SELECT ... ORDER BY id FOR UPDATE) -> per product in id order: stock_balances ->
 * product_branches -> product_serials last (ORDER BY id FOR UPDATE).
 * Cost (unit_cost, total_cost, line_value) only leaves this class with products.cost and never goes to the audit log.
 */
declare(strict_types=1);

final class InventoryDocs
{
    public const TYPES    = ['transfer' => 'Transfer', 'issue' => 'Internal use', 'writeoff' => 'Write-off', 'count' => 'Stock count'];
    public const PURPOSES = ['move' => 'Move', 'damage' => 'Mark damaged', 'display' => 'Display unit', 'restore' => 'Restore'];
    public const STATUSES = ['open' => 'Open', 'submitted' => 'Submitted', 'posted' => 'Posted', 'cancelled' => 'Cancelled'];
    public const PREFIXES = ['transfer' => 'TRF', 'issue' => 'ISS', 'writeoff' => 'WOF', 'count' => 'CNT'];

    /** stock_movements.type per document type. */
    public const MOVEMENT_TYPES = ['transfer' => 'transfer', 'issue' => 'issue', 'writeoff' => 'write_off', 'count' => 'count'];

    /** Permission needed per transfer purpose. */
    public const PURPOSE_PERMISSIONS = [
        'move' => 'inventory.transfer', 'damage' => 'inventory.damage', 'display' => 'inventory.issue', 'restore' => 'inventory.transfer',
    ];

    /** Any of these opens the Stock Operations list / documents. */
    public const VIEW_PERMISSIONS = ['inventory.transfer', 'inventory.damage', 'inventory.issue', 'counts.create', 'counts.approve'];

    public const MAX_LINES       = 100;
    public const MAX_COUNT_LINES = 500;

    private const COST_KEYS = ['unit_cost', 'total_cost', 'line_value'];

    // ------------------------------------------------------------------
    // Permissions
    // ------------------------------------------------------------------

    private static function requirePermission(string $permission, string $message): void
    {
        if (!Auth::can($permission)) {
            throw new HttpException(403, $message);
        }
    }

    private static function requireView(): void
    {
        if (!Auth::canAny(...self::VIEW_PERMISSIONS)) {
            throw new HttpException(403, 'You do not have permission to view stock documents.');
        }
    }

    // ------------------------------------------------------------------
    // Listing / lookup (current branch scope)
    // ------------------------------------------------------------------

    /** @param array{search?:string, type?:string, status?:string, from?:?string, to?:?string} $f */
    public static function count(array $f): int
    {
        self::requireView();
        [$where, $params] = self::where($f);
        $stmt = db()->prepare("SELECT COUNT(*) FROM inventory_docs d WHERE {$where}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public static function search(array $f, int $limit, int $offset): array
    {
        self::requireView();
        [$where, $params] = self::where($f);
        $cost = Auth::can('products.cost') ? 'd.total_cost, ' : '';
        $stmt = db()->prepare(
            "SELECT d.id, d.doc_no, d.doc_type, d.purpose, d.status, d.reason, d.total_qty, {$cost}
                    d.created_at, d.submitted_at, d.posted_at, d.cancelled_at, d.branch_id,
                    b.code AS branch_code, b.name AS branch_name,
                    fw.code AS from_warehouse_code, fl.code AS from_location_code, fl.name AS from_location_name,
                    tw.code AS to_warehouse_code, tl.code AS to_location_code, tl.name AS to_location_name,
                    u.full_name AS created_by_name,
                    (SELECT COUNT(*) FROM inventory_doc_lines dl WHERE dl.doc_id = d.id) AS line_count
               FROM inventory_docs d
               JOIN branches b ON b.id = d.branch_id
               JOIN warehouses fw ON fw.id = d.from_warehouse_id
               JOIN storage_locations fl ON fl.id = d.from_location_id
               LEFT JOIN warehouses tw ON tw.id = d.to_warehouse_id
               LEFT JOIN storage_locations tl ON tl.id = d.to_location_id
               JOIN users u ON u.id = d.created_by
              WHERE {$where}
              ORDER BY d.status IN ('open', 'submitted') DESC, d.created_at DESC, d.id DESC
              LIMIT ? OFFSET ?"
        );
        $stmt->execute([...$params, $limit, $offset]);
        return $stmt->fetchAll();
    }

    private static function where(array $f): array
    {
        [$scope, $params] = Branch::scopeSql('d.branch_id');
        $where = [$scope];
        $type = (string) ($f['type'] ?? '');
        if (isset(self::TYPES[$type])) {
            $where[]  = 'd.doc_type = ?';
            $params[] = $type;
        }
        $status = (string) ($f['status'] ?? '');
        if (isset(self::STATUSES[$status])) {
            $where[]  = 'd.status = ?';
            $params[] = $status;
        }
        if (($f['from'] ?? null) !== null) {
            $where[]  = 'd.created_at >= ?';
            $params[] = $f['from'] . ' 00:00:00';
        }
        if (($f['to'] ?? null) !== null) {
            $where[]  = 'd.created_at < ?';
            $params[] = (new DateTimeImmutable((string) $f['to']))->modify('+1 day')->format('Y-m-d 00:00:00');
        }
        $q = (string) ($f['search'] ?? '');
        if ($q !== '') {
            $like = like_pattern($q);
            $where[] = '(d.doc_no LIKE ? OR d.reason LIKE ? OR EXISTS (SELECT 1 FROM inventory_doc_lines dl
                            JOIN products p ON p.id = dl.product_id
                           WHERE dl.doc_id = d.id AND (p.code LIKE ? OR p.name LIKE ?)))';
            array_push($params, $like, $like, $like, $like);
        }
        return [implode(' AND ', $where), $params];
    }

    /**
     * Document with 'lines' (each with 'serials' = list of {id, serial_no, found, status}, and for counts
     * 'variance' = counted - system while not posted). Null when missing; 404 outside the scope.
     * unit_cost / total_cost / line_value only with products.cost.
     */
    public static function find(int $id): ?array
    {
        self::requireView();
        $stmt = db()->prepare(
            'SELECT d.*, b.code AS branch_code, b.name AS branch_name,
                    fw.code AS from_warehouse_code, fw.name AS from_warehouse_name,
                    fl.code AS from_location_code, fl.name AS from_location_name, fl.kind AS from_location_kind,
                    tw.code AS to_warehouse_code, tw.name AS to_warehouse_name,
                    tl.code AS to_location_code, tl.name AS to_location_name, tl.kind AS to_location_kind,
                    cu.full_name AS created_by_name, su.full_name AS submitted_by_name,
                    pu.full_name AS posted_by_name, xu.full_name AS cancelled_by_name
               FROM inventory_docs d
               JOIN branches b ON b.id = d.branch_id
               JOIN warehouses fw ON fw.id = d.from_warehouse_id
               JOIN storage_locations fl ON fl.id = d.from_location_id
               LEFT JOIN warehouses tw ON tw.id = d.to_warehouse_id
               LEFT JOIN storage_locations tl ON tl.id = d.to_location_id
               JOIN users cu ON cu.id = d.created_by
               LEFT JOIN users su ON su.id = d.submitted_by
               LEFT JOIN users pu ON pu.id = d.posted_by
               LEFT JOIN users xu ON xu.id = d.cancelled_by
              WHERE d.id = ?'
        );
        $stmt->execute([$id]);
        $doc = $stmt->fetch();
        if (!$doc) {
            return null;
        }
        Branch::assertAccess((int) $doc['branch_id']);

        $stmt = db()->prepare(
            'SELECT dl.*, p.code AS product_code, p.name AS product_name, p.track_serial, p.is_active AS product_active,
                    un.code AS unit_code
               FROM inventory_doc_lines dl
               JOIN products p ON p.id = dl.product_id
               LEFT JOIN units un ON un.id = p.unit_id
              WHERE dl.doc_id = ?
              ORDER BY dl.sort_order, dl.id'
        );
        $stmt->execute([$id]);
        $lines = $stmt->fetchAll();

        $serials = [];
        if ($lines) {
            $stmt = db()->prepare(
                'SELECT ds.line_id, ds.found, ps.id, ps.serial_no, ps.status
                   FROM inventory_doc_serials ds
                   JOIN inventory_doc_lines dl ON dl.id = ds.line_id
                   JOIN product_serials ps ON ps.id = ds.serial_id
                  WHERE dl.doc_id = ?
                  ORDER BY ps.serial_no, ps.id'
            );
            $stmt->execute([$id]);
            foreach ($stmt->fetchAll() as $s) {
                $serials[(int) $s['line_id']][] = [
                    'id' => (int) $s['id'], 'serial_no' => (string) $s['serial_no'],
                    'found' => (int) $s['found'], 'status' => (string) $s['status'],
                ];
            }
        }
        $showCost = Auth::can('products.cost');
        foreach ($lines as &$line) {
            $line['serials'] = $serials[(int) $line['id']] ?? [];
            $line['variance'] = $doc['doc_type'] === 'count' && $line['counted_qty'] !== null
                ? (int) $line['counted_qty'] - (int) $line['system_qty'] : null;
            if ($doc['status'] === 'posted' && $doc['doc_type'] === 'count') {
                $line['variance'] = $line['adjust_qty'] !== null ? (int) $line['adjust_qty'] : null;
            }
            $qty = $doc['doc_type'] === 'count' ? (int) ($line['adjust_qty'] ?? 0) : (int) ($line['quantity'] ?? 0);
            $line['line_value'] = $line['unit_cost'] !== null
                ? from_cents(($qty < 0 ? -1 : 1) * Costing::lineCents(abs($qty), (string) $line['unit_cost'])) : null;
            if (!$showCost) {
                $line = array_diff_key($line, array_flip(self::COST_KEYS));
            }
        }
        unset($line);
        if (!$showCost) {
            $doc = array_diff_key($doc, array_flip(self::COST_KEYS));
        }
        $doc['lines'] = $lines;
        return $doc;
    }

    /** "TRF-MAR-2026-000001" (all documents are numbered at creation). */
    public static function label(array $doc): string
    {
        return (string) ($doc['doc_no'] ?? ('#' . ($doc['id'] ?? '')));
    }

    /** Readable kind of document: "Transfer (Mark damaged)", "Write-off", ... */
    public static function typeLabel(array $doc): string
    {
        $type = self::TYPES[$doc['doc_type']] ?? (string) $doc['doc_type'];
        $purpose = $doc['purpose'] ?? null;
        return $purpose !== null && isset(self::PURPOSES[$purpose]) ? $type . ' (' . self::PURPOSES[$purpose] . ')' : $type;
    }

    /**
     * What the signed-in user may do with a count now (for buttons; the actions re-check everything).
     * @return array{save:bool, submit:bool, approve:bool, cancel:bool}
     */
    public static function actions(array $doc): array
    {
        $none = ['save' => false, 'submit' => false, 'approve' => false, 'cancel' => false];
        if ($doc['doc_type'] !== 'count' || Branch::current() !== (int) $doc['branch_id']) {
            return $none;
        }
        $uid  = Auth::id();
        $open = $doc['status'] === 'open';
        $sub  = $doc['status'] === 'submitted';
        return [
            'save'    => $open && Auth::can('counts.create'),
            'submit'  => $open && Auth::can('counts.create'),
            'approve' => $sub && Auth::can('counts.approve')
                         && $uid !== (int) $doc['created_by'] && $uid !== (int) ($doc['submitted_by'] ?? 0)
                         && !self::hasCounted((int) $doc['id'], (int) $uid),
            'cancel'  => ($open && (Auth::can('counts.approve') || (Auth::can('counts.create') && $uid === (int) $doc['created_by'])))
                         || ($sub && Auth::can('counts.approve')),
        ];
    }

    // ------------------------------------------------------------------
    // One-step documents: transfer / issue / write-off
    // ------------------------------------------------------------------

    /** Purpose of a transfer from the location kinds. @throws HttpException 422 for a combination that isn't allowed */
    public static function purpose(array $from, array $to): string
    {
        if ((int) $from['id'] === (int) $to['id']) {
            throw new HttpException(422, 'Choose a destination different from the source location.');
        }
        $sameWarehouse = (int) $from['warehouse_id'] === (int) $to['warehouse_id'];
        if ($to['kind'] === 'stock') {
            return $from['kind'] === 'stock' ? 'move' : 'restore';
        }
        if ($to['kind'] === 'damaged' && $from['kind'] !== 'damaged') {
            return $sameWarehouse ? 'damage' : throw new HttpException(422, 'Use the DAMAGED location of the same warehouse.');
        }
        if ($to['kind'] === 'display' && $from['kind'] === 'stock') {
            return $sameWarehouse ? 'display' : throw new HttpException(422, 'Use the DISPLAY location of the same warehouse.');
        }
        throw new HttpException(422, 'Damaged and display stock can only go back to a stock location.');
    }

    /**
     * Validate a transfer / issue / write-off form at the current branch (no locks; the post re-checks
     * everything). Fields: from_location_id, to_location_id (transfer), reason,
     * items[i][product_id], items[i][quantity], items[i][serial_ids][] (track_serial products).
     * Fully blank rows are skipped. Throws 403 when the user may not make this kind of document.
     *
     * @return array{0: array{type:string, purpose:?string, from_location_id:?int, to_location_id:?int, reason:?string,
     *                        items: list<array{product_id:?int, quantity:?int, serial_ids:list<int>}>},
     *               1: array<string,string>} errors keyed 'from_location_id', 'to_location_id', 'reason', 'items',
     *               'items.i.product_id', 'items.i.quantity', 'items.i.serial_ids'
     */
    public static function validate(string $type, array $in): array
    {
        if (!in_array($type, ['transfer', 'issue', 'writeoff'], true)) {
            throw new LogicException("Unknown one-step document type [{$type}]");
        }
        $branchId = Branch::forWrite();
        $errors = [];
        $data = [
            'type'             => $type,
            'purpose'          => null,
            'from_location_id' => input_int($in, 'from_location_id', 1),
            'to_location_id'   => $type === 'transfer' ? input_int($in, 'to_location_id', 1) : null,
            'reason'           => self::cleanReason(is_string($in['reason'] ?? null) ? $in['reason'] : ''),
            'items'            => [],
        ];
        $locs = self::locationRows(array_filter([$data['from_location_id'], $data['to_location_id']]), false);
        $from = self::usable($locs[$data['from_location_id'] ?? 0] ?? null, $branchId);
        $to   = $type === 'transfer' ? self::usable($locs[$data['to_location_id'] ?? 0] ?? null, $branchId) : null;
        if ($from === null) {
            $errors['from_location_id'] = 'Choose an active location of this branch.';
        }
        if ($type === 'transfer' && $to === null) {
            $errors['to_location_id'] = 'Choose an active location of this branch.';
        }

        $permission = self::typePermission($type, null);
        if ($type === 'transfer' && $from !== null && $to !== null) {
            try {
                $data['purpose'] = self::purpose($from, $to);
                $permission = self::PURPOSE_PERMISSIONS[$data['purpose']];
            } catch (HttpException $e) {
                $errors['to_location_id'] = $e->getMessage();
            }
        }
        if ($permission !== null) {
            self::requirePermission($permission, self::permissionMessage($type, $data['purpose']));
        } elseif (!Auth::canAny('inventory.transfer', 'inventory.damage', 'inventory.issue')) {
            throw new HttpException(403, 'You do not have permission to move stock.');
        }
        if ($type === 'issue' && $from !== null && $from['kind'] === 'damaged') {
            $errors['from_location_id'] = 'Internal use is issued from a stock or display location.';
        }
        $reasonError = self::reasonError($type, $data['purpose'], $data['reason']);
        if ($reasonError !== null) {
            $errors['reason'] = $reasonError;
        }

        // Lines
        $raw   = is_array($in['items'] ?? null) ? array_values($in['items']) : [];
        $lines = [];
        foreach ($raw as $i => $line) {
            if (!is_array($line)) {
                continue;
            }
            $serialIds = self::intList($line['serial_ids'] ?? []);
            $blank = static fn (string $k): bool => !isset($line[$k]) || (is_string($line[$k]) && trim($line[$k]) === '');
            if ($blank('product_id') && $blank('quantity') && !$serialIds) {
                continue; // empty template row
            }
            $lines[$i] = [$line, $serialIds];
        }
        if (!$lines) {
            $errors['items'] = 'Add at least one item.';
        } elseif (count($lines) > self::MAX_LINES) {
            $errors['items'] = 'A stock document can have at most ' . self::MAX_LINES . ' items.';
        }

        $ids = [];
        foreach ($lines as [$line]) {
            $pid = input_int($line, 'product_id', 1);
            if ($pid !== null) {
                $ids[$pid] = true;
            }
        }
        $products = [];
        $balances = [];
        if ($ids) {
            $in_  = implode(',', array_fill(0, count($ids), '?'));
            $stmt = db()->prepare("SELECT id, name, is_active, track_serial FROM products WHERE id IN ({$in_})");
            $stmt->execute(array_keys($ids));
            foreach ($stmt->fetchAll() as $p) {
                $products[(int) $p['id']] = $p;
            }
            if ($from !== null) {
                $stmt = db()->prepare("SELECT product_id, qty FROM stock_balances WHERE location_id = ? AND product_id IN ({$in_})");
                $stmt->execute([(int) $from['id'], ...array_keys($ids)]);
                $balances = array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
            }
        }

        $seen = [];
        foreach ($lines as $i => [$line, $serialIds]) {
            $key  = "items.{$i}.";
            $pid  = input_int($line, 'product_id', 1);
            $p    = $pid !== null ? ($products[$pid] ?? null) : null;
            $qty  = input_int($line, 'quantity', 1, Products::MAX_STOCK);
            $item = ['product_id' => $pid, 'quantity' => $qty, 'serial_ids' => []];

            if ($p === null || (int) $p['is_active'] !== 1) {
                $errors[$key . 'product_id'] = 'Choose an active product.';
            } elseif (isset($seen[$pid])) {
                $errors[$key . 'product_id'] = "{$p['name']} is already on this document. Use one line per product.";
            }
            if ($pid !== null) {
                $seen[$pid] = true;
            }
            if ($qty === null) {
                $errors[$key . 'quantity'] = 'Enter a whole number from 1 to ' . number_format(Products::MAX_STOCK) . '.';
            } elseif ($p !== null && $from !== null && ($balances[$pid] ?? 0) < $qty) {
                $errors[$key . 'quantity'] = 'Only ' . ($balances[$pid] ?? 0) . " at {$from['label']}.";
            }
            if ($p !== null) {
                if ((int) $p['track_serial'] === 1) {
                    if (count($serialIds) !== count(array_unique($serialIds))) {
                        $errors[$key . 'serial_ids'] = 'A serial number is chosen twice.';
                    } elseif ($qty !== null && count($serialIds) !== $qty) {
                        $errors[$key . 'serial_ids'] = "Choose exactly {$qty} serial number" . ($qty === 1 ? '' : 's')
                            . ' (' . count($serialIds) . ' chosen).';
                    } elseif ($serialIds && $from !== null) {
                        $in_  = implode(',', array_fill(0, count($serialIds), '?'));
                        $stmt = db()->prepare(
                            "SELECT serial_no FROM product_serials WHERE id IN ({$in_})
                                AND NOT (product_id = ? AND status = ? AND location_id = ?) ORDER BY serial_no"
                        );
                        $stmt->execute([...$serialIds, $pid, 'in_stock', (int) $from['id']]);
                        $gone = $stmt->fetchAll(PDO::FETCH_COLUMN);
                        $stmt = db()->prepare("SELECT COUNT(*) FROM product_serials WHERE id IN ({$in_})");
                        $stmt->execute($serialIds);
                        if ($gone || (int) $stmt->fetchColumn() !== count($serialIds)) {
                            $errors[$key . 'serial_ids'] = $gone
                                ? 'Serial ' . implode(', ', array_slice($gone, 0, 5)) . " is no longer at {$from['label']}."
                                : 'A selected serial number is no longer available.';
                        }
                    }
                    $item['serial_ids'] = array_values(array_unique($serialIds));
                } elseif ($serialIds) {
                    $errors[$key . 'serial_ids'] = "{$p['name']} does not use serial numbers.";
                }
            }
            $data['items'][] = $item;
        }
        return [$data, $errors];
    }

    /** Post a transfer (data from validate('transfer', ...)). @return array{id:int, doc_no:string} */
    public static function transfer(array $d, int $userId): array
    {
        return self::postOneStep('transfer', $d, $userId);
    }

    /** Post an internal-use issue (data from validate('issue', ...)). @return array{id:int, doc_no:string} */
    public static function issue(array $d, int $userId): array
    {
        return self::postOneStep('issue', $d, $userId);
    }

    /** Post a write-off (data from validate('writeoff', ...)). @return array{id:int, doc_no:string} */
    public static function writeOff(array $d, int $userId): array
    {
        return self::postOneStep('writeoff', $d, $userId);
    }

    private static function postOneStep(string $type, array $d, int $userId): array
    {
        $branchId = Branch::forWrite();
        // Coarse check before any work; the exact one (per purpose) follows under the locks.
        if (!Auth::canAny(...($type === 'transfer' ? ['inventory.transfer', 'inventory.damage', 'inventory.issue']
                                                   : [self::typePermission($type, null)]))) {
            throw new HttpException(403, self::permissionMessage($type, null));
        }
        $fromId = (int) ($d['from_location_id'] ?? 0);
        $toId   = $type === 'transfer' ? (int) ($d['to_location_id'] ?? 0) : 0;
        $reason = self::cleanReason((string) ($d['reason'] ?? ''));

        // Lines: unique products, qty 1..MAX_STOCK, serial ids as ints.
        $items = [];
        foreach (array_values(is_array($d['items'] ?? null) ? $d['items'] : []) as $n => $item) {
            $pid = (int) ($item['product_id'] ?? 0);
            $qty = (int) ($item['quantity'] ?? 0);
            if ($pid < 1 || $qty < 1 || $qty > Products::MAX_STOCK || isset($items[$pid])) {
                throw new HttpException(422, 'Check the items: one line per product, quantity 1 to ' . number_format(Products::MAX_STOCK) . '.');
            }
            $items[$pid] = ['product_id' => $pid, 'quantity' => $qty, 'sort' => $n + 1,
                            'serial_ids' => self::intList($item['serial_ids'] ?? [])];
        }
        if (!$items) {
            throw new HttpException(422, 'Add at least one item.');
        }
        if (count($items) > self::MAX_LINES) {
            throw new HttpException(422, 'A stock document can have at most ' . self::MAX_LINES . ' items.');
        }
        ksort($items); // product id order (lock order)

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $locs = self::locationRows(array_filter([$fromId, $toId]), true);
            $from = self::usable($locs[$fromId] ?? null, $branchId)
                ?? throw new HttpException(422, 'Choose an active source location of this branch.');
            $to = null;
            $purpose = null;
            if ($type === 'transfer') {
                $to = self::usable($locs[$toId] ?? null, $branchId)
                    ?? throw new HttpException(422, 'Choose an active destination location of this branch.');
                $purpose = self::purpose($from, $to);
            } elseif ($type === 'issue' && $from['kind'] === 'damaged') {
                throw new HttpException(422, 'Internal use is issued from a stock or display location.');
            }
            self::requirePermission(self::typePermission($type, $purpose), self::permissionMessage($type, $purpose));
            $reasonError = self::reasonError($type, $purpose, $reason);
            if ($reasonError !== null) {
                throw new HttpException(422, $reasonError);
            }

            $docNo = self::nextNumber($branchId, $type);
            $pdo->prepare(
                'INSERT INTO inventory_docs (doc_no, doc_type, purpose, branch_id, from_warehouse_id, from_location_id,
                                             to_warehouse_id, to_location_id, status, reason, total_qty, created_by,
                                             posted_by, posted_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            )->execute([
                $docNo, $type, $purpose, $branchId, (int) $from['warehouse_id'], (int) $from['id'],
                $to !== null ? (int) $to['warehouse_id'] : null, $to !== null ? (int) $to['id'] : null,
                'posted', $reason, 0, $userId, $userId,
            ]);
            $docId = (int) $pdo->lastInsertId();

            $products = self::lockProducts(array_keys($items));
            $problems = [];
            foreach ($items as $pid => $item) {
                $p = $products[$pid] ?? null;
                if ($p === null || (int) $p['is_active'] !== 1) {
                    $problems[] = 'An item on this document is no longer active.';
                } elseif ((int) $p['track_serial'] === 1
                    && (count($item['serial_ids']) !== $item['quantity'] || count(array_unique($item['serial_ids'])) !== count($item['serial_ids']))) {
                    $problems[] = "{$p['name']}: choose exactly {$item['quantity']} serial number" . ($item['quantity'] === 1 ? '' : 's') . '.';
                } elseif ((int) $p['track_serial'] !== 1 && $item['serial_ids']) {
                    $problems[] = "{$p['name']} does not use serial numbers.";
                }
            }
            if ($problems) {
                throw new HttpException(422, implode(' ', $problems), ['problems' => $problems]);
            }

            $note    = $docNo . ($reason !== null ? ': ' . $reason : '');
            $movType = self::MOVEMENT_TYPES[$type];
            $insLine = $pdo->prepare(
                'INSERT INTO inventory_doc_lines (doc_id, product_id, quantity, adjust_qty, unit_cost, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $lineIds    = [];
            $totalQty   = 0;
            $totalCents = 0;
            foreach ($items as $pid => $item) { // product id order
                $qty = $item['quantity'];
                if ($type === 'transfer') {
                    Stock::move($pid, $from, -$qty, $movType, $note, null, $userId, null, $docId);
                    $after = Stock::move($pid, $to, $qty, $movType, $note, null, $userId, null, $docId);
                    if ($after['location_qty'] > Products::MAX_STOCK) {
                        throw new HttpException(422, "{$products[$pid]['name']}: stock at {$to['label']} can be at most " . number_format(Products::MAX_STOCK) . '.');
                    }
                    $insLine->execute([$docId, $pid, $qty, null, null, $item['sort']]);
                } else {
                    Stock::balance($pid, (int) $from['id']);    // stock_balances before product_branches
                    $cost = Costing::avg($pid, $branchId);       // branch average, unchanged
                    Stock::move($pid, $from, -$qty, $movType, $note, null, $userId, null, $docId);
                    $totalCents += Costing::lineCents($qty, $cost);
                    $insLine->execute([$docId, $pid, $qty, -$qty, $cost, $item['sort']]);
                }
                $lineIds[$pid] = (int) $pdo->lastInsertId();
                $totalQty += $qty;
            }

            // Serials last (lock order): each in stock at the source, of that product.
            $serialCount = self::moveSerials($items, $lineIds, $from, $to, $products);

            $pdo->prepare('UPDATE inventory_docs SET total_qty = ?, total_cost = ? WHERE id = ?')
                ->execute([$totalQty, $type === 'transfer' ? null : from_cents($totalCents), $docId]);

            Audit::record('inventory', $type === 'writeoff' ? 'write_off' : $type, 'inventory_doc', $docId, $docNo, null,
                array_filter([
                    'purpose' => $purpose, 'from' => $from['label'], 'to' => $to['label'] ?? null, 'reason' => $reason,
                    'total_qty' => $totalQty, 'serials' => $serialCount,
                    'items' => self::auditItems($items, $products),
                ], static fn ($v) => $v !== null), $branchId);
            $pdo->commit();
            return ['id' => $docId, 'doc_no' => $docNo];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Lock the chosen serials (ORDER BY id FOR UPDATE), check them, then move them to $to (transfer)
     * or mark them removed (issue / write-off), and link them to the document lines. @return int count
     */
    private static function moveSerials(array $items, array $lineIds, array $from, ?array $to, array $products): int
    {
        $all = [];
        foreach ($items as $pid => $item) {
            foreach ($item['serial_ids'] as $sid) {
                $all[$sid] = $pid;
            }
        }
        if (!$all) {
            return 0;
        }
        $ids = array_keys($all);
        sort($ids);
        $pdo  = db();
        $in_  = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT id, product_id, serial_no, status, location_id FROM product_serials WHERE id IN ({$in_}) ORDER BY id FOR UPDATE");
        $stmt->execute($ids);
        $rows = [];
        foreach ($stmt->fetchAll() as $r) {
            $rows[(int) $r['id']] = $r;
        }
        foreach ($all as $sid => $pid) {
            $r = $rows[$sid] ?? null;
            if ($r === null || (int) $r['product_id'] !== $pid) {
                throw new HttpException(409, "{$products[$pid]['name']}: a selected serial number is no longer available.");
            }
            if ($r['status'] !== 'in_stock' || (int) $r['location_id'] !== (int) $from['id']) {
                throw new HttpException(409, "Serial {$r['serial_no']} is no longer at {$from['label']}.");
            }
        }
        $upd = $to !== null
            ? $pdo->prepare('UPDATE product_serials SET warehouse_id = ?, location_id = ? WHERE id = ?')
            : $pdo->prepare('UPDATE product_serials SET status = ? WHERE id = ?');
        $link = $pdo->prepare('INSERT INTO inventory_doc_serials (line_id, serial_id, found) VALUES (?, ?, ?)');
        foreach ($ids as $sid) {
            if ($to !== null) {
                $upd->execute([(int) $to['warehouse_id'], (int) $to['id'], $sid]);
            } else {
                $upd->execute(['removed', $sid]);
            }
            $link->execute([$lineIds[$all[$sid]], $sid, 1]);
        }
        return count($ids);
    }

    // ------------------------------------------------------------------
    // Stock counts
    // ------------------------------------------------------------------

    /**
     * Open a count of one location at the current branch (counts.create). Lines = active products
     * (optionally of one category) with stock there, or all of them with $includeZero.
     * system_qty and the expected serials are frozen now. @return array{id:int, doc_no:string}
     */
    public static function createCount(int $locationId, ?int $categoryId, bool $includeZero, int $userId): array
    {
        self::requirePermission('counts.create', 'You do not have permission to create stock counts.');
        $branchId = Branch::forWrite();
        $pdo = db();
        $pdo->beginTransaction();
        try {
            // The location row lock serialises "one open count per location" (and location deactivation).
            $stmt = $pdo->prepare('SELECT * FROM storage_locations WHERE id = ? FOR UPDATE');
            $stmt->execute([$locationId]);
            $l = $stmt->fetch();
            $stmt = $pdo->prepare('SELECT code, is_active FROM warehouses WHERE id = ? LOCK IN SHARE MODE');
            $stmt->execute([$l ? (int) $l['warehouse_id'] : 0]);
            $w = $stmt->fetch();
            if (!$l || !$w || (int) $l['branch_id'] !== $branchId || (int) $l['is_active'] !== 1 || (int) $w['is_active'] !== 1) {
                throw new HttpException(422, 'Choose an active location of this branch.');
            }
            $label = $w['code'] . ' / ' . $l['code'];

            $stmt = $pdo->prepare(
                "SELECT doc_no FROM inventory_docs WHERE from_location_id = ? AND doc_type = ? AND status IN ('open', 'submitted') LIMIT 1"
            );
            $stmt->execute([$locationId, 'count']);
            $open = $stmt->fetchColumn();
            if ($open !== false) {
                throw new HttpException(409, "Stock count {$open} is still open at {$label}. Finish or cancel it first.");
            }
            if ($categoryId !== null) {
                $stmt = $pdo->prepare('SELECT 1 FROM categories WHERE id = ?');
                $stmt->execute([$categoryId]);
                if (!$stmt->fetchColumn()) {
                    throw new HttpException(422, 'Choose a category from the list.');
                }
            }

            $where  = ['p.is_active = 1'];
            $params = [$locationId];
            if ($categoryId !== null) {
                $where[]  = 'p.category_id = ?';
                $params[] = $categoryId;
            }
            if (!$includeZero) {
                $where[] = 'COALESCE(sb.qty, 0) > 0';
            }
            $stmt = $pdo->prepare(
                'SELECT p.id, p.track_serial, COALESCE(sb.qty, 0) AS qty
                   FROM products p
                   LEFT JOIN stock_balances sb ON sb.product_id = p.id AND sb.location_id = ?
                  WHERE ' . implode(' AND ', $where) . '
                  ORDER BY p.code
                  LIMIT ' . (self::MAX_COUNT_LINES + 1)
            );
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            if (!$rows) {
                throw new HttpException(422, "There are no items to count at {$label}" . ($includeZero ? '.' : '. Tick "Include items with zero stock" to count them anyway.'));
            }
            if (count($rows) > self::MAX_COUNT_LINES) {
                throw new HttpException(422, 'More than ' . self::MAX_COUNT_LINES . ' items to count here. Narrow by category.');
            }

            $docNo = self::nextNumber($branchId, 'count');
            $pdo->prepare(
                'INSERT INTO inventory_docs (doc_no, doc_type, branch_id, from_warehouse_id, from_location_id, status, total_qty, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$docNo, 'count', $branchId, (int) $l['warehouse_id'], $locationId, 'open', 0, $userId]);
            $docId = (int) $pdo->lastInsertId();

            $insLine   = $pdo->prepare('INSERT INTO inventory_doc_lines (doc_id, product_id, system_qty, sort_order) VALUES (?, ?, ?, ?)');
            $insSerial = $pdo->prepare(
                'INSERT INTO inventory_doc_serials (line_id, serial_id, found)
                 SELECT ?, ps.id, 0 FROM product_serials ps WHERE ps.product_id = ? AND ps.location_id = ? AND ps.status = ?'
            );
            foreach ($rows as $n => $r) {
                $insLine->execute([$docId, (int) $r['id'], (int) $r['qty'], $n + 1]);
                if ((int) $r['track_serial'] === 1) {
                    $insSerial->execute([(int) $pdo->lastInsertId(), (int) $r['id'], $locationId, 'in_stock']);
                }
            }

            Audit::record('inventory', 'count_create', 'inventory_doc', $docId, $docNo, null,
                ['location' => $label, 'lines' => count($rows), 'category_id' => $categoryId, 'include_zero' => $includeZero ? 1 : 0],
                $branchId);
            $pdo->commit();
            return ['id' => $docId, 'doc_no' => $docNo];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Enter counted quantities of an open count (counts.create).
     * $counts: line id => counted qty for non-serial lines ('' = not counted yet; lines left out keep their value).
     * $foundSerialIds: every expected serial that was found (serial lines: counted = number found).
     * @throws HttpException 422 with details ['errors' => ['counts.<lineId>' => message]]
     */
    public static function saveCounts(int $id, array $counts, array $foundSerialIds, int $userId): void
    {
        self::requirePermission('counts.create', 'You do not have permission to enter stock counts.');
        $found = array_flip(self::intList($foundSerialIds));
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $doc = self::lockCount($id);
            if ($doc['status'] !== 'open') {
                throw new HttpException(409, "{$doc['doc_no']} is " . strtolower(self::STATUSES[$doc['status']]) . ' and can no longer be changed.');
            }
            $lines = self::countLines($id);
            $errors  = [];
            $updates = [];
            $changes = [];
            foreach ($lines as $line) {
                $lineId = (int) $line['id'];
                if ((int) $line['track_serial'] === 1) {
                    $n = 0;
                    foreach ($line['serial_ids'] as $sid) {
                        $n += isset($found[$sid]) ? 1 : 0;
                    }
                    $updates[$lineId] = $n;
                    continue;
                }
                if (!array_key_exists($lineId, $counts) && !array_key_exists((string) $lineId, $counts)) {
                    continue; // not on the form: keep
                }
                $raw = $counts[$lineId] ?? $counts[(string) $lineId] ?? null;
                if ($raw === null || (is_string($raw) && trim($raw) === '')) {
                    $updates[$lineId] = null;
                    continue;
                }
                $qty = input_int(['v' => is_string($raw) ? trim($raw) : $raw], 'v', 0, Products::MAX_STOCK);
                if ($qty === null) {
                    $errors["counts.{$lineId}"] = "{$line['product_name']}: enter a whole number from 0 to " . number_format(Products::MAX_STOCK) . '.';
                    continue;
                }
                $updates[$lineId] = $qty;
            }
            if ($errors) {
                throw new HttpException(422, 'Check the counted quantities. ' . implode(' ', array_slice($errors, 0, 3)), ['errors' => $errors]);
            }

            $upd = $pdo->prepare('UPDATE inventory_doc_lines SET counted_qty = ? WHERE id = ?');
            foreach ($lines as $line) {
                $lineId = (int) $line['id'];
                if (!array_key_exists($lineId, $updates)) {
                    continue;
                }
                $old = $line['counted_qty'] === null ? null : (int) $line['counted_qty'];
                if ($old !== $updates[$lineId]) {
                    $upd->execute([$updates[$lineId], $lineId]);
                    $changes[] = $line['product_code'] . ': ' . ($old ?? '-') . ' -> ' . ($updates[$lineId] ?? '-');
                }
            }
            $updSerial = $pdo->prepare('UPDATE inventory_doc_serials SET found = ? WHERE line_id = ? AND serial_id = ?');
            foreach ($lines as $line) {
                foreach ($line['serial_ids'] as $sid) {
                    $updSerial->execute([isset($found[$sid]) ? 1 : 0, (int) $line['id'], $sid]);
                }
            }
            if ($changes) {
                Audit::record('inventory', 'count_save', 'inventory_doc', $id, (string) $doc['doc_no'], null,
                    ['changes' => array_slice($changes, 0, 100)], (int) $doc['branch_id']);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Hand an open count over for approval (counts.create): every line must be counted. */
    public static function submit(int $id, int $userId): void
    {
        self::requirePermission('counts.create', 'You do not have permission to submit stock counts.');
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $doc = self::lockCount($id);
            if ($doc['status'] !== 'open') {
                throw new HttpException(409, "{$doc['doc_no']} is already " . strtolower(self::STATUSES[$doc['status']]) . '.');
            }
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM inventory_doc_lines WHERE doc_id = ? AND counted_qty IS NULL');
            $stmt->execute([$id]);
            $left = (int) $stmt->fetchColumn();
            if ($left > 0) {
                throw new HttpException(422, "Enter the counted quantity of every item ({$left} left).");
            }
            $pdo->prepare('UPDATE inventory_docs SET status = ?, submitted_by = ?, submitted_at = NOW() WHERE id = ?')
                ->execute(['submitted', $userId, $id]);
            Audit::record('inventory', 'count_submit', 'inventory_doc', $id, (string) $doc['doc_no'],
                ['status' => 'open'], ['status' => 'submitted'], (int) $doc['branch_id']);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Did $userId enter or change counted figures on this count (audited count_save)? */
    private static function hasCounted(int $docId, int $userId): bool
    {
        $stmt = db()->prepare(
            "SELECT 1 FROM audit_logs WHERE module = 'inventory' AND action = 'count_save'
                AND entity_type = 'inventory_doc' AND entity_id = ? AND user_id = ? LIMIT 1"
        );
        $stmt->execute([$docId, $userId]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Approve a submitted count (counts.approve; never by its creator, submitter or counter, also not a super admin):
     * post counted - frozen per line (serial lines: expected serials not found and still there -> removed).
     * @return array{doc_no:string, total_qty:int} total_qty = sum of |variance|
     */
    public static function approve(int $id, int $userId): array
    {
        self::requirePermission('counts.approve', 'You do not have permission to approve stock counts.');
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $doc = self::lockCount($id);
            if ($doc['status'] !== 'submitted') {
                throw new HttpException(409, $doc['status'] === 'open'
                    ? "{$doc['doc_no']} has not been submitted yet."
                    : "{$doc['doc_no']} is already " . strtolower(self::STATUSES[$doc['status']]) . '.');
            }
            if ($userId === (int) $doc['created_by'] || $userId === (int) ($doc['submitted_by'] ?? 0)
                || self::hasCounted($id, $userId)) {
                throw new HttpException(403, "You can't approve a count you created or counted.");
            }
            $branchId = (int) $doc['branch_id'];
            $location = self::locationArray((int) $doc['from_location_id']);
            $lines    = self::countLines($id); // product id order not guaranteed: sort below
            usort($lines, static fn (array $a, array $b): int => (int) $a['product_id'] <=> (int) $b['product_id']);
            $products = self::lockProducts(array_column($lines, 'product_id'));

            // Per product in id order: stock_balances, then product_branches.
            $balance = [];
            $cost    = [];
            foreach ($lines as $line) {
                $pid = (int) $line['product_id'];
                if ($line['counted_qty'] === null) {
                    throw new HttpException(409, "{$doc['doc_no']}: {$line['product_name']} has no counted quantity.");
                }
                $balance[$pid] = Stock::balance($pid, $location['id']);
                $cost[$pid]    = Costing::avg($pid, $branchId);
            }

            // Serials last: expected serials that were not found.
            $missing = []; // product id => list of serial ids still in stock at the location
            $notFound = [];
            foreach ($lines as $line) {
                if ((int) ($products[(int) $line['product_id']]['track_serial'] ?? 0) === 1) {
                    foreach ($line['serials'] as $s) {
                        if ((int) $s['found'] === 0) {
                            $notFound[] = (int) $s['serial_id'];
                        }
                    }
                }
            }
            if ($notFound) {
                sort($notFound);
                $in_  = implode(',', array_fill(0, count($notFound), '?'));
                $stmt = $pdo->prepare("SELECT id, product_id, status, location_id FROM product_serials WHERE id IN ({$in_}) ORDER BY id FOR UPDATE");
                $stmt->execute($notFound);
                foreach ($stmt->fetchAll() as $s) {
                    if ($s['status'] === 'in_stock' && (int) $s['location_id'] === $location['id']) {
                        $missing[(int) $s['product_id']][] = (int) $s['id'];
                    }
                }
            }

            $note = "{$doc['doc_no']}: stock count";
            $upd  = $pdo->prepare('UPDATE inventory_doc_lines SET adjust_qty = ?, unit_cost = ? WHERE id = ?');
            $removed     = $pdo->prepare('UPDATE product_serials SET status = ? WHERE id = ? AND status = ?');
            $totalQty    = 0;
            $totalCents  = 0;
            $variances   = [];
            foreach ($lines as $line) {
                $pid   = (int) $line['product_id'];
                $p     = $products[$pid] ?? null;
                $track = $p !== null && (int) $p['track_serial'] === 1;
                $delta = $track ? -count($missing[$pid] ?? []) : (int) $line['counted_qty'] - (int) $line['system_qty'];
                $new   = $balance[$pid] + $delta;
                if ($new < 0) {
                    throw new HttpException(409, "Stock of {$line['product_name']} at {$location['label']} changed; recount.");
                }
                if ($new > Products::MAX_STOCK) {
                    throw new HttpException(422, "{$line['product_name']}: stock at {$location['label']} can be at most " . number_format(Products::MAX_STOCK) . '.');
                }
                if ($delta !== 0) {
                    if ($delta > 0) {
                        Costing::ensure($pid, $branchId); // stock in without a cost: average unchanged
                    }
                    Stock::move($pid, $location, $delta, 'count', $note, null, $userId, null, $id);
                    foreach ($missing[$pid] ?? [] as $sid) {
                        $removed->execute(['removed', $sid, 'in_stock']);
                    }
                    $variances[] = $line['product_code'] . ': ' . ($delta > 0 ? '+' : '') . $delta;
                }
                $upd->execute([$delta, $cost[$pid], (int) $line['id']]);
                $totalQty   += abs($delta);
                $totalCents += ($delta < 0 ? -1 : 1) * Costing::lineCents(abs($delta), $cost[$pid]);
            }

            $pdo->prepare(
                'UPDATE inventory_docs SET status = ?, total_qty = ?, total_cost = ?, posted_by = ?, posted_at = NOW() WHERE id = ?'
            )->execute(['posted', $totalQty, from_cents($totalCents), $userId, $id]);
            Audit::record('inventory', 'count_post', 'inventory_doc', $id, (string) $doc['doc_no'], ['status' => 'submitted'],
                ['status' => 'posted', 'location' => $location['label'], 'total_qty' => $totalQty,
                 'variances' => array_slice($variances, 0, 100)], $branchId);
            $pdo->commit();
            return ['doc_no' => (string) $doc['doc_no'], 'total_qty' => $totalQty];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Cancel a count (no stock effect). Open: its creator (counts.create) or counts.approve;
     * submitted: counts.approve. Reason 3–255 characters.
     */
    public static function cancel(int $id, string $reason, int $userId): void
    {
        if (!Auth::canAny('counts.create', 'counts.approve')) {
            throw new HttpException(403, 'You do not have permission to cancel stock counts.');
        }
        $reason = self::cleanReason($reason);
        $len = mb_strlen((string) $reason);
        if ($len < 3 || $len > 255) {
            throw new HttpException(422, 'Enter a reason (3–255 characters).');
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $doc = self::lockCount($id);
            if (!in_array($doc['status'], ['open', 'submitted'], true)) {
                throw new HttpException(409, "{$doc['doc_no']} is already " . strtolower(self::STATUSES[$doc['status']]) . '.');
            }
            $allowed = Auth::can('counts.approve')
                || ($doc['status'] === 'open' && Auth::can('counts.create') && $userId === (int) $doc['created_by']);
            if (!$allowed) {
                throw new HttpException(403, $doc['status'] === 'open'
                    ? 'Only the person who created this count or an approver can cancel it.'
                    : 'Only an approver can cancel a submitted count.');
            }
            $pdo->prepare('UPDATE inventory_docs SET status = ?, cancelled_by = ?, cancelled_at = NOW(), cancel_reason = ? WHERE id = ?')
                ->execute(['cancelled', $userId, $reason, $id]);
            Audit::record('inventory', 'count_cancel', 'inventory_doc', $id, (string) $doc['doc_no'],
                ['status' => $doc['status']], ['status' => 'cancelled', 'reason' => $reason], (int) $doc['branch_id']);
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

    /** Lock a count row: 404 when missing / out of scope / not a count, 422 unless working in its branch. */
    private static function lockCount(int $id): array
    {
        $stmt = db()->prepare('SELECT * FROM inventory_docs WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $doc = $stmt->fetch();
        if (!$doc || $doc['doc_type'] !== 'count') {
            throw new HttpException(404, 'Stock count not found.');
        }
        Warehouses::assertWorkingBranch((int) $doc['branch_id']);
        return $doc;
    }

    /** Lines of a count with product name/code/track flag and 'serials' / 'serial_ids' (expected serials). */
    private static function countLines(int $docId): array
    {
        $stmt = db()->prepare(
            'SELECT dl.*, p.code AS product_code, p.name AS product_name, p.track_serial
               FROM inventory_doc_lines dl JOIN products p ON p.id = dl.product_id
              WHERE dl.doc_id = ? ORDER BY dl.sort_order, dl.id'
        );
        $stmt->execute([$docId]);
        $lines = $stmt->fetchAll();
        $stmt = db()->prepare(
            'SELECT ds.line_id, ds.serial_id, ds.found FROM inventory_doc_serials ds
               JOIN inventory_doc_lines dl ON dl.id = ds.line_id WHERE dl.doc_id = ? ORDER BY ds.serial_id'
        );
        $stmt->execute([$docId]);
        $serials = [];
        foreach ($stmt->fetchAll() as $s) {
            $serials[(int) $s['line_id']][] = ['serial_id' => (int) $s['serial_id'], 'found' => (int) $s['found']];
        }
        foreach ($lines as &$line) {
            $line['serials']    = $serials[(int) $line['id']] ?? [];
            $line['serial_ids'] = array_column($line['serials'], 'serial_id');
        }
        unset($line);
        return $lines;
    }

    /**
     * Location rows by id with warehouse code/active flag. $lock: storage_locations rows (S, id order),
     * then their warehouses (S): the order Warehouses::setActive uses.
     * @return array<int, array>
     */
    private static function locationRows(array $ids, bool $lock): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        sort($ids);
        $pdo  = db();
        $in_  = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(
            "SELECT id, warehouse_id, branch_id, code, name, kind, is_active FROM storage_locations
              WHERE id IN ({$in_}) ORDER BY id" . ($lock ? ' LOCK IN SHARE MODE' : '')
        );
        $stmt->execute($ids);
        $rows = [];
        foreach ($stmt->fetchAll() as $r) {
            $rows[(int) $r['id']] = $r;
        }
        $whIds = array_values(array_unique(array_map(static fn (array $r): int => (int) $r['warehouse_id'], $rows)));
        $whs   = [];
        if ($whIds) {
            sort($whIds);
            $in_  = implode(',', array_fill(0, count($whIds), '?'));
            $stmt = $pdo->prepare(
                "SELECT id, code, is_active FROM warehouses WHERE id IN ({$in_}) ORDER BY id" . ($lock ? ' LOCK IN SHARE MODE' : '')
            );
            $stmt->execute($whIds);
            foreach ($stmt->fetchAll() as $w) {
                $whs[(int) $w['id']] = $w;
            }
        }
        foreach ($rows as &$r) {
            $w = $whs[(int) $r['warehouse_id']] ?? ['code' => '?', 'is_active' => 0];
            $r['warehouse_code']   = (string) $w['code'];
            $r['warehouse_active'] = (int) $w['is_active'];
            $r['label']            = $w['code'] . ' / ' . $r['code'];
        }
        unset($r);
        return $rows;
    }

    /** A location usable at $branchId (active, active warehouse) as a Stock::move location array, else null. */
    private static function usable(?array $row, int $branchId): ?array
    {
        if ($row === null || (int) $row['branch_id'] !== $branchId || (int) $row['is_active'] !== 1 || (int) $row['warehouse_active'] !== 1) {
            return null;
        }
        return [
            'id' => (int) $row['id'], 'warehouse_id' => (int) $row['warehouse_id'], 'branch_id' => (int) $row['branch_id'],
            'kind' => (string) $row['kind'], 'code' => (string) $row['code'], 'label' => (string) $row['label'],
        ];
    }

    /** Stock::move location array of a count's location (may be inactive by now? never: deactivation is blocked). */
    private static function locationArray(int $locationId): array
    {
        $row = self::locationRows([$locationId], false)[$locationId] ?? throw new HttpException(404, 'Location not found.');
        return [
            'id' => (int) $row['id'], 'warehouse_id' => (int) $row['warehouse_id'], 'branch_id' => (int) $row['branch_id'],
            'kind' => (string) $row['kind'], 'code' => (string) $row['code'], 'label' => (string) $row['label'],
        ];
    }

    private static function typePermission(string $type, ?string $purpose): ?string
    {
        return match ($type) {
            'transfer' => $purpose !== null ? self::PURPOSE_PERMISSIONS[$purpose] : null,
            'issue'    => 'inventory.issue',
            'writeoff' => 'inventory.damage',
            default    => 'counts.create',
        };
    }

    private static function permissionMessage(string $type, ?string $purpose): string
    {
        return match (true) {
            $type === 'issue'    => 'You do not have permission to issue stock for internal use.',
            $type === 'writeoff' => 'You do not have permission to write off stock.',
            $purpose === 'damage'  => 'You do not have permission to mark stock as damaged.',
            $purpose === 'display' => 'You do not have permission to put units on display.',
            default => 'You do not have permission to move stock between locations.',
        };
    }

    /** Reason rule: required (3–255) for damage / display / issue / write-off, optional (but 3–255 when given) otherwise. */
    private static function reasonError(string $type, ?string $purpose, ?string $reason): ?string
    {
        $required = $type !== 'transfer' || in_array($purpose, ['damage', 'display'], true);
        $len = mb_strlen((string) $reason);
        if ($reason === null) {
            return $required ? 'Enter a reason (3–255 characters).' : null;
        }
        return ($len < 3 || $len > 255) ? 'Enter a reason (3–255 characters).' : null;
    }

    /** Trimmed reason without control characters, or null when empty. */
    private static function cleanReason(string $reason): ?string
    {
        $reason = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $reason) ?? '');
        return $reason === '' ? null : mb_substr($reason, 0, 256); // 256: lets the length check catch > 255
    }

    /** Positive unique-agnostic int list from an array of ids (strings / ints); other values are dropped. */
    private static function intList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $v) {
            $n = filter_var($v, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($n !== false) {
                $out[] = $n;
            }
        }
        return $out;
    }

    /** Number from document_sequences (locked row; a rollback releases it): TRF-MAR-2026-000001. */
    private static function nextNumber(int $branchId, string $type): string
    {
        $pdo    = db();
        $prefix = self::PREFIXES[$type];
        $year   = (int) date('Y');
        $pdo->prepare(
            'INSERT INTO document_sequences (branch_id, doc_type, year, last_no) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE last_no = last_no'
        )->execute([$branchId, $prefix, $year, 0]);
        $stmt = $pdo->prepare('SELECT last_no FROM document_sequences WHERE branch_id = ? AND doc_type = ? AND year = ? FOR UPDATE');
        $stmt->execute([$branchId, $prefix, $year]);
        $next = (int) $stmt->fetchColumn() + 1;
        $pdo->prepare('UPDATE document_sequences SET last_no = ? WHERE branch_id = ? AND doc_type = ? AND year = ?')
            ->execute([$next, $branchId, $prefix, $year]);
        $stmt = $pdo->prepare('SELECT code FROM branches WHERE id = ?');
        $stmt->execute([$branchId]);
        return sprintf('%s-%s-%04d-%06d', $prefix, (string) $stmt->fetchColumn(), $year, $next);
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

    /** "ITM-0002 x3 (3 S/N)" per line (never cost). */
    private static function auditItems(array $items, array $products): array
    {
        $out = [];
        foreach ($items as $pid => $item) {
            $out[] = ($products[$pid]['code'] ?? $pid) . ' x' . $item['quantity']
                . ($item['serial_ids'] ? ' (' . count($item['serial_ids']) . ' S/N)' : '');
        }
        return $out;
    }
}
