<?php

/**
 * Browser-test isolation — the ONE place the isolated environment is
 * defined and checked ("Class Record refinements" pass, 2026-09-28).
 *
 * Why this exists: `php artisan serve` passes only an allow-list of
 * environment variables (APP_ENV, PATH, …) to its `php -S` child, and even
 * those never reach Laravel there — this app calls Env::disablePutenv(), so
 * Laravel reads only $_SERVER/$_ENV, and `php -S` with variables_order=GPCS
 * does not copy the process environment into either. Every override was
 * therefore silently ignored and the served app used .env — the WORKING
 * database. Here the overrides are written straight into $_SERVER/$_ENV
 * BEFORE Laravel boots (Dotenv is immutable, so .env cannot replace them),
 * and browser_isolation_assert() refuses to continue unless the booted
 * application is really on the isolated SQLite file.
 */

const BROWSER_DB_DIR = __DIR__ . '/../../storage/framework/browser-testing';

function browser_isolation_db_path(): string
{
    if (!is_dir(BROWSER_DB_DIR)) {
        mkdir(BROWSER_DB_DIR, 0775, true);
    }

    return realpath(BROWSER_DB_DIR) . DIRECTORY_SEPARATOR . 'browser.sqlite';
}

/**
 * The isolated MySQL database used ONLY for concurrency/load checks
 * (BROWSER_ISOLATION=mysql). SQLite cannot show row-lock behaviour. The name
 * is fixed; the guard below refuses anything else, the working database
 * included.
 */
const BROWSER_MYSQL_DB = 'naggasican_dss_loadtest';

function browser_isolation_mode(): string
{
    return getenv('BROWSER_ISOLATION') === 'mysql' ? 'mysql' : 'sqlite';
}

function browser_isolation_apply(string $appUrl = 'http://127.0.0.1:8765'): void
{
    $mysql = browser_isolation_mode() === 'mysql';
    $overrides = [
        'APP_ENV'          => 'browser',
        'APP_DEBUG'        => $mysql ? 'false' : 'true',
        'APP_URL'          => $appUrl,
        'DB_CONNECTION'    => $mysql ? 'mysql' : 'sqlite',
        'DB_DATABASE'      => $mysql ? BROWSER_MYSQL_DB : browser_isolation_db_path(),
        'DB_FOREIGN_KEYS'  => 'true',
        'SESSION_DRIVER'   => 'database',
        'SESSION_SECURE_COOKIE' => 'false',
        'CACHE_STORE'      => 'database',
        'QUEUE_CONNECTION' => 'sync',
        'MAIL_MAILER'      => 'array',
        'LOG_CHANNEL'      => 'stderr',
        'TRUSTED_PROXIES'  => '',
    ];
    foreach ($overrides as $key => $value) {
        $_SERVER[$key] = $value;
        $_ENV[$key] = $value;
    }
}

/** Stops everything unless the booted app targets the isolated SQLite file. */
function browser_isolation_assert($app): void
{
    $config = $app->make('config');
    $connection = $config->get('database.default');
    $database = $config->get("database.connections.{$connection}.database");
    $expected = browser_isolation_db_path();

    $isolated = browser_isolation_mode() === 'mysql'
        ? $connection === 'mysql' && $database === BROWSER_MYSQL_DB
        : !($connection !== 'sqlite' || $database === null || realpath((string) $database) !== realpath($expected) && $database !== $expected);
    if (!$isolated) {
        $message = "REFUSING: browser testing is not isolated (connection '{$connection}', database '{$database}'). Expected "
            . (browser_isolation_mode() === 'mysql' ? 'mysql database ' . BROWSER_MYSQL_DB : "sqlite at {$expected}") . '.';
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $message . PHP_EOL);
            exit(255);
        }
        http_response_code(500);
        header('Content-Type: text/plain');
        echo $message;
        exit;
    }
}
