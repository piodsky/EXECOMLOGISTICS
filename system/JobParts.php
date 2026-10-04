<?php
/**
 * Parts for job orders (job_order_parts + job_order_part_serials). Phase 10b.
 *
 *   request  the assigned technician / a supervisor asks for a part (no stock change)
 *   issue    job_parts.issue, never the requester: stock leaves the branch's POS location into job custody
 *            (movement 'job_issue', cost = branch average snapshot on the line); serials -> 'in_custody'
 *   use      the technician installs what was issued (no stock change; serials -> 'installed')
 *   return   job_parts.issue takes back unused parts (movement 'job_return' at the line cost, re-averaged);
 *            serials -> 'in_stock' at the POS location
 *   cancel   a request that was never issued (requester, worker or issuer)
 * In custody = qty_issued - qty_used - qty_returned: in no location (like stock in transit). A job can't be completed
 * with open requests or released with parts in custody. Every change also writes the job timeline + audit log.
 * Lock order: job_orders row -> job_order_parts row -> products -> stock_balances -> product_branches -> product_serials.
 */
declare(strict_types=1);

final class JobParts
{
    public const STATUSES = ['requested' => 'Requested', 'issued' => 'Issued', 'cancelled' => 'Cancelled'];
    /** Job statuses in which parts can be requested / used. */
    public const WORK_STATUSES = ['diagnosing', 'in_repair', 'waiting_parts', 'for_testing'];
    public const MAX_QTY = 999;

    /**
     * Lines of a job with product info, 'custody' and 'serials' (list of {id, serial_no, state}). unit_cost only
     * with products.cost.
     */
    public static function forJob(int $jobId): array
    {
        $stmt = db()->prepare(
            'SELECT jp.*, p.code AS product_code, p.name AS product_name, p.price, p.track_serial, un.code AS unit_code,
                    ru.full_name AS requested_by_name, iu.full_name AS issued_by_name
               FROM job_order_parts jp
               JOIN products p ON p.id = jp.product_id
               LEFT JOIN units un ON un.id = p.unit_id
               JOIN users ru ON ru.id = jp.requested_by
               LEFT JOIN users iu ON iu.id = jp.issued_by
              WHERE jp.job_order_id = ? ORDER BY jp.id'
        );
        $stmt->execute([$jobId]);
        $lines = [];
        foreach ($stmt->fetchAll() as $l) {
            $l['custody'] = (int) $l['qty_issued'] - (int) $l['qty_used'] - (int) $l['qty_returned'];
            $l['serials'] = [];
            $lines[(int) $l['id']] = $l;
        }
        if ($lines) {
            $in_  = implode(',', array_fill(0, count($lines), '?'));
            $stmt = db()->prepare(
                "SELECT js.part_id, js.state, s.id, s.serial_no FROM job_order_part_serials js JOIN product_serials s ON s.id = js.serial_id
                  WHERE js.part_id IN ({$in_}) ORDER BY s.serial_no"
            );
            $stmt->execute(array_keys($lines));
            foreach ($stmt->fetchAll() as $s) {
                $lines[(int) $s['part_id']]['serials'][] = ['id' => (int) $s['id'], 'serial_no' => $s['serial_no'], 'state' => $s['state']];
            }
        }
        if (!Auth::can('products.cost')) {
            $lines = array_map(static fn (array $l): array => array_diff_key($l, ['unit_cost' => 1]), $lines);
        }
        return array_values($lines);
    }

    /** Open requests and units in custody of a job (locks nothing). @return array{pending:int, custody:int} */
    public static function openCounts(int $jobId): array
    {
        $stmt = db()->prepare(
            "SELECT COALESCE(SUM(status = 'requested'), 0), COALESCE(SUM(CASE WHEN status = 'issued' THEN qty_issued - qty_used - qty_returned END), 0)
               FROM job_order_parts WHERE job_order_id = ?"
        );
        $stmt->execute([$jobId]);
        [$p, $c] = $stmt->fetch(PDO::FETCH_NUM) ?: [0, 0];
        return ['pending' => (int) $p, 'custody' => (int) $c];
    }

    /** May the current user request parts on this job? */
    public static function canRequest(array $job): bool
    {
        return Branch::current() === (int) $job['branch_id'] && in_array($job['status'], self::WORK_STATUSES, true) && JobOrders::isWorker($job);
    }

