<?php
/**
 * Branch-to-branch transfers (stock_transfers + lines + serials), audit module 'transfers'.
 *
 *   requested  the receiving branch asks another branch for items (transfers.request, working in the receiving branch)
 *   approved   the sending branch approves, possibly fewer (transfers.approve, sending branch, not the requester)
 *   released   stock leaves the sending branch's POS location (transfer_out) at its branch average cost;
 *              serials are fixed now and become 'in_transit'. In transit = in neither branch.
 *   received   stock enters the receiving branch's POS location (transfer_in) and re-averages its cost at the
 *              sender's cost (transfers.receive, receiving branch, not the releaser). Short quantities need a
 *              note; serials not received become 'removed'.
 *   cancelled  only before release, by the requesting side (transfers.request) or the sending side (transfers.approve).
 * Approval does not reserve stock: availability is checked (and locked) at release.
 *
 * A transfer is visible when either branch is in scope; an action needs the session working in the branch
 * that acts (other branch -> 404, "All branches" -> 422).
 * Lock order: stock_transfers row -> document_sequences -> products (one SELECT ... ORDER BY id FOR UPDATE)
 * -> per product: stock_balances -> product_branches -> product_serials last (ORDER BY id FOR UPDATE).
 * Cost (unit_cost, total_cost, line_value) only leaves this class with products.cost and never goes to the audit log.
 */
declare(strict_types=1);

final class Transfers
{
    public const STATUSES = [
        'requested' => 'Requested', 'approved' => 'Approved', 'released' => 'In Transit',
        'received'  => 'Received',  'cancelled' => 'Cancelled',
    ];
    public const VIEW_PERMISSIONS = ['transfers.request', 'transfers.approve', 'transfers.release', 'transfers.receive'];
    public const PREFIX    = 'BT';
    public const MAX_LINES = 100;

    private const COST_KEYS = ['unit_cost', 'total_cost', 'line_value'];

