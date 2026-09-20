<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    /**
     * Final pre-demo audit (2026-09-20) — refuse to run against anything
     * but the in-memory SQLite database phpunit.xml configures. This runs
     * BEFORE RefreshDatabase's migrate:fresh (setUpTraits() is where the
     * trait hooks fire), because a PHPUnit invocation that skipped
     * phpunit.xml's <env> block (e.g. --no-configuration) once ran
     * migrate:fresh against the LIVE MySQL database. The .env of this
     * checkout points at real academic records; a test suite must never
     * be one missing flag away from emptying it.
     */
    protected function setUpTraits()
    {
        $connection = config('database.default');
        $database   = config("database.connections.{$connection}.database");

        if ($connection !== 'sqlite' || $database !== ':memory:') {
            fwrite(STDERR, "
REFUSING TO RUN TESTS: database connection is '{$connection}' ('{$database}'), not sqlite ':memory:'. Run via `php artisan test` or `vendor/bin/phpunit -c phpunit.xml` so phpunit.xml's env overrides apply.
");
            exit(255);
        }

        return parent::setUpTraits();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Every multi-step upload test (assessment detect/verify/preview,
        // Import Learners from ECR) writes its workbook to the "local"
        // disk. Without this, each suite run left ~100 real files under
        // storage/app/private/temp_* that nothing removed — 830 of them
        // (~22 MB) by the 2026-09-17 pre-demo audit. Faking the disk
        // keeps test uploads in storage/framework/testing (wiped per
        // test) and away from the real private upload directories.
        Storage::fake('local');
    }
}
