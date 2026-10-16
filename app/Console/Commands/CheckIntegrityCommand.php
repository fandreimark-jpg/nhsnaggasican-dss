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
use App\Services\AcademicMonitoringService;
use App\Services\GradingEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
                    'Legacy (archived) risk_results with no surviving grades',
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

        // 4b. A learner with BOTH a score and a Missing marker on one item.
        // AssessmentStatusService and both models refuse this; only a raw
        // write can produce it. The score wins on screen (a score is
        // Completed), but the stale marker is reported, never auto-removed.
        $scoredAndMissing = DB::table('assessment_learner_statuses')
            ->join('assessment_scores', function ($join) {
                $join->on('assessment_scores.assessment_id', '=', 'assessment_learner_statuses.assessment_id')
                     ->on('assessment_scores.student_id', '=', 'assessment_learner_statuses.student_id');
            })
            ->count();
        if ($scoredAndMissing > 0) {
            $findings[] = [
                'Learners with both a score and a Missing marker on one item',
                "{$scoredAndMissing} row(s)",
                'Review in Adviser > Assessments (Edit the item) — no automatic command; the recorded score is kept.',
            ];
        }

        // 4d. A current section whose adviser account no longer has the
        // Adviser role (e.g. the account was later changed to Subject
        // Teacher): advisory pages need role:adviser, so nobody can open
        // that section's advisory view or verify its grades. Reported, never
        // reassigned automatically — Admin > Sections decides.
        $activeYear = \App\Models\Section::activeSchoolYear();
        $strandedSections = DB::table('sections')->join('users', 'users.id', '=', 'sections.adviser_id')
            ->where('users.role', '!=', 'adviser')
            ->when($activeYear, fn($q) => $q->where('sections.school_year', '>=', $activeYear))
            ->get(['sections.name', 'sections.school_year', 'users.role']);
        if ($strandedSections->isNotEmpty()) {
            $findings[] = [
                'Current sections whose adviser account is not an Adviser',
                $strandedSections->map(fn($s) => $s->name . ' (' . $s->school_year . ', account role: ' . $s->role . ')')->join('; '),
                'Admin > Sections: assign an Adviser account to the section (or restore the account\'s Adviser role on Admin > Users).',
            ];
        }

        // 4e. Current teaching assignments held by an account that cannot
        // teach (admin/principal) or is disabled: no one can encode that class.
        $strandedAssignments = DB::table('teaching_assignments')->join('users', 'users.id', '=', 'teaching_assignments.user_id')
            ->where(fn($q) => $q->whereNotIn('users.role', ['adviser', 'subject_teacher'])->orWhere('users.is_active', false))
            ->when($activeYear, fn($q) => $q->where('teaching_assignments.school_year', '>=', $activeYear))
            ->count();
        if ($strandedAssignments > 0) {
            $findings[] = [
                'Current teaching assignments held by an account that cannot teach (wrong role or disabled)',
                "{$strandedAssignments} row(s)",
                'Admin > Teaching Assignments: Edit / Change Teacher.',
            ];
        }

        // 4f. Grading blockers in existing data (GradingEngine computes no
        // Examination figure / no grade for these; nothing is deleted here).
        $duplicateExamTypes = DB::table('assessments')->join('sections', 'sections.id', '=', 'assessments.section_id')
            ->join('subjects', 'subjects.id', '=', 'assessments.subject_id')
            ->where('assessments.component', 'examination')->whereNotNull('assessments.exam_role')->where('assessments.is_additional_support', false)
            ->groupBy('sections.name', 'subjects.name', 'assessments.school_year', 'assessments.grading_period', 'assessments.exam_role')
            ->havingRaw('COUNT(*) > 1')
            ->get(['sections.name as section', 'subjects.name as subject', 'assessments.school_year', 'assessments.grading_period', 'assessments.exam_role', DB::raw('COUNT(*) as n')]);
        if ($duplicateExamTypes->isNotEmpty()) {
            $findings[] = [
                'Classes with more than one regular item of one Examination Type (no Examination figure is computed)',
                $duplicateExamTypes->map(fn($d) => "{$d->section} / {$d->subject} ({$d->school_year}, Term {$d->grading_period}): {$d->n} × " . \App\Services\ExaminationRoles::label($d->exam_role))->join('; '),
                'Class Record: Edit the extra item to its correct Examination Type (Edit Assessment).',
            ];
        }
        $unconfigured = DB::table('subjects')->join('deped_subject_catalog', 'deped_subject_catalog.id', '=', 'subjects.catalog_id')
            ->where('deped_subject_catalog.teacher_supplied', true)->pluck('subjects.name');
        if ($unconfigured->isNotEmpty()) {
            $findings[] = [
                'Subjects linked to the "Other elective / special curricular program" row (no configured weights, so no grade is computed)',
                $unconfigured->join('; '),
                'Admin > Subjects: link each to its own DepEd catalog subject, or record the school\'s decision on its weights.',
            ];
        }

        // 4c. Academic concerns out of step with the current evidence — a
        // dry run of the monitoring rules, so nothing is written. Guarded:
        // this command must keep working on a database where the
        // academic_concerns migration has not been applied yet.
        if (Schema::hasTable('academic_concerns')) {
            $monitoring = app(AcademicMonitoringService::class);
            $contexts = DB::table('assessment_learner_statuses')
                ->join('assessments', 'assessments.id', '=', 'assessment_learner_statuses.assessment_id')
                ->select('assessments.school_year', 'assessments.grading_period')
                ->union(DB::table('academic_concerns')->where('status', 'open')->select('school_year', 'grading_period'))
                ->union(DB::table('grades')->where('is_verified', true)->select('school_year', 'grading_period'))
                ->get()
                ->unique(fn($row) => $row->school_year . '|' . $row->grading_period);

            foreach ($contexts as $row) {
                $plan = $monitoring->evaluate($row->school_year, (int) $row->grading_period, null, dryRun: true);
                if ($plan['created'] || $plan['refreshed'] || $plan['resolved']) {
                    $findings[] = [
                        'Academic concerns out of date with current evidence',
                        count($plan['created']) . ' to open, ' . count($plan['refreshed']) . ' to refresh, '
                            . count($plan['resolved']) . " to resolve — school year {$row->school_year}, Term {$row->grading_period}",
                        "php artisan dss:evaluate-concerns {$row->school_year} {$row->grading_period} --dry-run, then without --dry-run",
                    ];
                }
            }
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
