<?php
/**
 * Purchase requests (purchase_requests + lines), audit module 'purchasing'.
 *
 *   requested  branch staff ask for items (purchasing.request, working in the branch); numbered at once
 *              PR-<branch>-<year>-NNNNNN. Optional: needed-by date, purpose, an open job order of the branch.
 *   approved   qty_approved per line (0 = not approved), purchasing.approve, never the requester
 *   ordered    every approved unit is on a purchase order (draft / pending / approved / ... but not cancelled)
 *   rejected   purchasing.approve with a note
 *   cancelled  while requested, or approved with nothing on a purchase order yet (requester or approver)
 * "Ordered" quantities come from purchase_order_request_lines of POs that are not cancelled, so cancelling or
 * deleting a PO hands the units back (refreshStatus()). No stock or cost here.
 * Lock order: purchase_orders row (PO side) -> purchase_requests rows (ORDER BY id) -> document_sequences.
 */
declare(strict_types=1);

final class PurchaseRequests
{
    public const STATUSES = [
        'requested' => 'For Approval', 'approved' => 'Approved', 'ordered' => 'Ordered',
        'rejected'  => 'Rejected',     'cancelled' => 'Cancelled',
    ];
    public const BADGES = [
        'requested' => 'badge--info', 'approved' => 'badge--warning', 'ordered' => 'badge--success',
        'rejected'  => 'badge--danger', 'cancelled' => 'badge--danger',
    ];
    public const VIEW_PERMISSIONS = ['purchasing.request', 'purchasing.approve', 'purchasing.order'];
    public const PREFIX    = 'PR';
    public const MAX_LINES = 100;

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
            throw new HttpException(403, 'You do not have permission to view purchase requests.');
        }
    }

    /** The session must work in the request's branch: 422 under "All branches". */
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

    /** @param array{search?:string, status?:string} $f status may also be 'open' (approved, not fully ordered) */
    private static function where(array $f): array
    {
        [$scope, $params] = Branch::scopeSql('r.branch_id');
        $where  = [$scope];
        $status = (string) ($f['status'] ?? '');
        if (isset(self::STATUSES[$status])) {
            $where[]  = 'r.status = ?';
            $params[] = $status;
        }
        $q = (string) ($f['search'] ?? '');
        if ($q !== '') {
            $like = like_pattern($q);
            $where[] = '(r.pr_no LIKE ? OR r.purpose LIKE ? OR EXISTS (SELECT 1 FROM purchase_request_lines rl
                            JOIN products p ON p.id = rl.product_id
                           WHERE rl.request_id = r.id AND (p.code LIKE ? OR p.name LIKE ? OR rl.end_user LIKE ?)))';
            array_push($params, $like, $like, $like, $like, $like);
        }
        return [implode(' AND ', $where), $params];
    }

    public static function count(array $f): int
    {
        self::requireView();
        [$where, $params] = self::where($f);
        $stmt = db()->prepare("SELECT COUNT(*) FROM purchase_requests r WHERE {$where}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public static function search(array $f, int $limit, int $offset): array
    {
        self::requireView();
        [$where, $params] = self::where($f);
        $stmt = db()->prepare(
            "SELECT r.id, r.pr_no, r.status, r.needed_by, r.purpose, r.total_qty, r.requested_at, r.branch_id,
                    b.code AS branch_code, b.name AS branch_name, u.full_name AS requested_by_name, j.job_no,
                    (SELECT COUNT(*) FROM purchase_request_lines rl WHERE rl.request_id = r.id) AS line_count
               FROM purchase_requests r
               JOIN branches b ON b.id = r.branch_id
               JOIN users u ON u.id = r.requested_by
               LEFT JOIN job_orders j ON j.id = r.job_order_id
              WHERE {$where}
              ORDER BY r.status IN ('requested', 'approved') DESC, r.requested_at DESC, r.id DESC
              LIMIT ? OFFSET ?"
        );
        $stmt->execute([...$params, $limit, $offset]);
        return $stmt->fetchAll();
    }

    /** Work lists for the current branch: {approve: requests waiting, order: approved, not fully ordered}. */
    public static function workCounts(): array
    {
        $out = ['approve' => 0, 'order' => 0];
        if (!Branch::isConcrete() || !Auth::canAny(...self::VIEW_PERMISSIONS)) {
            return $out;
        }
        $stmt = db()->prepare(
            "SELECT SUM(status = 'requested'), SUM(status = 'approved') FROM purchase_requests
              WHERE branch_id = ? AND status IN ('requested', 'approved')"
        );
        $stmt->execute([(int) Branch::current()]);
        [$a, $o] = $stmt->fetch(PDO::FETCH_NUM) ?: [0, 0];
        return ['approve' => (int) $a, 'order' => (int) $o];
    }

    /**
     * Request with names, job number and 'lines' (product, unit, requested / approved / ordered / remaining)
     * and 'orders' (purchase orders that order its lines). Null when missing; 404 outside the branch scope.
     */
    public static function find(int $id): ?array
    {
        self::requireView();
        $stmt = db()->prepare(
            'SELECT r.*, b.code AS branch_code, b.name AS branch_name, b.address AS branch_address, b.contact_no AS branch_contact,
                    ru.full_name AS requested_by_name, du.full_name AS decided_by_name, xu.full_name AS cancelled_by_name,
                    j.job_no, j.status AS job_status
               FROM purchase_requests r
               JOIN branches b ON b.id = r.branch_id
               JOIN users ru ON ru.id = r.requested_by
               LEFT JOIN users du ON du.id = r.decided_by
               LEFT JOIN users xu ON xu.id = r.cancelled_by
               LEFT JOIN job_orders j ON j.id = r.job_order_id
              WHERE r.id = ?'
        );
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        if (!$r) {
            return null;
        }
        Branch::assertAccess((int) $r['branch_id']);

        $stmt = db()->prepare(
            "SELECT rl.*, p.code AS product_code, p.name AS product_name, p.track_serial, un.code AS unit_code,
                    COALESCE((SELECT SUM(x.qty) FROM purchase_order_request_lines x
                                JOIN purchase_order_lines ol ON ol.id = x.po_line_id
                                JOIN purchase_orders o ON o.id = ol.po_id
                               WHERE x.request_line_id = rl.id AND o.status <> 'cancelled'), 0) AS qty_ordered
               FROM purchase_request_lines rl
               JOIN products p ON p.id = rl.product_id
               LEFT JOIN units un ON un.id = p.unit_id
              WHERE rl.request_id = ?
              ORDER BY rl.sort_order, rl.id"
        );
        $stmt->execute([$id]);
        $lines = $stmt->fetchAll();
        foreach ($lines as &$l) {
            $l['qty_ordered'] = (int) $l['qty_ordered'];
            $l['remaining']   = $l['qty_approved'] === null ? 0 : max(0, (int) $l['qty_approved'] - $l['qty_ordered']);
        }
        unset($l);
        $r['lines'] = $lines;

        $stmt = db()->prepare(
            'SELECT DISTINCT o.id, o.po_no, o.status, o.order_date
               FROM purchase_order_request_lines x
               JOIN purchase_request_lines rl ON rl.id = x.request_line_id
               JOIN purchase_order_lines ol ON ol.id = x.po_line_id
               JOIN purchase_orders o ON o.id = ol.po_id
              WHERE rl.request_id = ?
              ORDER BY o.id'
        );
        $stmt->execute([$id]);
        $r['orders'] = $stmt->fetchAll();
        return $r;
    }

    public static function label(array $r): string
    {
        return (string) $r['pr_no'];
    }

    /** Which buttons the current user gets: {approve, reject, cancel, order}. */
    public static function actions(array $r): array
    {
        $here      = Branch::current() === (int) $r['branch_id'];
        $uid       = (int) Auth::id();
        $s         = $r['status'];
        $ordered   = array_sum(array_map(static fn (array $l): int => (int) $l['qty_ordered'], $r['lines'] ?? []));
        $remaining = array_sum(array_map(static fn (array $l): int => (int) $l['remaining'], $r['lines'] ?? []));
        $decide    = $s === 'requested' && $here && Auth::can('purchasing.approve') && $uid !== (int) $r['requested_by'];
        return [
            'approve' => $decide,
            'reject'  => $decide,
            'cancel'  => $here && ($s === 'requested' || ($s === 'approved' && $ordered === 0))
                         && (Auth::can('purchasing.approve') || ($uid === (int) $r['requested_by'] && Auth::can('purchasing.request'))),
            'order'   => $here && $s === 'approved' && $remaining > 0 && PurchaseOrders::canManage(),
        ];
    }

    /** Open job orders of the current branch (to link a request to). */
    public static function openJobs(): array
    {
        if (!Branch::isConcrete()) {
            return [];
        }
        $in_  = implode(',', array_fill(0, count(JobOrders::OPEN), '?'));
        $stmt = db()->prepare(
            "SELECT id, job_no, customer_name, brand, model FROM job_orders
              WHERE branch_id = ? AND status IN ({$in_}) ORDER BY id DESC LIMIT 200"
        );
        $stmt->execute([(int) Branch::current(), ...JobOrders::OPEN]);
        return $stmt->fetchAll();
    }

    // ------------------------------------------------------------------
    // Create
    // ------------------------------------------------------------------

    /**
     * @return array{0: array{needed_by:?string, purpose:?string, job_order_id:?int,
     *               items:list<array{product_id:?int, quantity:?int, end_user:?string}>}, 1: array<string,string>}
     *         errors keyed 'needed_by', 'purpose', 'job_order_id', 'items', 'items.i.product_id|quantity|end_user'
     */
    public static function validate(array $in): array
    {
        self::requirePermission('purchasing.request', 'You do not have permission to create purchase requests.');
        $errors = [];
        $purpose = input_string($in, 'purpose', 300);
        $data = [
            'needed_by'    => input_date($in, 'needed_by'),
            'purpose'      => $purpose !== '' ? $purpose : null,
            'job_order_id' => input_int($in, 'job_order_id', 1),
            'items'        => [],
        ];
        if (is_string($in['needed_by'] ?? null) && trim($in['needed_by']) !== '' && $data['needed_by'] === null) {
            $errors['needed_by'] = 'Enter a valid date.';
        } elseif ($data['needed_by'] !== null && $data['needed_by'] < date('Y-m-d')) {
            $errors['needed_by'] = 'The needed-by date cannot be in the past.';
        }
        if ($data['purpose'] !== null && mb_strlen($data['purpose']) > 255) {
            $errors['purpose'] = 'Keep the purpose under 255 characters.';
        }
        if ($data['job_order_id'] !== null && !in_array($data['job_order_id'], array_map('intval', array_column(self::openJobs(), 'id')), true)) {
            $errors['job_order_id'] = 'Choose an open job order of this branch.';
        }

        $lines = [];
        foreach (is_array($in['items'] ?? null) ? array_values($in['items']) : [] as $i => $line) {
            if (!is_array($line)) {
                continue;
            }
            $blank = static fn (string $k): bool => !isset($line[$k]) || (is_string($line[$k]) && trim($line[$k]) === '');
            if ($blank('product_id') && $blank('quantity') && $blank('end_user')) {
                continue; // empty template row
            }
            $lines[$i] = $line;
        }
        if (!$lines) {
            $errors['items'] = 'Add at least one item.';
        } elseif (count($lines) > self::MAX_LINES) {
            $errors['items'] = 'A request can have at most ' . self::MAX_LINES . ' items.';
        }
        $products = self::products($lines);
        $seen = [];
        foreach ($lines as $i => $line) {
            $pid  = input_int($line, 'product_id', 1);
            $qty  = input_int($line, 'quantity', 1, Products::MAX_STOCK);
            $user = input_string($line, 'end_user', 101);
            $p    = $pid !== null ? ($products[$pid] ?? null) : null;
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
            if (mb_strlen($user) > 100) {
                $errors["items.{$i}.end_user"] = 'Keep the end-user under 100 characters.';
            }
            $data['items'][] = ['product_id' => $pid, 'quantity' => $qty, 'end_user' => $user !== '' ? $user : null];
        }
        return [$data, $errors];
    }

    /** Create a request at the current branch (data from validate()). @return array{id:int, pr_no:string} */
    public static function create(array $d, int $userId): array
    {
        self::requirePermission('purchasing.request', 'You do not have permission to create purchase requests.');
        $branchId = Branch::forWrite();
        $items = [];
        foreach (array_values(is_array($d['items'] ?? null) ? $d['items'] : []) as $n => $item) {
            $pid = (int) ($item['product_id'] ?? 0);
            $qty = (int) ($item['quantity'] ?? 0);
            if ($pid < 1 || $qty < 1 || $qty > Products::MAX_STOCK || isset($items[$pid])) {
                throw new HttpException(422, 'Check the items: one line per product, quantity 1 to ' . number_format(Products::MAX_STOCK) . '.');
            }
            $items[$pid] = ['qty' => $qty, 'end_user' => $item['end_user'] ?? null, 'sort' => $n + 1];
        }
        if (!$items || count($items) > self::MAX_LINES) {
            throw new HttpException(422, $items ? 'A request can have at most ' . self::MAX_LINES . ' items.' : 'Add at least one item.');
        }
        $jobId = $d['job_order_id'] ?? null;

        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($jobId !== null) {
                $stmt = $pdo->prepare('SELECT job_no, branch_id, status FROM job_orders WHERE id = ?');
                $stmt->execute([$jobId]);
                $job = $stmt->fetch();
                if (!$job || (int) $job['branch_id'] !== $branchId || !in_array($job['status'], JobOrders::OPEN, true)) {
                    throw new HttpException(422, 'Choose an open job order of this branch.');
                }
            }
            $no = DocNumber::next($branchId, self::PREFIX);
            $pdo->prepare(
                'INSERT INTO purchase_requests (pr_no, branch_id, status, needed_by, purpose, job_order_id, total_qty, requested_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$no, $branchId, 'requested', $d['needed_by'] ?? null, $d['purpose'] ?? null, $jobId,
                array_sum(array_column($items, 'qty')), $userId]);
            $id = (int) $pdo->lastInsertId();

            $products = self::products(array_map(static fn (int $pid): array => ['product_id' => $pid], array_keys($items)));
            $add = $pdo->prepare(
                'INSERT INTO purchase_request_lines (request_id, product_id, end_user, qty_requested, sort_order) VALUES (?, ?, ?, ?, ?)'
            );
            $audit = [];
            foreach ($items as $pid => $item) {
                $p = $products[$pid] ?? null;
                if ($p === null || (int) $p['is_active'] !== 1) {
                    throw new HttpException(422, 'An item on this request is no longer active.');
                }
                $add->execute([$id, $pid, $item['end_user'], $item['qty'], $item['sort']]);
                $audit[] = $p['code'] . ' x' . $item['qty'];
            }
            Audit::record('purchasing', 'pr_create', 'purchase_request', $id, $no, null, array_filter([
                'needed_by' => $d['needed_by'] ?? null, 'purpose' => $d['purpose'] ?? null,
                'job' => isset($job) ? $job['job_no'] : null, 'items' => $audit,
            ], static fn ($v) => $v !== null), $branchId);
            $pdo->commit();
            return ['id' => $id, 'pr_no' => $no];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // ------------------------------------------------------------------
    // Approve / reject / cancel
    // ------------------------------------------------------------------

    /** Approve (line id => qty, 0..requested; a missing line keeps the requested qty). @return string pr no */
    public static function approve(int $id, array $qtys, ?string $note, int $userId): string
    {
        self::requirePermission('purchasing.approve', 'You do not have permission to approve purchase requests.');
        $note = self::cleanNote($note);
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $r = self::lock($id);
            self::assertDecidable($r, $userId, 'approve');
            $stmt = $pdo->prepare(
                'SELECT rl.id, rl.qty_requested, p.code, p.name FROM purchase_request_lines rl JOIN products p ON p.id = rl.product_id
                  WHERE rl.request_id = ? ORDER BY rl.sort_order, rl.id'
            );
            $stmt->execute([$id]);
            $upd   = $pdo->prepare('UPDATE purchase_request_lines SET qty_approved = ? WHERE id = ?');
            $total = 0;
            $audit = [];
            foreach ($stmt->fetchAll() as $l) {
                $lid = (int) $l['id'];
                $req = (int) $l['qty_requested'];
                $raw = $qtys[$lid] ?? $qtys[(string) $lid] ?? null;
                $qty = $raw === null || $raw === '' ? $req : filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => $req]]);
                if ($qty === false) {
                    throw new HttpException(422, "{$l['name']}: approve a whole number from 0 to {$req}.", ['errors' => ["qty.{$lid}" => "0 to {$req}"]]);
                }
                $upd->execute([$qty, $lid]);
                $total += $qty;
                if ($qty !== $req) {
                    $audit[] = "{$l['code']} {$req} -> {$qty}";
                }
            }
            if ($total === 0) {
                throw new HttpException(422, 'Approve at least one item, or reject the request instead.');
            }
            $pdo->prepare('UPDATE purchase_requests SET status = ?, decided_by = ?, decided_at = NOW(), decision_note = ? WHERE id = ?')
                ->execute(['approved', $userId, $note, $id]);
            Audit::record('purchasing', 'pr_approve', 'purchase_request', $id, $r['pr_no'], ['status' => 'requested'],
                array_filter(['status' => 'approved', 'approved_qty' => $total, 'changed' => $audit ?: null, 'note' => $note]),
                (int) $r['branch_id']);
            $pdo->commit();
            return (string) $r['pr_no'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function reject(int $id, ?string $note, int $userId): string
    {
        self::requirePermission('purchasing.approve', 'You do not have permission to reject purchase requests.');
        $note = self::cleanNote($note);
        if ($note === null || mb_strlen($note) < 3) {
            throw new HttpException(422, 'Enter the reason for rejecting (3–255 characters).', ['errors' => ['note' => 'Enter a reason.']]);
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $r = self::lock($id);
            self::assertDecidable($r, $userId, 'reject');
            $pdo->prepare('UPDATE purchase_requests SET status = ?, decided_by = ?, decided_at = NOW(), decision_note = ? WHERE id = ?')
                ->execute(['rejected', $userId, $note, $id]);
            Audit::record('purchasing', 'pr_reject', 'purchase_request', $id, $r['pr_no'], ['status' => 'requested'],
                ['status' => 'rejected', 'note' => $note], (int) $r['branch_id']);
            $pdo->commit();
            return (string) $r['pr_no'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Cancel while requested, or approved with nothing on a purchase order (requester or approver). */
    public static function cancel(int $id, ?string $reason, int $userId): string
    {
        self::requireView();
        $reason = self::cleanNote($reason);
        if ($reason === null || mb_strlen($reason) < 3) {
            throw new HttpException(422, 'Enter a reason (3–255 characters).', ['errors' => ['reason' => 'Enter a reason.']]);
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $r = self::lock($id);
            self::assertWorkingIn((int) $r['branch_id']);
            $mine = $userId === (int) $r['requested_by'] && Auth::can('purchasing.request');
            if (!$mine && !Auth::can('purchasing.approve')) {
                throw new HttpException(403, 'Only the requester or an approver can cancel this request.');
            }
            if (!in_array($r['status'], ['requested', 'approved'], true)) {
                throw new HttpException(409, "{$r['pr_no']} is " . strtolower(self::STATUSES[$r['status']]) . ', so it can no longer be cancelled.');
            }
            if (self::orderedTotal([$id]) > 0) {
                throw new HttpException(409, "{$r['pr_no']} is already on a purchase order. Cancel or change that purchase order first.");
            }
            $pdo->prepare('UPDATE purchase_requests SET status = ?, cancelled_by = ?, cancelled_at = NOW(), cancel_reason = ? WHERE id = ?')
                ->execute(['cancelled', $userId, $reason, $id]);
            Audit::record('purchasing', 'pr_cancel', 'purchase_request', $id, $r['pr_no'], ['status' => $r['status']],
                ['status' => 'cancelled', 'reason' => $reason], (int) $r['branch_id']);
            $pdo->commit();
            return (string) $r['pr_no'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // ------------------------------------------------------------------
    // Purchase order side
    // ------------------------------------------------------------------

    /**
     * Approved request lines of a branch that still need ordering, for a new PO (optionally only some requests).
     * @param list<int>|null $requestIds
     * @return list<array{line_id:int, request_id:int, pr_no:string, product_id:int, end_user:?string, remaining:int}>
     */
    public static function openLines(int $branchId, ?array $requestIds = null): array
    {
        $sql = "SELECT rl.id AS line_id, r.id AS request_id, r.pr_no, rl.product_id, rl.end_user,
                       rl.qty_approved - COALESCE((SELECT SUM(x.qty) FROM purchase_order_request_lines x
                                    JOIN purchase_order_lines ol ON ol.id = x.po_line_id
                                    JOIN purchase_orders o ON o.id = ol.po_id
                                   WHERE x.request_line_id = rl.id AND o.status <> 'cancelled'), 0) AS remaining
                  FROM purchase_request_lines rl
                  JOIN purchase_requests r ON r.id = rl.request_id
                 WHERE r.branch_id = ? AND r.status = 'approved' AND rl.qty_approved > 0";
        $params = [$branchId];
        if ($requestIds !== null) {
            $requestIds = array_values(array_unique(array_map('intval', $requestIds)));
            if (!$requestIds) {
                return [];
            }
            $sql .= ' AND r.id IN (' . implode(',', array_fill(0, count($requestIds), '?')) . ')';
            array_push($params, ...$requestIds);
        }
        $stmt = db()->prepare($sql . ' ORDER BY r.id, rl.sort_order, rl.id');
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll() as $l) {
            if ((int) $l['remaining'] > 0) {
                $out[] = [
                    'line_id' => (int) $l['line_id'], 'request_id' => (int) $l['request_id'], 'pr_no' => $l['pr_no'],
                    'product_id' => (int) $l['product_id'], 'end_user' => $l['end_user'], 'remaining' => (int) $l['remaining'],
                ];
            }
        }
        return $out;
    }

    /** Approved requests of a branch with units still to order (PO form picker). */
    public static function openRequests(int $branchId): array
    {
        $byRequest = [];
        foreach (self::openLines($branchId) as $l) {
            $byRequest[$l['request_id']] ??= ['id' => $l['request_id'], 'pr_no' => $l['pr_no'], 'lines' => 0, 'qty' => 0];
            $byRequest[$l['request_id']]['lines']++;
            $byRequest[$l['request_id']]['qty'] += $l['remaining'];
        }
        if (!$byRequest) {
            return [];
        }
        $in_  = implode(',', array_fill(0, count($byRequest), '?'));
        $stmt = db()->prepare("SELECT r.id, r.purpose, r.needed_by, u.full_name FROM purchase_requests r JOIN users u ON u.id = r.requested_by WHERE r.id IN ({$in_})");
        $stmt->execute(array_keys($byRequest));
        foreach ($stmt->fetchAll() as $r) {
            $byRequest[(int) $r['id']] += ['purpose' => $r['purpose'], 'needed_by' => $r['needed_by'], 'requested_by_name' => $r['full_name']];
        }
        return array_values($byRequest);
    }

    /**
     * Lock the requests behind these request lines (ORDER BY id FOR UPDATE) and return the lines with what is
     * still free to order, not counting purchase order $exceptPoId. @param list<int> $lineIds
     * @return array<int, array{request_id:int, pr_no:string, branch_id:int, status:string, product_id:int, free:int}>
     */
    public static function lockLines(array $lineIds, ?int $exceptPoId): array
    {
        $lineIds = array_values(array_unique(array_map('intval', $lineIds)));
        if (!$lineIds) {
            return [];
        }
        $in_  = implode(',', array_fill(0, count($lineIds), '?'));
        $stmt = db()->prepare(
            "SELECT id FROM purchase_requests WHERE id IN (SELECT request_id FROM purchase_request_lines WHERE id IN ({$in_}))
              ORDER BY id FOR UPDATE"
        );
        $stmt->execute($lineIds);
        $stmt = db()->prepare(
            "SELECT rl.id, rl.request_id, r.pr_no, r.branch_id, r.status, rl.product_id,
                    COALESCE(rl.qty_approved, 0) - COALESCE((SELECT SUM(x.qty) FROM purchase_order_request_lines x
                                 JOIN purchase_order_lines ol ON ol.id = x.po_line_id
                                 JOIN purchase_orders o ON o.id = ol.po_id
                                WHERE x.request_line_id = rl.id AND o.status <> 'cancelled' AND o.id <> ?), 0) AS free
               FROM purchase_request_lines rl JOIN purchase_requests r ON r.id = rl.request_id
              WHERE rl.id IN ({$in_})"
        );
        $stmt->execute([$exceptPoId ?? 0, ...$lineIds]);
        $out = [];
        foreach ($stmt->fetchAll() as $l) {
            $out[(int) $l['id']] = [
                'request_id' => (int) $l['request_id'], 'pr_no' => (string) $l['pr_no'], 'branch_id' => (int) $l['branch_id'],
                'status' => (string) $l['status'], 'product_id' => (int) $l['product_id'], 'free' => (int) $l['free'],
            ];
        }
        return $out;
    }

    /**
     * approved <-> ordered after purchase orders changed (inside the caller's transaction, requests locked
     * or about to be). @param list<int> $requestIds
     */
    public static function refreshStatus(array $requestIds): void
    {
        $requestIds = array_values(array_unique(array_filter(array_map('intval', $requestIds))));
        if (!$requestIds) {
            return;
        }
        sort($requestIds);
        $in_  = implode(',', array_fill(0, count($requestIds), '?'));
        $stmt = db()->prepare(
            "SELECT r.id, r.pr_no, r.status, r.branch_id,
                    SUM(GREATEST(COALESCE(rl.qty_approved, 0) - COALESCE((SELECT SUM(x.qty) FROM purchase_order_request_lines x
                                 JOIN purchase_order_lines ol ON ol.id = x.po_line_id
                                 JOIN purchase_orders o ON o.id = ol.po_id
                                WHERE x.request_line_id = rl.id AND o.status <> 'cancelled'), 0), 0)) AS remaining
               FROM purchase_requests r JOIN purchase_request_lines rl ON rl.request_id = r.id
              WHERE r.id IN ({$in_}) AND r.status IN ('approved', 'ordered')
              GROUP BY r.id, r.pr_no, r.status, r.branch_id"
        );
        $stmt->execute($requestIds);
        $upd = db()->prepare('UPDATE purchase_requests SET status = ? WHERE id = ?');
        foreach ($stmt->fetchAll() as $r) {
            $new = (int) $r['remaining'] === 0 ? 'ordered' : 'approved';
            if ($new !== $r['status']) {
                $upd->execute([$new, (int) $r['id']]);
                Audit::record('purchasing', 'pr_' . $new, 'purchase_request', (int) $r['id'], $r['pr_no'],
                    ['status' => $r['status']], ['status' => $new], (int) $r['branch_id']);
            }
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private static function lock(int $id): array
    {
        $stmt = db()->prepare('SELECT * FROM purchase_requests WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $r = $stmt->fetch() ?: throw new HttpException(404, 'Purchase request not found.');
        Branch::assertAccess((int) $r['branch_id']);
        return $r;
    }

    private static function assertDecidable(array $r, int $userId, string $verb): void
    {
        self::assertWorkingIn((int) $r['branch_id']);
        if ($r['status'] !== 'requested') {
            throw new HttpException(409, "{$r['pr_no']} is " . strtolower(self::STATUSES[$r['status']]) . ", so it can no longer be {$verb}d.");
        }
        if ($userId === (int) $r['requested_by']) {
            throw new HttpException(403, "You can't {$verb} a request you made yourself.");
        }
    }

    /** Units of these requests on purchase orders that are not cancelled. */
    private static function orderedTotal(array $requestIds): int
    {
        $in_  = implode(',', array_fill(0, count($requestIds), '?'));
        $stmt = db()->prepare(
            "SELECT COALESCE(SUM(x.qty), 0) FROM purchase_order_request_lines x
               JOIN purchase_request_lines rl ON rl.id = x.request_line_id
               JOIN purchase_order_lines ol ON ol.id = x.po_line_id
               JOIN purchase_orders o ON o.id = ol.po_id
              WHERE rl.request_id IN ({$in_}) AND o.status <> 'cancelled'"
        );
        $stmt->execute($requestIds);
        return (int) $stmt->fetchColumn();
    }

    /** @param array<int|string, array> $lines rows with product_id @return array<int,array> id => product */
    private static function products(array $lines): array
    {
        $ids = [];
        foreach ($lines as $line) {
            $pid = input_int($line, 'product_id', 1);
            if ($pid !== null) {
                $ids[$pid] = true;
            }
        }
        if (!$ids) {
            return [];
        }
        $in_  = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare("SELECT id, code, name, is_active FROM products WHERE id IN ({$in_})");
        $stmt->execute(array_keys($ids));
        return array_column($stmt->fetchAll(), null, 'id');
    }

    private static function cleanNote(?string $text): ?string
    {
        $text = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string) $text) ?? '');
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > 255) {
            throw new HttpException(422, 'Keep the note under 255 characters.');
        }
        return $text;
    }
}
