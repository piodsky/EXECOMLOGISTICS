<?php
/**
 * Audit trail (audit_logs): who did what, where, with before/after values.
 *
 * Audit::record() runs on the caller's connection, so inside a transaction it commits or
 * rolls back together with the change it describes. Secrets (passwords, hashes, tokens)
 * are always stripped from the values.
 */
declare(strict_types=1);

final class Audit
{
    /** Company-wide master data: logged without a branch (only access_all users see these rows). */
    private const GLOBAL_MODULES = ['roles', 'branches', 'settings', 'products', 'master_data', 'suppliers', 'auth'];

    /** Keys never written to the log. */
    private const SECRET_KEYS = ['password', 'password_hash', 'password_confirm', 'current_password', '_csrf', 'csrf', 'token'];

    /** Module => label for the filter. */
    public const MODULES = [
        'users'       => 'Users',
        'roles'       => 'Roles',
        'branches'    => 'Branches',
        'settings'    => 'Settings',
        'sales'       => 'Sales',
        'inventory'   => 'Inventory',
        'products'    => 'Products',
        'customers'   => 'Customers',
        'master_data' => 'Master Data',
        'suppliers'   => 'Suppliers',
        'receiving'   => 'Receiving',
        'warehouses'  => 'Warehouses',
        'transfers'   => 'Transfers',
        'job_orders'  => 'Job Orders',
        'auth'        => 'Sign-in & Security',
    ];

    /**
     * @param int|null $branchId the branch the change belongs to; null = the current branch
     *                           (no branch for company-wide modules or "All branches")
     */
    public static function record(
        string $module,
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        ?string $ref = null,
        ?array $old = null,
        ?array $new = null,
        ?int $branchId = null,
    ): void {
        $user = Auth::user();
        if ($branchId === null && !in_array($module, self::GLOBAL_MODULES, true) && Branch::isConcrete()) {
            $branchId = Branch::current();
        }

        db()->prepare(
            'INSERT INTO audit_logs (occurred_at, user_id, username, role, branch_id, module, action, entity_type,
                                     entity_id, entity_ref, old_values, new_values, ip_address, user_agent)
             VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $user['id'] ?? null,
            isset($user['username']) ? mb_substr($user['username'], 0, 50) : null,
            isset($user['role']) ? mb_substr($user['role'], 0, 30) : null,
            $branchId,
            mb_substr($module, 0, 30),
            mb_substr($action, 0, 50),
            $entityType !== null ? mb_substr($entityType, 0, 40) : null,
            $entityId,
            $ref !== null ? mb_substr($ref, 0, 60) : null,
            self::json($old),
            self::json($new),
            client_ip(),
            mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null,
        ]);
    }

    /** Only the keys whose value changed: [old subset, new subset]. */
    public static function diff(array $old, array $new): array
    {
        // DB rows hold strings ("3", "25000.00"), form data ints/floats: compare loosely by text.
        $norm = static fn (mixed $v): string => is_array($v) ? (string) json_encode($v) : ($v === null ? "\0null" : (string) $v);
        $o = [];
        $n = [];
        foreach ($new as $key => $value) {
            if (!array_key_exists($key, $old) || $norm($old[$key]) !== $norm($value)) {
                $o[$key] = $old[$key] ?? null;
                $n[$key] = $value;
            }
        }
        return [$o, $n];
    }

    private static function json(?array $values): ?string
    {
        if ($values === null) {
            return null;
        }
        $values = self::clean($values);
        return json_encode($values, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
    }

    private static function clean(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SECRET_KEYS, true)) {
                unset($values[$key]);
            } elseif (is_array($value)) {
                $values[$key] = self::clean($value);
            }
        }
        return $values;
    }

    // ------------------------------------------------------------------
    // Viewer (pages/audit-log.php)
    // ------------------------------------------------------------------

    /** @param array{q:string, module:string, user:?int, from:?string, to:?string} $f */
    public static function count(array $f): int
    {
        [$where, $params] = self::where($f);
        $stmt = db()->prepare("SELECT COUNT(*) FROM audit_logs a WHERE {$where}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public static function search(array $f, int $limit, int $offset): array
    {
        [$where, $params] = self::where($f);
        $stmt = db()->prepare(
            "SELECT a.*, b.code AS branch_code, b.name AS branch_name
               FROM audit_logs a
               LEFT JOIN branches b ON b.id = a.branch_id
              WHERE {$where}
              ORDER BY a.occurred_at DESC, a.id DESC
              LIMIT ? OFFSET ?"
        );
        $stmt->execute([...$params, $limit, $offset]);
        return $stmt->fetchAll();
    }

    /** Users that appear in the visible log rows (for the filter). */
    public static function users(): array
    {
        [$scope, $params] = self::scope();
        $stmt = db()->prepare(
            "SELECT DISTINCT a.user_id AS id, a.username FROM audit_logs a
              WHERE a.user_id IS NOT NULL AND {$scope}
              ORDER BY a.username"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Rows in the current branch scope; rows without a branch only for access_all users. */
    private static function scope(): array
    {
        $cur = Branch::current();
        if ($cur === Branch::ALL) {
            return ['1 = 1', []];
        }
        if ($cur === null) {
            return ['0 = 1', []];
        }
        return Branch::canSeeAll()
            ? ['(a.branch_id = ? OR a.branch_id IS NULL)', [$cur]]
            : ['a.branch_id = ?', [$cur]];
    }

    private static function where(array $f): array
    {
        [$scope, $params] = self::scope();
        $where = [$scope];
        if ($f['q'] !== '') {
            $where[] = '(a.entity_ref LIKE ? OR a.username LIKE ? OR a.action LIKE ?)';
            $like = like_pattern($f['q']);
            array_push($params, $like, $like, $like);
        }
        if (isset(self::MODULES[$f['module']])) {
            $where[]  = 'a.module = ?';
            $params[] = $f['module'];
        }
        if ($f['user'] !== null) {
            $where[]  = 'a.user_id = ?';
            $params[] = $f['user'];
        }
        if ($f['from'] !== null) {
            $where[]  = 'a.occurred_at >= ?';
            $params[] = $f['from'] . ' 00:00:00';
        }
        if ($f['to'] !== null) {
            $where[]  = 'a.occurred_at < ?';
            $params[] = (new DateTimeImmutable($f['to']))->modify('+1 day')->format('Y-m-d 00:00:00');
        }
        return [implode(' AND ', $where), $params];
    }
}
