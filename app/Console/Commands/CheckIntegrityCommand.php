<?php

namespace App\Console\Commands;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\AssessmentScore;
use App\Models\Grade;
use App\Models\Intervention;
use App\Models\ReportSubmission;
use App\Models\RiskResult;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Services\GradingEngine;
use Illuminate\Console\Command;

/**
 * Reports orphaned/inconsistent state WITHOUT changing anything — see
 * the "live in-term risk + stale data guard" prompt (Problem 1b). Every
 * check here describes a shape that could only be produced by
 * hand-written SQL bypassing this app's foreign-key constraints (a
 * normal delete through Eloquent, or php artisan dss:truncate, keeps
 * these consistent by construction) — the exact mistake that caused the
 * original incident. Meant to be run before a demo, or any time
 * something on screen looks wrong.
 */
class CheckIntegrityCommand extends Command
{
    protected $signature = 'dss:check-integrity';

    protected $description = 'Reports orphaned/stale data (risk results, submissions, interventions, assessment scores, students) without changing anything. Exits non-zero if anything is found.';

    public function handle(GradingEngine $gradingEngine): int
    {
        $findings = [];

        // 1. Risk results whose term has no grades. Iterated from
        // risk_results itself, not academic_terms — a stale row can
        // outlive its academic_terms row too if that was wiped alongside
        // grades, and this must still catch it.
        foreach (RiskResult::query()->select('school_year')->distinct()->pluck('school_year') as $schoolYear) {
            foreach (AcademicTerm::staleRiskTerms($schoolYear) as $term) {
                $count = RiskResult::where('school_year', $schoolYear)->where('grading_period', $term)->count();
                $findings[] = [
                    'Risk results with no surviving grades',
                    "{$count} row(s) — school year {$schoolYear}, Term {$term}",
                    'php artisan dss:truncate --level=academic',
                ];
            }
        }

        // 2. Report submissions whose term has no grades.
        foreach (ReportSubmission::query()->select('school_year', 'grading_period')->distinct()->get() as $row) {
            $hasGrades = Grade::where('school_year', $row->school_year)->where('grading_period', $row->grading_period)->exists();
            if (!$hasGrades) {
                $count = ReportSubmission::where('school_year', $row->school_year)->where('grading_period', $row->grading_period)->count();
                $findings[] = [
                    'Report submissions with no surviving grades',
                    "{$count} row(s) — school year {$row->school_year}, Term {$row->grading_period}",
                    'php artisan dss:truncate --level=academic',
                ];
            }
        }

        // 3. Interventions whose risk_result_id points at a missing row.
        $orphanedInterventions = Intervention::whereNotNull('risk_result_id')->whereDoesntHave('riskResult')->count();
        if ($orphanedInterventions > 0) {
            $findings[] = [
                'Interventions with a missing risk_result_id',
                "{$orphanedInterventions} row(s)",
                'php artisan dss:truncate --level=academic',
            ];
        }

        // 4. Assessment scores whose assessment no longer exists.
        $orphanedScores = AssessmentScore::whereDoesntHave('assessment')->count();
        if ($orphanedScores > 0) {
            $findings[] = [
                'Assessment scores with a missing assessment',
                "{$orphanedScores} row(s)",
                'php artisan dss:truncate --level=assessments',
            ];
        }

        // 5. Students whose section no longer exists.
        $orphanedStudents = Student::whereDoesntHave('section')->count();
        if ($orphanedStudents > 0) {
            $findings[] = [
                'Students with a missing section',
                "{$orphanedStudents} row(s)",
                'Reassign via Admin > Students, or remove them manually — no automatic command (this touches master data, never auto-resolved).',
            ];
        }

        // 6. "Multi-school-year academic history" work order — the
        //    year lifecycle invariants. More than one active academic year
        //    can only come from a hand edit (activate() locks the table);
        //    a student whose current section has no matching enrollment
        //    row can only come from a raw insert that bypassed
        //    Student::saved; a term row unlinked from its academic year
        //    can only come from a raw insert that bypassed ensureExistFor().
        $activeYears = AcademicYear::where('is_active', true)->count();
        if ($activeYears > 1) {
            $findings[] = [
                'More than one active academic year',
                "{$activeYears} row(s) flagged active",
                'Activate exactly one year from Admin > Academic Terms; activation deactivates every other year.',
            ];
        }

        $unenrolledCurrent = Student::whereNotNull('section_id')
            ->whereDoesntHave('enrollments', fn($q) => $q->whereColumn('student_enrollments.section_id', 'students.section_id'))
            ->count();
        if ($unenrolledCurrent > 0) {
            $findings[] = [
                'Students whose current section has no enrollment row',
                "{$unenrolledCurrent} row(s)",
                'Re-save the learner from Admin > Students (Edit), or run StudentEnrollmentService::ensureForSection() for the section.',
            ];
        }

        $unlinkedTerms = AcademicTerm::whereNull('academic_year_id')->count();
        if ($unlinkedTerms > 0) {
            $findings[] = [
                'Academic terms not linked to an academic year',
                "{$unlinkedTerms} row(s)",
                'Open Admin > Academic Terms once — AcademicTerm::ensureExistFor() links the active year; other years link on activation.',
            ];
        }

        // 7. Section elective choices (section_subjects) — a row's
        //    school_year must agree with both its section's and its academic
        //    term's (SubjectOfferingService::chooseElective() writes all
        //    three from the section, so a disagreement can only come from a
        //    hand edit); and every (section, subject, term) that holds
        //    academic records must still be resolved by the subject
        //    configuration — otherwise that history is invisible to the
        //    Adviser and Principal screens.
        $mismatchedOfferings = \App\Models\SectionSubject::query()
            ->join('sections', 'sections.id', '=', 'section_subjects.section_id')
            ->join('academic_terms', 'academic_terms.id', '=', 'section_subjects.academic_term_id')
            ->where(function ($q) {
                $q->whereColumn('section_subjects.school_year', '!=', 'sections.school_year')
                  ->orWhereColumn('section_subjects.school_year', '!=', 'academic_terms.school_year');
            })
            ->count();
        if ($mismatchedOfferings > 0) {
            $findings[] = [
                'Subject offerings whose school year disagrees with their section or term',
                "{$mismatchedOfferings} row(s)",
                'Correct section_subjects.school_year by hand to match the section — every consumer reads it as a join key.',
            ];
        }

        // "Subject applicability" refactor — academic records that sit on a
        // (section, subject, term) the CURRENT subject configuration no
        // longer resolves (a term removed from Terms Taught, a subject
        // moved to another grade/track, an elective choice removed). The
        // Admin form refuses such an edit while records exist, so this
        // only fires for rows written some other way; it never deletes.
        $unresolvedHistory = 0;
        $sectionsById = \App\Models\Section::all()->keyBy('id');
        foreach ($sectionsById as $section) {
            $resolvedByTerm = [];
            foreach (\App\Models\AcademicTerm::termNumbers() as $term) {
                $resolvedByTerm[$term] = \App\Models\Subject::forSection($section, $term)->pluck('id')->flip();
            }
            foreach (['grades', 'assessments', 'assessment_uploads'] as $table) {
                $pairs = \Illuminate\Support\Facades\DB::table($table)
                    ->where('section_id', $section->id)
                    ->where('school_year', $section->school_year)
                    ->select('subject_id', 'grading_period')
                    ->distinct()
                    ->get();
                foreach ($pairs as $pair) {
                    if (!isset($resolvedByTerm[(int) $pair->grading_period]) || !$resolvedByTerm[(int) $pair->grading_period]->has((int) $pair->subject_id)) {
                        $unresolvedHistory++;
                    }
                }
            }
        }
        if ($unresolvedHistory > 0) {
            $findings[] = [
                'Academic records on a (section, subject, term) the subject configuration no longer resolves',
                "{$unresolvedHistory} pair(s) across grades/assessments/uploads",
                "Restore the subject's Terms Taught / grade level / track (Admin > Subjects) or the section's elective choice (Sections > Subjects) so the history is visible again. Records are never deleted.",
            ];
        }

        $exitCode = self::SUCCESS;

        if (empty($findings)) {
            $this->info('No orphaned or inconsistent data found.');
        } else {
            $this->error(count($findings) . ' issue(s) found:');
            $this->table(['Issue', 'Detail', 'To resolve'], $findings);
            $exitCode = self::FAILURE;
        }

        // "ECR alignment" work order, PART 2d — GradingEngine::
        // resolveDo8GroupKey() routes a Grade-12 elective to a DO 8
        // Work-Immersion weighting bucket by matching its NAME against a
        // short keyword list, since no per-subject DO 8 catalog exists (see
        // that method's docblock). That is a heuristic, not a data
        // integrity problem — a subject that genuinely is named "Work
        // Immersion" is correctly routed — so this is a separate,
        // NON-FAILING listing printed after the pass/fail result above, not
        // counted toward this command's exit code. The point is that a
        // subject routed there by an accident of naming is visible here
        // rather than only showing up as an unexplained grade weight.
        $do8WorkImmersionRoutes = [];
        foreach (Subject::where('grade_level', 12)->where('type', 'elective')->get() as $subject) {
            $pseudoSection = new Section(['track_id' => $subject->track_id]);
            $slug = $gradingEngine->resolveDo8GroupKey($pseudoSection, $subject);
            if (str_ends_with($slug, '_work_immersion')) {
                $do8WorkImmersionRoutes[] = [$subject->name, $slug];
            }
        }

        if (!empty($do8WorkImmersionRoutes)) {
            $this->newLine();
            $this->line(count($do8WorkImmersionRoutes) . ' Grade 12 subject(s) routed to a DO 8 Work-Immersion weighting bucket by name — review, not necessarily wrong:');
            $this->table(['Subject', 'Resolved DO 8 group'], $do8WorkImmersionRoutes);
        }

        return $exitCode;
    }
}