    /** Buttons for one line: issue, use, return, cancel. */
    public static function lineActions(array $job, array $line): array
    {
        $here = Branch::current() === (int) $job['branch_id'];
        $uid  = (int) Auth::id();
        $s    = $line['status'];
        return [
            'issue'  => $here && $s === 'requested' && in_array($job['status'], JobOrders::OPEN, true)
                        && Auth::can('job_parts.issue') && $uid !== (int) $line['requested_by'],
            'use'    => $here && $s === 'issued' && $line['custody'] > 0 && in_array($job['status'], self::WORK_STATUSES, true)
                        && JobOrders::isWorker($job),
            'return' => $here && $s === 'issued' && $line['custody'] > 0 && in_array($job['status'], [...JobOrders::OPEN, 'completed'], true)
                        && Auth::can('job_parts.issue'),
            'cancel' => $here && $s === 'requested' && !in_array($job['status'], ['released', 'closed', 'cancelled'], true)
                        && ($uid === (int) $line['requested_by'] || JobOrders::isWorker($job) || Auth::can('job_parts.issue')),
        ];
    }

    /** Active products for the request list, with the quantity at the job branch's POS location. */
    public static function products(int $branchId): array
    {
        try {
            $loc = Branch::defaultLocation($branchId)['id'];
        } catch (HttpException) {
            $loc = 0;
        }
        $stmt = db()->prepare(
            'SELECT p.id, p.code, p.name, p.track_serial, COALESCE(sb.qty, 0) AS qty
               FROM products p LEFT JOIN stock_balances sb ON sb.product_id = p.id AND sb.location_id = ?
              WHERE p.is_active = 1 ORDER BY p.code'
        );
        $stmt->execute([$loc]);
        return $stmt->fetchAll();
    }

    // ------------------------------------------------------------------
    // Actions (each: one transaction, job row locked first)
    // ------------------------------------------------------------------

    /** Request a part: product_id, quantity, note (optional). @return string message */
    public static function request(int $jobId, array $in, int $userId): string
    {
        return self::tx(function () use ($jobId, $in, $userId): string {
            $j = JobOrders::lockForAction($jobId);
            if (!in_array($j['status'], self::WORK_STATUSES, true)) {
                throw new HttpException(409, "{$j['job_no']} is " . strtolower(JobOrders::STATUSES[$j['status']]) . ', so parts can no longer be requested.');
            }
            if (!JobOrders::isWorker($j)) {
                throw new HttpException(403, 'Only the assigned technician or a supervisor can request parts.');
            }
            $pid  = input_int($in, 'product_id', 1);
            $qty  = input_int($in, 'quantity', 1, self::MAX_QTY);
            $note = self::clean(input_string($in, 'note', 256));
            $errs = [];
            $stmt = db()->prepare('SELECT id, code, name, is_active FROM products WHERE id = ? FOR UPDATE');
            $stmt->execute([$pid ?? 0]);
            $p = $stmt->fetch();
            if (!$p || (int) $p['is_active'] !== 1) {
                $errs['product_id'] = 'Choose an active product.';
            }
            if ($qty === null) {
                $errs['quantity'] = 'Enter a quantity from 1 to ' . self::MAX_QTY . '.';
            }
            if ($note !== null && mb_strlen($note) > 255) {
                $errs['note'] = 'Keep the note under 255 characters.';
            }
            if ($errs) {
                throw new HttpException(422, reset($errs), ['errors' => $errs]);
            }
            db()->prepare('INSERT INTO job_order_parts (job_order_id, product_id, qty_requested, note, requested_by) VALUES (?, ?, ?, ?, ?)')
                ->execute([$jobId, $pid, $qty, $note, $userId]);
            $partId = (int) db()->lastInsertId();
            $what = "{$qty} × {$p['code']} {$p['name']}";
            JobOrders::event($jobId, $userId, 'parts_request', null, null, "Requested {$what}." . ($note !== null ? " {$note}" : ''));
            Audit::record('job_orders', 'parts_request', 'job_order', $jobId, $j['job_no'], null,
                array_filter(['part' => $partId, 'item' => "{$p['code']} x{$qty}", 'note' => $note], static fn ($v) => $v !== null), (int) $j['branch_id']);
            return "Requested {$what}. The parts counter issues it.";
        });
    }

