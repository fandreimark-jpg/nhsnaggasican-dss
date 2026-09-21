<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

// Final pre-demo audit (2026-09-21): this app runs as a PHP ZTS module inside
// Apache's threaded MPM (php8apache2_4.dll, mpm_winnt). Laravel's Env reads
// and writes variables through putenv() by default, and PHP unsets a
// request's putenv() variables PROCESS-WIDE at that request's shutdown. Two
// overlapping requests therefore race: one can lose the whole .env mid-boot
// (logged as "production.ERROR: No application encryption key has been
// specified", HTTP 500). Reproduced at 28 failures in ~300 requests under
// three concurrent clients. Reading .env through $_ENV/$_SERVER only — both
// per-request — removes the shared state. Nothing here reads getenv() itself.
\Illuminate\Support\Env::disablePutenv();

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
   ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(headers:
            \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR |
            \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO |
            \Illuminate\Http\Request::HEADER_X_FORWARDED_PORT
        );
        $middleware->alias([
            'role' => \App\Http\Middleware\RoleMiddleware::class,
        ]);

        $middleware->web(append: [
            \App\Http\Middleware\EnsureAccountIsActive::class,
            \App\Http\Middleware\PreventBackHistory::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
