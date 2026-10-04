<?php
/**
 * Collections = accounts receivable of "On account" bills (sales.payment_type 'charge', status completed), audit
 * module 'collections'.
 *
 *   receivable  a charge sale with balance = total - settled_amount > 0 (aging by days since the bill)
 *   collection  one payment of one customer at a branch (collections.manage, working in the branch), posted at once:
 *               CR-<branch>-<year>-NNNNNN; method cash / check / bank / GCash + reference; per bill the cash
 *               applied, the expanded withholding tax (BIR 2307) and the final VAT withheld (BIR 2306) the customer
 *               deducted. Each bill's settled_amount grows by the three; never above its total.
 *   cancel      collections.cancel, reason: the bills are open again.
 *   2307        a collection with taxes withheld waits for the certificate (form_2307 pending -> received + date).
 * A bill with collections can't be voided (Sales::void checks settled_amount).
 * Lock order: collections row -> sales (ORDER BY id) -> document_sequences.
 */
declare(strict_types=1);

final class Collections
{
    public const METHODS  = ['cash' => 'Cash', 'check' => 'Check', 'bank' => 'Bank deposit / transfer', 'gcash' => 'GCash'];
    public const STATUSES = ['posted' => 'Posted', 'cancelled' => 'Cancelled'];
    public const BADGES   = ['posted' => 'badge--success', 'cancelled' => 'badge--danger'];
    public const FORM_2307 = ['none' => 'Not needed', 'pending' => 'To receive', 'received' => 'Received'];
    public const VIEW_PERMISSIONS = ['collections.manage', 'collections.cancel'];
    public const PREFIX    = 'CR';
    public const MAX_LINES = 100;
    /** Aging buckets: key => [label, min days, max days|null]. */
    public const AGING = [
        'current' => ['0–30 days', 0, 30],
        'd31'     => ['31–60 days', 31, 60],
        'd61'     => ['61–90 days', 61, 90],
        'd90'     => ['Over 90 days', 91, null],
    ];

    public static function canView(): bool
    {
        return Auth::canAny(...self::VIEW_PERMISSIONS);
    }

    public static function requireView(): void
    {
        if (!self::canView()) {
            throw new HttpException(403, 'You do not have permission to view collections.');
        }
    }

    private static function requirePermission(string $permission, string $message): void
    {
        if (!Auth::can($permission)) {
            throw new HttpException(403, $message);
        }
    }

    // ------------------------------------------------------------------
    // Receivables (open on-account bills)
    // ------------------------------------------------------------------

