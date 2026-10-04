<?php
/**
 * Payables = what EXECOM owes its suppliers (after the old NewEXECOM Payables / Disbursements), audit module
 * 'payables'. Everything needs payables.manage (or .cancel) AND products.cost: supplier invoices are purchase costs.
 * The audit log records numbers and references only, never amounts (cost rule).
 *
 *   supplier invoice  AP-<branch>-<year>-NNNNNN from one posted receiving report of the current branch (one live invoice
 *                     per RR): the supplier's invoice no. / date, due date (default invoice date + suppliers.terms_days),
 *                     amount (default the RR total). open -> paid (paid_amount = posted disbursement lines, cash + EWT);
 *                     open with nothing paid -> cancelled (payables.cancel, reason). An RR with a live invoice can't
 *                     be cancelled (Receiving::cancel).
 *   disbursement      DV-<branch>-<year>-NNNNNN: one payment to one supplier (cash / check / bank / GCash + reference)
 *                     applied to its open invoices at the branch, per invoice the cash paid + the EWT EXECOM withheld
 *                     (EXECOM issues the 2307). Posted at once; checks issued -> cleared; cancel (payables.cancel,
 *                     reason) opens the invoices again.
 * Lock order: receiving_reports -> supplier_invoices (ORDER BY id) -> document_sequences; disbursements -> supplier_invoices.
 */
declare(strict_types=1);

final class Payables
{
    public const STATUSES  = ['open' => 'Unpaid', 'paid' => 'Paid', 'cancelled' => 'Cancelled'];
    public const BADGES    = ['open' => 'badge--warning', 'paid' => 'badge--success', 'cancelled' => 'badge--danger'];
    public const FILTERS   = ['open' => 'Unpaid / partial', 'overdue' => 'Overdue', 'paid' => 'Paid', 'cancelled' => 'Cancelled', 'all' => 'All'];
    public const DV_STATUSES = ['posted' => 'Posted', 'cancelled' => 'Cancelled'];
    public const DV_BADGES   = ['posted' => 'badge--success', 'cancelled' => 'badge--danger'];
    public const CHECK_STATUSES = ['none' => '—', 'issued' => 'Issued', 'cleared' => 'Cleared'];
    public const MAX_LINES = 100;

    public static function canView(): bool
    {
        return Auth::canAny('payables.manage', 'payables.cancel') && Auth::can('products.cost');
    }

    public static function requireView(): void
    {
        if (!self::canView()) {
            throw new HttpException(403, 'You do not have permission to view payables.');
        }
    }

    private static function requireManage(): void
    {
        if (!Auth::can('payables.manage') || !Auth::can('products.cost')) {
            throw new HttpException(403, 'You do not have permission to record payables.');
        }
    }

    private static function requireCancel(): void
    {
        if (!Auth::can('payables.cancel') || !Auth::can('products.cost')) {
            throw new HttpException(403, 'You do not have permission to cancel payables.');
        }
    }

    // ------------------------------------------------------------------
    // Supplier invoices: lists
    // ------------------------------------------------------------------

    /** Days overdue (SQL, alias i). */
    private const OVERDUE = 'DATEDIFF(CURDATE(), i.due_date)';

