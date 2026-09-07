<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The sanctioned way to wipe demo data at a defined level — see the
 * "live in-term risk + stale data guard" prompt (Problem 1). Existed
 * only as ad-hoc hand-written SQL before this, which is exactly what
 * caused the stale-risk-results incident this task exists to guard
 * against: someone truncated `grades` directly without also clearing
 * `risk_results`/`report_submissions`, a combination this command's two
 * levels can never produce, since `academic` always includes everything
 * `assessments` does.
 *
 * Master/system data (students, sections, subjects, tracks,
 * specializations, users) is never touched by either level.
 */
class TruncateCommand extends Command
{
    protected $signature = 'dss:truncate {--level=assessments : Which layer to wipe: assessments|academic}';

    protected $description = 'Safely wipes demo data at a defined level (assessments = evidence only; academic = assessments + grades/risk results/submissions/interventions/terms/logs)';

    /**
     * 'academic' is deliberately a strict superset of 'assessments' —
     * there is no level that removes grades while leaving risk_results
     * behind, which is the exact gap hand-written SQL exploited.
     */
    private const LEVELS = [
        'assessments' => ['assessment_scores', 'assessments'],
        'academic' => [
            'assessment_scores',
            'assessments',
            'grades',
            'risk_results',
            'report_submissions',
            'interventions',
            'academic_terms',
            'activity_logs',
        ],
    ];

    public function handle(): int
    {
        $level = $this->option('level');

        if (!isset(self::LEVELS[$level])) {
            $this->error("Unknown level '{$level}'. Valid levels: " . implode(', ', array_keys(self::LEVELS)) . '.');
            return self::FAILURE;
        }

        $tables = self::LEVELS[$level];

        $rows = collect($tables)->map(fn($table) => [$table, DB::table($table)->count()])->all();
        $this->warn("Level '{$level}' will TRUNCATE the following tables:");
        $this->table(['Table', 'Current Rows'], $rows);

        if ($level === 'assessments') {
            $this->line('Note: risk_results and report_submissions are NOT touched at this level. If either has rows, they will describe grades that no longer exist after this — run `php artisan dss:check-integrity` afterward to confirm.');
        }

        if (!$this->confirm('Truncate these tables?', false)) {
            $this->warn('Cancelled — nothing truncated.');
            return self::SUCCESS;
        }

        $driver = DB::connection()->getDriverName();
        $this->toggleForeignKeyChecks($driver, false);
        foreach ($tables as $table) {
            DB::table($table)->truncate();
        }
        $this->toggleForeignKeyChecks($driver, true);

        $this->info('Truncated: ' . implode(', ', $tables));

        return self::SUCCESS;
    }

    /**
     * `SET FOREIGN_KEY_CHECKS` is MySQL-only syntax — SQLite (used by the
     * automated test suite) uses `PRAGMA foreign_keys` instead, and other
     * drivers may support neither, so this is a best-effort toggle.
     */
    private function toggleForeignKeyChecks(string $driver, bool $enabled): void
    {
        match ($driver) {
            'mysql' => DB::statement('SET FOREIGN_KEY_CHECKS=' . ($enabled ? '1' : '0')),
            'sqlite' => DB::statement('PRAGMA foreign_keys = ' . ($enabled ? 'ON' : 'OFF')),
            default => null,
        };
    }
}
