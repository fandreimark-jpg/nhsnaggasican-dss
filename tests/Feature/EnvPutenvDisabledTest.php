<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Final pre-demo audit (2026-09-21).
 *
 * The demo box serves this app as a PHP ZTS module under Apache's threaded
 * MPM. With Laravel's default putenv adapter enabled, PHP's per-request
 * putenv() cleanup is process-wide, so two overlapping requests could race
 * and one would boot with NO .env at all ("No application encryption key",
 * HTTP 500, logged under the "production" environment). Reproduced at 28
 * failures in ~300 requests with three concurrent clients; zero after
 * bootstrap/app.php calls Env::disablePutenv().
 *
 * These tests pin that decision: the environment repository must read
 * $_ENV / $_SERVER only, never the process environment.
 */
class EnvPutenvDisabledTest extends TestCase
{
    public function test_env_helper_does_not_read_the_process_environment(): void
    {
        putenv('DSS_AUDIT_PUTENV_PROBE=from_putenv');

        try {
            $this->assertNull(
                env('DSS_AUDIT_PUTENV_PROBE'),
                'env() read a putenv() variable — Env::disablePutenv() is no longer in effect in bootstrap/app.php.'
            );
        } finally {
            putenv('DSS_AUDIT_PUTENV_PROBE');
        }
    }

    public function test_env_helper_still_reads_the_per_request_superglobals(): void
    {
        $_ENV['DSS_AUDIT_ENV_PROBE'] = 'from_env';
        $_SERVER['DSS_AUDIT_SERVER_PROBE'] = 'from_server';

        try {
            $this->assertSame('from_env', env('DSS_AUDIT_ENV_PROBE'));
            $this->assertSame('from_server', env('DSS_AUDIT_SERVER_PROBE'));
        } finally {
            unset($_ENV['DSS_AUDIT_ENV_PROBE'], $_SERVER['DSS_AUDIT_SERVER_PROBE']);
        }
    }

    public function test_bootstrap_disables_putenv_before_the_application_is_configured(): void
    {
        $source = file_get_contents(base_path('bootstrap/app.php'));

        $this->assertStringContainsString('Env::disablePutenv();', $source);
        $this->assertLessThan(
            strpos($source, 'Application::configure('),
            strpos($source, 'Env::disablePutenv();'),
            'disablePutenv() must run before the application is configured.'
        );
    }
}