    /** Issue a requested line: quantity (1..requested, default all), serial_ids for serial-tracked items. */
    public static function issue(int $partId, array $in, int $userId): string
    {
        if (!Auth::can('job_parts.issue')) {
            throw new HttpException(403, 'You do not have permission to issue parts.');
        }
        return self::tx(function () use ($partId, $in, $userId): string {
            [$j, $l] = self::lockLine($partId);
            if ($l['status'] !== 'requested') {
                throw new HttpException(409, 'This request was already ' . ($l['status'] === 'issued' ? 'issued' : 'cancelled') . '.');
            }
            if (!in_array($j['status'], JobOrders::OPEN, true)) {
                throw new HttpException(409, "{$j['job_no']} is " . strtolower(JobOrders::STATUSES[$j['status']]) . ', so parts can no longer be issued.');
            }
            if ($userId === (int) $l['requested_by']) {
                throw new HttpException(403, "You can't issue parts you requested yourself.");
            }
            $req = (int) $l['qty_requested'];
            $raw = $in['quantity'] ?? '';
            $qty = is_string($raw) && trim($raw) === '' ? $req : input_int($in, 'quantity', 1, $req);
            if ($qty === null) {
                throw new HttpException(422, "Issue a whole number from 1 to {$req}.", ['errors' => ["qty.{$partId}" => "1 to {$req}"]]);
            }
            $p   = self::lockProduct((int) $l['product_id']);
            $ids = self::intList($in['serial_ids'] ?? []);
            if ((int) $p['track_serial'] === 1) {
                $qty = count($ids);
                if ($qty < 1 || $qty > $req || count(array_unique($ids)) !== $qty) {
                    throw new HttpException(422, "{$p['name']}: choose 1 to {$req} serial numbers to issue.", ['errors' => ["serials.{$partId}" => "Choose 1 to {$req}."]]);
                }
            } elseif ($ids) {
                throw new HttpException(422, "{$p['name']} does not use serial numbers.");
            }
            $branchId = (int) $j['branch_id'];
            $loc  = Branch::defaultLocation($branchId);
            Stock::balance((int) $p['id'], $loc['id']);   // stock_balances before product_branches
            $cost = Costing::avg((int) $p['id'], $branchId);
            $tech = $j['technician_id'] !== null ? JobOrders::userName((int) $j['technician_id']) : '';
            Stock::move((int) $p['id'], $loc + ['label' => $loc['branch_name']], -$qty, 'job_issue',
                "{$j['job_no']}: issued" . ($tech !== '' ? " to {$tech}" : ''), null, $userId, null, null, null, (int) $j['id']);
            $serialNos = $ids ? self::moveSerials($partId, (int) $p['id'], $ids, 'in_stock', 'in_custody', 'issued', $loc) : [];
            db()->prepare("UPDATE job_order_parts SET status = 'issued', qty_issued = ?, unit_cost = ?, issued_by = ?, issued_at = NOW() WHERE id = ?")
                ->execute([$qty, $cost, $userId, $partId]);
            $what = "{$qty} × {$p['code']} {$p['name']}" . ($serialNos ? ' (S/N ' . implode(', ', $serialNos) . ')' : '');
            JobOrders::event((int) $j['id'], $userId, 'parts_issue', null, null, "Issued {$what}" . ($qty < $req ? " of {$req} requested" : '') . '.');
            Audit::record('job_orders', 'parts_issue', 'job_order', (int) $j['id'], $j['job_no'], null,
                array_filter(['part' => $partId, 'item' => "{$p['code']} x{$qty}", 'serials' => $serialNos ?: null], static fn ($v) => $v !== null), $branchId);
            return "Issued {$what} to {$j['job_no']}.";
        });
    }

    /** The technician used issued parts: quantity (non-serial) or serial_ids. */
    public static function useParts(int $partId, array $in, int $userId): string
    {
        return self::tx(function () use ($partId, $in, $userId): string {
            [$j, $l] = self::lockLine($partId);
            if (!JobOrders::isWorker($j)) {
                throw new HttpException(403, 'Only the assigned technician or a supervisor can record used parts.');
            }
            if (!in_array($j['status'], self::WORK_STATUSES, true)) {
                throw new HttpException(409, "{$j['job_no']} is " . strtolower(JobOrders::STATUSES[$j['status']]) . ', so parts can no longer be used on it.');
            }
            [$qty, $p, $serialNos] = self::fromCustody($partId, $l, $in, 'installed', 'used', null);
            db()->prepare('UPDATE job_order_parts SET qty_used = qty_used + ? WHERE id = ?')->execute([$qty, $partId]);
            $what = "{$qty} × {$p['code']} {$p['name']}" . ($serialNos ? ' (S/N ' . implode(', ', $serialNos) . ')' : '');
            JobOrders::event((int) $j['id'], $userId, 'parts_use', null, null, "Used {$what}.");
            Audit::record('job_orders', 'parts_use', 'job_order', (int) $j['id'], $j['job_no'], null,
                array_filter(['part' => $partId, 'item' => "{$p['code']} x{$qty}", 'serials' => $serialNos ?: null], static fn ($v) => $v !== null), (int) $j['branch_id']);
            return "Recorded {$what} as used.";
        });
    }

