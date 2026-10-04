<?php
/**
 * Application settings. Values come from .env; defaults are the safe choice.
 */
declare(strict_types=1);

return [
    'name'     => (string) Env::get('APP_NAME', 'EXECOM Logistics'),
    'env'      => (string) Env::get('APP_ENV', 'production'),
    'debug'    => (bool) Env::get('APP_DEBUG', false),
    'url'      => rtrim((string) Env::get('APP_URL', ''), '/'),
    'timezone' => (string) Env::get('APP_TIMEZONE', 'Asia/Manila'),
    'currency' => (string) Env::get('APP_CURRENCY', '₱'),
    'version'  => '1.0.0',

    'session' => [
        'name'             => (string) Env::get('SESSION_NAME', 'EXECOM_SID'),
        // 0 = no auto-logout (user stays signed in until Logout or the browser closes)
        'idle_timeout'     => (int) Env::get('SESSION_IDLE_TIMEOUT', 0),
        'absolute_timeout' => (int) Env::get('SESSION_ABSOLUTE_TIMEOUT', 0),
        'rotate_every'     => 900, // regenerate the session ID every 15 min
    ],

    'security' => [
        'login_max_attempts' => (int) Env::get('LOGIN_MAX_ATTEMPTS', 5),
        'lockout_minutes'    => (int) Env::get('LOGIN_LOCKOUT_MINUTES', 15),
        // Go-live over the internet / VPN: redirect http -> https and send HSTS (needs a working certificate).
        'force_https'        => (bool) Env::get('FORCE_HTTPS', false),
    ],

    // Roles and permissions live in the database (roles, role_permissions) and config/permissions.php.
];
