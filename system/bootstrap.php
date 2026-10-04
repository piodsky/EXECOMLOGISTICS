<?php
/**
 * Bootstrap — the first line of every entry point (pages, api, login, logout):
 *   require __DIR__ . '/../system/bootstrap.php';
 */
declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));

require ROOT_PATH . '/system/Env.php';
require ROOT_PATH . '/system/helpers.php';
require ROOT_PATH . '/system/Database.php';
require ROOT_PATH . '/system/Session.php';
require ROOT_PATH . '/system/Csrf.php';
require ROOT_PATH . '/system/Auth.php';

// Other classes in /system (Sales, Customers, HttpException, ...) load on first use.
spl_autoload_register(static function (string $class): void {
    $file = ROOT_PATH . '/system/' . str_replace(['\\', '/', '.'], '', $class) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

set_exception_handler('handle_exception');

Env::load(ROOT_PATH . '/.env');

error_reporting(E_ALL);
ini_set('display_errors', config('app.debug') ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', ROOT_PATH . '/storage/logs/php-errors.log');
// Stack traces in the logs never show argument values (passwords, form data) outside debug mode.
ini_set('zend.exception_ignore_args', config('app.debug') ? '0' : '1');

date_default_timezone_set((string) config('app.timezone', 'Asia/Manila'));
mb_internal_encoding('UTF-8');

force_https();
send_security_headers();
Session::start();
