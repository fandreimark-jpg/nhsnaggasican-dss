<?php

namespace App\Console\Commands;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Section;
use App\Services\AcademicMonitoringService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Runs academic monitoring for one school year and term. With --dry-run it
 * reports what WOULD be opened, refreshed and resolved and writes nothing.
 *
 * Never creates an intervention — a concern is For Review evidence for
 * the Principal, who decides.
 */
class EvaluateConcernsCommand extends Command
{
    protected $signature = 'dss:evaluate-concerns
        {school_year : e.g. 2026-2027}
        {term : 1, 2, or 3}
        {--section= : limit to one section id of that school year}
        {--dry-run : report what would change and write nothing}';

    protected $description = 'Evaluate the enabled academic monitoring rules for a school year/term: open, refresh and resolve Academic Concerns. --dry-run writes nothing. Never creates an intervention.';

    public function handle(AcademicMonitoringService $monitoring): int
    {
        $schoolYear = (string) $this->argument('school_year');
        $term = (int) $this->argument('term');
        $sectionId = $this->option('section') !== null ? (int) $this->option('section') : null;
        $dryRun = (bool) $this->option('dry-run');

        if (!Schema::hasTable('academic_concerns')) {
            $this->error('The academic_concerns table does not exist yet — its migration has not been applied to this database.');
            return self::FAILURE;
        }

        if (!AcademicYear::where('school_year', $schoolYear)->exists()) {
            $this->error("Unknown school year '{$schoolYear}'.");
            return self::FAILURE;
        }

        if (!in_array($term, AcademicTerm::termNumbers(), true)) {
            $this->error("Unknown term '{$this->argument('term')}'. Valid terms: " . implode(', ', AcademicTerm::termNumbers()) . '.');
            return self::FAILURE;
        }

        if ($sectionId !== null && !Section::whereKey($sectionId)->where('school_year', $schoolYear)->exists()) {
            $this->error("Section {$sectionId} does not belong to school year {$schoolYear}.");
            return self::FAILURE;
        }

        $rules = $monitoring->rules();
        if ($rules === []) {
            $this->warn('No monitoring rules are enabled (config dss.monitoring_rules). Nothing to evaluate.');
            return self::SUCCESS;
        }

        $this->info(($dryRun ? 'DRY RUN — nothing will be written. ' : '')
            . "Evaluating {$schoolYear}, Term {$term}" . ($sectionId ? ", section {$sectionId}" : ', every section')
            . ' — rules: ' . implode(', ', array_map(fn($r) => $r->key() . ' v' . $r->version(), $rules)) . '.');

        $report = $monitoring->evaluate($schoolYear, $term, $sectionId, $dryRun);

        $rows = [];
        foreach ($report['created'] as $c) {
            $rows[] = [$dryRun ? 'Would open' : 'Opened', $c['rule'], $c['summary']];
        }
        foreach ($report['refreshed'] as $c) {
            $rows[] = [$dryRun ? 'Would refresh' : 'Refreshed', $c['rule'], '#' . $c['concern_id'] . ' — ' . $c['summary']];
        }
        foreach ($report['resolved'] as $c) {
            $rows[] = [$dryRun ? 'Would resolve' : 'Resolved', $c['rule'], '#' . $c['concern_id'] . ' — ' . $c['reason']];
        }

        if ($rows !== []) {
            $this->table(['Action', 'Rule', 'Detail'], $rows);
        }

        $this->line(sprintf(
            '%s: %d opened, %d refreshed, %d unchanged, %d resolved.',
            $dryRun ? 'Would change' : 'Done',
            count($report['created']), count($report['refreshed']), $report['unchanged'], count($report['resolved'])
        ));

        return self::SUCCESS;
    }
}