    /** @param array{search?:string, status?:string, supplier?:?int, aging?:string} $f */
    private static function where(array $f, bool $withAging = true): array
    {
        [$scope, $params] = Branch::scopeSql('i.branch_id');
        $where  = [$scope];
        $status = (string) ($f['status'] ?? 'open');
        if ($status === 'overdue') {
            $where[] = "i.status = 'open' AND i.due_date < CURDATE()";
        } elseif (isset(self::STATUSES[$status])) {
            $where[]  = 'i.status = ?';
            $params[] = $status;
        }
        if (($f['supplier'] ?? null) !== null) {
            $where[]  = 'i.supplier_id = ?';
            $params[] = (int) $f['supplier'];
        }
        $aging = (string) ($f['aging'] ?? '');
        if ($withAging && isset(Collections::AGING[$aging])) {
            [, $min, $max] = Collections::AGING[$aging];
            $where[] = "i.status = 'open'";
            if ($min !== null) {
                $where[]  = self::OVERDUE . ' >= ?';
                $params[] = $min;
            }
            if ($max !== null) {
                $where[]  = self::OVERDUE . ' <= ?';
                $params[] = $max;
            }
        }
        $q = (string) ($f['search'] ?? '');
        if ($q !== '') {
            $like = like_pattern($q);
            $where[] = '(i.ap_no LIKE ? OR i.invoice_no LIKE ? OR s.name LIKE ? OR rr.rr_no LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }
        return [implode(' AND ', $where), $params];
    }

    private const FROM = 'FROM supplier_invoices i
               JOIN suppliers s ON s.id = i.supplier_id
               JOIN receiving_reports rr ON rr.id = i.receiving_id
               JOIN branches b ON b.id = i.branch_id';

    public static function count(array $f): int
    {
        self::requireView();
        [$where, $params] = self::where($f);
        $stmt = db()->prepare('SELECT COUNT(*) ' . self::FROM . " WHERE {$where}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public static function search(array $f, int $limit, int $offset): array
    {
        self::requireView();
        [$where, $params] = self::where($f);
        $stmt = db()->prepare(
            'SELECT i.id, i.ap_no, i.invoice_no, i.invoice_date, i.due_date, i.amount, i.paid_amount, i.amount - i.paid_amount AS balance,
                    i.status, i.supplier_id, s.name AS supplier_name, rr.id AS rr_id, rr.rr_no, i.branch_id, b.code AS branch_code,
                    b.name AS branch_name, ' . self::OVERDUE . ' AS days
              ' . self::FROM . "
              WHERE {$where}
              ORDER BY i.status = 'open' DESC, i.due_date, i.id
              LIMIT ? OFFSET ?"
        );
        $stmt->execute([...$params, $limit, $offset]);
        return $stmt->fetchAll();
    }

    /** Unpaid balance per aging bucket (days overdue), filters except status / bucket. */
    public static function aging(array $f): array
    {
        self::requireView();
        [$where, $params] = self::where(['status' => 'open'] + $f, false);
        $stmt = db()->prepare('SELECT ' . self::OVERDUE . ' AS days, i.amount - i.paid_amount AS balance ' . self::FROM . " WHERE {$where}");
        $stmt->execute($params);
        $out = ['buckets' => array_map(static fn (): array => ['count' => 0, 'cents' => 0], Collections::AGING), 'count' => 0, 'cents' => 0];
        foreach ($stmt->fetchAll() as $r) {
            $key = Collections::bucket((int) $r['days']);
            $c   = to_cents($r['balance']);
            $out['buckets'][$key]['count']++;
            $out['buckets'][$key]['cents'] += $c;
            $out['count']++;
            $out['cents'] += $c;
        }
        return $out;
    }

    /** Posted receiving reports of the current branch with no live supplier invoice (newest first). */
    public static function toInvoice(int $limit = 50): array
    {
        if (!Branch::isConcrete() || !self::canView()) {
            return [];
        }
        $stmt = db()->prepare(
            "SELECT rr.id, rr.rr_no, rr.received_date, rr.reference_no, rr.total_cost, s.id AS supplier_id, s.name AS supplier_name, s.terms_days,
                    po.po_no
               FROM receiving_reports rr
               JOIN suppliers s ON s.id = rr.supplier_id
               LEFT JOIN purchase_orders po ON po.id = rr.po_id
              WHERE rr.branch_id = ? AND rr.status = 'posted'
                AND NOT EXISTS (SELECT 1 FROM supplier_invoices i WHERE i.receiving_id = rr.id AND i.status <> 'cancelled')
              ORDER BY rr.received_date DESC, rr.id DESC LIMIT " . max(1, min(500, $limit))
        );
        $stmt->execute([(int) Branch::current()]);
        return $stmt->fetchAll();
    }

    /** Work counts of the current branch: RRs to invoice, invoices overdue / due within 7 days, checks issued not cleared. */
    public static function workCounts(): array
    {
        $out = ['to_invoice' => 0, 'overdue' => 0, 'due_soon' => 0, 'checks' => 0];
        if (!Branch::isConcrete() || !self::canView()) {
            return $out;
        }
        $cur  = (int) Branch::current();
        $stmt = db()->prepare(
            "SELECT COUNT(*) FROM receiving_reports rr WHERE rr.branch_id = ? AND rr.status = 'posted'
                AND NOT EXISTS (SELECT 1 FROM supplier_invoices i WHERE i.receiving_id = rr.id AND i.status <> 'cancelled')"
        );
        $stmt->execute([$cur]);
        $out['to_invoice'] = (int) $stmt->fetchColumn();
        $stmt = db()->prepare(
            "SELECT SUM(due_date < CURDATE()), SUM(due_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 7 DAY)
               FROM supplier_invoices WHERE branch_id = ? AND status = 'open'"
        );
        $stmt->execute([$cur]);
        [$o, $d] = $stmt->fetch(PDO::FETCH_NUM) ?: [0, 0];
        $stmt = db()->prepare("SELECT COUNT(*) FROM disbursements WHERE branch_id = ? AND status = 'posted' AND check_status = 'issued'");
        $stmt->execute([$cur]);
        return ['to_invoice' => $out['to_invoice'], 'overdue' => (int) $o, 'due_soon' => (int) $d, 'checks' => (int) $stmt->fetchColumn()];
    }

    public static function find(int $id): ?array
    {
        self::requireView();
        $stmt = db()->prepare(
            'SELECT i.*, s.name AS supplier_name, s.tin AS supplier_tin, s.address AS supplier_address, s.terms_days,
                    rr.rr_no, rr.received_date, rr.reference_no AS rr_reference, rr.total_cost AS rr_total, po.id AS po_id, po.po_no,
                    b.code AS branch_code, b.name AS branch_name, cu.full_name AS created_by_name, xu.full_name AS cancelled_by_name
               FROM supplier_invoices i
               JOIN suppliers s ON s.id = i.supplier_id
               JOIN receiving_reports rr ON rr.id = i.receiving_id
               LEFT JOIN purchase_orders po ON po.id = rr.po_id
               JOIN branches b ON b.id = i.branch_id
               JOIN users cu ON cu.id = i.created_by
               LEFT JOIN users xu ON xu.id = i.cancelled_by
              WHERE i.id = ?'
        );
        $stmt->execute([$id]);
        $i = $stmt->fetch();
        if (!$i) {
            return null;
        }
        Branch::assertAccess((int) $i['branch_id']);
        $stmt = db()->prepare(
            'SELECT d.id, d.dv_no, d.payment_date, d.method, d.reference, d.status, l.amount, l.ewt_amount
               FROM disbursement_lines l JOIN disbursements d ON d.id = l.disbursement_id
              WHERE l.invoice_id = ? ORDER BY d.payment_date, d.id'
        );
        $stmt->execute([$id]);
        $i['payments'] = $stmt->fetchAll();
        return $i;
    }

    public static function invoiceActions(array $i): array
    {
        $here = Branch::current() === (int) $i['branch_id'];
        return [
            'pay'    => $here && $i['status'] === 'open' && Auth::can('payables.manage'),
            'cancel' => $here && $i['status'] === 'open' && to_cents($i['paid_amount']) === 0 && Auth::can('payables.cancel'),
        ];
    }

    // ------------------------------------------------------------------
    // Supplier invoices: record / cancel
    // ------------------------------------------------------------------

    /** The receiving report an invoice is made from (posted, current branch, no live invoice). */
    public static function receivingFor(int $rrId): array
    {
        self::requireManage();
        $stmt = db()->prepare(
            "SELECT rr.id, rr.rr_no, rr.branch_id, rr.status, rr.received_date, rr.reference_no, rr.total_cost, rr.supplier_id,
                    s.name AS supplier_name, s.terms_days, po.po_no
               FROM receiving_reports rr JOIN suppliers s ON s.id = rr.supplier_id LEFT JOIN purchase_orders po ON po.id = rr.po_id
              WHERE rr.id = ?"
        );
        $stmt->execute([$rrId]);
        $rr = $stmt->fetch() ?: throw new HttpException(404, 'Receiving report not found.');
        Branch::assertAccess((int) $rr['branch_id']);
        CustomerOrders::assertWorkingIn((int) $rr['branch_id']);
        if ($rr['status'] !== 'posted') {
            throw new HttpException(409, 'Only a posted receiving report can be invoiced.');
        }
        return $rr;
    }

    /** Header: invoice_no, invoice_date, due_date, amount, notes. @return array{0: array, 1: array<string,string>} */
    public static function validateInvoice(array $in, array $rr): array
    {
        self::requireManage();
        $errors = [];
        $no = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', input_string($in, 'invoice_no', 61)) ?? '');
        $notes = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', input_string($in, 'notes', 256)) ?? '');
        $raw = is_string($in['amount'] ?? null) ? str_replace(',', '', trim($in['amount'])) : '';
        $amount = $raw === '' ? null : input_decimal(['v' => $raw], 'v', 0.01, 99999999.99, 2);
        $data = [
            'invoice_no'   => $no,
            'invoice_date' => input_date($in, 'invoice_date'),
            'due_date'     => input_date($in, 'due_date'),
            'amount'       => $amount === null ? null : to_cents((string) $amount),
            'notes'        => $notes !== '' ? mb_substr($notes, 0, 255) : null,
        ];
        if (mb_strlen($no) < 2 || mb_strlen($no) > 60) {
            $errors['invoice_no'] = "Enter the supplier's invoice / charge invoice no.";
        }
        if ($data['invoice_date'] === null) {
            $errors['invoice_date'] = 'Enter the invoice date.';
        } elseif ($data['invoice_date'] > date('Y-m-d')) {
            $errors['invoice_date'] = 'The invoice date cannot be in the future.';
        }
        if ($data['due_date'] === null) {
            $errors['due_date'] = 'Enter the due date.';
        } elseif ($data['invoice_date'] !== null && $data['due_date'] < $data['invoice_date']) {
            $errors['due_date'] = 'The due date cannot be before the invoice date.';
        }
        if ($data['amount'] === null) {
            $errors['amount'] = 'Enter the invoice amount.';
        }
        return [$data, $errors];
    }

    /** @return array{id:int, ap_no:string} */
    public static function createInvoice(int $rrId, array $d, int $userId): array
    {
        self::requireManage();
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT id, rr_no, branch_id, status, supplier_id FROM receiving_reports WHERE id = ? FOR UPDATE');
            $stmt->execute([$rrId]);
            $rr = $stmt->fetch() ?: throw new HttpException(404, 'Receiving report not found.');
            Branch::assertAccess((int) $rr['branch_id']);
            CustomerOrders::assertWorkingIn((int) $rr['branch_id']);
            if ($rr['status'] !== 'posted') {
                throw new HttpException(409, 'Only a posted receiving report can be invoiced.');
            }
            $stmt = $pdo->prepare("SELECT ap_no FROM supplier_invoices WHERE receiving_id = ? AND status <> 'cancelled' LIMIT 1");
            $stmt->execute([$rrId]);
            if ($dup = $stmt->fetchColumn()) {
                throw new HttpException(409, "{$rr['rr_no']} is already invoiced on {$dup}.");
            }
            $no = DocNumber::next((int) $rr['branch_id'], 'AP');
            $pdo->prepare(
                'INSERT INTO supplier_invoices (ap_no, branch_id, supplier_id, receiving_id, invoice_no, invoice_date, due_date, amount, notes, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$no, (int) $rr['branch_id'], (int) $rr['supplier_id'], $rrId, $d['invoice_no'], $d['invoice_date'], $d['due_date'],
                from_cents($d['amount']), $d['notes'], $userId]);
            $id = (int) $pdo->lastInsertId();
            Audit::record('payables', 'invoice', 'supplier_invoice', $id, $no, null,
                ['rr' => $rr['rr_no'], 'invoice_no' => $d['invoice_no'], 'due' => $d['due_date']], (int) $rr['branch_id']);
            $pdo->commit();
            return ['id' => $id, 'ap_no' => $no];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function cancelInvoice(int $id, ?string $reason, int $userId): string
    {
        self::requireCancel();
        $reason = CustomerOrders::cleanReason($reason, 'reason');
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM supplier_invoices WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $i = $stmt->fetch() ?: throw new HttpException(404, 'Supplier invoice not found.');
            Branch::assertAccess((int) $i['branch_id']);
            CustomerOrders::assertWorkingIn((int) $i['branch_id']);
            if ($i['status'] !== 'open' || to_cents((string) $i['paid_amount']) !== 0) {
                throw new HttpException(409, "{$i['ap_no']} has payments or is not open: cancel its disbursements first.");
            }
            $pdo->prepare('UPDATE supplier_invoices SET status = ?, cancelled_by = ?, cancelled_at = NOW(), cancel_reason = ? WHERE id = ?')
                ->execute(['cancelled', $userId, $reason, $id]);
            Audit::record('payables', 'invoice_cancel', 'supplier_invoice', $id, (string) $i['ap_no'], ['status' => 'open'],
                ['status' => 'cancelled', 'reason' => $reason], (int) $i['branch_id']);
            $pdo->commit();
            return (string) $i['ap_no'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // ------------------------------------------------------------------
    // Disbursements: lists
    // ------------------------------------------------------------------

    /** Suppliers with unpaid invoices at the current branch (payment form). */
    public static function suppliersWithBalance(): array
    {
        if (!Branch::isConcrete() || !self::canView()) {
            return [];
        }
        $stmt = db()->prepare(
            "SELECT s.id, s.name, COUNT(*) AS invoices, SUM(i.amount - i.paid_amount) AS balance
               FROM supplier_invoices i JOIN suppliers s ON s.id = i.supplier_id
              WHERE i.branch_id = ? AND i.status = 'open' GROUP BY s.id, s.name ORDER BY s.name"
        );
        $stmt->execute([(int) Branch::current()]);
        return $stmt->fetchAll();
    }

    public static function openInvoices(int $supplierId): array
    {
        if (!Branch::isConcrete() || !self::canView()) {
            return [];
        }
        $stmt = db()->prepare(
            "SELECT i.id, i.ap_no, i.invoice_no, i.invoice_date, i.due_date, i.amount, i.paid_amount, i.amount - i.paid_amount AS balance,
                    rr.rr_no, " . self::OVERDUE . " AS days
               FROM supplier_invoices i JOIN receiving_reports rr ON rr.id = i.receiving_id
              WHERE i.branch_id = ? AND i.supplier_id = ? AND i.status = 'open'
              ORDER BY i.due_date, i.id LIMIT " . self::MAX_LINES
        );
        $stmt->execute([(int) Branch::current(), $supplierId]);
        return $stmt->fetchAll();
    }

    /** @param array{search?:string, status?:string, method?:string, checks?:string, from?:?string, to?:?string} $f */
    private static function dvWhere(array $f): array
    {
        [$scope, $params] = Branch::scopeSql('d.branch_id');
        $where = [$scope];
        if (isset(self::DV_STATUSES[$f['status'] ?? ''])) {
            $where[]  = 'd.status = ?';
            $params[] = $f['status'];
        }
        if (isset(Collections::METHODS[$f['method'] ?? ''])) {
            $where[]  = 'd.method = ?';
            $params[] = $f['method'];
        }
        if (($f['checks'] ?? '') === 'issued') {
            $where[] = "d.status = 'posted' AND d.check_status = 'issued'";
        }
        if (!empty($f['from'])) {
            $where[]  = 'd.payment_date >= ?';
            $params[] = $f['from'];
        }
        if (!empty($f['to'])) {
            $where[]  = 'd.payment_date <= ?';
            $params[] = $f['to'];
        }
        $q = (string) ($f['search'] ?? '');
        if ($q !== '') {
            $like = like_pattern($q);
            $where[] = '(d.dv_no LIKE ? OR d.supplier_name LIKE ? OR d.reference LIKE ?
                         OR EXISTS (SELECT 1 FROM disbursement_lines l JOIN supplier_invoices i ON i.id = l.invoice_id
                                     WHERE l.disbursement_id = d.id AND (i.ap_no LIKE ? OR i.invoice_no LIKE ?)))';
            array_push($params, $like, $like, $like, $like, $like);
        }
        return [implode(' AND ', $where), $params];
    }

    public static function dvCount(array $f): int
    {
        self::requireView();
        [$where, $params] = self::dvWhere($f);
        $stmt = db()->prepare("SELECT COUNT(*) FROM disbursements d WHERE {$where}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public static function dvSearch(array $f, int $limit, int $offset): array
    {
        self::requireView();
        [$where, $params] = self::dvWhere($f);
        $stmt = db()->prepare(
            "SELECT d.id, d.dv_no, d.payment_date, d.supplier_name, d.method, d.reference, d.check_date, d.check_status, d.amount_paid,
                    d.ewt_total, d.total_settled, d.status, d.branch_id, b.code AS branch_code, b.name AS branch_name, u.full_name AS created_by_name,
                    (SELECT COUNT(*) FROM disbursement_lines l WHERE l.disbursement_id = d.id) AS invoices
               FROM disbursements d JOIN branches b ON b.id = d.branch_id JOIN users u ON u.id = d.created_by
              WHERE {$where}
              ORDER BY d.payment_date DESC, d.id DESC
              LIMIT ? OFFSET ?"
        );
        $stmt->execute([...$params, $limit, $offset]);
        return $stmt->fetchAll();
    }

    public static function dvSummary(array $f): array
    {
        self::requireView();
        [$where, $params] = self::dvWhere(['status' => ''] + $f);
        $stmt = db()->prepare(
            "SELECT COUNT(*) AS vouchers, COALESCE(SUM(d.amount_paid), 0) AS paid, COALESCE(SUM(d.ewt_total), 0) AS ewt,
                    COALESCE(SUM(d.total_settled), 0) AS settled
               FROM disbursements d WHERE {$where} AND d.status = 'posted'"
        );
        $stmt->execute($params);
        return $stmt->fetch();
    }

    public static function findDv(int $id): ?array
    {
        self::requireView();
        $stmt = db()->prepare(
            'SELECT d.*, s.tin AS supplier_tin, s.address AS supplier_address, b.code AS branch_code, b.name AS branch_name,
                    u.full_name AS created_by_name, xu.full_name AS cancelled_by_name
               FROM disbursements d
               JOIN suppliers s ON s.id = d.supplier_id
               JOIN branches b ON b.id = d.branch_id
               JOIN users u ON u.id = d.created_by
               LEFT JOIN users xu ON xu.id = d.cancelled_by
              WHERE d.id = ?'
        );
        $stmt->execute([$id]);
        $d = $stmt->fetch();
        if (!$d) {
            return null;
        }
        Branch::assertAccess((int) $d['branch_id']);
        $stmt = db()->prepare(
            'SELECT l.*, i.ap_no, i.invoice_no, i.invoice_date, i.due_date, i.amount AS invoice_amount, i.paid_amount, i.status AS invoice_status, rr.rr_no
               FROM disbursement_lines l
               JOIN supplier_invoices i ON i.id = l.invoice_id
               JOIN receiving_reports rr ON rr.id = i.receiving_id
              WHERE l.disbursement_id = ? ORDER BY i.due_date, i.id'
        );
        $stmt->execute([$id]);
        $d['lines'] = $stmt->fetchAll();
        return $d;
    }

    public static function dvActions(array $d): array
    {
        $here   = Branch::current() === (int) $d['branch_id'];
        $posted = $d['status'] === 'posted';
        return [
            'cancel' => $here && $posted && Auth::can('payables.cancel'),
            'clear'  => $here && $posted && $d['check_status'] === 'issued' && Auth::can('payables.manage'),
        ];
    }

    // ------------------------------------------------------------------
    // Disbursements: post / cancel / check cleared
    // ------------------------------------------------------------------

    /**
     * Header: supplier_id, payment_date, method, reference, bank_name, check_date, particulars. Lines keyed by invoice
     * id: lines[<id>][amount|ewt] (money; blank = 0; all-zero lines skipped). @return array{0: array, 1: array<string,string>}
     */
    public static function validateDv(array $in): array
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
        $method = input_string($in, 'method', 10);
        $data = [
            'supplier_id'  => input_int($in, 'supplier_id', 1),
            'payment_date' => input_date($in, 'payment_date'),
            'method'       => $method,
            'reference'    => $text('reference', 60),
            'bank_name'    => $text('bank_name', 60),
            'check_date'   => input_date($in, 'check_date'),
            'particulars'  => $text('particulars', 255),
            'lines'        => [],
        ];
        if ($data['supplier_id'] === null) {
            $errors['supplier_id'] = 'Choose the supplier.';
        }
        if ($data['payment_date'] === null) {
            $errors['payment_date'] = 'Enter the payment date.';
        } elseif ($data['payment_date'] > date('Y-m-d')) {
            $errors['payment_date'] = 'The payment date cannot be in the future.';
        }
        if (!isset(Collections::METHODS[$method])) {
            $errors['method'] = 'Choose how EXECOM pays.';
        } elseif ($method === 'check' && $data['reference'] === null) {
            $errors['reference'] = 'Enter the check number.';
        } elseif (in_array($method, ['bank', 'gcash'], true) && $data['reference'] === null) {
            $errors['reference'] = 'Enter the transfer / transaction reference.';
        }
        if ($method !== 'check') {
            $data['check_date'] = null;
        } elseif ($data['check_date'] === null) {
            $data['check_date'] = $data['payment_date'];
        }
        if ($method === 'cash' || $method === 'gcash') {
            $data['bank_name'] = null;
        }
        $raw = is_array($in['lines'] ?? null) ? $in['lines'] : [];
        if (count($raw) > self::MAX_LINES) {
            $errors['lines'] = 'At most ' . self::MAX_LINES . ' invoices in one voucher.';
            $raw = [];
        }
        foreach ($raw as $invId => $line) {
            $iid = filter_var($invId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($iid === false || !is_array($line)) {
                continue;
            }
            $cents = [];
            foreach (['amount', 'ewt'] as $k) {
                $v = is_string($line[$k] ?? null) ? str_replace(',', '', trim($line[$k])) : '';
                if ($v === '') {
                    $cents[$k] = 0;
                    continue;
                }
                $dec = input_decimal(['v' => $v], 'v', 0, 99999999.99, 2);
                if ($dec === null) {
                    $errors["lines.{$iid}.{$k}"] = 'Enter an amount, e.g. 1,500.00.';
                    $cents[$k] = 0;
                } else {
                    $cents[$k] = to_cents((string) $dec);
                }
            }
            if ($cents['amount'] + $cents['ewt'] > 0) {
                $data['lines'][$iid] = $cents;
            }
        }
        if (!$data['lines'] && !isset($errors['lines'])) {
            $errors['lines'] = 'Enter what is paid on at least one invoice.';
        }
        return [$data, $errors];
    }

    /** @return array{id:int, dv_no:string} */
    public static function postDv(array $d, int $userId): array
    {
        self::requireManage();
        $branchId = Branch::forWrite();
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT id, name FROM suppliers WHERE id = ?');
            $stmt->execute([(int) $d['supplier_id']]);
            $supplier = $stmt->fetch() ?: throw new HttpException(422, 'Choose the supplier.');
            $ids = array_map('intval', array_keys($d['lines']));
            sort($ids);
            $in_  = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT id, ap_no, branch_id, supplier_id, status, amount, paid_amount FROM supplier_invoices WHERE id IN ({$in_}) ORDER BY id FOR UPDATE");
            $stmt->execute($ids);
            $invoices = array_column($stmt->fetchAll(), null, 'id');
            $errors = [];
            $totals = ['amount' => 0, 'ewt' => 0];
            foreach ($d['lines'] as $iid => $c) {
                $i = $invoices[$iid] ?? null;
                if ($i === null || (int) $i['branch_id'] !== $branchId || (int) $i['supplier_id'] !== (int) $supplier['id'] || $i['status'] !== 'open') {
                    throw new HttpException(409, 'An invoice on this voucher is no longer open for this supplier. Reload the page.');
                }
                $balance = to_cents((string) $i['amount']) - to_cents((string) $i['paid_amount']);
                if ($c['amount'] + $c['ewt'] > $balance) {
                    $errors["lines.{$iid}.amount"] = "{$i['ap_no']}: only " . money(from_cents($balance)) . ' is still due.';
                }
                $totals['amount'] += $c['amount'];
                $totals['ewt']    += $c['ewt'];
            }
            if ($errors) {
                throw new HttpException(422, (string) reset($errors), ['errors' => $errors]);
            }
            $no = DocNumber::next($branchId, 'DV');
            $pdo->prepare(
                'INSERT INTO disbursements (dv_no, branch_id, supplier_id, supplier_name, payment_date, method, reference, bank_name, check_date,
                                            check_status, amount_paid, ewt_total, total_settled, particulars, status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$no, $branchId, (int) $supplier['id'], mb_substr((string) $supplier['name'], 0, 120), $d['payment_date'], $d['method'],
                $d['reference'], $d['bank_name'], $d['check_date'], $d['method'] === 'check' ? 'issued' : 'none', from_cents($totals['amount']),
                from_cents($totals['ewt']), from_cents($totals['amount'] + $totals['ewt']), $d['particulars'], 'posted', $userId]);
            $id = (int) $pdo->lastInsertId();
            $line = $pdo->prepare('INSERT INTO disbursement_lines (disbursement_id, invoice_id, amount, ewt_amount) VALUES (?, ?, ?, ?)');
            $pay  = $pdo->prepare("UPDATE supplier_invoices SET paid_amount = paid_amount + ?, status = IF(paid_amount >= amount, 'paid', 'open') WHERE id = ?");
            $refs = [];
            foreach ($d['lines'] as $iid => $c) {
                $line->execute([$id, $iid, from_cents($c['amount']), from_cents($c['ewt'])]);
                $pay->execute([from_cents($c['amount'] + $c['ewt']), $iid]);
                $refs[] = $invoices[$iid]['ap_no'];
            }
            Audit::record('payables', 'pay', 'disbursement', $id, $no, null, array_filter([
                'supplier' => $supplier['name'], 'method' => Collections::METHODS[$d['method']], 'reference' => $d['reference'], 'invoices' => $refs,
                'ewt' => $totals['ewt'] > 0 ? 'withheld' : null,
            ], static fn ($v) => $v !== null), $branchId);
            $pdo->commit();
            return ['id' => $id, 'dv_no' => $no];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function cancelDv(int $id, ?string $reason, int $userId): string
    {
        self::requireCancel();
        $reason = CustomerOrders::cleanReason($reason, 'reason');
        return self::dvTransition($id, static function (array $d) use ($id, $reason, $userId): string {
            if ($d['status'] !== 'posted') {
                throw new HttpException(409, "{$d['dv_no']} is already cancelled.");
            }
            $stmt = db()->prepare('SELECT invoice_id, amount + ewt_amount AS credit FROM disbursement_lines WHERE disbursement_id = ?');
            $stmt->execute([$id]);
            $lines = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            $ids = array_map('intval', array_keys($lines));
            sort($ids);
            $in_  = implode(',', array_fill(0, count($ids), '?'));
            $stmt = db()->prepare("SELECT id, paid_amount FROM supplier_invoices WHERE id IN ({$in_}) ORDER BY id FOR UPDATE");
            $stmt->execute($ids);
            $upd = db()->prepare("UPDATE supplier_invoices SET paid_amount = paid_amount - ?, status = 'open' WHERE id = ?");
            foreach ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $iid => $paid) {
                if (to_cents((string) $paid) < to_cents((string) $lines[$iid])) {
                    throw new HttpException(409, 'The invoices of this voucher no longer match it. Check the stock integrity page.');
                }
                $upd->execute([$lines[$iid], (int) $iid]);
            }
            db()->prepare('UPDATE disbursements SET status = ?, cancelled_by = ?, cancelled_at = NOW(), cancel_reason = ? WHERE id = ?')
                ->execute(['cancelled', $userId, $reason, $id]);
            Audit::record('payables', 'pay_cancel', 'disbursement', $id, (string) $d['dv_no'], ['status' => 'posted'],
                ['status' => 'cancelled', 'reason' => $reason], (int) $d['branch_id']);
            return (string) $d['dv_no'];
        });
    }

    public static function clearCheck(int $id, ?string $date, int $userId): string
    {
        self::requireManage();
        $when = input_date(['d' => (string) $date], 'd');
        if ($when === null || $when > date('Y-m-d')) {
            throw new HttpException(422, 'Enter the date the check cleared (not in the future).', ['errors' => ['cleared_at' => 'Enter a valid date.']]);
        }
        return self::dvTransition($id, static function (array $d) use ($id, $when): string {
            if ($d['status'] !== 'posted' || $d['check_status'] !== 'issued') {
                throw new HttpException(409, "{$d['dv_no']} has no issued check waiting to clear.");
            }
            db()->prepare("UPDATE disbursements SET check_status = 'cleared', cleared_at = ? WHERE id = ?")->execute([$when, $id]);
            Audit::record('payables', 'check_cleared', 'disbursement', $id, (string) $d['dv_no'], ['check' => 'issued'],
                ['check' => 'cleared', 'date' => $when, 'check_no' => $d['reference']], (int) $d['branch_id']);
            return (string) $d['dv_no'];
        });
    }

    private static function dvTransition(int $id, callable $fn): string
    {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM disbursements WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $d = $stmt->fetch() ?: throw new HttpException(404, 'Disbursement not found.');
            Branch::assertAccess((int) $d['branch_id']);
            CustomerOrders::assertWorkingIn((int) $d['branch_id']);
            $out = $fn($d);
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
