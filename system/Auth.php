<?php
/**
 * Authentication, permissions (config/permissions.php + roles) and the signed-in user.
 * Only the user ID (and a stamp of the password hash) is kept in the session; the user row
 * is reloaded on every request, so disabling a user, changing a role or resetting a
 * password applies at once (a new password ends that user's other sessions).
 */
declare(strict_types=1);

final class Auth
{
    /** Valid bcrypt hash of a random string — used when the username doesn't exist
     *  so both paths cost one password_verify() (no username enumeration by timing). */
    private const DUMMY_HASH = '$2y$10$Frzh6ApwFrfV3jB1qMgF1OpTZrflXktAq8DBE3PaAewG28QDNXUDe';

    private static ?array $user = null;
    private static bool $loaded = false;

    /** @return array{ok:bool, error?:string} */
    public static function attempt(string $username, #[SensitiveParameter] string $password): array
    {
        $ip      = client_ip();
        $max     = max(1, (int) config('app.security.login_max_attempts', 5));
        $minutes = max(1, (int) config('app.security.lockout_minutes', 15));

        if (self::isLockedOut($username, $ip, $max, $minutes)) {
            self::securityEvent('login_locked', $username, ['minutes' => $minutes]);
            return ['ok' => false, 'error' => "Too many failed attempts. Please wait {$minutes} minutes and try again."];
        }

        $stmt = db()->prepare(
            'SELECT u.id, u.password_hash, u.is_active, u.branch_id, r.id AS role_id, r.is_active AS role_active, r.is_super
               FROM users u LEFT JOIN roles r ON r.code = u.role
              WHERE u.username = ? LIMIT 1'
        );
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        $valid = password_verify($password, $user ? $user['password_hash'] : self::DUMMY_HASH) && $user !== false;

        if (!$valid) {
            self::recordAttempt($username, $ip, false);
            self::securityEvent('login_failed', $username, ['reason' => $user ? 'wrong password' : 'unknown username']);
            return ['ok' => false, 'error' => 'Invalid username or password.'];
        }

        if ((int) $user['is_active'] !== 1) {
            self::securityEvent('login_failed', $username, ['reason' => 'account disabled']);
            return ['ok' => false, 'error' => 'This account is disabled. Please contact the administrator.'];
        }
        if ($user['role_id'] === null || (int) $user['role_active'] !== 1) {
            return ['ok' => false, 'error' => 'Your role is inactive. Contact the administrator.'];
        }

        // A user needs at least one active branch to work in.
        $accessAll = (int) $user['is_super'] === 1 || self::roleHas((int) $user['role_id'], 'branches.access_all');
        if (Branch::allowedIdsFor((int) $user['id'], (int) $user['branch_id'], $accessAll) === []) {
            return ['ok' => false, 'error' => 'Your branch is inactive. Contact the administrator.'];
        }

        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        }

        db()->prepare('DELETE FROM login_attempts WHERE username = ? AND ip_address = ? AND success = 0')
            ->execute([$username, $ip]);
        self::recordAttempt($username, $ip, true);
        self::login((int) $user['id']);
        Audit::record('auth', 'login', 'user', (int) $user['id'], $username, null, null, (int) $user['branch_id']);

        return ['ok' => true];
    }

    /**
     * Check another user's credentials without signing in (an approver at the till). Same lockout as
     * the login form. @return array{id:int, full_name:string, branch_id:int, role_id:int, is_super:int}
     * @throws HttpException 422 on wrong credentials / lockout, 403 when the account or role is inactive
     */
    public static function verifyCredentials(string $username, #[SensitiveParameter] string $password): array
    {
        $ip      = client_ip();
        $max     = max(1, (int) config('app.security.login_max_attempts', 5));
        $minutes = max(1, (int) config('app.security.lockout_minutes', 15));
        if (self::isLockedOut($username, $ip, $max, $minutes)) {
            throw new HttpException(422, "Too many failed attempts. Please wait {$minutes} minutes and try again.");
        }
        $stmt = db()->prepare(
            'SELECT u.id, u.full_name, u.password_hash, u.is_active, u.branch_id, r.id AS role_id, r.is_active AS role_active, r.is_super
               FROM users u LEFT JOIN roles r ON r.code = u.role
              WHERE u.username = ? LIMIT 1'
        );
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        if (!password_verify($password, $user ? $user['password_hash'] : self::DUMMY_HASH) || $user === false) {
            self::recordAttempt($username, $ip, false);
            self::securityEvent('approval_failed', $username, ['reason' => 'wrong approver credentials']);
            throw new HttpException(422, 'Invalid username or password.');
        }
        if ((int) $user['is_active'] !== 1 || $user['role_id'] === null || (int) $user['role_active'] !== 1) {
            throw new HttpException(403, 'This account cannot approve.');
        }
        db()->prepare('DELETE FROM login_attempts WHERE username = ? AND ip_address = ? AND success = 0')->execute([$username, $ip]);
        return [
            'id' => (int) $user['id'], 'full_name' => (string) $user['full_name'], 'branch_id' => (int) $user['branch_id'],
            'role_id' => (int) $user['role_id'], 'is_super' => (int) $user['is_super'],
        ];
    }

    /** Does a user (from verifyCredentials) hold a permission? Super roles hold all. */
    public static function userCan(array $user, string $key): bool
    {
        return (int) $user['is_super'] === 1 || self::roleHas((int) $user['role_id'], $key);
    }

    public static function login(int $userId): void
    {
        $intended = $_SESSION['_intended'] ?? null;

        $_SESSION = [];
        Session::regenerate();
        Csrf::rotate();
        $_SESSION['auth'] = ['id' => $userId, 'pw' => self::passwordStamp($userId)];
        if (is_string($intended)) {
            $_SESSION['_intended'] = $intended;
        }

        db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$userId]);