    /** @param array{search?:string, customer?:?int, aging?:string} $f */
    private static function receivableWhere(array $f, bool $withAging = true): array
    {
        [$scope, $params] = Branch::scopeSql('s.branch_id');
        $where = [$scope, "s.payment_type = 'charge'", "s.status = 'completed'", 's.total > s.settled_amount'];
        if (($f['customer'] ?? null) !== null) {
            $where[]  = 's.customer_id = ?';
            $params[] = (int) $f['customer'];
        }
        $aging = (string) ($f['aging'] ?? '');
        if ($withAging && isset(self::AGING[$aging])) {
            [, $min, $max] = self::AGING[$aging];
            $where[]  = 'DATEDIFF(CURDATE(), DATE(s.created_at)) >= ?';
            $params[] = $min;
            if ($max !== null) {
                $where[]  = 'DATEDIFF(CURDATE(), DATE(s.created_at)) <= ?';
                $params[] = $max;
            }
        }
        $q = (string) ($f['search'] ?? '');
        if ($q !== '') {
            $like = like_pattern($q);
            $where[] = '(s.sale_no LIKE ? OR c.name LIKE ? OR co.order_no LIKE ? OR co.customer_po_no LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }
        return [implode(' AND ', $where), $params];
    }

    private const RECEIVABLE_FROM = 'FROM sales s
               JOIN branches b ON b.id = s.branch_id
               LEFT JOIN customers c ON c.id = s.customer_id
               LEFT JOIN customer_orders co ON co.id = s.customer_order_id';

    public static function receivableCount(array $f): int
    {
        self::requireView();
        [$where, $params] = self::receivableWhere($f);
        $stmt = db()->prepare('SELECT COUNT(*) ' . self::RECEIVABLE_FROM . " WHERE {$where}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /** Oldest first. */
    public static function receivables(array $f, int $limit, int $offset): array
    {
        self::requireView();
        [$where, $params] = self::receivableWhere($f);
        $stmt = db()->prepare(
            'SELECT s.id, s.sale_no, s.created_at, s.total, s.settled_amount, s.total - s.settled_amount AS balance, s.customer_id,
                    c.name AS customer_name, co.id AS order_id, co.order_no, co.customer_po_no, co.payment_term,
                    s.branch_id, b.code AS branch_code, b.name AS branch_name, DATEDIFF(CURDATE(), DATE(s.created_at)) AS days
              ' . self::RECEIVABLE_FROM . "
              WHERE {$where}
              ORDER BY s.created_at, s.id
              LIMIT ? OFFSET ?"
        );
        $stmt->execute([...$params, $limit, $offset]);
        return $stmt->fetchAll();
    }

    /** Balance per aging bucket + total (filters except the bucket). @return array{buckets: array<string,array{count:int, cents:int}>, count:int, cents:int} */
    public static function aging(array $f): array
    {
        self::requireView();
        [$where, $params] = self::receivableWhere($f, false);
        $stmt = db()->prepare(
            'SELECT DATEDIFF(CURDATE(), DATE(s.created_at)) AS days, s.total - s.settled_amount AS balance ' . self::RECEIVABLE_FROM . " WHERE {$where}"
        );
        $stmt->execute($params);
        $out = ['buckets' => array_map(static fn (): array => ['count' => 0, 'cents' => 0], self::AGING), 'count' => 0, 'cents' => 0];
        foreach ($stmt->fetchAll() as $r) {
            $key = self::bucket((int) $r['days']);
            $c   = to_cents($r['balance']);
            $out['buckets'][$key]['count']++;
            $out['buckets'][$key]['cents'] += $c;
            $out['count']++;
            $out['cents'] += $c;
        }
        return $out;
    }

    public static function bucket(int $days): string
    {
        foreach (self::AGING as $key => [, $min, $max]) {
            if ($days >= $min && ($max === null || $days <= $max)) {
                return $key;
            }
        }
        return 'current';
    }

    /** Customers with an open balance at the current branch (collection form). */
    public static function customersWithBalance(): array
    {
        if (!Branch::isConcrete()) {
            return [];
        }
        $stmt = db()->prepare(
            "SELECT c.id, c.name, ct.name AS type_name, COUNT(*) AS bills, SUM(s.total - s.settled_amount) AS balance
               FROM sales s JOIN customers c ON c.id = s.customer_id LEFT JOIN customer_types ct ON ct.id = c.customer_type_id
              WHERE s.branch_id = ? AND s.payment_type = 'charge' AND s.status = 'completed' AND s.total > s.settled_amount
              GROUP BY c.id, c.name, ct.name ORDER BY c.name"
        );
        $stmt->execute([(int) Branch::current()]);
        return $stmt->fetchAll();
    }

    /** Open on-account bills of a customer at the current branch, oldest first, with the VAT split (withholding base). */
    public static function openBills(int $customerId): array
    {
        if (!Branch::isConcrete()) {
            return [];
        }
        $stmt = db()->prepare(
            "SELECT s.id, s.sale_no, s.created_at, s.total, s.vat_amount, s.settled_amount, s.total - s.settled_amount AS balance,
                    co.order_no, co.customer_po_no, DATEDIFF(CURDATE(), DATE(s.created_at)) AS days
               FROM sales s LEFT JOIN customer_orders co ON co.id = s.customer_order_id
              WHERE s.branch_id = ? AND s.customer_id = ? AND s.payment_type = 'charge' AND s.status = 'completed'
                AND s.total > s.settled_amount
              ORDER BY s.created_at, s.id LIMIT " . self::MAX_LINES
        );
        $stmt->execute([(int) Branch::current(), $customerId]);
        return $stmt->fetchAll();
    }

    /** Work counts for the current branch: open bills, overdue (> 30 days), certificates to receive. */
    public static function workCounts(): array
    {
        $out = ['open' => 0, 'overdue' => 0, 'forms' => 0];
        if (!Branch::isConcrete() || !self::canView()) {
            return $out;
        }
        $cur  = (int) Branch::current();
        $stmt = db()->prepare(
            "SELECT COUNT(*), SUM(DATEDIFF(CURDATE(), DATE(created_at)) > 30) FROM sales
              WHERE branch_id = ? AND payment_type = 'charge' AND status = 'completed' AND total > settled_amount"
        );
        $stmt->execute([$cur]);
        [$open, $over] = $stmt->fetch(PDO::FETCH_NUM) ?: [0, 0];
        $stmt = db()->prepare("SELECT COUNT(*) FROM collections WHERE branch_id = ? AND status = 'posted' AND form_2307 = 'pending'");
        $stmt->execute([$cur]);
        return ['open' => (int) $open, 'overdue' => (int) $over, 'forms' => (int) $stmt->fetchColumn()];
    }

    // ------------------------------------------------------------------
    // Collection receipts: listing / lookup
    // ------------------------------------------------------------------

    /** @param array{search?:string, status?:string, method?:string, forms?:string, from?:?string, to?:?string} $f */
    private static function where(array $f): array
    {
        [$scope, $params] = Branch::scopeSql('c.branch_id');
        $where = [$scope];
        if (isset(self::STATUSES[$f['status'] ?? ''])) {
            $where[]  = 'c.status = ?';
            $params[] = $f['status'];
        }
        if (isset(self::METHODS[$f['method'] ?? ''])) {
            $where[]  = 'c.method = ?';
            $params[] = $f['method'];
        }
        if (($f['forms'] ?? '') === 'pending') {
            $where[] = "c.status = 'posted' AND c.form_2307 = 'pending'";
        }
        if (!empty($f['from'])) {
            $where[]  = 'c.collection_date >= ?';
            $params[] = $f['from'];
        }
        if (!empty($f['to'])) {
            $where[]  = 'c.collection_date <= ?';
            $params[] = $f['to'];
        }
        $q = (string) ($f['search'] ?? '');
        if ($q !== '') {
            $like = like_pattern($q);
            $where[] = '(c.collection_no LIKE ? OR c.customer_name LIKE ? OR c.reference LIKE ?
                         OR EXISTS (SELECT 1 FROM collection_lines cl JOIN sales s ON s.id = cl.sale_id WHERE cl.collection_id = c.id AND s.sale_no LIKE ?))';
            array_push($params, $like, $like, $like, $like);
        }
        return [implode(' AND ', $where), $params];
    }

    public static function count(array $f): int
    {
        self::requireView();
        [$where, $params] = self::where($f);
        $stmt = db()->prepare("SELECT COUNT(*) FROM collections c WHERE {$where}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public static function search(array $f, int $limit, int $offset): array
    {
        self::requireView();
        [$where, $params] = self::where($f);
        $stmt = db()->prepare(
            "SELECT c.id, c.collection_no, c.collection_date, c.customer_name, c.method, c.reference, c.amount_received, c.ewt_total,
                    c.vat_withheld_total, c.total_credited, c.form_2307, c.status, c.branch_id, b.code AS branch_code, b.name AS branch_name,
                    u.full_name AS created_by_name,
                    (SELECT COUNT(*) FROM collection_lines cl WHERE cl.collection_id = c.id) AS bills
               FROM collections c JOIN branches b ON b.id = c.branch_id JOIN users u ON u.id = c.created_by
              WHERE {$where}
              ORDER BY c.collection_date DESC, c.id DESC
              LIMIT ? OFFSET ?"
        );
        $stmt->execute([...$params, $limit, $offset]);
        return $stmt->fetchAll();
    }

    /** Posted totals for the filters (status filter ignored): cash, EWT, VAT withheld, credited, receipts. */
    public static function summary(array $f): array
    {
        self::requireView();
        [$where, $params] = self::where(['status' => ''] + $f);
        $stmt = db()->prepare(
            "SELECT COUNT(*) AS receipts, COALESCE(SUM(c.amount_received), 0) AS cash, COALESCE(SUM(c.ewt_total), 0) AS ewt,
                    COALESCE(SUM(c.vat_withheld_total), 0) AS vat, COALESCE(SUM(c.total_credited), 0) AS credited
               FROM collections c WHERE {$where} AND c.status = 'posted'"
        );
        $stmt->execute($params);
        return $stmt->fetch();
    }

    public static function find(int $id): ?array
    {
        self::requireView();
        $stmt = db()->prepare(
            'SELECT c.*, b.code AS branch_code, b.name AS branch_name, cu.tin AS customer_tin, cu.address AS customer_address,
                    u.full_name AS created_by_name, xu.full_name AS cancelled_by_name, fu.full_name AS form_2307_by_name
               FROM collections c
               JOIN branches b ON b.id = c.branch_id
               JOIN customers cu ON cu.id = c.customer_id
               JOIN users u ON u.id = c.created_by
               LEFT JOIN users xu ON xu.id = c.cancelled_by
               LEFT JOIN users fu ON fu.id = c.form_2307_by
              WHERE c.id = ?'
        );
        $stmt->execute([$id]);
        $c = $stmt->fetch();
        if (!$c) {
            return null;
        }
        Branch::assertAccess((int) $c['branch_id']);
        $stmt = db()->prepare(
            'SELECT cl.*, s.sale_no, s.total, s.settled_amount, s.created_at AS billed_at, s.status AS sale_status,
                    co.id AS order_id, co.order_no, co.customer_po_no
               FROM collection_lines cl
               JOIN sales s ON s.id = cl.sale_id
               LEFT JOIN customer_orders co ON co.id = s.customer_order_id
              WHERE cl.collection_id = ? ORDER BY s.created_at, s.id'
        );
        $stmt->execute([$id]);
        $c['lines'] = $stmt->fetchAll();
        return $c;
    }

    /** Collections that paid a bill (sale-view, bill print). */
    public static function forSale(int $saleId): array
    {
        $stmt = db()->prepare(
            'SELECT c.id, c.collection_no, c.collection_date, c.method, c.reference, c.status, cl.amount, cl.ewt_amount, cl.vat_withheld
               FROM collection_lines cl JOIN collections c ON c.id = cl.collection_id
              WHERE cl.sale_id = ? ORDER BY c.collection_date, c.id'
        );
        $stmt->execute([$saleId]);
        return $stmt->fetchAll();
    }

    public static function actions(array $c): array
    {
        $here   = Branch::current() === (int) $c['branch_id'];
        $posted = $c['status'] === 'posted';
        return [
            'cancel' => $here && $posted && Auth::can('collections.cancel'),
            'form'   => $here && $posted && $c['form_2307'] === 'pending' && Auth::can('collections.manage'),
        ];
    }

    // ------------------------------------------------------------------
    // Post / cancel / certificate
    // ------------------------------------------------------------------

    /**
     * Header: customer_id, collection_date, method, reference, bank_name, check_date, notes. Lines keyed by sale id:
     * lines[<sale id>][amount|ewt|vat] (money, commas allowed; blank = 0; all-zero lines are skipped).
     * @return array{0: array, 1: array<string,string>}
     */
    public static function validate(array $in): array
    {
        self::requirePermission('collections.manage', 'You do not have permission to record collections.');
        $errors = [];
        $text = static function (string $key, int $max) use ($in, &$errors): ?string {
            $v = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', input_string($in, $key, $max + 1)) ?? '');
            if (mb_strlen($v) > $max) {
                $errors[$key] = "Keep it under {$max} characters.";
            }
            return $v !== '' ? $v : null;
        };
        $method = input_string($in, 'method', 10);
        $data = [
            'customer_id'     => input_int($in, 'customer_id', 1),
            'collection_date' => input_date($in, 'collection_date'),
            'method'          => $method,
            'reference'       => $text('reference', 60),
            'bank_name'       => $text('bank_name', 60),
            'check_date'      => input_date($in, 'check_date'),
            'notes'           => $text('notes', 255),
            'lines'           => [],
        ];
        if ($data['customer_id'] === null) {
            $errors['customer_id'] = 'Choose the customer.';
        }
        if ($data['collection_date'] === null) {
            $errors['collection_date'] = 'Enter the date the payment was received.';
        } elseif ($data['collection_date'] > date('Y-m-d')) {
            $errors['collection_date'] = 'The collection date cannot be in the future.';
        }
        if (!isset(self::METHODS[$method])) {
            $errors['method'] = 'Choose how the customer paid.';
        } elseif ($method === 'check' && $data['reference'] === null) {
            $errors['reference'] = 'Enter the check number.';
        } elseif (in_array($method, ['bank', 'gcash'], true) && $data['reference'] === null) {
            $errors['reference'] = 'Enter the deposit / transaction reference.';
        }
        if ($method !== 'check') {
            $data['check_date'] = null;
        }
        if ($method === 'cash' || $method === 'gcash') {
            $data['bank_name'] = null;
        }
        $raw = is_array($in['lines'] ?? null) ? $in['lines'] : [];
        if (count($raw) > self::MAX_LINES) {
            $errors['lines'] = 'At most ' . self::MAX_LINES . ' bills in one collection.';
            $raw = [];
        }
        foreach ($raw as $saleId => $line) {
            $sid = filter_var($saleId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($sid === false || !is_array($line)) {
                continue;
            }
            $cents = [];
            foreach (['amount', 'ewt', 'vat'] as $k) {
                $v = is_string($line[$k] ?? null) ? str_replace(',', '', trim($line[$k])) : '';
                if ($v === '') {
                    $cents[$k] = 0;
                    continue;
                }
                $d = input_decimal(['v' => $v], 'v', 0, 99999999.99, 2);
                if ($d === null) {
                    $errors["lines.{$sid}.{$k}"] = 'Enter an amount, e.g. 1,500.00.';
                    $cents[$k] = 0;
                } else {
                    $cents[$k] = to_cents((string) $d);
                }
            }
            if ($cents['amount'] + $cents['ewt'] + $cents['vat'] > 0) {
                $data['lines'][$sid] = $cents;
            }
        }
        if (!$data['lines'] && !isset($errors['lines'])) {
            $errors['lines'] = 'Enter what was paid on at least one bill.';
        }
        return [$data, $errors];
    }

    /** Post a validated collection at the current branch. @return array{id:int, collection_no:string} */
    public static function post(array $d, int $userId): array
    {
        self::requirePermission('collections.manage', 'You do not have permission to record collections.');
        $branchId = Branch::forWrite();
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT id, name FROM customers WHERE id = ?');
            $stmt->execute([(int) $d['customer_id']]);
            $customer = $stmt->fetch() ?: throw new HttpException(422, 'Choose the customer.');

            $ids = array_map('intval', array_keys($d['lines']));
            sort($ids);
            $in_  = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT id, sale_no, branch_id, customer_id, payment_type, status, total, settled_amount FROM sales WHERE id IN ({$in_}) ORDER BY id FOR UPDATE");
            $stmt->execute($ids);
            $sales = array_column($stmt->fetchAll(), null, 'id');
            $errors = [];
            $totals = ['amount' => 0, 'ewt' => 0, 'vat' => 0];
            foreach ($d['lines'] as $sid => $c) {
                $s = $sales[$sid] ?? null;
                if ($s === null || (int) $s['branch_id'] !== $branchId || (int) $s['customer_id'] !== (int) $customer['id']
                    || $s['payment_type'] !== 'charge' || $s['status'] !== 'completed') {
                    throw new HttpException(409, 'A bill on this collection is no longer open for this customer. Reload the page.');
                }
                $balance = to_cents($s['total']) - to_cents($s['settled_amount']);
                $credit  = $c['amount'] + $c['ewt'] + $c['vat'];
                if ($credit > $balance) {
                    $errors["lines.{$sid}.amount"] = "Bill No. {$s['sale_no']}: only " . money(from_cents(max(0, $balance))) . ' is still due.';
                }
                foreach ($totals as $k => $_) {
                    $totals[$k] += $c[$k];
                }
            }
            if ($errors) {
                throw new HttpException(422, (string) reset($errors), ['errors' => $errors]);
            }
            $credited = $totals['amount'] + $totals['ewt'] + $totals['vat'];
            $no = DocNumber::next($branchId, self::PREFIX);
            $pdo->prepare(
                'INSERT INTO collections (collection_no, branch_id, customer_id, customer_name, collection_date, method, reference, bank_name, check_date,
                                          amount_received, ewt_total, vat_withheld_total, total_credited, form_2307, notes, status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$no, $branchId, (int) $customer['id'], mb_substr((string) $customer['name'], 0, 100), $d['collection_date'], $d['method'],
                $d['reference'], $d['bank_name'], $d['check_date'], from_cents($totals['amount']), from_cents($totals['ewt']), from_cents($totals['vat']),
                from_cents($credited), $totals['ewt'] + $totals['vat'] > 0 ? 'pending' : 'none', $d['notes'], 'posted', $userId]);
            $id = (int) $pdo->lastInsertId();
            $line = $pdo->prepare('INSERT INTO collection_lines (collection_id, sale_id, amount, ewt_amount, vat_withheld) VALUES (?, ?, ?, ?, ?)');
            $settle = $pdo->prepare('UPDATE sales SET settled_amount = settled_amount + ? WHERE id = ?');
            $bills = [];
            foreach ($d['lines'] as $sid => $c) {
                $line->execute([$id, $sid, from_cents($c['amount']), from_cents($c['ewt']), from_cents($c['vat'])]);
                $settle->execute([from_cents($c['amount'] + $c['ewt'] + $c['vat']), $sid]);
                $bills[] = $sales[$sid]['sale_no'];
            }
            Audit::record('collections', 'post', 'collection', $id, $no, null, array_filter([
                'customer' => $customer['name'], 'method' => self::METHODS[$d['method']], 'reference' => $d['reference'],
                'bills' => $bills, 'received' => from_cents($totals['amount']),
                'ewt' => $totals['ewt'] ? from_cents($totals['ewt']) : null, 'vat_withheld' => $totals['vat'] ? from_cents($totals['vat']) : null,
            ], static fn ($v) => $v !== null), $branchId);
            $pdo->commit();
            return ['id' => $id, 'collection_no' => $no];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function cancel(int $id, ?string $reason, int $userId): string
    {
        self::requirePermission('collections.cancel', 'You do not have permission to cancel collections.');
        $reason = CustomerOrders::cleanReason($reason, 'reason');
        return self::transition($id, static function (array $c) use ($id, $reason, $userId): string {
            if ($c['status'] !== 'posted') {
                throw new HttpException(409, "{$c['collection_no']} is already cancelled.");
            }
            $stmt = db()->prepare('SELECT cl.sale_id, cl.amount + cl.ewt_amount + cl.vat_withheld AS credit FROM collection_lines cl WHERE cl.collection_id = ?');
            $stmt->execute([$id]);
            $lines = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            $ids   = array_map('intval', array_keys($lines));
            sort($ids);
            $in_  = implode(',', array_fill(0, count($ids), '?'));
            $stmt = db()->prepare("SELECT id, settled_amount FROM sales WHERE id IN ({$in_}) ORDER BY id FOR UPDATE");
            $stmt->execute($ids);
            $upd = db()->prepare('UPDATE sales SET settled_amount = settled_amount - ? WHERE id = ?');
            foreach ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $sid => $settled) {
                if (to_cents((string) $settled) < to_cents((string) $lines[$sid])) {
                    throw new HttpException(409, 'The bills of this collection no longer match it. Check the stock integrity page.');
                }
                $upd->execute([$lines[$sid], (int) $sid]);
            }
            db()->prepare('UPDATE collections SET status = ?, cancelled_by = ?, cancelled_at = NOW(), cancel_reason = ? WHERE id = ?')
                ->execute(['cancelled', $userId, $reason, $id]);
            Audit::record('collections', 'cancel', 'collection', $id, (string) $c['collection_no'], ['status' => 'posted'],
                ['status' => 'cancelled', 'reason' => $reason], (int) $c['branch_id']);
            return (string) $c['collection_no'];
        });
    }

    /** The customer's withholding certificate (BIR 2307 / 2306) arrived. */
    public static function receiveForm(int $id, ?string $date, int $userId): string
    {
        self::requirePermission('collections.manage', 'You do not have permission to record collections.');
        $when = input_date(['d' => (string) $date], 'd');
        if ($when === null || $when > date('Y-m-d')) {
            throw new HttpException(422, 'Enter the date the certificate was received (not in the future).', ['errors' => ['received_at' => 'Enter a valid date.']]);
        }
        return self::transition($id, static function (array $c) use ($id, $when, $userId): string {
            if ($c['status'] !== 'posted' || $c['form_2307'] !== 'pending') {
                throw new HttpException(409, "{$c['collection_no']} is not waiting for a certificate.");
            }
            db()->prepare('UPDATE collections SET form_2307 = ?, form_2307_received_at = ?, form_2307_by = ? WHERE id = ?')
                ->execute(['received', $when, $userId, $id]);
            Audit::record('collections', 'form_2307', 'collection', $id, (string) $c['collection_no'], ['form_2307' => 'pending'],
                ['form_2307' => 'received', 'received_at' => $when], (int) $c['branch_id']);
            return (string) $c['collection_no'];
        });
    }

    private static function transition(int $id, callable $fn): string
    {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM collections WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $c = $stmt->fetch() ?: throw new HttpException(404, 'Collection not found.');
            Branch::assertAccess((int) $c['branch_id']);
            CustomerOrders::assertWorkingIn((int) $c['branch_id']);
            $out = $fn($c);
            $pdo->commit();
            return $out;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
