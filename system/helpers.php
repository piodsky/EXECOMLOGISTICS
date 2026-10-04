<?php
/**
 * Global helper functions (config, URLs, escaping, input, responses, flash).
 */
declare(strict_types=1);

// ---------------------------------------------------------------------
// Config & paths
// ---------------------------------------------------------------------

/** config('app.name'), config('menu.pos.roles') — reads config/<file>.php */
function config(string $key, mixed $default = null): mixed
{
    static $cache = [];

    $parts = explode('.', $key);
    $file  = basename(array_shift($parts));
    if (!array_key_exists($file, $cache)) {
        $path = ROOT_PATH . '/config/' . $file . '.php';
        $cache[$file] = is_file($path) ? require $path : [];
    }

    $value = $cache[$file];
    foreach ($parts as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

/** URL path of the app, e.g. "/EXECOMLOGISTICS" (no trailing slash). */
function base_path(): string
{
    static $base = null;
    if ($base === null) {
        $path = parse_url((string) config('app.url', ''), PHP_URL_PATH);
        $base = rtrim(is_string($path) ? $path : '', '/');
    }
    return $base;
}

/** Host-relative URL, so the app also works from other PCs/tablets on the LAN. */
function url(string $path = ''): string
{
    return base_path() . '/' . ltrim($path, '/');
}

/** Asset URL with a cache-busting version (file modification time). */
function asset(string $path): string
{
    static $versions = [];
    $path = ltrim($path, '/');
    if (!isset($versions[$path])) {
        $file = ROOT_PATH . '/assets/' . $path;
        $versions[$path] = is_file($file) ? (string) filemtime($file) : '';
    }
    return url('assets/' . $path) . ($versions[$path] !== '' ? '?v=' . $versions[$path] : '');
}

// ---------------------------------------------------------------------
// Output
// ---------------------------------------------------------------------

/** Escape for HTML output. Use for EVERY dynamic value: <?= e($x) ?> */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

/** Inline SVG icon from assets/img/icons.svg (sprite). */
function icon(string $name, string $class = ''): string
{
    $cls = 'icon' . ($class !== '' ? ' ' . $class : '');
    return '<svg class="' . e($cls) . '" aria-hidden="true" focusable="false"><use href="'
        . e(asset('img/icons.svg')) . '#i-' . e($name) . '"></use></svg>';
}

function money(float|int|string|null $amount): string
{
    return config('app.currency', '₱') . ' ' . number_format((float) $amount, 2);
}

/** Money math is done in integer centavos to avoid float rounding errors. */
function to_cents(float|int|string $amount): int
{
    return (int) round((float) $amount * 100);
}

/** Centavos -> DECIMAL string for the database ("352.80"). */
function from_cents(int $cents): string
{
    return number_format($cents / 100, 2, '.', '');
}

/** Shop setting from the `settings` table (loaded once per request). */
function setting(string $key, string $default = ''): string
{
    static $settings = null;
    if ($settings === null) {
        $stmt = db()->prepare('SELECT setting_key, setting_value FROM settings');
        $stmt->execute();
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    return array_key_exists($key, $settings) ? (string) $settings[$key] : $default;
}

// ---------------------------------------------------------------------
// Request & input
// ---------------------------------------------------------------------

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 80) === 443;
}

function is_api_request(): bool
{
    return str_starts_with((string) ($_SERVER['SCRIPT_NAME'] ?? ''), base_path() . '/api/');
}

/** Client IP. REMOTE_ADDR only — X-Forwarded-For is client-controlled. */
function client_ip(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

/** Trimmed string without control characters, cut to $max characters. */
function input_string(array $source, string $key, int $max = 255): string
{
    $value = $source[$key] ?? '';
    if (!is_string($value)) {
        return '';
    }
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
    return mb_substr(trim($value), 0, $max);
}

/** Integer or null if missing/invalid/out of range. */
function input_int(array $source, string $key, ?int $min = null, ?int $max = null): ?int
{
    $options = [];
    if ($min !== null) $options['min_range'] = $min;
    if ($max !== null) $options['max_range'] = $max;
    $value = filter_var($source[$key] ?? null, FILTER_VALIDATE_INT, ['options' => $options]);
    return $value === false ? null : $value;
}

/** Decimal (e.g. price) or null if invalid/out of range. Rounded to $scale places. */
function input_decimal(array $source, string $key, float $min = 0, float $max = 9999999.99, int $scale = 2): ?float
{
    $raw = $source[$key] ?? null;
    if (is_int($raw) || is_float($raw)) {
        $raw = (string) $raw;
    }
    if (!is_string($raw) || !preg_match('/^\d{1,9}(\.\d{1,4})?$/', trim($raw))) {
        return null;
    }
    $value = round((float) $raw, $scale);
    return ($value < $min || $value > $max) ? null : $value;
}

/** A real calendar date as 'Y-m-d' (from <input type="date">), or null if missing/invalid. */
function input_date(array $source, string $key): ?string
{
    $raw = $source[$key] ?? null;
    if (!is_string($raw) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
    return $date && $date->format('Y-m-d') === $raw ? $raw : null;
}

/** Decode a JSON request body (AJAX), max 1 MB. */
function request_json(): array
{
    $raw = file_get_contents('php://input', false, null, 0, 1_048_576);
    $data = json_decode($raw !== false ? $raw : '', true);
    return is_array($data) ? $data : [];
}

// ---------------------------------------------------------------------
// Responses
// ---------------------------------------------------------------------

/** Redirect to an app path ("login.php") or an absolute path ("/EXECOMLOGISTICS/..."). */
function redirect(string $to): never
{
    header('Location: ' . (str_starts_with($to, '/') ? $to : url($to)), true, 303);
    exit;
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
    exit;
}

function abort(int $code, string $message = '', ?string $title = null): never
{
    $titles = [
        400 => 'Bad Request', 403 => 'Access Denied', 404 => 'Page Not Found',
        405 => 'Method Not Allowed', 500 => 'Something Went Wrong',
    ];
    $title   = $title ?? $titles[$code] ?? 'Error';
    $message = $message !== '' ? $message : $title;

    if (is_api_request()) {
        json_response(['ok' => false, 'message' => $message], $code);
    }

    http_response_code($code);
    require ROOT_PATH . '/includes/error.php';
    exit;
}

/**
 * API endpoint guard. Call at the top of every file in /api:
 *   api_guard('POST', 'pos.access');                  // permission key
 *   api_guard('POST', ['customers.edit', 'pos.access']); // any of them
 * Checks method, login, permission (strings containing a dot; anything else is treated as a
 * legacy role code) and, for non-GET, the CSRF header.
 */
function api_guard(string $method, array|string $access): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $method) {
        header('Allow: ' . $method);
        json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
    }
    Auth::requireLogin();
    $access = (array) $access;
    $perms  = array_values(array_filter($access, static fn ($a) => is_string($a) && str_contains($a, '.')));
    $roles  = array_values(array_filter($access, static fn ($a) => is_string($a) && !str_contains($a, '.')));
    $ok = ($perms && Auth::canAny(...$perms)) || ($roles && Auth::hasRole(...$roles));
    if (!$ok) {
        json_response(['ok' => false, 'message' => 'You do not have permission to do that.'], 403);
    }
    if ($method !== 'GET') {
        Csrf::verifyRequest();
    }
}

/**
 * Page guard: login + permission from config/menu.php ('permission': a key or a list = any of them;
 * legacy 'roles' only when no permission is set). Returns the menu item.
 */
function require_page(string $key): array
{
    $item = config('menu', [])[$key] ?? null;
    if (!is_array($item)) {
        throw new LogicException("Unknown page [{$key}] — add it to config/menu.php");
    }
    if (isset($item['permission'])) {
        Auth::requirePermission(...(array) $item['permission']);
    } else {
        Auth::requireRole(...($item['roles'] ?? []));
    }
    return $item + ['key' => $key, 'title' => $item['label']];
}

/** Can the signed-in user open this menu item? */
function can_open_menu(array $item): bool
{
    if (isset($item['permission'])) {
        return Auth::canAny(...(array) $item['permission']);
    }
    return Auth::hasRole(...($item['roles'] ?? []));
}

/**
 * Settings tabs in order: key => [label, icon, path, permission].
 * includes/settings-nav.php shows the ones the user may open; each page guards its own.
 */
function settings_tabs(): array
{
    return [
        'company'  => ['Company & Receipt', 'receipt', 'pages/settings.php',  'settings.manage'],
        'users'    => ['Users',             'user',    'pages/users.php',     'users.view'],
        'roles'    => ['Roles',             'shield',  'pages/roles.php',     'roles.manage'],
        'branches' => ['Branches',          'store',   'pages/branches.php',  'branches.manage'],
        'warehouses' => ['Warehouses',      'grid',    'pages/warehouses.php', 'warehouses.manage'],
        'audit'    => ['Audit Log',         'clock',   'pages/audit-log.php', 'audit_logs.view'],
    ];
}

/**
 * Guard for a Settings page: login + the tab's own permission (+ extra ones, all required).
 * Returns a page array that keeps "Settings" highlighted in the sidebar.
 */
function settings_page(string $tab, string $title, string ...$alsoRequired): array
{
    $def = settings_tabs()[$tab] ?? throw new LogicException("Unknown settings tab [{$tab}]");
    Auth::requirePermission($def[3]);
    foreach ($alsoRequired as $perm) {
        Auth::requirePermission($perm);
    }
    $item = config('menu', [])['settings'] ?? [];
    return ['key' => 'settings', 'title' => $title, 'tab' => $tab] + $item;
}

/** App path of a menu item (Settings → the first tab the user can open). */
function menu_path(array $item): string
{
    if (!empty($item['tabs'])) {
        foreach (settings_tabs() as [, , $path, $perm]) {
            if (Auth::can($perm)) {
                return $path;
            }
        }
    }
    return $item['url'];
}

/** App path of the first page the signed-in user can open (menu order), e.g. 'pages/pos.php'. */
function home_path(): string
{
    foreach (config('menu', []) as $item) {
        if (can_open_menu($item)) {
            return menu_path($item);
        }
    }
    return 'pages/account.php';
}

function home_url(): string
{
    return url(home_path());
}

/** CSV cell guard: values starting with = + - @ (or tab/CR) would run as formulas in Excel; prefix '. */
function csv_cell(mixed $value): string
{
    $value = (string) $value;
    return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
}

// ---------------------------------------------------------------------
// Flash messages & old input (shown once after a redirect)
// ---------------------------------------------------------------------

/** @param 'success'|'error'|'warning'|'info' $type */
function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

function flash_messages(): array
{
    $messages = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return is_array($messages) ? $messages : [];
}

function flash_old(array $input): void
{
    $_SESSION['_old'] = $input;
}

function old(string $key, string $default = ''): string
{
    $old = old_input();
    return is_string($old[$key] ?? null) ? $old[$key] : $default;
}

/** True when the form is being redisplayed after a failed submit. */
function has_old(): bool
{
    return old_input() !== [];
}

function old_input(): array
{
    static $old = null;
    if ($old === null) {
        $old = is_array($_SESSION['_old'] ?? null) ? $_SESSION['_old'] : [];
        unset($_SESSION['_old']);
    }
    return $old;
}

/** Per-field validation errors for the next request: ['name' => 'Enter a name.'] */
function flash_errors(array $errors): void
{
    $_SESSION['_errors'] = $errors;
}

function form_errors(): array
{
    static $errors = null;
    if ($errors === null) {
        $errors = is_array($_SESSION['_errors'] ?? null) ? $_SESSION['_errors'] : [];
        unset($_SESSION['_errors']);
    }
    return $errors;
}

/** <p class="form-error">…</p> under a field, or '' when it is valid. */
function field_error(string $field): string
{
    $msg = form_errors()[$field] ?? null;
    return is_string($msg) ? '<p class="form-error" id="err-' . e($field) . '">' . e($msg) . '</p>' : '';
}

/** Extra attributes for an invalid input: class + aria. Use inside the tag. */
function invalid(string $field): string
{
    return isset(form_errors()[$field])
        ? ' aria-invalid="true" aria-describedby="err-' . e($field) . '"'
        : '';
}

// ---------------------------------------------------------------------
// Lists: search & pagination
// ---------------------------------------------------------------------

/** LIKE pattern with %, _ and \ escaped:  WHERE name LIKE ?  ->  like_pattern($q) */
function like_pattern(string $term): string
{
    return '%' . addcslashes($term, '%_\\') . '%';
}

/** Page math from ?page=N. */
function paginate(int $total, int $perPage = 15): array
{
    $pages = max(1, (int) ceil($total / $perPage));
    $page  = min($pages, input_int($_GET, 'page', 1) ?? 1);
    return [
        'total'    => $total,
        'per_page' => $perPage,
        'page'     => $page,
        'pages'    => $pages,
        'offset'   => ($page - 1) * $perPage,
    ];
}

/**
 * "Return to" target posted by a form, restricted to a page in /pages with a plain query
 * string (never another host or path), e.g. "inventory.php?q=laptop&page=2".
 */
function safe_return(mixed $value, string $default): string
{
    return is_string($value) && preg_match('/^[a-z-]+\.php(\?[A-Za-z0-9=&%_.+\-]*)?$/', $value)
        ? $value
        : $default;
}

// ---------------------------------------------------------------------
// Errors, logging & headers
// ---------------------------------------------------------------------

function log_message(string $level, string $message): void
{
    $dir = ROOT_PATH . '/storage/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $line = sprintf("[%s] %s: %s\n", date('Y-m-d H:i:s'), strtoupper($level), $message);
    @error_log($line, 3, $dir . '/app-' . date('Y-m-d') . '.log');
}

function handle_exception(Throwable $e): void
{
    // Expected errors (validation, not found, out of stock): answer, don't log.
    if ($e instanceof HttpException) {
        if (is_api_request()) {
            json_response(['ok' => false, 'message' => $e->getMessage()] + $e->details, $e->status);
        }
        abort($e->status, $e->getMessage());
    }

    log_message('error', $e::class . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()
        . "\n" . $e->getTraceAsString());

    $message = config('app.debug', false)
        ? $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')'
        : 'An unexpected error occurred. Please try again or contact the administrator.';

    if (headers_sent()) {
        echo '<p>' . e($message) . '</p>';
        return;
    }
    abort(500, $message);
}

/**
 * FORCE_HTTPS=true: plain-http requests are redirected to https (GET/HEAD 301, other methods 308 so a form post is
 * not turned into a GET). Off by default (local XAMPP has no certificate).
 */
function force_https(): void
{
    if (!config('app.security.force_https', false) || is_https() || PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if (!preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $host)) {
        abort(400, 'Bad request.');
    }
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    header('Location: https://' . preg_replace('/:\d+$/', '', $host) . (string) ($_SERVER['REQUEST_URI'] ?? '/'),
        true, in_array($method, ['GET', 'HEAD'], true) ? 301 : 308);
    exit;
}

function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header_remove('X-Powered-By');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), payment=()');
    if (is_https() && config('app.security.force_https', false)) {
        header('Strict-Transport-Security: max-age=31536000'); // browsers then refuse plain http for a year
    }
    header(content_security_policy("'none'"));
    // Pages hold sales data: never cache (also stops "Back" after Logout showing a page).
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

function content_security_policy(string $frameAncestors): string
{
    return "Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; "
        . "img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; object-src 'none'; "
        . "base-uri 'self'; form-action 'self'; frame-ancestors {$frameAncestors}";
}

/** For pages the POS loads in a hidden iframe (receipt printing). */
function allow_same_origin_framing(): void
{
    header('X-Frame-Options: SAMEORIGIN');
    header(content_security_policy("'self'"));
}