    /** Unused parts come back into the POS location: quantity (non-serial) or serial_ids. */
    public static function returnParts(int $partId, array $in, int $userId): string
    {
        if (!Auth::can('job_parts.issue')) {
            throw new HttpException(403, 'You do not have permission to take back parts.');
        }
        return self::tx(function () use ($partId, $in, $userId): string {
            [$j, $l] = self::lockLine($partId);
            if (!in_array($j['status'], [...JobOrders::OPEN, 'completed'], true)) {
                throw new HttpException(409, "{$j['job_no']} is " . strtolower(JobOrders::STATUSES[$j['status']]) . ', so parts can no longer be returned on it.');
            }
            $branchId = (int) $j['branch_id'];
            $loc = Branch::defaultLocation($branchId);
            $p   = self::lockProduct((int) $l['product_id']);
            $qty = self::returnQty($l, $p, $in, $partId);
            Costing::inbound((int) $p['id'], $branchId, $qty, (string) $l['unit_cost']); // locks stock_balances, then product_branches
            $after = Stock::move((int) $p['id'], $loc + ['label' => $loc['branch_name']], $qty, 'job_return', "{$j['job_no']}: unused part returned",
                null, $userId, null, null, null, (int) $j['id']);
            if ($after['location_qty'] > Products::MAX_STOCK) {
                throw new HttpException(422, "{$p['name']}: stock can be at most " . number_format(Products::MAX_STOCK) . '.');
            }
            [, , $serialNos] = self::fromCustody($partId, $l, $in, 'in_stock', 'returned', $loc, $p);
            db()->prepare('UPDATE job_order_parts SET qty_returned = qty_returned + ? WHERE id = ?')->execute([$qty, $partId]);
            $what = "{$qty} × {$p['code']} {$p['name']}" . ($serialNos ? ' (S/N ' . implode(', ', $serialNos) . ')' : '');
            JobOrders::event((int) $j['id'], $userId, 'parts_return', null, null, "Returned {$what} to stock.");
            Audit::record('job_orders', 'parts_return', 'job_order', (int) $j['id'], $j['job_no'], null,
                array_filter(['part' => $partId, 'item' => "{$p['code']} x{$qty}", 'serials' => $serialNos ?: null], static fn ($v) => $v !== null), $branchId);
            return "Returned {$what} to stock.";
        });
    }