        self::$loaded      = false;
        self::$user        = null;
        self::$permissions = null;
        Branch::reset();
    }

    public static function logout(): void
    {
        if (($u = self::user()) !== null) {
            Audit::record('auth', 'logout', 'user', (int) $u['id'], (string) $u['username'], null, null, (int) $u['branch_id']);
        }
        self::$user        = null;
        self::$loaded      = true;
        self::$permissions = null;
        Branch::reset();
        Session::restart();
    }

    /**
     * The signed-in user (reloaded every request; inactive users or roles are signed out):
     * id, username, full_name, role (code), role_name, is_super (0|1), branch_id (home branch).
     */
    public static function user(): ?array
    {
        if (!self::$loaded) {
            self::$loaded = true;
            $id = $_SESSION['auth']['id'] ?? null;
            if (is_int($id)) {
                $stmt = db()->prepare(
                    'SELECT u.id, u.username, u.full_name, u.role, u.branch_id, u.password_hash,
                            r.id AS role_id, r.name AS role_name, r.is_super
                       FROM users u
                       JOIN roles r ON r.code = u.role AND r.is_active = 1
                      WHERE u.id = ? AND u.is_active = 1
                      LIMIT 1'
                );
                $stmt->execute([$id]);
                $row   = $stmt->fetch() ?: null;
                $stamp = $row ? hash('sha256', $row['password_hash']) : '';
                if ($row !== null && !isset($_SESSION['auth']['pw'])) {
                    $_SESSION['auth']['pw'] = $stamp; // session from before stamps existed
                }
                if ($row === null || !hash_equals((string) $_SESSION['auth']['pw'], $stamp)) {
                    unset($_SESSION['auth']); // deleted, disabled, or password changed elsewhere
                    self::$user = null;
                } else {
                    unset($row['password_hash']);
                    $row['id']        = (int) $row['id'];
                    $row['branch_id'] = (int) $row['branch_id'];
                    $row['role_id']   = (int) $row['role_id'];
                    $row['is_super']  = (int) $row['is_super'];
                    self::$user = $row;
                }
            }
        }
        return self::$user;
    }

    // ------------------------------------------------------------------
    // Permissions (config/permissions.php). Default deny.
    // ------------------------------------------------------------------

    /** @var list<string>|null permission keys of the signed-in user (memoised per request) */
    private static ?array $permissions = null;

    public static function isSuper(): bool
    {
        $user = self::user();
        return $user !== null && $user['is_super'] === 1;
    }

    /** Permission keys the signed-in user holds (a super role holds every registered key). */
    public static function permissions(): array
    {
        $user = self::user();
        if ($user === null) {
            return [];
        }
        if (self::$permissions === null) {
            if ($user['is_super'] === 1) {
                self::$permissions = array_keys(config('permissions', []));
            } else {
                $stmt = db()->prepare(
                    'SELECT p.perm_key FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
                      WHERE rp.role_id = ? ORDER BY p.sort_order'
                );
                $stmt->execute([$user['role_id']]);
                self::$permissions = array_values(array_intersect(
                    $stmt->fetchAll(PDO::FETCH_COLUMN),
                    array_keys(config('permissions', []))
                ));
            }
        }
        return self::$permissions;
    }

    public static function can(string $key): bool
    {
        if (!array_key_exists($key, config('permissions', []))) {
            if (config('app.debug', false)) {
                throw new LogicException("Unknown permission [{$key}]: add it to config/permissions.php");
            }
            log_message('warning', "Unknown permission checked: {$key}");
            return false;
        }
        $user = self::user();
        if ($user === null) {
            return false;
        }
        return $user['is_super'] === 1 || in_array($key, self::permissions(), true);
    }

    public static function canAny(string ...$keys): bool
    {
        foreach ($keys as $key) {
            if (self::can($key)) {
                return true;
            }
        }
        return false;
    }

    /** Login + at least one of the permissions, else 403. */
    public static function requirePermission(string ...$keys): void
    {
        self::requireLogin();
        if (!self::canAny(...$keys)) {
            abort(403, 'You do not have permission to access this page.');
        }
    }

    /** Does a role (by id) hold a permission? Used before a session exists (sign-in). */
    private static function roleHas(int $roleId, string $key): bool
    {
        $stmt = db()->prepare(
            'SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
              WHERE rp.role_id = ? AND p.perm_key = ? LIMIT 1'
        );
        $stmt->execute([$roleId, $key]);
        return (bool) $stmt->fetchColumn();
    }

    /** Hash of the stored password hash: changes whenever the password does. */
    private static function passwordStamp(int $userId): string
    {
        $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        return hash('sha256', (string) $stmt->fetchColumn());
    }

    /** Keep the current session signed in after the user changes their own password. */
    public static function refreshPasswordStamp(): void
    {
        if (isset($_SESSION['auth']['id'])) {
            $_SESSION['auth']['pw'] = self::passwordStamp((int) $_SESSION['auth']['id']);
        }
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function id(): ?int
    {
        return self::user() ? (int) self::user()['id'] : null;
    }

    /** @deprecated kept for compatibility; use can() with a permission key. */
    public static function hasRole(string ...$roles): bool
    {
        $user = self::user();
        return $user !== null && in_array($user['role'], $roles, true);
    }

    public static function requireLogin(): void
    {
        if (self::check()) {
            return;
        }
        if (is_api_request()) {
            json_response(['ok' => false, 'message' => 'Please sign in to continue.'], 401);
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            $_SESSION['_intended'] = (string) ($_SERVER['REQUEST_URI'] ?? '');
        }
        redirect('login.php');
    }

    public static function requireRole(string ...$roles): void
    {
        self::requireLogin();
        if (!self::hasRole(...$roles)) {
            abort(403, 'You do not have permission to access this page.');
        }
    }

    /** Where to go after login: the page that was requested, if it is a safe local path. */
    public static function intendedUrl(): string
    {
        $url = $_SESSION['_intended'] ?? '';
        unset($_SESSION['_intended']);

        $prefix = base_path() . '/';
        if (is_string($url) && str_starts_with($url, $prefix)
            && !preg_match('#//|\\\\|[\r\n]#', substr($url, 1))) {
            return $url;
        }
        return home_url();
    }

    /**
     * Failed / blocked sign-in in the audit log (module 'auth', no branch: only users with access to all branches
     * see it). The attempted username is the reference; the password is never logged.
     */
    private static function securityEvent(string $action, string $username, array $details): void
    {
        try {
            Audit::record('auth', $action, 'user', null, mb_substr($username, 0, 60), null, $details, null);
        } catch (Throwable $e) {
            log_message('error', 'Audit of ' . $action . ' failed: ' . $e->getMessage()); // never block a sign-in on the log
        }
    }

    private static function isLockedOut(string $username, string $ip, int $max, int $minutes): bool
    {
        $since = date('Y-m-d H:i:s', time() - $minutes * 60);
        $stmt  = db()->prepare(
            'SELECT COALESCE(SUM(username = ?), 0) AS pair_failures, COUNT(*) AS ip_failures
               FROM login_attempts
              WHERE ip_address = ? AND success = 0 AND attempted_at >= ?'
        );
        $stmt->execute([$username, $ip, $since]);
        $row = $stmt->fetch();

        // Per username+IP limit, plus a looser per-IP limit against password spraying.
        return (int) $row['pair_failures'] >= $max || (int) $row['ip_failures'] >= $max * 4;
    }

    private static function recordAttempt(string $username, string $ip, bool $success): void
    {
        db()->prepare('INSERT INTO login_attempts (username, ip_address, success, attempted_at) VALUES (?, ?, ?, ?)')
            ->execute([mb_substr($username, 0, 50), $ip, $success ? 1 : 0, date('Y-m-d H:i:s')]);

        // Housekeeping: keep 30 days of history.
        if (random_int(1, 50) === 1) {
            db()->prepare('DELETE FROM login_attempts WHERE attempted_at < ?')
                ->execute([date('Y-m-d H:i:s', time() - 30 * 86400)]);
        }
    }
}
