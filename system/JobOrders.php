<?php
/**
 * Job orders (job_orders + job_order_events), audit module 'job_orders'. Phase 10a: intake, assignment,
 * diagnosis, quotation approval and repair statuses; parts, billing and release follow in Phase 10b.
 *
 *   new -> assigned (assign / take) -> diagnosing (start) -> diagnosis + estimate:
 *       estimate above setting job_quote_threshold (or "ask the customer") -> for_approval, else -> in_repair
 *   for_approval -> in_repair (customer approved) | completed (customer declined; recorded who / how)
 *   in_repair <-> waiting_parts, in_repair -> for_testing -> completed | in_repair (test failed)
 *   new / assigned -> cancelled (reason)
 *
 * Who: job_orders.view / job_orders.assign see every job of the branch; others (technicians) see their own,
 * the ones they took in and the branch's unassigned new jobs. Work on a job = job_orders.assign (any job) or
 * job_orders.update on a job assigned to you; taking an unassigned new job needs job_orders.update. Intake and
 * intake edits: job_orders.create. Customer decisions: the worker or the front desk (job_orders.create).
 * Every action needs the session working in the job's branch. Lock order: job_orders row -> document_sequences.
 */
declare(strict_types=1);

final class JobOrders
{
    public const STATUSES = [
        'new' => 'New', 'assigned' => 'Assigned', 'diagnosing' => 'Diagnosing', 'for_approval' => 'For Approval',
        'in_repair' => 'In Repair', 'waiting_parts' => 'Waiting for Parts', 'for_testing' => 'For Testing',
        'completed' => 'Completed', 'released' => 'Released', 'closed' => 'Closed', 'cancelled' => 'Cancelled',
    ];
    public const BADGES = [
        'new' => 'badge--info', 'assigned' => 'badge--info', 'diagnosing' => 'badge--warning', 'for_approval' => 'badge--warning',
        'in_repair' => 'badge--warning', 'waiting_parts' => 'badge--danger', 'for_testing' => 'badge--info',
        'completed' => 'badge--success', 'released' => 'badge--success', 'closed' => '', 'cancelled' => 'badge--danger',
    ];
    public const PRIORITY_BADGES = ['low' => '', 'normal' => '', 'high' => 'badge--warning', 'urgent' => 'badge--danger'];
    public const OPEN       = ['new', 'assigned', 'diagnosing', 'for_approval', 'in_repair', 'waiting_parts', 'for_testing'];
    public const PRIORITIES = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'];
    public const LOCATIONS  = ['in_shop' => 'In shop', 'on_site' => 'On site'];
    public const METHODS    = ['in_person' => 'In person', 'phone' => 'Phone call', 'sms' => 'SMS / chat', 'email' => 'Email'];
    public const VIEW_PERMISSIONS = ['job_orders.view', 'job_orders.create', 'job_orders.update', 'job_orders.assign',
                                     'job_parts.issue', 'job_orders.release'];
    public const PREFIX = 'JO';

    /** action => statuses it starts from (see act()). */
    private const FROM = [
        'take'        => ['new'],
        'assign'      => self::OPEN,
        'start'       => ['assigned'],
        'diagnose'    => ['diagnosing'],
        'decision'    => ['for_approval'],
        'wait_parts'  => ['in_repair'],
        'resume'      => ['waiting_parts'],
        'to_testing'  => ['in_repair'],
        'test_failed' => ['for_testing'],
        'complete'    => ['for_testing'],
        'cancel'      => ['new', 'assigned'],
        'note'        => [...self::OPEN, 'completed', 'released'],
        'set_labor'   => ['completed'],
    ];

    /** Intake fields (edit diff + audit). */
    private const INTAKE = ['customer_id', 'customer_name', 'customer_phone', 'contact_person', 'job_type_id', 'device_type_id',
        'brand', 'model', 'serial_no', 'accessories', 'device_condition', 'problem', 'remarks', 'priority', 'service_location', 'expected_at'];

    // ------------------------------------------------------------------
    // Permissions / visibility
    // ------------------------------------------------------------------

    private static function requireView(): void
    {
        if (!Auth::canAny(...self::VIEW_PERMISSIONS)) {
            throw new HttpException(403, 'You do not have permission to view job orders.');
        }
    }

    /** job_orders.view / assign / job_parts.issue / job_orders.release: every job of the branch; otherwise own, taken in,
     *  or unassigned new. */
    public static function seesAll(): bool
    {
        return Auth::canAny('job_orders.view', 'job_orders.assign', 'job_parts.issue', 'job_orders.release');
    }

    private static function visibleSql(string $alias = 'j'): array
    {
        [$scope, $params] = Branch::scopeSql("{$alias}.branch_id");
        if (self::seesAll()) {
            return [$scope, $params];
        }
        $uid = (int) Auth::id();
        return ["{$scope} AND ({$alias}.technician_id = ? OR {$alias}.created_by = ? OR ({$alias}.status = 'new' AND {$alias}.technician_id IS NULL))",
            [...$params, $uid, $uid]];
    }

    private static function isVisible(array $j): bool
    {
        if (!Branch::inScope((int) $j['branch_id'])) {
            return false;
        }
        $uid = (int) Auth::id();
        return self::seesAll() || (int) $j['technician_id'] === $uid || (int) $j['created_by'] === $uid
            || ($j['status'] === 'new' && $j['technician_id'] === null);
    }