    /** Cancel a request that was never issued. */
    public static function cancel(int $partId, int $userId): string
    {
        return self::tx(function () use ($partId, $userId): string {
            [$j, $l] = self::lockLine($partId);
            if ($l['status'] !== 'requested') {
                throw new HttpException(409, 'Only requests that were not issued yet can be cancelled.');
            }
            $l['custody'] = 0;
            if (!self::lineActions($j, $l)['cancel']) {
                throw new HttpException(403, 'You cannot cancel this request.');
            }
            db()->prepare("UPDATE job_order_parts SET status = 'cancelled', cancelled_by = ?, cancelled_at = NOW() WHERE id = ?")->execute([$userId, $partId]);
            $stmt = db()->prepare('SELECT code, name FROM products WHERE id = ?');
            $stmt->execute([(int) $l['product_id']]);
            $p = $stmt->fetch();
            JobOrders::event((int) $j['id'], $userId, 'parts_cancel', null, null, "Cancelled the request for {$l['qty_requested']} × {$p['code']} {$p['name']}.");
            Audit::record('job_orders', 'parts_cancel', 'job_order', (int) $j['id'], $j['job_no'], null, ['part' => $partId], (int) $j['branch_id']);
            return 'The parts request was cancelled.';
        });
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private static function tx(callable $fn): string
    {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $out = $fn();
            $pdo->commit();
            return $out;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Lock the job (visible, working in its branch) and then the line. @return array{0: array, 1: array} */
    private static function lockLine(int $partId): array
    {
        $stmt = db()->prepare('SELECT job_order_id FROM job_order_parts WHERE id = ?');
        $stmt->execute([$partId]);
        $jobId = $stmt->fetchColumn();
        if ($jobId === false) {
            throw new HttpException(404, 'Parts request not found.');
        }
        $j = JobOrders::lockForAction((int) $jobId);
        $stmt = db()->prepare('SELECT * FROM job_order_parts WHERE id = ? AND job_order_id = ? FOR UPDATE');
        $stmt->execute([$partId, (int) $jobId]);
        $l = $stmt->fetch() ?: throw new HttpException(404, 'Parts request not found.');
        $l['custody'] = (int) $l['qty_issued'] - (int) $l['qty_used'] - (int) $l['qty_returned'];
        return [$j, $l];
    }

    private static function lockProduct(int $id): array
    {
        $stmt = db()->prepare('SELECT id, code, name, track_serial FROM products WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: throw new HttpException(404, 'Product not found.');
    }

    /** Quantity to return (validated against custody) before any write. */
    private static function returnQty(array $l, array $p, array $in, int $partId): int
    {
        if ($l['status'] !== 'issued' || $l['custody'] < 1) {
            throw new HttpException(409, 'Nothing of this line is with the technician any more.');
        }
        if ((int) $p['track_serial'] === 1) {
            $n = count(array_unique(self::intList($in['serial_ids'] ?? [])));
            if ($n < 1) {
                throw new HttpException(422, "{$p['name']}: choose the serial numbers.", ['errors' => ["serials.{$partId}" => 'Choose at least one.']]);
            }
            return $n;
        }
        $qty = input_int($in, 'quantity', 1, $l['custody']);
        if ($qty === null) {
            throw new HttpException(422, "Enter a whole number from 1 to {$l['custody']}.", ['errors' => ["qty.{$partId}" => "1 to {$l['custody']}"]]);
        }
        return $qty;
    }

    /**
     * Take units out of custody (used / returned). Serial lines: the chosen serials must be 'issued' on this line;
     * they become $serialStatus ('installed' / 'in_stock' at $loc). @return array{0:int, 1:array, 2:list<string>}
     */
    private static function fromCustody(int $partId, array $l, array $in, string $serialStatus, string $state, ?array $loc, ?array $p = null): array
    {
        $p ??= self::lockProduct((int) $l['product_id']);
        $qty = self::returnQty($l, $p, $in, $partId);
        $nos = [];
        if ((int) $p['track_serial'] === 1) {
            $ids = array_values(array_unique(self::intList($in['serial_ids'] ?? [])));
            $nos = self::moveSerials($partId, (int) $p['id'], $ids, 'in_custody', $serialStatus, $state, $loc);
        }
        return [$qty, $p, $nos];
    }

    /**
     * Serials last in the lock order. Issue: in_stock at $loc -> in_custody (+ link 'issued'). Use / return: linked as
     * 'issued' on this line and in_custody -> $to (in_stock moves them to $loc). @return list<string> serial numbers
     */
    private static function moveSerials(int $partId, int $productId, array $ids, string $from, string $to, string $state, ?array $loc): array
    {
        sort($ids);
        $in_  = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare("SELECT id, product_id, serial_no, status, location_id FROM product_serials WHERE id IN ({$in_}) ORDER BY id FOR UPDATE");
        $stmt->execute($ids);
        $rows = array_column($stmt->fetchAll(), null, 'id');
        $linked = [];
        if ($state !== 'issued') {
            $stmt = db()->prepare("SELECT serial_id FROM job_order_part_serials WHERE part_id = ? AND state = 'issued'");
            $stmt->execute([$partId]);
            $linked = array_flip(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
        }
        $nos = [];
        foreach ($ids as $sid) {
            $r = $rows[$sid] ?? null;
            if ($r === null || (int) $r['product_id'] !== $productId || $r['status'] !== $from
                || ($state === 'issued' && (int) $r['location_id'] !== (int) $loc['id'])
                || ($state !== 'issued' && !isset($linked[$sid]))) {
                throw new HttpException(409, 'Serial ' . ($r['serial_no'] ?? $sid) . ($state === 'issued'
                    ? ' is no longer in stock at the POS location.' : ' is not with the technician on this line.'));
            }
            if ($to === 'in_stock') {
                db()->prepare('UPDATE product_serials SET status = ?, branch_id = ?, warehouse_id = ?, location_id = ? WHERE id = ?')
                    ->execute([$to, $loc['branch_id'], $loc['warehouse_id'], $loc['id'], $sid]);
            } else {
                db()->prepare('UPDATE product_serials SET status = ? WHERE id = ?')->execute([$to, $sid]);
            }
            if ($state === 'issued') {
                db()->prepare('INSERT INTO job_order_part_serials (part_id, serial_id, state) VALUES (?, ?, ?)')->execute([$partId, $sid, 'issued']);
            } else {
                db()->prepare('UPDATE job_order_part_serials SET state = ? WHERE part_id = ? AND serial_id = ?')->execute([$state, $partId, $sid]);
            }
            $nos[] = (string) $r['serial_no'];
        }
        return $nos;
    }

    private static function clean(string $text): ?string
    {
        $text = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text) ?? '');
        return $text === '' ? null : $text;
    }

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
}
