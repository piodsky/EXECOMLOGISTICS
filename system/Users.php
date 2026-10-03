<?php
/**
 * User accounts: listing, validation, create/update, activate/deactivate, delete, passwords.
 * Safety rules (enforced here, not just hidden in the UI):
 *   - you can't deactivate, delete or change the role of your own account;
 *   - there is always at least one active super admin (checked under a row lock);
 *   - you only manage users in your branch scope whose role you may assign (Roles::assignableRole);
 *   - users who rang up sales can only be deactivated (sales keep their cashier);
 *   - extra branches (user_branches) are granted only by users with branches.access_all.
 */
declare(strict_types=1);

final class Users
{
    public const MIN_PASSWORD = 8;
    /** bcrypt only uses the first 72 bytes */
    public const MAX_PASSWORD = 72;
    /** Passwords that are refused outright (the shipped defaults and the usual suspects). */
    private const WEAK = ['admin123', 'cashier123', 'password', 'password1', 'password123', '12345678',
        '123456789', '1234567890', 'qwerty123', 'qwertyui', '11111111', '00000000', 'execom123', 'abcd1234'];
    /** The sample logins from database.sql — used to warn at sign-in. */
    public const DEFAULT_PASSWORDS = ['admin123', 'cashier123'];

    private const SELECT = 'SELECT u.id, u.username, u.full_name, u.role, u.branch_id, u.is_active, u.last_login_at, u.created_at,
                    r.name AS role_name, r.is_super, r.is_system, b.code AS branch_code, b.name AS branch_name,
                    (SELECT COUNT(*) FROM sales s WHERE s.user_id = u.id) AS sales
               FROM users u
               JOIN roles r ON r.code = u.role
               JOIN branches b ON b.id = u.branch_id';

    /** Users in scope: everyone for branches.access_all, otherwise home branch in the current branch. */
    private static function scope(): array
    {
        return Branch::canSeeAll() ? ['1 = 1', []] : Branch::scopeSql('u.branch_id');
    }

    public static function all(): array
    {
        [$scope, $params] = self::scope();
        $stmt = db()->prepare(self::SELECT . " WHERE {$scope} ORDER BY u.is_active DESC, r.is_super DESC, r.name, u.full_name");
        $stmt->execute($params);
        $users = $stmt->fetchAll();
        $extra = self::extraBranchMap(array_map(static fn ($u) => (int) $u['id'], $users));
        foreach ($users as &$u) {
            $u['extra_branches'] = $extra[(int) $u['id']] ?? [];
        }
        return $users;
    }

    /** User in scope (with 'extra_branches' => [branch_id => code]) or null. */
    public static function find(int $id): ?array
    {
        [$scope, $params] = self::scope();
        $stmt = db()->prepare(self::SELECT . " WHERE u.id = ? AND {$scope}");
        $stmt->execute([$id, ...$params]);
        $user = $stmt->fetch();
        if (!$user) {
            return null;
        }
        $user['extra_branches'] = self::extraBranchMap([$id])[$id] ?? [];
        return $user;
    }