    // ------------------------------------------------------------------
    // Listing / lookup
    // ------------------------------------------------------------------

    /** @param array{search?:string, status?:string, technician?:string, priority?:string} $f status '' | 'open' | a status */
    private static function where(array $f): array
    {
        [$where, $params] = self::visibleSql();
        $where = [$where];
        $status = (string) ($f['status'] ?? '');
        if ($status === 'open') {
            $where[] = "j.status IN ('" . implode("','", self::OPEN) . "')";
        } elseif (isset(self::STATUSES[$status])) {
            $where[]  = 'j.status = ?';
            $params[] = $status;
        }
        $tech = (string) ($f['technician'] ?? '');
        if ($tech === 'me') {
            $where[]  = 'j.technician_id = ?';
            $params[] = (int) Auth::id();
        } elseif ($tech === 'none') {
            $where[] = 'j.technician_id IS NULL';
        } elseif (ctype_digit($tech)) {
            $where[]  = 'j.technician_id = ?';
            $params[] = (int) $tech;
        }
        if (($f['parts'] ?? '') === 'pending') {
            $where[] = "j.status IN ('" . implode("','", self::OPEN) . "') AND EXISTS (SELECT 1 FROM job_order_parts jp WHERE jp.job_order_id = j.id AND jp.status = 'requested')";
        }
        $prio = (string) ($f['priority'] ?? '');
        if (isset(self::PRIORITIES[$prio])) {
            $where[]  = 'j.priority = ?';
            $params[] = $prio;
        }
        $q = (string) ($f['search'] ?? '');
        if ($q !== '') {
            $like = like_pattern($q);
            $where[] = '(j.job_no LIKE ? OR j.customer_name LIKE ? OR j.customer_phone LIKE ? OR j.serial_no LIKE ? OR j.brand LIKE ? OR j.model LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like, $like);
        }
        return [implode(' AND ', $where), $params];
    }

