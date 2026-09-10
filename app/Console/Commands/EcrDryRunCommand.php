<?php

namespace App\Console\Commands;

use App\Models\Subject;
use App\Services\EcrProfileDetector;
use App\Services\EcrReaderService;
use Illuminate\Console\Command;

/**
 * "ECR alignment" work order, PART 5 — reports what an upload of the given
 * DepEd Strengthened SHS Electronic Class Record workbook WOULD do, against
 * whatever subject the school year/grade/section/course title actually
 * resolves to locally. Writes NOTHING: no Student, Subject, Assessment,
 * AssessmentScore, or AssessmentUpload row is created, updated, or even
 * queried for a write. Every query this command makes is read-only.
 *
 * This is deliberately the ONLY way this work order's Part 5 exercises a
 * real workbook — the actual import (writing Assessment/AssessmentScore
 * rows) only ever happens through the normal Adviser upload screens, by a
 * human, after their own backup.
 */
class EcrDryRunCommand extends Command
{
    protected $signature = 'dss:ecr-dry-run {path : Path to the .xlsx file} {--term=1 : Which Term sheet to read (1, 2, or 3)}';

    protected $description = 'Read-only dry run of a DepEd Strengthened SHS E-Class Record workbook — reports what an upload would do. Writes nothing.';

    public function handle(EcrProfileDetector $detector, EcrReaderService $reader): int
    {
        $path = $this->argument('path');
        $term = (int) $this->option('term');

        if (!is_file($path)) {
            $this->error("No such file: {$path}");
            return self::FAILURE;
        }

        if (!in_array($term, [1, 2, 3], true)) {
            $this->error('--term must be 1, 2, or 3.');
            return self::FAILURE;
        }

        $version = $detector->detect($path);
        if ($version === null) {
            $this->warn('This file does not match the DepEd ECR profile (sheet names, HELPER!B4, INPUT DATA!T64) — the existing flat reader would handle it instead. Nothing more to report here.');
            return self::SUCCESS;
        }

        $this->info("ECR profile detected — version {$version}.");
        $this->newLine();

        $meta = $reader->describe($path);
        $this->table(['Field', 'Value'], [
            ['Teacher', $meta['teacher'] ?: '(blank)'],
            ['Grade level', $meta['grade_level'] ?? '(blank)'],
            ['Section', $meta['section_name'] ?: '(blank)'],
            ['Subject category (track)', $meta['subject_category'] ?: '(blank)'],
            ['Cluster', $meta['cluster'] ?: '(blank)'],
            ['Course title', $meta['course_title'] ?: '(blank)'],
            ['Other Elective?', $meta['is_other_elective'] ? 'yes' : 'no'],
            ['Roster entries found', $meta['roster_count']],
        ]);

        if ($meta['roster_count'] === 0) {
            $this->warn('No roster entries found — INPUT DATA has no learner names/LRNs filled in. This is expected for a blank template; nothing further to report.');
            return self::SUCCESS;
        }

        $this->newLine();
        $this->info("Reading Term {$term}...");
        $rows = $reader->toFlatRows($path, $term);
        $skipped = $reader->skippedItemCounts();

        if (count($rows) < 2) {
            $this->warn("Term {$term} sheet not found or produced no rows.");
            return self::SUCCESS;
        }

        [$header, $maxRow] = [$rows[0], $rows[1]];
        $studentRows = array_slice($rows, 2);

        $itemCount = count($header) - 3;
        $this->info("{$itemCount} assessment item(s) found with at least one score, across " . count($studentRows) . ' roster entries:');
        $this->table(
            array_slice($header, 3),
            [array_slice($maxRow, 3)]
        );
        $this->line('(row above is the highest possible score per item)');

        $this->newLine();
        $this->info('Skipped (no score from anyone, per PART 5c — not counted as items):');
        $this->table(['Component', 'Skipped slots'], [
            ['Written Work', $skipped['written_work']],
            ['Performance Task', $skipped['performance_task']],
            ['Examination', $skipped['examination']],
        ]);

        // Roster reconciliation (5e) — read-only comparison against
        // whatever section name was typed in the file, matched by name
        // only (this command never assumes which local Section the file
        // belongs to; it only reports what it can see).
        $this->newLine();
        $sampleLrns = collect($studentRows)->pluck(0)->filter()->take(5);
        if ($sampleLrns->isNotEmpty()) {
            $existing = \App\Models\Student::whereIn('lrn', $sampleLrns)->pluck('lrn');
            $this->info('Sample LRN check (first 5 in file) — already in this database: ' . ($existing->isEmpty() ? 'none' : $existing->implode(', ')));
        }

        // Weight cross-check (5f) — only meaningful once a real local
        // Subject is identified; this command doesn't assume one, so it
        // only runs the check if a subject with this exact course title
        // already exists locally.
        if ($meta['course_title'] !== '') {
            $subject = Subject::whereRaw('LOWER(name) = ?', [strtolower($meta['course_title'])])->first();
            if ($subject) {
                $warning = $reader->checkWeightMismatch($path, $subject);
                $this->newLine();
                $this->info($warning ? "Weight cross-check: {$warning}" : "Weight cross-check: agrees with locally-resolved weights for \"{$subject->name}\".");
            }
        }

        $this->newLine();
        $this->info('Dry run complete. Nothing was written.');

        return self::SUCCESS;
    }
}
