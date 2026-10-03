<?php
/**
 * Roles & permissions (roles, permissions, role_permissions; registry in config/permissions.php).
 *
 * Rules (enforced here, not just in the UI):
 *   - a super role (is_super = 1) holds every permission and is locked: no edit, no delete;
 *   - system roles (is_system = 1): only a super admin may change their permissions; never deleted;
 *   - custom roles: code ^[a-z][a-z0-9_]{2,29}$, deletable while no user has them;
 *   - nobody edits the role they hold; you can only grant permissions you hold yourself;
 *   - roles an actor may ASSIGN to users: everything for a super admin, otherwise active, non-super
 *     roles whose permission set is a strict subset of the actor's own.
 */
declare(strict_types=1);

final class Roles
{
    /** @var array<string,list<string>>|null role code => permission keys (memo) */
    private static ?array $keyMap = null;

    /** Registry: key => [module, label]. */
    public static function registry(): array
    {
        return config('permissions', []);
    }

    /** module => [key => label], in registry order. */
    public static function grouped(): array
    {
        $out = [];
        foreach (self::registry() as $key => [$module, $label]) {
            $out[$module][$key] = $label;
        }
        return $out;
    }

    /** Every role with its number of users and permissions. */
    public static function all(): array
    {
        $stmt = db()->prepare(
            'SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role = r.code) AS users,
                    (SELECT COUNT(*) FROM users u WHERE u.role = r.code AND u.is_active = 1) AS active_users,
                    (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id) AS perms
               FROM roles r
              ORDER BY r.is_super DESC, r.is_system DESC, r.name'
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** code => name of every role (labels for lists). */
    public static function names(): array
    {
        $stmt = db()->prepare('SELECT code, name FROM roles ORDER BY name');
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public static function find(int $id): ?array
    {
        $stmt = db()->prepare(
            'SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role = r.code) AS users FROM roles r WHERE r.id = ?'
        );
        $stmt->execute([$id]);
        $role = $stmt->fetch();
        if (!$role) {
            return null;
        }
        $role['permissions'] = self::keysOf($role);
        return $role;
    }

    public static function findByCode(string $code): ?array
    {
        $stmt = db()->prepare('SELECT * FROM roles WHERE code = ?');
        $stmt->execute([$code]);
        return $stmt->fetch() ?: null;
    }

    /** Permission keys of a role row (a super role holds the whole registry). */
    public static function keysOf(array $role): array
    {
        if ((int) $role['is_super'] === 1) {
            return array_keys(self::registry());
        }
        return self::keyMap()[$role['code']] ?? [];
    }

    private static function keyMap(): array
    {
        if (self::$keyMap === null) {
            $stmt = db()->prepare(
                'SELECT r.code, p.perm_key FROM role_permissions rp
                   JOIN roles r ON r.id = rp.role_id
                   JOIN permissions p ON p.id = rp.permission_id
                  ORDER BY p.sort_order'
            );
            $stmt->execute();
            self::$keyMap = [];
            foreach ($stmt->fetchAll() as $row) {
                if (array_key_exists($row['perm_key'], self::registry())) {
                    self::$keyMap[$row['code']][] = $row['perm_key'];
                }
            }
        }
        return self::$keyMap;
    }

    // ------------------------------------------------------------------
    // What the signed-in user may assign / edit
    // ------------------------------------------------------------------

    /** Active roles the signed-in user may give to users: code => row. */
    public static function assignable(): array
    {
        $stmt = db()->prepare('SELECT * FROM roles WHERE is_active = ? ORDER BY is_super DESC, is_system DESC, name');
        $stmt->execute([1]);
        $out = [];
        foreach ($stmt->fetchAll() as $role) {
            if (self::assignableRole($role)) {
                $out[$role['code']] = $role;
            }
        }
        return $out;
    }

    public static function canAssign(string $code): bool
    {
        $role = self::findByCode($code);
        return $role !== null && (int) $role['is_active'] === 1 && self::assignableRole($role);
    }

    /** Ignores is_active (used to protect existing users that hold the role). */
    public static function assignableRole(array $role): bool
    {
        if (Auth::isSuper()) {
            return true;
        }
        if ((int) $role['is_super'] === 1) {
            return false;
        }
        $mine = Auth::permissions();
        $keys = self::keysOf($role);
        return !array_diff($keys, $mine) && count($keys) < count($mine);
    }

    /** Why the signed-in user can't edit this role, or null when they can. */
    public static function editBlocker(array $role): ?string
    {
        if (!Auth::can('roles.manage')) {
            return 'You do not have permission to manage roles.';
        }
        if ((int) $role['is_super'] === 1) {
            return 'The ' . $role['name'] . ' role is locked: it always has every permission.';
        }
        if ($role['code'] === (Auth::user()['role'] ?? null)) {
            return "You can't edit the role you hold.";
        }
        if ((int) $role['is_system'] === 1 && !Auth::isSuper()) {
            return 'Only a super administrator can change a system role.';
        }
        if (!Auth::isSuper() && array_diff(self::keysOf($role), Auth::permissions())) {
            return "This role has permissions you don't hold, so you can't edit it.";
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Validation & saving
    // ------------------------------------------------------------------

    /**
     * @param array|null $role the role being edited (null = new custom role)
     * @return array{0: array, 1: array<string,string>}
     */
    public static function validate(array $in, ?array $role): array
    {
        $errors = [];
        $custom = $role === null || (int) $role['is_system'] !== 1;
        $data = [
            'code'        => $role['code'] ?? strtolower(input_string($in, 'code', 30)),
            'name'        => $custom ? input_string($in, 'name', 60) : $role['name'],
            'description' => $custom ? input_string($in, 'description', 255) : (string) ($role['description'] ?? ''),
            'is_active'   => $custom ? (isset($in['is_active']) ? 1 : 0) : (int) $role['is_active'],
        ];
        // POS limits (percent below the suggested price / sale discount without approval).
        foreach (['max_price_drop', 'max_discount'] as $key) {
            $raw = $in[$key] ?? '0';
            $val = input_decimal([$key => is_string($raw) && trim($raw) === '' ? '0' : $raw], $key, 0, 100);
            if ($val === null) {
                $errors[$key] = 'Enter a percentage from 0 to 100.';
            }
            $data[$key] = number_format((float) $val, 2, '.', '');
        }

        if ($role === null) {
            if (!preg_match('/^[a-z][a-z0-9_]{2,29}$/', $data['code'])) {
                $errors['code'] = 'Use 3–30 lowercase letters, numbers or underscores, starting with a letter (e.g. stock_clerk).';
            } elseif (self::findByCode($data['code']) !== null) {
                $errors['code'] = 'Another role already uses this code.';
            }
        }
        if ($custom && mb_strlen($data['name']) < 2) {
            $errors['name'] = 'Enter the role name (at least 2 characters).';
        }

        $raw  = $in['permissions'] ?? [];
        $keys = [];
        if (!is_array($raw)) {
            $errors['permissions'] = 'Choose the permissions for this role.';
        } else {
            foreach ($raw as $key) {
                if (!is_string($key) || !array_key_exists($key, self::registry())) {
                    $errors['permissions'] = 'Unknown permission: ' . (is_string($key) ? mb_substr($key, 0, 50) : '?') . '.';
                    break;
                }
                if (!Auth::can($key)) {
                    $errors['permissions'] = "You can only grant permissions you hold yourself ({$key}).";
                    break;
                }
                $keys[$key] = true;
            }
        }
        // Registry order
        $data['permissions'] = array_values(array_filter(array_keys(self::registry()), static fn ($k) => isset($keys[$k])));
        $data['description'] = $data['description'] !== '' ? $data['description'] : null;

        return [$data, $errors];
    }

    public static function create(array $data): int
    {
        if (!Auth::can('roles.manage')) {
            throw new HttpException(403, 'You do not have permission to manage roles.');
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO roles (code, name, description, is_system, is_super, is_active, max_price_drop, max_discount)
                            VALUES (?, ?, ?, 0, 0, ?, ?, ?)')
                ->execute([$data['code'], $data['name'], $data['description'], $data['is_active'], $data['max_price_drop'], $data['max_discount']]);
            $id = (int) $pdo->lastInsertId();
            self::syncPermissions($id, $data['permissions']);
            Audit::record('roles', 'create', 'role', $id, $data['code'], null, [
                'name' => $data['name'], 'is_active' => $data['is_active'], 'permissions' => $data['permissions'],
                'max_price_drop' => $data['max_price_drop'], 'max_discount' => $data['max_discount'],
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        self::$keyMap = null;
        return $id;
    }

    public static function update(array $role, array $data): void
    {
        $blocker = self::editBlocker($role);
        if ($blocker !== null) {
            throw new HttpException(403, $blocker);
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE roles SET name = ?, description = ?, is_active = ?, max_price_drop = ?, max_discount = ? WHERE id = ?')
                ->execute([$data['name'], $data['description'], $data['is_active'], $data['max_price_drop'], $data['max_discount'], $role['id']]);
            self::syncPermissions((int) $role['id'], $data['permissions']);

            $before = ['name' => $role['name'], 'description' => $role['description'], 'is_active' => (int) $role['is_active'],
                       'max_price_drop' => (string) ($role['max_price_drop'] ?? '0.00'), 'max_discount' => (string) ($role['max_discount'] ?? '0.00')];
            $after  = ['name' => $data['name'], 'description' => $data['description'], 'is_active' => $data['is_active'],
                       'max_price_drop' => $data['max_price_drop'], 'max_discount' => $data['max_discount']];
            [$old, $new] = Audit::diff($before, $after);
            $was = self::keysOf($role);
            $added   = array_values(array_diff($data['permissions'], $was));
            $removed = array_values(array_diff($was, $data['permissions']));
            if ($added || $removed) {
                $old['permissions_removed'] = $removed;
                $new['permissions_added']   = $added;
            }
            if ($new || $old) {
                Audit::record('roles', 'update', 'role', (int) $role['id'], $role['code'], $old, $new);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        self::$keyMap = null;
    }

    private static function syncPermissions(int $roleId, array $keys): void
    {
        db()->prepare('DELETE FROM role_permissions WHERE role_id = ?')->execute([$roleId]);
        if ($keys) {
            $in = implode(',', array_fill(0, count($keys), '?'));
            db()->prepare(
                "INSERT INTO role_permissions (role_id, permission_id)
                 SELECT ?, p.id FROM permissions p WHERE p.perm_key IN ({$in}) ORDER BY p.id"
            )->execute([$roleId, ...$keys]);
        }
    }

    /** Delete a custom role nobody has. @return array the deleted role */
    public static function delete(int $id): array
    {
        $role = self::find($id) ?? throw new HttpException(404, 'Role not found.');
        $blocker = self::editBlocker($role);
        if ($blocker !== null) {
            throw new HttpException(403, $blocker);
        }
        if ((int) $role['is_system'] === 1) {
            throw new HttpException(409, "{$role['name']} is a system role and can't be deleted.");
        }
        if ((int) $role['users'] > 0) {
            throw new HttpException(409, "{$role['name']} is assigned to {$role['users']} " . ((int) $role['users'] === 1 ? 'user' : 'users')
                . '. Give them another role first.');
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM roles WHERE id = ?')->execute([$id]); // grants cascade
            Audit::record('roles', 'delete', 'role', $id, $role['code'], [
                'name' => $role['name'], 'permissions' => $role['permissions'],
            ], null);
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ((int) ($e->errorInfo[1] ?? 0) === 1451) { // a user got this role meanwhile
                throw new HttpException(409, "{$role['name']} is assigned to users. Give them another role first.");
            }
            throw $e;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        self::$keyMap = null;
        return $role;
    }
}