    // ------------------------------------------------------------------
    // Permissions / branch
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
            throw new HttpException(403, 'You do not have permission to view branch transfers.');
        }
    }

    /** The session must work in $branchId: 422 under "All branches", 403 at another branch. */
    private static function assertWorkingIn(int $branchId, string $side): void
    {
        $cur = Branch::current();
        if ($cur === $branchId) {
            return;
        }
        if ($cur === Branch::ALL && Branch::inScope($branchId)) {
            throw new HttpException(422, 'Switch to branch ' . self::branchName($branchId) . ' first.');
        }
        throw new HttpException(403, "Only the {$side} branch can do this.");
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

    /** WHERE for a transfer alias t: either branch in the current scope. */
    private static function scope(): array
    {
        [$from, $p1] = Branch::scopeSql('t.from_branch_id');
        [$to, $p2]   = Branch::scopeSql('t.to_branch_id');
        return ["({$from} OR {$to})", [...$p1, ...$p2]];
    }

    /** @param array{search?:string, status?:string, direction?:string} $f direction: incoming | outgoing (concrete branch) */
    private static function where(array $f): array
    {
        [$scope, $params] = self::scope();
        $where = [$scope];
        $status = (string) ($f['status'] ?? '');
        if (isset(self::STATUSES[$status])) {
            $where[]  = 't.status = ?';
            $params[] = $status;
        }
        $cur = Branch::current();
        $dir = (string) ($f['direction'] ?? '');
        if ($cur !== null && $cur !== Branch::ALL && in_array($dir, ['incoming', 'outgoing'], true)) {
            $where[]  = $dir === 'incoming' ? 't.to_branch_id = ?' : 't.from_branch_id = ?';
            $params[] = $cur;
        }
        $q = (string) ($f['search'] ?? '');
        if ($q !== '') {
            $like = like_pattern($q);
            $where[] = '(t.transfer_no LIKE ? OR t.notes LIKE ? OR EXISTS (SELECT 1 FROM stock_transfer_lines tl
                            JOIN products p ON p.id = tl.product_id
                           WHERE tl.transfer_id = t.id AND (p.code LIKE ? OR p.name LIKE ?)))';
            array_push($params, $like, $like, $like, $like);
        }
        return [implode(' AND ', $where), $params];
    }

    public static function count(array $f): int
    {
        self::requireView();
        [$where, $params] = self::where($f);
        $stmt = db()->prepare("SELECT COUNT(*) FROM stock_transfers t WHERE {$where}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public static function search(array $f, int $limit, int $offset): array
    {
        self::requireView();
        [$where, $params] = self::where($f);
        $cost = Auth::can('products.cost') ? 't.total_cost, ' : '';
        $stmt = db()->prepare(
            "SELECT t.id, t.transfer_no, t.status, t.notes, t.total_qty, {$cost} t.from_branch_id, t.to_branch_id,
                    t.requested_at, t.released_at, t.received_at, t.cancelled_at,
                    fb.code AS from_code, fb.name AS from_name, tb.code AS to_code, tb.name AS to_name,
                    u.full_name AS requested_by_name,
                    (SELECT COUNT(*) FROM stock_transfer_lines tl WHERE tl.transfer_id = t.id) AS line_count
               FROM stock_transfers t
               JOIN branches fb ON fb.id = t.from_branch_id
               JOIN branches tb ON tb.id = t.to_branch_id
               JOIN users u ON u.id = t.requested_by
              WHERE {$where}
              ORDER BY t.status IN ('requested', 'approved', 'released') DESC, t.requested_at DESC, t.id DESC
              LIMIT ? OFFSET ?"
        );
        $stmt->execute([...$params, $limit, $offset]);
        return $stmt->fetchAll();
    }

    /** Work list counts for the current branch: to approve / to release (sending) and incoming (receiving). */
    public static function workCounts(): array
    {
        $out = ['approve' => 0, 'release' => 0, 'receive' => 0];
        $cur = Branch::current();
        if ($cur === null || $cur === Branch::ALL || !Auth::canAny(...self::VIEW_PERMISSIONS)) {
            return $out;
        }
        $stmt = db()->prepare(
            "SELECT SUM(from_branch_id = ? AND status = 'requested'), SUM(from_branch_id = ? AND status = 'approved'),
                    SUM(to_branch_id = ? AND status = 'released')
               FROM stock_transfers WHERE (from_branch_id = ? OR to_branch_id = ?) AND status IN ('requested', 'approved', 'released')"
        );
        $stmt->execute([$cur, $cur, $cur, $cur, $cur]);
        [$a, $r, $i] = $stmt->fetch(PDO::FETCH_NUM) ?: [0, 0, 0];
        return ['approve' => (int) $a, 'release' => (int) $r, 'receive' => (int) $i];
    }

    /**
     * Transfer with branch / people names and 'lines' (product info, quantities, 'serials' = list of
     * {id, serial_no, received, status}; 'source_qty' = stock at the sending POS location while not yet
     * released, only for the sending side). Null when missing; 404 when neither branch is in scope.
     */
    public static function find(int $id): ?array
    {
        self::requireView();
        $stmt = db()->prepare(
            'SELECT t.*, fb.code AS from_code, fb.name AS from_name, tb.code AS to_code, tb.name AS to_name,
                    ru.full_name AS requested_by_name, au.full_name AS approved_by_name, lu.full_name AS released_by_name,
                    cu.full_name AS received_by_name, xu.full_name AS cancelled_by_name
               FROM stock_transfers t
               JOIN branches fb ON fb.id = t.from_branch_id
               JOIN branches tb ON tb.id = t.to_branch_id
               JOIN users ru ON ru.id = t.requested_by
               LEFT JOIN users au ON au.id = t.approved_by
               LEFT JOIN users lu ON lu.id = t.released_by
               LEFT JOIN users cu ON cu.id = t.received_by
               LEFT JOIN users xu ON xu.id = t.cancelled_by
              WHERE t.id = ?'
        );
        $stmt->execute([$id]);
        $t = $stmt->fetch();
        if (!$t) {
            return null;
        }
        if (!Branch::inScope((int) $t['from_branch_id']) && !Branch::inScope((int) $t['to_branch_id'])) {
            throw new HttpException(404, 'Not found.');
        }

        $stmt = db()->prepare(
            'SELECT tl.*, p.code AS product_code, p.name AS product_name, p.track_serial, u.code AS unit_code
               FROM stock_transfer_lines tl
               JOIN products p ON p.id = tl.product_id
               LEFT JOIN units u ON u.id = p.unit_id
              WHERE tl.transfer_id = ?
              ORDER BY tl.sort_order, tl.id'
        );
        $stmt->execute([$id]);
        $lines = [];
        foreach ($stmt->fetchAll() as $l) {
            $l['serials'] = [];
            $l['line_value'] = $l['unit_cost'] !== null && $l['qty_released'] !== null
                ? from_cents(Costing::lineCents((int) $l['qty_released'], (string) $l['unit_cost'])) : null;
            $lines[(int) $l['id']] = $l;
        }
        if ($lines) {
            $in_  = implode(',', array_fill(0, count($lines), '?'));
            $stmt = db()->prepare(
                "SELECT ts.line_id, ts.received, s.id, s.serial_no, s.status
                   FROM stock_transfer_serials ts JOIN product_serials s ON s.id = ts.serial_id
                  WHERE ts.line_id IN ({$in_}) ORDER BY s.serial_no"
            );
            $stmt->execute(array_keys($lines));
            foreach ($stmt->fetchAll() as $s) {
                $lines[(int) $s['line_id']]['serials'][] = [
                    'id' => (int) $s['id'], 'serial_no' => $s['serial_no'],
                    'received' => $s['received'] === null ? null : (int) $s['received'], 'status' => $s['status'],
                ];
            }
            // Availability for the approver / releaser (the sending side only).
            $loc = null;
            if (in_array($t['status'], ['requested', 'approved'], true) && Branch::inScope((int) $t['from_branch_id'])) {
                try {
                    $loc = Branch::defaultLocation((int) $t['from_branch_id']);
                } catch (HttpException) {
                    $loc = null; // no POS location: release will say so; just skip the hint
                }
            }
            if ($loc !== null) {
                $pids = array_values(array_unique(array_map(static fn (array $l): int => (int) $l['product_id'], $lines)));
                $inP  = implode(',', array_fill(0, count($pids), '?'));
                $stmt = db()->prepare("SELECT product_id, qty FROM stock_balances WHERE location_id = ? AND product_id IN ({$inP})");
                $stmt->execute([$loc['id'], ...$pids]);
                $qty = array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
                foreach ($lines as &$l) {
                    $l['source_qty'] = $qty[(int) $l['product_id']] ?? 0;
                }
                unset($l);
            }
        }
        $t['lines'] = array_values($lines);

        if (!Auth::can('products.cost')) {
            $t = array_diff_key($t, array_flip(self::COST_KEYS));
            $t['lines'] = array_map(static fn (array $l): array => array_diff_key($l, array_flip(self::COST_KEYS)), $t['lines']);
        }
        return $t;
    }

    /** Which buttons the current user gets: {approve, release, receive, cancel}. */
    public static function actions(array $t): array
    {
        $cur  = Branch::current();
        $uid  = (int) Auth::id();
        $from = $cur === (int) $t['from_branch_id'];
        $to   = $cur === (int) $t['to_branch_id'];
        $s    = $t['status'];
        return [
            'approve' => $s === 'requested' && $from && Auth::can('transfers.approve') && $uid !== (int) $t['requested_by'],
            'release' => $s === 'approved' && $from && Auth::can('transfers.release'),
            'receive' => $s === 'released' && $to && Auth::can('transfers.receive') && $uid !== (int) $t['released_by'],
            'cancel'  => in_array($s, ['requested', 'approved'], true)
                         && (($to && Auth::can('transfers.request')) || ($from && Auth::can('transfers.approve'))),
        ];
    }

    public static function label(array $t): string
    {
        return (string) $t['transfer_no'];
    }

    // ------------------------------------------------------------------
    // Request
    // ------------------------------------------------------------------

    /** Active branches other than the current one (where stock can be requested from). */
    public static function sourceBranches(): array
    {
        $cur  = Branch::current();
        $stmt = db()->prepare('SELECT id, code, name FROM branches WHERE is_active = 1 AND id <> ? ORDER BY name');
        $stmt->execute([$cur === null || $cur === Branch::ALL ? 0 : $cur]);
        return $stmt->fetchAll();
    }

    /**
     * @return array{0: array{from_branch_id:?int, notes:?string, items:list<array{product_id:?int, quantity:?int}>},
     *               1: array<string,string>} errors keyed 'from_branch_id', 'notes', 'items', 'items.i.product_id', 'items.i.quantity'
     */
    public static function validateRequest(array $in): array
    {
        self::requirePermission('transfers.request', 'You do not have permission to request stock from other branches.');
        $toBranch = Branch::forWrite();
        $errors = [];
        $data = [
            'from_branch_id' => input_int($in, 'from_branch_id', 1),
            'notes'          => self::cleanText(is_string($in['notes'] ?? null) ? $in['notes'] : ''),
            'items'          => [],
        ];
        $sources = array_column(self::sourceBranches(), null, 'id');
        if ($data['from_branch_id'] === null || !isset($sources[$data['from_branch_id']]) || $data['from_branch_id'] === $toBranch) {
            $errors['from_branch_id'] = 'Choose the branch to request from.';
        }
        if ($data['notes'] !== null && mb_strlen($data['notes']) > 255) {
            $errors['notes'] = 'Keep the note under 255 characters.';
        }

        $lines = [];
        foreach (is_array($in['items'] ?? null) ? array_values($in['items']) : [] as $i => $line) {
            if (!is_array($line)) {
                continue;
            }
            $blank = static fn (string $k): bool => !isset($line[$k]) || (is_string($line[$k]) && trim($line[$k]) === '');
            if ($blank('product_id') && $blank('quantity')) {
                continue; // empty template row
            }
            $lines[$i] = $line;
        }
        if (!$lines) {
            $errors['items'] = 'Add at least one item.';
        } elseif (count($lines) > self::MAX_LINES) {
            $errors['items'] = 'A transfer can have at most ' . self::MAX_LINES . ' items.';
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
            $pid = input_int($line, 'product_id', 1);
            $qty = input_int($line, 'quantity', 1, Products::MAX_STOCK);
            $p   = $pid !== null ? ($products[$pid] ?? null) : null;
            if ($p === null || (int) $p['is_active'] !== 1) {
                $errors["items.{$i}.product_id"] = 'Choose an active product.';
            } elseif (isset($seen[$pid])) {
                $errors["items.{$i}.product_id"] = "{$p['name']} is already on this request. Use one line per product.";
            }
            if ($pid !== null) {
                $seen[$pid] = true;
            }
            if ($qty === null) {
                $errors["items.{$i}.quantity"] = 'Enter a whole number from 1 to ' . number_format(Products::MAX_STOCK) . '.';
            }
            $data['items'][] = ['product_id' => $pid, 'quantity' => $qty];
        }
        return [$data, $errors];
    }

    /** Create a request (data from validateRequest). @return array{id:int, transfer_no:string} */
    public static function request(array $d, int $userId): array
    {
        self::requirePermission('transfers.request', 'You do not have permission to request stock from other branches.');
        $toBranch   = Branch::forWrite();
        $fromBranch = (int) ($d['from_branch_id'] ?? 0);
        $notes      = self::cleanText((string) ($d['notes'] ?? ''));
        if ($notes !== null && mb_strlen($notes) > 255) {
            throw new HttpException(422, 'Keep the note under 255 characters.');
        }
        $items = [];
        foreach (array_values(is_array($d['items'] ?? null) ? $d['items'] : []) as $n => $item) {
            $pid = (int) ($item['product_id'] ?? 0);
            $qty = (int) ($item['quantity'] ?? 0);
            if ($pid < 1 || $qty < 1 || $qty > Products::MAX_STOCK || isset($items[$pid])) {
                throw new HttpException(422, 'Check the items: one line per product, quantity 1 to ' . number_format(Products::MAX_STOCK) . '.');
            }
            $items[$pid] = ['qty' => $qty, 'sort' => $n + 1];
        }
        if (!$items || count($items) > self::MAX_LINES) {
            throw new HttpException(422, $items ? 'A transfer can have at most ' . self::MAX_LINES . ' items.' : 'Add at least one item.');
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT id FROM branches WHERE id = ? AND is_active = 1');
            $stmt->execute([$fromBranch]);
            if ($fromBranch === $toBranch || $stmt->fetchColumn() === false) {
                throw new HttpException(422, 'Choose the branch to request from.');
            }
            $no = self::nextNumber($fromBranch);
            $pdo->prepare(
                'INSERT INTO stock_transfers (transfer_no, from_branch_id, to_branch_id, status, notes, total_qty, requested_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([$no, $fromBranch, $toBranch, 'requested', $notes, array_sum(array_column($items, 'qty')), $userId]);
            $id = (int) $pdo->lastInsertId();

            $products = self::lockProducts(array_keys($items));
            $add = $pdo->prepare('INSERT INTO stock_transfer_lines (transfer_id, product_id, qty_requested, sort_order) VALUES (?, ?, ?, ?)');
            foreach ($items as $pid => $item) {
                $p = $products[$pid] ?? null;
                if ($p === null || (int) $p['is_active'] !== 1) {
                    throw new HttpException(422, 'An item on this request is no longer active.');
                }
                $add->execute([$id, $pid, $item['qty'], $item['sort']]);
            }
            Audit::record('transfers', 'request', 'stock_transfer', $id, $no, null, array_filter([
                'from' => self::branchName($fromBranch), 'to' => self::branchName($toBranch), 'notes' => $notes,
                'items' => self::auditItems(array_map(static fn (array $i): int => $i['qty'], $items), $products),
            ], static fn ($v) => $v !== null), $toBranch);
            $pdo->commit();
            return ['id' => $id, 'transfer_no' => $no];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // ------------------------------------------------------------------
    // Approve / release / receive / cancel
    // ------------------------------------------------------------------

    /**
     * Approve a request at the sending branch (not by the requester). $qtys = line id => approved qty
     * (0 = not sent; a missing line keeps the requested qty). @return string transfer no
     */
    public static function approve(int $id, array $qtys, int $userId): string
    {
        self::requirePermission('transfers.approve', 'You do not have permission to approve transfers.');
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $t = self::lock($id);
            self::assertWorkingIn((int) $t['from_branch_id'], 'sending');
            if ($t['status'] !== 'requested') {
                throw new HttpException(409, "{$t['transfer_no']} is " . strtolower(self::STATUSES[$t['status']]) . ', so it can no longer be approved.');
            }
            if ($userId === (int) $t['requested_by']) {
                throw new HttpException(403, "You can't approve a transfer you requested.");
            }
            $lines = self::lines($id);
            $total = 0;
            $upd   = $pdo->prepare('UPDATE stock_transfer_lines SET qty_approved = ? WHERE id = ?');
            $audit = [];
            foreach ($lines as $l) {
                $lid = (int) $l['id'];
                $req = (int) $l['qty_requested'];
                $raw = $qtys[$lid] ?? $qtys[(string) $lid] ?? null;
                $qty = $raw === null || $raw === '' ? $req : filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => $req]]);
                if ($qty === false) {
                    throw new HttpException(422, "{$l['product_name']}: approve a whole number from 0 to {$req}.", ['errors' => ["qty.{$lid}" => "0 to {$req}"]]);
                }
                $upd->execute([$qty, $lid]);
                $total += $qty;
                if ($qty !== $req) {
                    $audit[] = "{$l['product_code']} {$req} -> {$qty}";
                }
            }
            if ($total === 0) {
                throw new HttpException(422, 'Approve at least one item, or cancel the request instead.');
            }
            $pdo->prepare('UPDATE stock_transfers SET status = ?, approved_by = ?, approved_at = NOW(), total_qty = ? WHERE id = ?')
                ->execute(['approved', $userId, $total, $id]);
            Audit::record('transfers', 'approve', 'stock_transfer', $id, $t['transfer_no'],
                ['status' => 'requested'], array_filter(['status' => 'approved', 'total_qty' => $total, 'changed' => $audit ?: null]),
                (int) $t['from_branch_id']);
            $pdo->commit();
            return (string) $t['transfer_no'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Release an approved transfer at the sending branch: stock leaves its POS location at the branch average
     * cost; serial-tracked lines need exactly qty_approved serials (line id => serial ids). @return string transfer no
     */
    public static function release(int $id, array $serialIdsByLine, int $userId): string
    {
        self::requirePermission('transfers.release', 'You do not have permission to release transfers.');
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $t = self::lock($id);
            $branchId = (int) $t['from_branch_id'];
            self::assertWorkingIn($branchId, 'sending');
            if ($t['status'] !== 'approved') {
                throw new HttpException(409, "{$t['transfer_no']} is " . strtolower(self::STATUSES[$t['status']]) . ', so it can no longer be released.');
            }
            $from  = Branch::defaultLocation($branchId);
            $lines = array_filter(self::lines($id), static fn (array $l): bool => (int) $l['qty_approved'] > 0);
            $pdo->prepare('UPDATE stock_transfer_lines SET qty_released = 0 WHERE transfer_id = ? AND qty_approved = 0')->execute([$id]);
            usort($lines, static fn (array $a, array $b): int => (int) $a['product_id'] <=> (int) $b['product_id']);
            $products = self::lockProducts(array_column($lines, 'product_id'));

            // Serial choice per tracked line: exactly qty_approved distinct ids.
            $serials = [];
            foreach ($lines as $l) {
                $lid = (int) $l['id'];
                $ids = self::intList($serialIdsByLine[$lid] ?? $serialIdsByLine[(string) $lid] ?? []);
                $p   = $products[(int) $l['product_id']];
                $qty = (int) $l['qty_approved'];
                if ((int) $p['track_serial'] === 1) {
                    if (count($ids) !== $qty || count(array_unique($ids)) !== $qty) {
                        throw new HttpException(422, "{$p['name']}: choose exactly {$qty} serial number" . ($qty === 1 ? '' : 's') . '.',
                            ['errors' => ["serials.{$lid}" => "Choose {$qty}."]]);
                    }
                    $serials[$lid] = [(int) $l['product_id'], $ids];
                } elseif ($ids) {
                    throw new HttpException(422, "{$p['name']} does not use serial numbers.");
                }
            }

            $note  = "{$t['transfer_no']} to " . self::branchName((int) $t['to_branch_id']);
            $upd   = $pdo->prepare('UPDATE stock_transfer_lines SET qty_released = ?, unit_cost = ? WHERE id = ?');
            $total = 0;
            $cents = 0;
            foreach ($lines as $l) { // product id order
                $pid = (int) $l['product_id'];
                $qty = (int) $l['qty_approved'];
                Stock::balance($pid, $from['id']);        // stock_balances before product_branches
                $cost = Costing::avg($pid, $branchId);     // sender's average, unchanged by the release
                Stock::move($pid, $from + ['label' => $from['branch_name']], -$qty, 'transfer_out', $note, null, $userId, null, null, $id);
                $upd->execute([$qty, $cost, (int) $l['id']]);
                $total += $qty;
                $cents += Costing::lineCents($qty, $cost);
            }

            // Serials last: in stock at the sending POS location, of that product -> in transit.
            $count = self::lockSerialsForRelease($serials, $from['id'], $products);

            $pdo->prepare(
                'UPDATE stock_transfers SET status = ?, from_warehouse_id = ?, from_location_id = ?, released_by = ?, released_at = NOW(),
                                            total_qty = ?, total_cost = ? WHERE id = ?'
            )->execute(['released', $from['warehouse_id'], $from['id'], $userId, $total, from_cents($cents), $id]);
            Audit::record('transfers', 'release', 'stock_transfer', $id, $t['transfer_no'], ['status' => 'approved'],
                ['status' => 'released', 'total_qty' => $total, 'serials' => $count], $branchId);
            $pdo->commit();
            return (string) $t['transfer_no'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<int, array{0:int, 1:list<int>}> $serials line id => [product id, serial ids] */
    private static function lockSerialsForRelease(array $serials, int $locationId, array $products): int
    {
        $all = [];
        foreach ($serials as $lid => [$pid, $ids]) {
            foreach ($ids as $sid) {
                if (isset($all[$sid])) {
                    throw new HttpException(422, 'A serial number is chosen twice.');
                }
                $all[$sid] = [$lid, $pid];
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
        $rows = array_column($stmt->fetchAll(), null, 'id');
        $upd  = $pdo->prepare('UPDATE product_serials SET status = ? WHERE id = ?');
        $link = $pdo->prepare('INSERT INTO stock_transfer_serials (line_id, serial_id, received) VALUES (?, ?, NULL)');
        foreach ($ids as $sid) {
            [$lid, $pid] = $all[$sid];
            $r = $rows[$sid] ?? null;
            if ($r === null || (int) $r['product_id'] !== $pid) {
                throw new HttpException(409, "{$products[$pid]['name']}: a selected serial number is no longer available.");
            }
            if ($r['status'] !== 'in_stock' || (int) $r['location_id'] !== $locationId) {
                throw new HttpException(409, "Serial {$r['serial_no']} is no longer in stock at the POS location.");
            }
            $upd->execute(['in_transit', $sid]);
            $link->execute([$lid, $sid]);
        }
        return count($ids);
    }

    /**
     * Receive a released transfer at the receiving branch (not by the releaser). $qtys = line id => qty received
     * (non-serial lines; missing = all), $serialIds = serials that arrived (serial lines count these). Anything
     * short needs $note (3-255). Stock enters the receiving POS location at the sender's cost. @return string transfer no
     */
    public static function receive(int $id, array $qtys, array $serialIds, ?string $note, int $userId): string
    {
        self::requirePermission('transfers.receive', 'You do not have permission to receive transfers.');
        $note = self::cleanText((string) $note);
        $pdo  = db();
        $pdo->beginTransaction();
        try {
            $t = self::lock($id);
            $branchId = (int) $t['to_branch_id'];
            self::assertWorkingIn($branchId, 'receiving');
            if ($t['status'] !== 'released') {
                throw new HttpException(409, "{$t['transfer_no']} is " . strtolower(self::STATUSES[$t['status']]) . ', so it can no longer be received.');
            }
            if ($userId === (int) $t['released_by']) {
                throw new HttpException(403, "You can't receive a transfer you released.");
            }
            $to      = Branch::defaultLocation($branchId);
            $arrived = array_flip(self::intList($serialIds));
            $lines   = array_filter(self::lines($id), static fn (array $l): bool => (int) $l['qty_released'] > 0);
            usort($lines, static fn (array $a, array $b): int => (int) $a['product_id'] <=> (int) $b['product_id']);
            $products = self::lockProducts(array_column($lines, 'product_id'));

            // Serial rows of the released lines.
            $lineSerials = [];
            if ($lines) {
                $in_  = implode(',', array_fill(0, count($lines), '?'));
                $stmt = $pdo->prepare("SELECT line_id, serial_id FROM stock_transfer_serials WHERE line_id IN ({$in_})");
                $stmt->execute(array_column($lines, 'id'));
                foreach ($stmt->fetchAll() as $r) {
                    $lineSerials[(int) $r['line_id']][] = (int) $r['serial_id'];
                }
            }

            // Received quantity per line.
            $recv  = [];
            $short = 0;
            $shortList = [];
            foreach ($lines as $l) {
                $lid = (int) $l['id'];
                $rel = (int) $l['qty_released'];
                $p   = $products[(int) $l['product_id']];
                if (isset($lineSerials[$lid])) {
                    if ((int) $p['track_serial'] !== 1) {
                        throw new HttpException(409, "{$p['name']}: serial tracking changed while in transit. Ask an administrator.");
                    }
                    $qty = count(array_filter($lineSerials[$lid], static fn (int $sid): bool => isset($arrived[$sid])));
                } else {
                    if ((int) $p['track_serial'] === 1) {
                        throw new HttpException(409, "{$p['name']}: serial tracking changed while in transit. Ask an administrator.");
                    }
                    $raw = $qtys[$lid] ?? $qtys[(string) $lid] ?? null;
                    $qty = $raw === null || $raw === '' ? $rel : filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => $rel]]);
                    if ($qty === false) {
                        throw new HttpException(422, "{$p['name']}: enter a whole number from 0 to {$rel}.", ['errors' => ["qty.{$lid}" => "0 to {$rel}"]]);
                    }
                }
                $recv[$lid] = $qty;
                if ($qty < $rel) {
                    $short += $rel - $qty;
                    $shortList[] = "{$p['code']} " . ($rel - $qty) . ' short';
                }
            }
            if ($short > 0 && ($note === null || mb_strlen($note) < 3 || mb_strlen($note) > 255)) {
                throw new HttpException(422, "{$short} unit" . ($short === 1 ? ' is' : 's are') . ' short. Explain what happened (3 to 255 characters).',
                    ['errors' => ['receive_note' => 'Explain the shortage (3 to 255 characters).']]);
            }
            if ($note !== null && mb_strlen($note) > 255) {
                throw new HttpException(422, 'Keep the note under 255 characters.');
            }

            $msg   = "{$t['transfer_no']} from " . self::branchName((int) $t['from_branch_id']);
            $upd   = $pdo->prepare('UPDATE stock_transfer_lines SET qty_received = ? WHERE id = ?');
            $total = 0;
            foreach ($lines as $l) { // product id order
                $pid = (int) $l['product_id'];
                $qty = $recv[(int) $l['id']];
                if ($qty > 0) {
                    $cost = $l['unit_cost'] !== null ? (string) $l['unit_cost'] : Costing::avg($pid, (int) $t['from_branch_id']);
                    Costing::inbound($pid, $branchId, $qty, $cost);  // locks stock_balances, then product_branches
                    $after = Stock::move($pid, $to + ['label' => $to['branch_name']], $qty, 'transfer_in', $msg, null, $userId, null, null, $id);
                    if ($after['location_qty'] > Products::MAX_STOCK) {
                        throw new HttpException(422, "{$products[$pid]['name']}: stock can be at most " . number_format(Products::MAX_STOCK) . '.');
                    }
                }
                $upd->execute([$qty, (int) $l['id']]);
                $total += $qty;
            }

            // Serials last: in transit -> in stock at the receiving POS location, or removed when missing.
            $serialCount = 0;
            $all = array_merge(...array_values($lineSerials ?: [[]]));
            if ($all) {
                sort($all);
                $in_  = implode(',', array_fill(0, count($all), '?'));
                $stmt = $pdo->prepare("SELECT id, serial_no, status FROM product_serials WHERE id IN ({$in_}) ORDER BY id FOR UPDATE");
                $stmt->execute($all);
                $rows   = array_column($stmt->fetchAll(), null, 'id');
                $inStk  = $pdo->prepare('UPDATE product_serials SET status = ?, branch_id = ?, warehouse_id = ?, location_id = ? WHERE id = ?');
                $gone   = $pdo->prepare('UPDATE product_serials SET status = ? WHERE id = ?');
                $mark   = $pdo->prepare('UPDATE stock_transfer_serials SET received = ? WHERE serial_id = ? AND line_id = ?');
                $lineOf = [];
                foreach ($lineSerials as $lid => $sids) {
                    foreach ($sids as $sid) {
                        $lineOf[$sid] = $lid;
                    }
                }
                foreach ($all as $sid) {
                    if (($rows[$sid]['status'] ?? null) !== 'in_transit') {
                        throw new HttpException(409, 'Serial ' . ($rows[$sid]['serial_no'] ?? $sid) . ' is not in transit. Ask an administrator.');
                    }
                    if (isset($arrived[$sid])) {
                        $inStk->execute(['in_stock', $branchId, $to['warehouse_id'], $to['id'], $sid]);
                        $serialCount++;
                    } else {
                        $gone->execute(['removed', $sid]);
                    }
                    $mark->execute([isset($arrived[$sid]) ? 1 : 0, $sid, $lineOf[$sid]]);
                }
            }

            $pdo->prepare(
                'UPDATE stock_transfers SET status = ?, to_warehouse_id = ?, to_location_id = ?, received_by = ?, received_at = NOW(),
                                            receive_note = ? WHERE id = ?'
            )->execute(['received', $to['warehouse_id'], $to['id'], $userId, $note, $id]);
            Audit::record('transfers', 'receive', 'stock_transfer', $id, $t['transfer_no'], ['status' => 'released'], array_filter([
                'status' => 'received', 'received_qty' => $total, 'serials' => $serialCount ?: null,
                'short' => $shortList ?: null, 'note' => $note,
            ], static fn ($v) => $v !== null), $branchId);
            $pdo->commit();
            return (string) $t['transfer_no'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Cancel before release: the requesting side (transfers.request) or the sending side (transfers.approve). */
    public static function cancel(int $id, string $reason, int $userId): void
    {
        self::requireView();
        $reason = self::cleanText($reason);
        if ($reason === null || mb_strlen($reason) < 3 || mb_strlen($reason) > 255) {
            throw new HttpException(422, 'Enter a reason (3 to 255 characters).', ['errors' => ['reason' => 'Enter a reason (3 to 255 characters).']]);
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $t   = self::lock($id);
            $cur = Branch::current();
            if (!in_array($cur, [(int) $t['from_branch_id'], (int) $t['to_branch_id']], true)) {
                self::assertWorkingIn((int) $t['to_branch_id'], 'requesting or sending');
            }
            $side = $cur === (int) $t['to_branch_id'] ? 'transfers.request' : 'transfers.approve';
            self::requirePermission($side, 'You do not have permission to cancel this transfer.');
            if (!in_array($t['status'], ['requested', 'approved'], true)) {
                throw new HttpException(409, $t['status'] === 'cancelled'
                    ? "{$t['transfer_no']} is already cancelled."
                    : "{$t['transfer_no']} was already released, so it can't be cancelled. Receive it instead.");
            }
            $pdo->prepare('UPDATE stock_transfers SET status = ?, cancelled_by = ?, cancelled_at = NOW(), cancel_reason = ? WHERE id = ?')
                ->execute(['cancelled', $userId, $reason, $id]);
            Audit::record('transfers', 'cancel', 'stock_transfer', $id, $t['transfer_no'], ['status' => $t['status']],
                ['status' => 'cancelled', 'reason' => $reason], (int) $cur);
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

    /** Lock the transfer row (first in the lock order); 404 when missing or out of scope. */
    private static function lock(int $id): array
    {
        $stmt = db()->prepare('SELECT * FROM stock_transfers WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $t = $stmt->fetch();
        if (!$t || (!Branch::inScope((int) $t['from_branch_id']) && !Branch::inScope((int) $t['to_branch_id']))) {
            throw new HttpException(404, 'Transfer not found.');
        }
        return $t;
    }

    private static function lines(int $id): array
    {
        $stmt = db()->prepare(
            'SELECT tl.*, p.code AS product_code, p.name AS product_name
               FROM stock_transfer_lines tl JOIN products p ON p.id = tl.product_id
              WHERE tl.transfer_id = ? ORDER BY tl.sort_order, tl.id'
        );
        $stmt->execute([$id]);
        return $stmt->fetchAll();
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

    /** BT-<sending branch>-<year>-NNNNNN from document_sequences (locked row; a rollback releases it). */
    private static function nextNumber(int $branchId): string
    {
        $pdo  = db();
        $year = (int) date('Y');
        $pdo->prepare(
            'INSERT INTO document_sequences (branch_id, doc_type, year, last_no) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE last_no = last_no'
        )->execute([$branchId, self::PREFIX, $year, 0]);
        $stmt = $pdo->prepare('SELECT last_no FROM document_sequences WHERE branch_id = ? AND doc_type = ? AND year = ? FOR UPDATE');
        $stmt->execute([$branchId, self::PREFIX, $year]);
        $next = (int) $stmt->fetchColumn() + 1;
        $pdo->prepare('UPDATE document_sequences SET last_no = ? WHERE branch_id = ? AND doc_type = ? AND year = ?')
            ->execute([$next, $branchId, self::PREFIX, $year]);
        $stmt = $pdo->prepare('SELECT code FROM branches WHERE id = ?');
        $stmt->execute([$branchId]);
        return sprintf('%s-%s-%04d-%06d', self::PREFIX, (string) $stmt->fetchColumn(), $year, $next);
    }

    private static function cleanText(string $text): ?string
    {
        $text = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text) ?? '');
        return $text === '' ? null : mb_substr($text, 0, 256); // 256: lets the length check catch > 255
    }

    /** Positive int list from an array of ids (strings / ints); other values are dropped. */
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

    /** "ITM-0002 x3" per line (never cost). @param array<int,int> $qtys product id => qty */
    private static function auditItems(array $qtys, array $products): array
    {
        $out = [];
        foreach ($qtys as $pid => $qty) {
            $out[] = ($products[$pid]['code'] ?? $pid) . ' x' . $qty;
        }
        return $out;
    }
}
