<?php

/**
 * Router for PHP's built-in server, for BROWSER TESTS ONLY:
 *
 *   php -S 127.0.0.1:8765 -t public tests/Browser/router.php
 *
 * Applies the isolated environment (tests/Browser/isolation.php) before
 * Laravel boots, then refuses every request unless the booted app is on the
 * isolated SQLite file. Never used by XAMPP/Apache or production.
 */

require __DIR__ . '/isolation.php';

$root = dirname(__DIR__, 2);
browser_isolation_apply('http://' . ($_SERVER['HTTP_HOST'] ?? '127.0.0.1:8765'));

$path = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
if ($path !== '/' && is_file($root . '/public' . $path) && !str_ends_with($path, '.php')) {
    return false; // built assets, images, icons — served as files
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/public/index.php';
$_SERVER['PHP_SELF'] = '/index.php';

define('LARAVEL_START', microtime(true));
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request = Illuminate\Http\Request::capture();
$app->instance('request', $request);
$kernel->bootstrap();
browser_isolation_assert($app);

$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