    public static function count(array $f): int
    {
        self::requireView();
        [$where, $params] = self::where($f);
        $stmt = db()->prepare("SELECT COUNT(*) FROM job_orders j WHERE {$where}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public static function search(array $f, int $limit, int $offset): array
    {
        self::requireView();
        [$where, $params] = self::where($f);
        $stmt = db()->prepare(
            "SELECT j.id, j.job_no, j.status, j.priority, j.service_location, j.customer_name, j.customer_phone, j.brand, j.model,
                    j.serial_no, j.problem, j.expected_at, j.created_at, j.technician_id, j.branch_id, j.warranty_until,
                    b.code AS branch_code, t.full_name AS technician_name, dt.name AS device_type
               FROM job_orders j
               JOIN branches b ON b.id = j.branch_id
               LEFT JOIN users t ON t.id = j.technician_id
               LEFT JOIN lookups dt ON dt.id = j.device_type_id
              WHERE {$where}
              ORDER BY j.status IN ('" . implode("','", self::OPEN) . "') DESC,
                       FIELD(j.priority, 'urgent', 'high', 'normal', 'low'), j.created_at DESC, j.id DESC
              LIMIT ? OFFSET ?"
        );
        $stmt->execute([...$params, $limit, $offset]);
        return $stmt->fetchAll();
    }

    /** Work list counts for the current branch: mine (open), unassigned, for approval, waiting parts, completed, parts to issue. */
    public static function workCounts(): array
    {
        $out = ['mine' => 0, 'unassigned' => 0, 'for_approval' => 0, 'waiting_parts' => 0, 'completed' => 0, 'parts' => 0];
        if (!Branch::isConcrete() || !Auth::canAny(...self::VIEW_PERMISSIONS)) {
            return $out;
        }
        [$where, $params] = self::visibleSql();
        $open = "'" . implode("','", self::OPEN) . "'";
        $stmt = db()->prepare(
            "SELECT SUM(j.technician_id = ? AND j.status IN ({$open})), SUM(j.status = 'new' AND j.technician_id IS NULL),
                    SUM(j.status = 'for_approval'), SUM(j.status = 'waiting_parts'), SUM(j.status = 'completed'),
                    SUM(j.status IN ({$open}) AND EXISTS (SELECT 1 FROM job_order_parts jp WHERE jp.job_order_id = j.id AND jp.status = 'requested'))
               FROM job_orders j WHERE {$where}"
        );
        $stmt->execute([(int) Auth::id(), ...$params]);
        $row = $stmt->fetch(PDO::FETCH_NUM) ?: [];
        foreach (array_keys($out) as $i => $k) {
            $out[$k] = (int) ($row[$i] ?? 0);
        }
        return $out;
    }

    /**
     * Job with names, 'events' (timeline) and 'sold' (our sale of the device: product, sale no/date, warranty)
     * or null when missing; 404 when not visible to the current user.
     */
    public static function find(int $id): ?array
    {
        self::requireView();
        $stmt = db()->prepare(
            'SELECT j.*, b.code AS branch_code, b.name AS branch_name, b.address AS branch_address, b.contact_no AS branch_contact,
                    t.full_name AS technician_name, cu.full_name AS created_by_name, au.full_name AS approval_recorded_by_name,
                    co.full_name AS completed_by_name, xu.full_name AS cancelled_by_name, ru.full_name AS released_by_name,
                    jt.name AS job_type, dt.name AS device_type, pj.job_no AS parent_job_no, sl.sale_no
               FROM job_orders j
               JOIN branches b ON b.id = j.branch_id
               JOIN users cu ON cu.id = j.created_by
               LEFT JOIN users t ON t.id = j.technician_id
               LEFT JOIN users au ON au.id = j.approval_recorded_by
               LEFT JOIN users co ON co.id = j.completed_by
               LEFT JOIN users xu ON xu.id = j.cancelled_by
               LEFT JOIN users ru ON ru.id = j.released_by
               LEFT JOIN job_orders pj ON pj.id = j.parent_job_id
               LEFT JOIN sales sl ON sl.id = j.sale_id
               LEFT JOIN lookups jt ON jt.id = j.job_type_id
               LEFT JOIN lookups dt ON dt.id = j.device_type_id
              WHERE j.id = ?'
        );
        $stmt->execute([$id]);
        $j = $stmt->fetch();
        if (!$j) {
            return null;
        }
        if (!self::isVisible($j)) {
            throw new HttpException(404, 'Job order not found.');
        }
        $stmt = db()->prepare(
            'SELECT e.*, u.full_name AS user_name FROM job_order_events e JOIN users u ON u.id = e.user_id
              WHERE e.job_order_id = ? ORDER BY e.id DESC'
        );
        $stmt->execute([$id]);
        $j['events'] = $stmt->fetchAll();
        $j['sold']   = $j['serial_id'] !== null ? self::soldSerial(null, (int) $j['serial_id']) : null;
        $stmt = db()->prepare('SELECT id, job_no, status, created_at FROM job_orders WHERE parent_job_id = ? ORDER BY id');
        $stmt->execute([$id]);
        $j['back_jobs'] = $stmt->fetchAll();
        return $j;
    }

    /**
     * Buttons for the current user: take, assign, start, diagnose, decision, wait_parts, resume, to_testing,
     * test_failed, complete, cancel, note, set_labor, edit, back_job. Nothing outside the job's branch.
     */
    public static function actions(array $j): array
    {
        $out = array_fill_keys([...array_keys(self::FROM), 'edit', 'back_job'], false);
        if (Branch::current() !== (int) $j['branch_id']) {
            return $out;
        }
        $uid    = (int) Auth::id();
        $sup    = Auth::can('job_orders.assign');
        $worker = $sup || (Auth::can('job_orders.update') && (int) $j['technician_id'] === $uid);
        $desk   = Auth::can('job_orders.create');
        $s      = $j['status'];
        $in     = static fn (string $a): bool => in_array($s, self::FROM[$a], true);
        $out['take']   = $in('take') && $j['technician_id'] === null && Auth::can('job_orders.update');
        $out['assign'] = $in('assign') && $sup;
        foreach (['start', 'diagnose', 'wait_parts', 'resume', 'to_testing', 'test_failed', 'complete', 'set_labor'] as $a) {
            $out[$a] = $in($a) && $worker;
        }
        $out['decision'] = $in('decision') && ($worker || $desk);
        $out['cancel']   = $in('cancel') && ($sup || ($desk && (int) $j['created_by'] === $uid && $s === 'new'));
        $out['note']     = $in('note') && ($worker || $desk);
        $out['edit']     = in_array($s, self::OPEN, true) && ($sup || $desk);
        $out['back_job'] = in_array($s, ['released', 'closed'], true) && $desk;
        return $out;
    }

    /** Active users who can work on jobs (job_orders.update) at $branchId, for the assign list. */
    public static function technicians(int $branchId): array
    {
        $stmt = db()->prepare(
            "SELECT u.id, u.full_name, r.name AS role_name,
                    (SELECT COUNT(*) FROM job_orders j WHERE j.technician_id = u.id
                        AND j.status IN ('" . implode("','", self::OPEN) . "')) AS open_jobs
               FROM users u JOIN roles r ON r.code = u.role
              WHERE u.is_active = 1 AND r.is_super = 0
                AND (u.branch_id = ? OR EXISTS (SELECT 1 FROM user_branches ub WHERE ub.user_id = u.id AND ub.branch_id = ?))
                AND EXISTS (SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
                             WHERE rp.role_id = r.id AND p.perm_key = 'job_orders.update')
              ORDER BY u.full_name"
        );
        $stmt->execute([$branchId, $branchId]);
        return $stmt->fetchAll();
    }

    /** Technicians for the list filter (anyone assigned to a job in scope). */
    public static function assignees(): array
    {
        [$scope, $params] = Branch::scopeSql('j.branch_id');
        $stmt = db()->prepare(
            "SELECT DISTINCT u.id, u.full_name FROM job_orders j JOIN users u ON u.id = j.technician_id WHERE {$scope} ORDER BY u.full_name"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Labour charge from the form ('labor', commas allowed; required, 0 = none). */
    private static function laborInput(array $in): string
    {
        $raw = is_string($in['labor'] ?? null) ? str_replace(',', '', trim($in['labor'])) : '';
        $v   = $raw === '' ? null : input_decimal(['v' => $raw], 'v', 0, 9999999.99, 2);
        if ($v === null) {
            throw new HttpException(422, 'Enter the labour charge in pesos (0 when there is none).', ['errors' => ['labor' => 'Enter an amount, e.g. 500.00.']]);
        }
        return number_format($v, 2, '.', '');
    }

    /** Suggested labour at completion: the estimate minus the parts used (at their selling price), never below 0. */
    public static function suggestedLabor(array $j): string
    {
        $stmt = db()->prepare("SELECT COALESCE(SUM(jp.qty_used * p.price), 0) FROM job_order_parts jp JOIN products p ON p.id = jp.product_id
                                WHERE jp.job_order_id = ? AND jp.status = 'issued'");
        $stmt->execute([(int) $j['id']]);
        $cents = to_cents((string) ($j['estimate'] ?? '0')) - to_cents((string) $stmt->fetchColumn());
        return from_cents(max(0, $cents));
    }

    public static function quoteThreshold(): string
    {
        $v = setting('job_quote_threshold', '1000.00');
        return is_numeric($v) && (float) $v >= 0 ? number_format((float) $v, 2, '.', '') : '1000.00';
    }

    // ------------------------------------------------------------------
    // Intake (create / edit)
    // ------------------------------------------------------------------

    /**
     * @param array|null $job the job being edited (from find()), null for a new one
     * @return array{0: array, 1: array<string,string>} [clean data, field errors]
     */
    public static function validate(array $in, ?array $job = null): array
    {
        $text = static fn (string $k, int $max): ?string => ($v = self::cleanText(input_string($in, $k, $max + 1))) === null ? null : $v;
        $data = [
            'customer_id'      => input_int($in, 'customer_id', 1),
            'customer_name'    => $text('customer_name', 100),
            'customer_phone'   => $text('customer_phone', 30),
            'contact_person'   => $text('contact_person', 100),
            'job_type_id'      => input_int($in, 'job_type_id', 1),
            'device_type_id'   => input_int($in, 'device_type_id', 1),
            'brand'            => $text('brand', 80),
            'model'            => $text('model', 80),
            'serial_no'        => $text('serial_no', 80),
            'accessories'      => $text('accessories', 255),
            'device_condition' => $text('device_condition', 255),
            'problem'          => self::cleanMemo(input_string($in, 'problem', 1001)),
            'remarks'          => self::cleanMemo(input_string($in, 'remarks', 501)),
            'priority'         => input_string($in, 'priority', 10) ?: 'normal',
            'service_location' => input_string($in, 'service_location', 10) ?: 'in_shop',
            'expected_at'      => input_date($in, 'expected_at'),
            'technician_id'    => $job === null ? input_int($in, 'technician_id', 1) : null,
        ];
        $errors = [];

        if ($data['customer_id'] !== null) {
            $c = Customers::find($data['customer_id']);
            if ($c === null || ((int) $c['is_active'] !== 1 && $data['customer_id'] !== (int) ($job['customer_id'] ?? 0))) {
                $errors['customer_id'] = 'Choose an active customer, or leave it empty for a walk-in.';
            } else {
                $data['customer_name']  ??= $c['name'];
                $data['customer_phone'] ??= $c['phone'];
            }
        }
        if ($data['customer_name'] === null || mb_strlen($data['customer_name']) < 2 || mb_strlen($data['customer_name']) > 100) {
            $errors['customer_name'] = "Enter the customer's name (2 to 100 characters).";
        }
        if ($data['customer_phone'] === null || !preg_match('/^[0-9+()\s-]{7,30}$/', $data['customer_phone'])) {
            $errors['customer_phone'] = 'Enter a contact number: digits, spaces, + ( ) or - (7 to 30 characters).';
        }
        $lengths = ['contact_person' => 100, 'brand' => 80, 'model' => 80, 'serial_no' => 80, 'accessories' => 255, 'device_condition' => 255];
        foreach ($lengths as $k => $max) {
            if ($data[$k] !== null && mb_strlen($data[$k]) > $max) {
                $errors[$k] = "Keep it under {$max} characters.";
            }
        }
        if ($data['problem'] === null || mb_strlen($data['problem']) < 3 || mb_strlen($data['problem']) > 1000) {
            $errors['problem'] = 'Describe the problem reported by the customer (3 to 1,000 characters).';
        }
        if ($data['remarks'] !== null && mb_strlen($data['remarks']) > 500) {
            $errors['remarks'] = 'Keep the remarks under 500 characters.';
        }
        if ($data['job_type_id'] !== null && !MasterData::isChoice('job-types', $data['job_type_id'], isset($job['job_type_id']) ? (int) $job['job_type_id'] : null)) {
            $errors['job_type_id'] = 'Choose a job type from the list.';
        }
        if ($data['device_type_id'] === null) {
            $errors['device_type_id'] = 'Choose the device type.';
        } elseif (!MasterData::isChoice('device-types', $data['device_type_id'], isset($job['device_type_id']) ? (int) $job['device_type_id'] : null)) {
            $errors['device_type_id'] = 'Choose a device type from the list.';
        }
        if (!isset(self::PRIORITIES[$data['priority']])) {
            $errors['priority'] = 'Choose a priority.';
        }
        if (!isset(self::LOCATIONS[$data['service_location']])) {
            $errors['service_location'] = 'Choose where the service is done.';
        }
        $rawDate = $in['expected_at'] ?? '';
        if (is_string($rawDate) && trim($rawDate) !== '' && $data['expected_at'] === null) {
            $errors['expected_at'] = 'Enter a valid date.';
        } elseif ($data['expected_at'] !== null && $data['expected_at'] !== ($job['expected_at'] ?? null) && $data['expected_at'] < date('Y-m-d')) {
            $errors['expected_at'] = 'The expected date cannot be in the past.';
        }
        if ($data['technician_id'] !== null) {
            if (!Auth::can('job_orders.assign')) {
                $data['technician_id'] = null; // only supervisors assign at intake
            } elseif (!Branch::isConcrete() || !in_array($data['technician_id'], array_map('intval', array_column(self::technicians((int) Branch::current()), 'id')), true)) {
                $errors['technician_id'] = 'Choose a technician of this branch.';
            }
        }
        return [$data, $errors];
    }

    /** Take in a device from the raw form input (422 with details.errors). @return array{id:int, job_no:string} */
    public static function create(array $in, int $userId): array
    {
        if (!Auth::can('job_orders.create')) {
            throw new HttpException(403, 'You do not have permission to take in job orders.');
        }
        $branchId = Branch::forWrite();
        [$d, $errors] = self::validate($in);
        if ($errors) {
            throw new HttpException(422, reset($errors), ['errors' => $errors]);
        }
        $sold = $d['serial_no'] !== null ? self::soldSerial($d['serial_no']) : null;
        $tech = $d['technician_id'];

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $no = self::nextNumber($branchId);
            $parent = self::parentFor($in, $branchId);
            $cols = [...self::INTAKE, 'job_no', 'branch_id', 'status', 'serial_id', 'warranty_until', 'technician_id', 'assigned_at', 'created_by', 'parent_job_id'];
            $vals = [];
            foreach (self::INTAKE as $k) {
                $vals[] = $d[$k];
            }
            array_push($vals, $no, $branchId, $tech !== null ? 'assigned' : 'new', $sold['serial_id'] ?? null, $sold['warranty_until'] ?? null,
                $tech, $tech !== null ? date('Y-m-d H:i:s') : null, $userId, $parent['id'] ?? null);
            $pdo->prepare('INSERT INTO job_orders (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
                ->execute($vals);
            $id = (int) $pdo->lastInsertId();
            self::event($id, $userId, 'create', null, 'new', $parent !== null ? "Back-job of {$parent['job_no']}." : null);
            if ($parent !== null) {
                self::event($parent['id'], $userId, 'note', null, null, "Back-job {$no} was opened for this device.");
            }
            if ($tech !== null) {
                self::event($id, $userId, 'assign', 'new', 'assigned', 'Assigned to ' . self::userName($tech) . '.');
            }
            Audit::record('job_orders', 'create', 'job_order', $id, $no, null, array_filter([
                'customer' => $d['customer_name'], 'device' => trim(($d['brand'] ?? '') . ' ' . ($d['model'] ?? '')) ?: null,
                'serial_no' => $d['serial_no'], 'priority' => $d['priority'], 'technician' => $tech !== null ? self::userName($tech) : null,
                'back_job_of' => $parent['job_no'] ?? null,
            ], static fn ($v) => $v !== null), $branchId);
            $pdo->commit();
            return ['id' => $id, 'job_no' => $no];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Back-job: 'parent_job_id' = a released / closed job of this branch the user can see (locked), or null.
     * @return array{id:int, job_no:string}|null
     */
    private static function parentFor(array $in, int $branchId): ?array
    {
        $pid = input_int($in, 'parent_job_id', 1);
        if ($pid === null) {
            return null;
        }
        $stmt = db()->prepare('SELECT * FROM job_orders WHERE id = ? FOR UPDATE');
        $stmt->execute([$pid]);
        $p = $stmt->fetch();
        if (!$p || !self::isVisible($p) || (int) $p['branch_id'] !== $branchId || !in_array($p['status'], ['released', 'closed'], true)) {
            throw new HttpException(422, 'A back-job can only be opened for a released job of this branch.');
        }
        return ['id' => (int) $p['id'], 'job_no' => (string) $p['job_no']];
    }

    /** Edit the intake details of an open job (job_orders.create or job_orders.assign). */
    public static function update(int $id, array $in, int $userId): string
    {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $j = self::lock($id);
            self::assertWorkingIn($j);
            if (!self::actions($j)['edit']) {
                throw in_array($j['status'], self::OPEN, true)
                    ? new HttpException(403, 'You do not have permission to edit this job order.')
                    : new HttpException(409, "{$j['job_no']} is " . strtolower(self::STATUSES[$j['status']]) . ', so it can no longer be edited.');
            }
            [$d, $errors] = self::validate($in, $j);
            if ($errors) {
                throw new HttpException(422, reset($errors), ['errors' => $errors]);
            }
            $sold = ['serial_id' => $j['serial_id'], 'warranty_until' => $j['warranty_until']];
            if ($d['serial_no'] !== $j['serial_no']) {
                $found = $d['serial_no'] !== null ? self::soldSerial($d['serial_no']) : null;
                $sold  = ['serial_id' => $found['serial_id'] ?? null, 'warranty_until' => $found['warranty_until'] ?? null];
            }
            [$old, $new] = Audit::diff(array_intersect_key($j, array_flip(self::INTAKE)), array_intersect_key($d, array_flip(self::INTAKE)));
            if ($new) {
                $set = implode(', ', array_map(static fn (string $k): string => "{$k} = ?", [...self::INTAKE, 'serial_id', 'warranty_until']));
                $vals = array_map(static fn (string $k) => $d[$k], self::INTAKE);
                $pdo->prepare("UPDATE job_orders SET {$set} WHERE id = ?")->execute([...$vals, $sold['serial_id'], $sold['warranty_until'], $id]);
                self::event($id, $userId, 'edit', null, null, 'Intake details updated: ' . implode(', ', array_map(
                    static fn (string $k): string => str_replace('_', ' ', $k), array_keys($new))) . '.');
                Audit::record('job_orders', 'update', 'job_order', $id, $j['job_no'], $old, $new, (int) $j['branch_id']);
            }
            $pdo->commit();
            return (string) $j['job_no'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // ------------------------------------------------------------------
    // Workflow
    // ------------------------------------------------------------------

    /**
     * Run one workflow action (see FROM) with its form input. @return string success message
     * Inputs: assign technician_id; diagnose diagnosis, estimate, ask_customer; decision decision (approve|decline),
     * method, by_name, note; wait_parts / test_failed / note note (required); resume / to_testing note (optional);
     * complete resolution; cancel reason.
     */
    public static function act(int $id, string $action, array $in, int $userId): string
    {
        if (!isset(self::FROM[$action])) {
            throw new HttpException(400, 'Unknown action.');
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $j = self::lock($id);
            self::assertWorkingIn($j);
            $no = (string) $j['job_no'];
            if ($action === 'take' && $j['technician_id'] !== null) {
                throw new HttpException(409, "{$no} was already taken by " . self::userName((int) $j['technician_id']) . '.');
            }
            if (!in_array($j['status'], self::FROM[$action], true)) {
                throw new HttpException(409, "{$no} is " . strtolower(self::STATUSES[$j['status']]) . ', so this step is no longer possible.');
            }
            if (!self::actions($j)[$action]) {
                throw new HttpException(403, 'Only the assigned technician or a supervisor can do this.');
            }
            $from = (string) $j['status'];
            $note = self::cleanMemo(input_string($in, 'note', 2001));
            $needNote = static function (?string $n, string $msg): string {
                if ($n === null || mb_strlen($n) < 3 || mb_strlen($n) > 2000) {
                    throw new HttpException(422, $msg, ['errors' => ['note' => $msg]]);
                }
                return $n;
            };
            if ($note !== null && mb_strlen($note) > 2000) {
                throw new HttpException(422, 'Keep the note under 2,000 characters.', ['errors' => ['note' => 'Keep it under 2,000 characters.']]);
            }
            $set = [];
            $to  = $from;
            $msg = '';
            $audit = [];

            switch ($action) {
                case 'take':
                    $set = ['technician_id' => $userId, 'assigned_at' => date('Y-m-d H:i:s')];
                    $to  = 'assigned';
                    $note = 'Taken by ' . self::userName($userId) . '.';
                    $msg = "You took {$no}. Start the diagnosis when you begin.";
                    break;
                case 'assign':
                    $tech = input_int($in, 'technician_id', 1);
                    $ids  = array_map('intval', array_column(self::technicians((int) $j['branch_id']), 'id'));
                    if ($tech === null || !in_array($tech, $ids, true)) {
                        throw new HttpException(422, 'Choose a technician of this branch.', ['errors' => ['technician_id' => 'Choose a technician.']]);
                    }
                    if ($tech === (int) $j['technician_id']) {
                        throw new HttpException(422, "{$no} is already assigned to " . self::userName($tech) . '.', ['errors' => ['technician_id' => 'Already assigned.']]);
                    }
                    $set  = ['technician_id' => $tech, 'assigned_at' => date('Y-m-d H:i:s')];
                    $to   = $from === 'new' ? 'assigned' : $from;
                    $name = self::userName($tech);
                    $note = ($j['technician_id'] === null ? "Assigned to {$name}." : 'Reassigned from ' . self::userName((int) $j['technician_id']) . " to {$name}.")
                        . ($note !== null ? ' ' . $note : '');
                    $audit = ['technician' => $name];
                    $msg = "{$no} is assigned to {$name}.";
                    break;
                case 'start':
                    $to  = 'diagnosing';
                    $msg = "Diagnosis of {$no} started.";
                    break;
                case 'diagnose':
                    $diag = self::cleanMemo(input_string($in, 'diagnosis', 2001));
                    if ($diag === null || mb_strlen($diag) < 3 || mb_strlen($diag) > 2000) {
                        throw new HttpException(422, 'Write the diagnosis (3 to 2,000 characters).', ['errors' => ['diagnosis' => 'Write the diagnosis (3 to 2,000 characters).']]);
                    }
                    $raw = $in['estimate'] ?? '';
                    $est = is_string($raw) && trim($raw) === '' ? 0.0 : input_decimal(['v' => is_string($raw) ? str_replace(',', '', $raw) : ''], 'v', 0, 9999999.99, 2);
                    if ($est === null) {
                        throw new HttpException(422, 'Enter the estimate in pesos (0 when there is no charge).', ['errors' => ['estimate' => 'Enter an amount, e.g. 1,500.00.']]);
                    }
                    $estimate  = number_format($est, 2, '.', '');
                    $threshold = self::quoteThreshold();
                    $ask       = !empty($in['ask_customer']) || to_cents($estimate) > to_cents($threshold);
                    $set = ['diagnosis' => $diag, 'estimate' => $estimate, 'diagnosed_at' => date('Y-m-d H:i:s'),
                            'approval' => $ask ? 'pending' : 'not_needed'];
                    $to  = $ask ? 'for_approval' : 'in_repair';
                    $note = 'Diagnosis: ' . $diag . ' Estimate: ' . money($estimate) . ($ask ? ' (needs the customer\'s approval).' : '.');
                    $audit = ['estimate' => $estimate, 'approval' => $set['approval']];
                    $msg = $ask ? "{$no} waits for the customer's approval of the " . money($estimate) . ' estimate.'
                                : "{$no} is in repair (no quotation approval needed).";
                    break;
                case 'decision':
                    $decision = input_string($in, 'decision', 10);
                    $method   = input_string($in, 'method', 20);
                    $by       = self::cleanText(input_string($in, 'by_name', 101));
                    $errs = [];
                    if (!in_array($decision, ['approve', 'decline'], true)) {
                        $errs['decision'] = 'Choose approved or declined.';
                    }
                    if (!isset(self::METHODS[$method])) {
                        $errs['method'] = 'Choose how the customer answered.';
                    }
                    if ($by === null || mb_strlen($by) < 2 || mb_strlen($by) > 100) {
                        $errs['by_name'] = 'Enter who answered for the customer (2 to 100 characters).';
                    }
                    if ($note !== null && mb_strlen($note) > 255) {
                        $errs['note'] = 'Keep the note under 255 characters.';
                    }
                    if ($errs) {
                        throw new HttpException(422, reset($errs), ['errors' => $errs]);
                    }
                    $ok  = $decision === 'approve';
                    $set = ['approval' => $ok ? 'approved' : 'declined', 'approval_method' => $method, 'approval_by_name' => $by,
                            'approval_note' => $note, 'approval_recorded_by' => $userId, 'approval_at' => date('Y-m-d H:i:s')];
                    if (!$ok) {
                        $set += ['resolution' => 'Customer declined the quotation; not repaired.', 'completed_by' => $userId, 'completed_at' => date('Y-m-d H:i:s')];
                    }
                    $to   = $ok ? 'in_repair' : 'completed';
                    $note = ($ok ? 'Quotation approved' : 'Quotation declined') . " by {$by} (" . strtolower(self::METHODS[$method]) . ').' . ($note !== null ? ' ' . $note : '');
                    $audit = ['approval' => $set['approval'], 'method' => $method, 'by' => $by];
                    $msg = $ok ? "{$no}: quotation approved. The repair can start." : "{$no}: quotation declined. The job is completed without repair, ready to return the device.";
                    break;
                case 'wait_parts':
                    $note = $needNote($note, 'Write which parts are needed (3 to 2,000 characters).');
                    $to   = 'waiting_parts';
                    $msg  = "{$no} is waiting for parts.";
                    break;
                case 'resume':
                    $to  = 'in_repair';
                    $msg = "{$no} is back in repair.";
                    break;
                case 'to_testing':
                    $to  = 'for_testing';
                    $msg = "{$no} is ready for testing.";
                    break;
                case 'test_failed':
                    $note = $needNote($note, 'Write what failed in the test (3 to 2,000 characters).');
                    $to   = 'in_repair';
                    $msg  = "{$no} went back to repair.";
                    break;
                case 'complete':
                    if (JobParts::openCounts($id)['pending'] > 0) {
                        throw new HttpException(409, 'Cancel or wait for the open parts requests before completing the job.');
                    }
                    $labor = self::laborInput($in);
                    $res = self::cleanMemo(input_string($in, 'resolution', 2001));
                    if ($res === null || mb_strlen($res) < 3 || mb_strlen($res) > 2000) {
                        throw new HttpException(422, 'Write the work done (3 to 2,000 characters).', ['errors' => ['resolution' => 'Write the work done (3 to 2,000 characters).']]);
                    }
                    $set  = ['resolution' => $res, 'labor' => $labor, 'completed_by' => $userId, 'completed_at' => date('Y-m-d H:i:s')];
                    $to   = 'completed';
                    $note = 'Work done: ' . $res . ' Labour: ' . money($labor) . '.';
                    $audit = ['labor' => $labor];
                    $msg  = "{$no} is completed and ready for release.";
                    break;
                case 'cancel':
                    $reason = self::cleanText(input_string($in, 'reason', 256));
                    if ($reason === null || mb_strlen($reason) < 3 || mb_strlen($reason) > 255) {
                        throw new HttpException(422, 'Enter a reason (3 to 255 characters).', ['errors' => ['reason' => 'Enter a reason (3 to 255 characters).']]);
                    }
                    $set  = ['cancelled_by' => $userId, 'cancelled_at' => date('Y-m-d H:i:s'), 'cancel_reason' => $reason];
                    $to   = 'cancelled';
                    $note = 'Cancelled: ' . $reason;
                    $audit = ['reason' => $reason];
                    $msg  = "{$no} was cancelled.";
                    break;
                case 'set_labor':
                    $labor = self::laborInput($in);
                    if ($j['labor'] !== null && to_cents($j['labor']) === to_cents($labor)) {
                        throw new HttpException(422, 'The labour charge is already ' . money($labor) . '.', ['errors' => ['labor' => 'Unchanged.']]);
                    }
                    $set  = ['labor' => $labor];
                    $note = 'Labour charge changed from ' . money($j['labor'] ?? 0) . ' to ' . money($labor) . '.' . ($note !== null ? ' ' . $note : '');
                    $audit = ['labor' => $labor];
                    $msg  = 'The labour charge is now ' . money($labor) . '.';
                    break;
                case 'note':
                    $note = $needNote($note, 'Write the note (3 to 2,000 characters).');
                    $msg  = 'Note added.';
                    break;
            }

            if ($to !== $from) {
                $set['status'] = $to;
            }
            if ($set) {
                $cols = implode(', ', array_map(static fn (string $k): string => "{$k} = ?", array_keys($set)));
                $pdo->prepare("UPDATE job_orders SET {$cols} WHERE id = ?")->execute([...array_values($set), $id]);
            }
            self::event($id, $userId, $action, $to !== $from ? $from : null, $to !== $from ? $to : null, $note);
            if ($action !== 'note') {
                Audit::record('job_orders', $action, 'job_order', $id, $no, ['status' => $from] + ($action === 'set_labor' ? ['labor' => $j['labor']] : []),
                    ['status' => $to] + $audit, (int) $j['branch_id']);
            }
            $pdo->commit();
            return $msg;
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

    /** Lock the job row (first in the lock order) and require the session to work in its branch. For JobParts / JobBilling. */
    public static function lockForAction(int $id): array
    {
        $j = self::lock($id);
        self::assertWorkingIn($j);
        return $j;
    }

    /** Assigned technician (job_orders.update) or supervisor (job_orders.assign). */
    public static function isWorker(array $j): bool
    {
        return Auth::can('job_orders.assign') || (Auth::can('job_orders.update') && (int) $j['technician_id'] === (int) Auth::id());
    }

    /** Lock the job row; 404 when missing or not visible. */
    private static function lock(int $id): array
    {
        self::requireView();
        $stmt = db()->prepare('SELECT * FROM job_orders WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $j = $stmt->fetch();
        if (!$j || !self::isVisible($j)) {
            throw new HttpException(404, 'Job order not found.');
        }
        return $j;
    }

    /** The session must work in the job's branch: 422 under "All branches". */
    private static function assertWorkingIn(array $j): void
    {
        if (Branch::current() === (int) $j['branch_id']) {
            return;
        }
        $stmt = db()->prepare('SELECT name FROM branches WHERE id = ?');
        $stmt->execute([(int) $j['branch_id']]);
        throw new HttpException(422, 'Switch to branch ' . $stmt->fetchColumn() . ' first.');
    }

    public static function event(int $jobId, int $userId, string $action, ?string $from, ?string $to, ?string $note): void
    {
        db()->prepare('INSERT INTO job_order_events (job_order_id, user_id, action, from_status, to_status, note) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$jobId, $userId, $action, $from, $to, $note === null ? null : mb_substr($note, 0, 2000)]);
    }

    public static function userName(int $id): string
    {
        $stmt = db()->prepare('SELECT full_name FROM users WHERE id = ?');
        $stmt->execute([$id]);
        return (string) $stmt->fetchColumn();
    }

    /**
     * Our sale of a serial number (latest completed sale): serial_id, product, sale_id / sale_no / sold_at,
     * branch_id, warranty_days, warranty_until (null without warranty). By serial text or by serial id.
     */
    public static function soldSerial(?string $serialNo, ?int $serialId = null): ?array
    {
        $stmt = db()->prepare(
            "SELECT ps.id AS serial_id, ps.serial_no, p.name AS product_name, p.code AS product_code, p.warranty_days,
                    s.id AS sale_id, s.sale_no, s.branch_id, COALESCE(s.completed_at, s.created_at) AS sold_at
               FROM product_serials ps
               JOIN products p ON p.id = ps.product_id
               JOIN sale_item_serials sis ON sis.serial_id = ps.id
               JOIN sale_items si ON si.id = sis.sale_item_id
               JOIN sales s ON s.id = si.sale_id AND s.status = 'completed'
              WHERE " . ($serialId !== null ? 'ps.id = ?' : "ps.serial_no = ? AND ps.status = 'sold'") . '
              ORDER BY s.id DESC LIMIT 1'
        );
        $stmt->execute([$serialId ?? $serialNo]);
        $r = $stmt->fetch();
        if (!$r) {
            return null;
        }
        $days = (int) $r['warranty_days'];
        $r['warranty_until'] = $days > 0 ? date('Y-m-d', strtotime(substr((string) $r['sold_at'], 0, 10) . " +{$days} days")) : null;
        return $r;
    }

    /** JO-<branch>-<year>-NNNNNN from document_sequences (locked row; a rollback releases it). */
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

    /** One line: control characters -> spaces, trimmed; null when empty. */
    private static function cleanText(string $text): ?string
    {
        $text = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text) ?? '');
        return $text === '' ? null : $text;
    }

    /** Multi-line text: keeps line breaks, drops other control characters; null when empty. */
    private static function cleanMemo(string $text): ?string
    {
        $text = str_replace("\r\n", "\n", $text);
        $text = trim(preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', ' ', $text) ?? '');
        return $text === '' ? null : $text;
    }
}