    /** @return array<int, array<int,string>> user id => [branch id => code] */
    private static function extraBranchMap(array $userIds): array
    {
        if (!$userIds) {
            return [];
        }
        $in = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = db()->prepare(
            "SELECT ub.user_id, b.id, b.code FROM user_branches ub JOIN branches b ON b.id = ub.branch_id
              WHERE ub.user_id IN ({$in}) ORDER BY b.code"
        );
        $stmt->execute($userIds);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['user_id']][(int) $row['id']] = $row['code'];
        }
        return $out;
    }

    /** Why the signed-in user may not manage this user, or null. You may always edit yourself. */
    public static function manageBlocker(array $target, int $selfId): ?string
    {
        if ((int) $target['id'] === $selfId) {
            return null;
        }
        // Managing a user (e.g. resetting their password) gives access to every branch they work at.
        $extra = $target['extra_branches'] ?? (self::extraBranchMap([(int) $target['id']])[(int) $target['id']] ?? []);
        if (!Branch::canSeeAll() && array_diff(array_keys($extra), Branch::allowedIds())) {
            return "{$target['full_name']} also works at branches you don't manage. Ask a super administrator.";
        }
        if (!Roles::assignableRole(['code' => $target['role'], 'is_super' => $target['is_super']])) {
            return "You can't manage users with the {$target['role_name']} role.";
        }
        return null;
    }

    /** Lock every active super admin row; how many remain without $exceptId. Call inside a transaction. */
    private static function lockedActiveSupers(int $exceptId): int
    {
        $stmt = db()->prepare(
            'SELECT id FROM users
              WHERE is_active = 1 AND role IN (SELECT code FROM roles WHERE is_super = 1)
              FOR UPDATE'
        );
        $stmt->execute();
        return count(array_filter($stmt->fetchAll(PDO::FETCH_COLUMN), static fn ($id) => (int) $id !== $exceptId));
    }

    private static function isSuperRole(string $code): bool
    {
        $role = Roles::findByCode($code);
        return $role !== null && (int) $role['is_super'] === 1;
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    /** Why a password is not acceptable, or null if it is fine. */
    public static function passwordProblem(string $password, string $username = ''): ?string
    {
        if (strlen($password) < self::MIN_PASSWORD) {
            return 'Use at least ' . self::MIN_PASSWORD . ' characters.';
        }
        if (strlen($password) > self::MAX_PASSWORD) {
            return 'Use at most ' . self::MAX_PASSWORD . ' characters.';
        }
        if ($username !== '' && stripos($password, $username) !== false) {
            return "Don't include the username in the password.";
        }
        if (in_array(strtolower($password), self::WEAK, true) || count(array_unique(str_split($password))) < 4) {
            return 'This password is too easy to guess. Choose another.';
        }
        return null;
    }

    /**
     * Validate the user form. $id = null for a new user; $selfId = the user doing it.
     * The password is required for new users and optional when editing (blank = keep).
     * 'branches' (extra branches) is null when the actor can't grant them (= leave unchanged).
     * @return array{0: array, 1: array<string,string>}
     */
    public static function validate(array $input, ?int $id, int $selfId): array
    {
        $current = $id !== null ? (self::find($id) ?? throw new HttpException(404, 'User not found.')) : null;
        $isSelf  = $id !== null && $id === $selfId;

        $data = [
            'username'  => input_string($input, 'username', 50),
            'full_name' => input_string($input, 'full_name', 100),
            'role'      => is_string($input['role'] ?? null) ? mb_substr($input['role'], 0, 30) : '',
        ];
        $password = is_string($input['password'] ?? null) ? $input['password'] : '';
        $confirm  = is_string($input['password_confirm'] ?? null) ? $input['password_confirm'] : '';
        $errors   = [];

        if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $data['username'])) {
            $errors['username'] = 'Use 3–50 letters, numbers, dots, dashes or underscores (no spaces).';
        } else {
            $stmt = db()->prepare('SELECT id FROM users WHERE username = ? AND id <> ?');
            $stmt->execute([$data['username'], $id ?? 0]);
            if ($stmt->fetch()) {
                $errors['username'] = 'This username is already taken.';
            }
        }
        if (mb_strlen($data['full_name']) < 2) {
            $errors['full_name'] = 'Enter the full name (at least 2 characters).';
        }

        // Role
        if ($isSelf) {
            if ($data['role'] !== $current['role']) {
                $errors['role'] = "You can't change your own role.";
            }
            $data['role'] = $current['role'];
        } elseif ($data['role'] === '' || ($current !== null && $data['role'] === $current['role']
                ? !Roles::assignableRole(['code' => $current['role'], 'is_super' => $current['is_super']])
                : !Roles::canAssign($data['role']))) {
            $errors['role'] = 'Choose a role.';
        } elseif ($current !== null && (int) $current['is_super'] === 1 && (int) $current['is_active'] === 1
            && !self::isSuperRole($data['role']) && self::countActiveSupers((int) $current['id']) === 0) {
            $errors['role'] = 'This is the only active super admin. Make another user a super admin first.';
        }

        // Home branch: one of the actor's branches (forced when there is only one)
        $allowed = Branch::allowedIds();
        $branch  = count($allowed) === 1 ? $allowed[0] : input_int($input, 'branch_id', 1);
        $keep    = $current !== null && $branch === (int) $current['branch_id'];
        if ($branch === null || (!in_array($branch, $allowed, true) && !$keep)) {
            $errors['branch_id'] = 'Choose the home branch.';
        }
        $data['branch_id'] = $branch;

        // Extra branches: only granted by users who can access every branch
        $data['branches'] = null;
        if (Branch::canSeeAll() && !$isSelf) {
            $extra = [];
            foreach ((array) ($input['branches'] ?? []) as $b) {
                $b = filter_var($b, FILTER_VALIDATE_INT);
                if ($b !== false && $b !== $branch && in_array($b, $allowed, true)) {
                    $extra[$b] = $b;
                }
            }
            $data['branches'] = array_values($extra);
        }

        if ($password !== '' || $id === null) {
            $problem = self::passwordProblem($password, $data['username']);
            if ($problem !== null) {
                $errors['password'] = $problem;
            } elseif (!hash_equals($password, $confirm)) {
                $errors['password_confirm'] = "The passwords don't match.";
            }
        }
        $data['password'] = $password; // '' = keep the current one

        return [$data, $errors];
    }

    /** Active super admins other than $exceptId (no lock; for form messages). */
    private static function countActiveSupers(int $exceptId): int
    {
        $stmt = db()->prepare(
            'SELECT COUNT(*) FROM users WHERE is_active = 1 AND id <> ? AND role IN (SELECT code FROM roles WHERE is_super = 1)'
        );
        $stmt->execute([$exceptId]);
        return (int) $stmt->fetchColumn();
    }

    // ------------------------------------------------------------------
    // Create / update / status / delete
    // ------------------------------------------------------------------

    private static function requirePermission(string $key): void
    {
        if (!Auth::can($key)) {
            throw new HttpException(403, 'You do not have permission to do that.');
        }
    }

    /**
     * Set a user's extra branches to $branchIds (active branches). Grants to inactive branches are
     * kept: the form can't show them, so leaving them out must not silently revoke them.
     */
    private static function syncBranches(int $userId, array $branchIds): void
    {
        $pdo = db();
        $activeOnly = 'branch_id IN (SELECT b.id FROM branches b WHERE b.is_active = 1)';
        if ($branchIds) {
            $in = implode(',', array_fill(0, count($branchIds), '?'));
            $pdo->prepare("DELETE FROM user_branches WHERE user_id = ? AND branch_id NOT IN ({$in}) AND {$activeOnly}")->execute([$userId, ...$branchIds]);
        } else {
            $pdo->prepare("DELETE FROM user_branches WHERE user_id = ? AND {$activeOnly}")->execute([$userId]);
        }
        $add = $pdo->prepare('INSERT IGNORE INTO user_branches (user_id, branch_id, granted_by) VALUES (?, ?, ?)');
        foreach ($branchIds as $branchId) {
            $add->execute([$userId, $branchId, Auth::id()]);
        }
    }

    /** Extra branches of a user that are inactive right now (kept by syncBranches). @return list<int> */
    private static function inactiveGrants(int $userId): array
    {
        $stmt = db()->prepare(
            'SELECT ub.branch_id FROM user_branches ub JOIN branches b ON b.id = ub.branch_id
              WHERE ub.user_id = ? AND b.is_active = 0'
        );
        $stmt->execute([$userId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Repeat validate()'s role / branch rules on save so no caller can skip them:
     * a changed role must be assignable, a changed home branch must be one of yours,
     * extra branches only from users with branches.access_all and only active branches.
     */
    private static function assertAssignable(array $data, ?array $current, int $selfId): void
    {
        $isSelf     = $current !== null && (int) $current['id'] === $selfId;
        $sameRole   = $current !== null && $data['role'] === $current['role'];
        $roleOk     = $sameRole
            ? ($isSelf || Roles::assignableRole(['code' => $current['role'], 'is_super' => $current['is_super']]))
            : (!$isSelf && Roles::canAssign($data['role']));
        $sameBranch = $current !== null && (int) $data['branch_id'] === (int) $current['branch_id'];
        $branchOk   = $sameBranch || in_array((int) $data['branch_id'], Branch::allowedIds(), true);
        $extraOk    = $data['branches'] === null
            || (Branch::canSeeAll() && !$isSelf && !array_diff($data['branches'], Branch::allowedIds()));
        if (!$roleOk || !$branchOk || !$extraOk) {
            throw new HttpException(403, 'You cannot give this user that role or branch.');
        }
    }

    public static function create(array $data): int
    {
        self::requirePermission('users.manage');
        // validate() already checked these; repeat them so no caller can skip the rules.
        if (!Roles::canAssign($data['role']) || !in_array((int) $data['branch_id'], Branch::allowedIds(), true)) {
            throw new HttpException(403, 'You cannot create a user with that role or branch.');
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role, branch_id) VALUES (?, ?, ?, ?, ?)')
                ->execute([$data['username'], password_hash($data['password'], PASSWORD_DEFAULT), $data['full_name'],
                    $data['role'], $data['branch_id']]);
            $id = (int) $pdo->lastInsertId();
            if ($data['branches']) {
                self::syncBranches($id, $data['branches']);
            }
            Audit::record('users', 'create', 'user', $id, $data['username'], null, [
                'username' => $data['username'], 'full_name' => $data['full_name'], 'role' => $data['role'],
                'branch_id' => $data['branch_id'], 'extra_branches' => $data['branches'] ?? [],
            ], $data['branch_id']);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return $id;
    }

    public static function update(int $id, array $data, int $selfId): void
    {
        self::requirePermission('users.manage');
        $current = self::find($id) ?? throw new HttpException(404, 'User not found.');
        $blocker = self::manageBlocker($current, $selfId);
        if ($blocker !== null) {
            throw new HttpException(403, $blocker);
        }
        self::assertAssignable($data, $current, $selfId);
        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ((int) $current['is_super'] === 1 && (int) $current['is_active'] === 1 && !self::isSuperRole($data['role'])
                && self::lockedActiveSupers($id) === 0) {
                throw new HttpException(409, 'This is the only active super admin. Make another user a super admin first.');
            }
            $pdo->prepare('UPDATE users SET username = ?, full_name = ?, role = ?, branch_id = ? WHERE id = ?')
                ->execute([$data['username'], $data['full_name'], $data['role'], $data['branch_id'], $id]);
            if ($data['branches'] !== null) {
                self::syncBranches($id, $data['branches']);
            }

            $before = [
                'username' => $current['username'], 'full_name' => $current['full_name'], 'role' => $current['role'],
                'branch_id' => (int) $current['branch_id'], 'extra_branches' => array_keys($current['extra_branches']),
            ];
            $after = [
                'username' => $data['username'], 'full_name' => $data['full_name'], 'role' => $data['role'],
                'branch_id' => $data['branch_id'],
                'extra_branches' => $data['branches'] !== null
                    ? array_values(array_unique([...$data['branches'], ...self::inactiveGrants($id)]))
                    : array_keys($current['extra_branches']),
            ];
            sort($before['extra_branches']);
            sort($after['extra_branches']);
            [$old, $new] = Audit::diff($before, $after);
            if ($new) {
                Audit::record('users', 'update', 'user', $id, $data['username'], $old, $new, $data['branch_id']);
            }
            if ($data['password'] !== '') {
                self::setPassword($id, $data['password']);
                Audit::record('users', 'password_reset', 'user', $id, $data['username'], null, null, $data['branch_id']);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** New password hash. Other sessions of that user end on their next request (see Auth::user()). */
    public static function setPassword(int $id, string $password): void
    {
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
    }

    /** @return array the user after the change */
    public static function toggleActive(int $id, int $selfId): array
    {
        self::requirePermission('users.manage');
        $user = self::find($id) ?? throw new HttpException(404, 'User not found.');
        if ($id === $selfId) {
            throw new HttpException(409, "You can't deactivate your own account.");
        }
        $blocker = self::manageBlocker($user, $selfId);
        if ($blocker !== null) {
            throw new HttpException(403, $blocker);
        }
        $active = (int) $user['is_active'] === 1;
        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($active && (int) $user['is_super'] === 1 && self::lockedActiveSupers($id) === 0) {
                throw new HttpException(409, "{$user['full_name']} is the only active super admin and can't be deactivated.");
            }
            $pdo->prepare('UPDATE users SET is_active = ? WHERE id = ?')->execute([$active ? 0 : 1, $id]);
            Audit::record('users', $active ? 'deactivate' : 'activate', 'user', $id, $user['username'],
                ['is_active' => $active ? 1 : 0], ['is_active' => $active ? 0 : 1], (int) $user['branch_id']);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        $user['is_active'] = $active ? 0 : 1;
        return $user;
    }

    public static function delete(int $id, int $selfId): array
    {
        self::requirePermission('users.delete');
        $user = self::find($id) ?? throw new HttpException(404, 'User not found.');
        if ($id === $selfId) {
            throw new HttpException(409, "You can't delete your own account.");
        }
        $blocker = self::manageBlocker($user, $selfId);
        if ($blocker !== null) {
            throw new HttpException(403, $blocker);
        }
        if ((int) $user['sales'] > 0) {
            throw new HttpException(409, "{$user['full_name']} has {$user['sales']} sales on record and can only be deactivated.");
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ((int) $user['is_super'] === 1 && (int) $user['is_active'] === 1 && self::lockedActiveSupers($id) === 0) {
                throw new HttpException(409, "{$user['full_name']} is the only active super admin and can't be deleted.");
            }
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            Audit::record('users', 'delete', 'user', $id, $user['username'], [
                'username' => $user['username'], 'full_name' => $user['full_name'], 'role' => $user['role'],
                'branch_id' => (int) $user['branch_id'],
            ], null, (int) $user['branch_id']);
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ((int) ($e->errorInfo[1] ?? 0) === 1451) {
                throw new HttpException(409, "{$user['full_name']} has records that refer to them and can only be deactivated.");
            }
            throw $e;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return $user;
    }

    /** Change your own password after confirming the current one. @return array<string,string> field errors */
    public static function changeOwnPassword(int $id, string $current, string $new, string $confirm): array
    {
        $stmt = db()->prepare('SELECT username, password_hash FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $user = $stmt->fetch() ?: throw new HttpException(404, 'User not found.');

        if (!password_verify($current, $user['password_hash'])) {
            return ['current_password' => 'The current password is not correct.'];
        }
        if (hash_equals($current, $new)) {
            return ['password' => 'Choose a password different from the current one.'];
        }
        $problem = self::passwordProblem($new, $user['username']);
        if ($problem !== null) {
            return ['password' => $problem];
        }
        if (!hash_equals($new, $confirm)) {
            return ['password_confirm' => "The passwords don't match."];
        }
        self::setPassword($id, $new);
        Audit::record('users', 'password_change', 'user', $id, $user['username']);
        return [];
    }
}
