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

    /**
     * A term report as an adviser's explicit submission leaves it (2026-10-07):
     * the submitted marker plus a new immutable version whose snapshot is
     * built by the same ReportSnapshotService the Submit action uses.
     * Recipients' Reports pages show only such versions.
     */
    protected function submittedReport(\App\Models\Section $section, int $term, ?\App\Models\User $by = null): \App\Models\ReportSubmission
    {
        $by ??= \App\Models\User::find($section->adviser_id) ?? \App\Models\User::factory()->create(['role' => 'adviser']);
        $row = \App\Models\ReportSubmission::updateOrCreate(
            ['section_id' => $section->id, 'grading_period' => $term, 'school_year' => $section->school_year],
            ['submitted_by' => $by->id, 'submitted_at' => now(), 'status' => 'submitted']
        );
        $row->versions()->create(['version' => (int) $row->versions()->max('version') + 1, 'submitted_by' => $by->id, 'submitted_at' => now(),
            'snapshot' => (new \App\Services\ReportSnapshotService())->build($section, $term)]);

        return $row;
    }

    /**
     * Recording an intervention needs a valid context (2026-10-07): the
     * learner's section has an active adviser (assigned automatically as the
     * responsible staff) and is taught the subject. Older fixtures built from
     * random factory grade levels call this to state that context explicitly.
     */
    protected function interventionReady(\App\Models\Student $student, \App\Models\Subject ...$subjects): \App\Models\User
    {
        $section = $student->section;
        if (!$section->adviser_id) {
            $section->forceFill(['adviser_id' => \App\Models\User::factory()->create(['role' => 'adviser'])->id])->save();
        }
        foreach ($subjects as $subject) {
            $subject->forceFill(['grade_level' => $section->grade_level])->save();
        }

        return \App\Models\User::find($section->adviser_id);
    }
}
