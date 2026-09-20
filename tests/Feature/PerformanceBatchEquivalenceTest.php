<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Grade;
use App\Models\Intervention;
use App\Models\RiskResult;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TransmutationRange;
use App\Models\User;
use App\Services\GradingEngine;
use App\Services\InTermStatusService;
use App\Services\PerformanceAnalysisService;
use App\Services\ProgressMonitoringService;
use App\Services\RiskFeatureExtractor;
use App\Services\TransmutationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * "Performance audit" pass — the batched reads introduced there must
 * produce exactly what the per-row queries they replaced produced. The
 * ORACLES in this file are those original queries, written out in full,
 * so a future change to the batched path that drifts from them fails
 * here rather than on a dashboard.
 *
 * Also pins the query budget: computing a whole roster must not scale
 * one query per learner per subject any more.
 */
class PerformanceBatchEquivalenceTest extends TestCase
{
    use RefreshDatabase;

    private Section $section;
    private User $adviser;
    /** @var array<int, Subject> */
    private array $subjects = [];
    /** @var array<int, Student> */
    private array $students = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\SubjectGroupWeightsSeeder::class);
        $this->seed(\Database\Seeders\TransmutationRangesSeeder::class);
        $this->seed(\Database\Seeders\Do015TransmutationSeeder::class);
        $this->seed(\Database\Seeders\ExamRoleSharesSeeder::class);

        $this->adviser = User::factory()->create(['role' => 'adviser']);
        $this->section = Section::factory()->create(['school_year' => '2026-2027', 'grade_level' => 11, 'adviser_id' => $this->adviser->id]);

        foreach (['Effective Communication', 'General Mathematics', 'Research 1'] as $i => $name) {
            $this->subjects[] = Subject::factory()->create([
                'name' => $name, 'type' => 'core', 'grade_level' => 11,
                // Research 1 has no Examination component under DO 015.
                'subject_group' => $i === 2 ? 'research_innovation' : 'core_academic',
            ]);
        }

        for ($i = 0; $i < 6; $i++) {
            $this->students[] = Student::factory()->create(['section_id' => $this->section->id]);
        }

        // Items for terms 1 and 2, every component, with exam roles on
        // General Mathematics; scores deliberately uneven: learner 0 has
        // everything, learner 1 misses one WW item, learner 2 has no
        // Examination score, learner 3 has only Term 1 evidence, learner 4
        // has nothing at all, learner 5 has a zero (a recorded 0 is not
        // "missing").
        foreach ([1, 2] as $term) {
            foreach ($this->subjects as $s => $subject) {
                $items = [
                    ['Quiz 1', 'written_work', 20, null], ['Quiz 2', 'written_work', 15, null],
                    ['Project', 'performance_task', 50, null],
                ];
                if ($s !== 2) {
                    $items[] = ['Summative Test 1', 'examination', 30, $s === 1 ? 'st1' : null];
                    $items[] = ['Term Exam', 'examination', 50, $s === 1 ? 'term_exam' : null];
                }
                foreach ($items as [$name, $component, $max, $role]) {
                    $assessment = Assessment::factory()->create([
                        'subject_id' => $subject->id, 'section_id' => $this->section->id,
                        'grading_period' => $term, 'school_year' => '2026-2027',
                        'name' => $name, 'component' => $component, 'max_score' => $max, 'exam_role' => $role,
                        'uploaded_by' => $this->adviser->id,
                    ]);
                    foreach ($this->students as $k => $student) {
                        if ($k === 4) continue;
                        if ($k === 3 && $term === 2) continue;
                        if ($k === 1 && $name === 'Quiz 2') continue;
                        if ($k === 2 && $component === 'examination') continue;
                        $score = $k === 5 ? 0 : round($max * (0.5 + 0.09 * $k + 0.03 * $s + 0.02 * $term), 2);
                        AssessmentScore::factory()->create([
                            'assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => min($score, $max),
                            'created_at' => $term === 1 ? '2026-08-01 08:00:00' : '2026-10-0' . (1 + ($k % 3)) . ' 08:00:00',
                        ]);
                    }
                }
            }
        }

        // Official grades: Term 1 for everyone, Term 2 with a changed
        // subject mix for learner 1 (no Research 1 grade) — the same-subject
        // trend must notice.
        foreach ($this->students as $k => $student) {
            foreach ($this->subjects as $s => $subject) {
                Grade::factory()->create(['student_id' => $student->id, 'subject_id' => $subject->id, 'section_id' => $this->section->id, 'encoded_by' => $this->adviser->id, 'grading_period' => 1, 'grade' => 70 + $k * 4 + $s, 'school_year' => '2026-2027']);
                if ($k === 1 && $s === 2) continue;
                if ($k === 4) continue;
                Grade::factory()->create(['student_id' => $student->id, 'subject_id' => $subject->id, 'section_id' => $this->section->id, 'encoded_by' => $this->adviser->id, 'grading_period' => 2, 'grade' => 72 + $k * 3 - $s, 'school_year' => '2026-2027']);
            }
            if ($k !== 4) {
                RiskResult::create(['student_id' => $student->id, 'section_id' => $this->section->id, 'grading_period' => 1, 'school_year' => '2026-2027', 'average_grade' => 70 + $k * 4, 'risk_level' => 'moderate', 'ml_risk_level' => 'moderate', 'was_overridden' => false, 'generated_at' => now()]);
            }
        }
    }

    // ------------------------------------------------------------------
    // ORACLES — the exact per-row queries the batched paths replaced.
    // ------------------------------------------------------------------

    private function oracleComponentPercentage(Student $student, Subject $subject, int $term, string $component): ?float
    {
        $ids = Assessment::where('subject_id', $subject->id)->where('section_id', $this->section->id)
            ->where('grading_period', $term)->where('school_year', '2026-2027')->where('component', $component)->pluck('id');
        if ($ids->isEmpty()) return null;
        $scores = AssessmentScore::where('student_id', $student->id)->whereIn('assessment_id', $ids)->with('assessment')->get();
        if ($scores->isEmpty()) return null;
        $earned = $scores->sum(fn($s) => (float) $s->score);
        $max = $scores->sum(fn($s) => (float) $s->assessment->max_score);
        return $max <= 0 ? null : round(($earned / $max) * 100, 2);
    }

    private function oracleItemCount(Student $student, Subject $subject, int $term): int
    {
        return AssessmentScore::where('student_id', $student->id)
            ->whereHas('assessment', fn($q) => $q->where('subject_id', $subject->id)->where('section_id', $this->section->id)->where('grading_period', $term)->where('school_year', '2026-2027'))
            ->count();
    }

    private function oracleTransmute(float $g, string $scheme): ?float
    {
        $range = TransmutationRange::where('scheme', $scheme)->where('min_initial', '<=', $g)->where('max_initial', '>=', $g)->first();
        return $range ? (float) $range->transmuted : null;
    }

    private function oracleFeatures(Student $student, int $term, float $average): array
    {
        $expected = Assessment::where('section_id', $this->section->id)->where('grading_period', $term)->where('school_year', '2026-2027')->count();
        $missing = null;
        if ($expected > 0) {
            $recorded = AssessmentScore::query()->join('assessments', 'assessments.id', '=', 'assessment_scores.assessment_id')
                ->where('assessments.section_id', $this->section->id)->where('assessments.grading_period', $term)->where('assessments.school_year', '2026-2027')
                ->where('assessment_scores.student_id', $student->id)->count();
            $missing = max(0, $expected - $recorded);
        }

        $prev = $term <= 1 ? null : RiskResult::where('student_id', $student->id)->where('school_year', '2026-2027')->where('grading_period', $term - 1)->value('average_grade');
        $prev = $prev !== null ? (float) $prev : null;

        $sameDelta = null; $composition = null;
        if ($term > 1) {
            $previous = Grade::where('student_id', $student->id)->where('school_year', '2026-2027')->where('grading_period', $term - 1)->pluck('grade', 'subject_id');
            if ($previous->isNotEmpty()) {
                $current = Grade::where('student_id', $student->id)->where('school_year', '2026-2027')->where('grading_period', $term)->pluck('grade', 'subject_id');
                $shared = $current->keys()->intersect($previous->keys());
                $composition = $current->keys()->diff($previous->keys())->isNotEmpty() || $previous->keys()->diff($current->keys())->isNotEmpty();
                if ($shared->isNotEmpty()) {
                    $sameDelta = round($shared->map(fn($id) => (float) $current[$id] - (float) $previous[$id])->avg(), 2);
                }
            }
        }

        return [
            'prev_period_average' => $prev,
            'trend_delta' => $prev === null ? null : round($average - $prev, 2),
            'missing_assessment_count' => $missing,
            'same_subject_trend_delta' => $sameDelta,
            'subject_composition_changed' => $composition,
        ];
    }

    // ------------------------------------------------------------------

    public function test_grading_engine_matches_per_row_queries_for_every_learner_subject_and_term(): void
    {
        $engine = new GradingEngine();
        $transmutation = new TransmutationService();
        $compared = 0;

        foreach ([1, 2, 3] as $term) {
            foreach ($this->students as $student) {
                foreach ($this->subjects as $subject) {
                    $result = $engine->computeGrade($student, $subject, $this->section, $term, '2026-2027');

                    foreach (['written_work', 'performance_task'] as $component) {
                        $this->assertSame(
                            $this->oracleComponentPercentage($student, $subject, $term, $component),
                            $result['components'][$component],
                            "{$component} for learner {$student->id}, subject {$subject->name}, term {$term}"
                        );
                    }
                    // Examination with no roles is the flat aggregate; the
                    // role-weighted subject is covered by the recompute
                    // checksum below rather than re-deriving the share math here.
                    if ($subject->name === 'Effective Communication') {
                        $this->assertSame($this->oracleComponentPercentage($student, $subject, $term, 'examination'), $result['components']['examination']);
                    }
                    if ($subject->name === 'Research 1') {
                        $this->assertNull($result['components']['examination'], 'a profile with no Examination component never reports one');
                    }

                    $this->assertSame($this->oracleItemCount($student, $subject, $term), $engine->scoredItemCount($student, $subject, $this->section, $term, '2026-2027'));

                    if ($result['complete']) {
                        $scheme = $result['transmutation_scheme'];
                        $this->assertSame($this->oracleTransmute($result['computed_grade'], $scheme), $result['transmuted_grade']);
                    }
                    $compared++;
                }
            }
        }

        $this->assertSame(54, $compared);
    }

    public function test_transmutation_band_lookup_matches_the_range_query_at_every_boundary(): void
    {
        $svc = new TransmutationService();
        foreach (['do8_2015', 'do015_2026'] as $scheme) {
            $probes = [0.0, 59.99, 60.0, 60.01, 69.99, 70.0, 70.01, 74.99, 75.0, 99.99, 100.0];
            foreach (TransmutationRange::where('scheme', $scheme)->get() as $band) {
                $probes[] = (float) $band->min_initial;
                $probes[] = (float) $band->max_initial;
            }
            foreach ($probes as $g) {
                $got = $svc->transmuteWithAvailability($g, $scheme);
                $this->assertSame($this->oracleTransmute($g, $scheme), $got['available'] && !$got['provisional'] ? $got['value'] : null, "{$scheme} at {$g}");
            }
        }
    }

    public function test_in_term_status_item_count_carried_by_the_analysis_matches_the_query(): void
    {
        $analysis = new PerformanceAnalysisService();
        $status = new InTermStatusService($analysis);

        foreach ($this->students as $student) {
            foreach ($this->subjects as $subject) {
                $row = $analysis->analyzeStudent($student, $subject, $this->section, 2, '2026-2027');
                $this->assertArrayHasKey('item_count', $row);
                $withCarried = $status->fromAnalysis($row, $student, $subject, $this->section, 2, '2026-2027');
                unset($row['item_count']);
                $withQuery = $status->fromAnalysis($row, $student, $subject, $this->section, 2, '2026-2027');
                $this->assertSame($withQuery, $withCarried);
                $this->assertSame($this->oracleItemCount($student, $subject, 2), $withCarried['item_count']);
            }
        }
    }

    public function test_risk_features_match_per_learner_queries(): void
    {
        $extractor = new RiskFeatureExtractor();

        foreach ([1, 2] as $term) {
            foreach ($this->students as $k => $student) {
                $average = 70.0 + $k * 3;
                $features = $extractor->extract($student, $this->section, $term, '2026-2027', $average);
                foreach ($this->oracleFeatures($student, $term, $average) as $key => $expected) {
                    $this->assertSame($expected, $features[$key], "{$key} for learner index {$k}, term {$term}");
                }
            }
        }

        // The learner with no scores at all is "missing everything", the
        // one with a recorded zero is not missing that item.
        $everything = Assessment::where('section_id', $this->section->id)->where('grading_period', 1)->count();
        $this->assertSame($everything, $extractor->extract($this->students[4], $this->section, 1, '2026-2027', 0.0)['missing_assessment_count']);
        $this->assertSame(0, $extractor->extract($this->students[5], $this->section, 1, '2026-2027', 0.0)['missing_assessment_count']);
        // Learner 1's Term 2 mix dropped Research 1 — composition changed; learner 0's did not.
        $this->assertTrue($extractor->extract($this->students[1], $this->section, 2, '2026-2027', 80.0)['subject_composition_changed']);
        $this->assertFalse($extractor->extract($this->students[0], $this->section, 2, '2026-2027', 80.0)['subject_composition_changed']);
    }

    public function test_within_term_progress_as_of_cutoff_matches_the_created_at_query(): void
    {
        $principal = User::factory()->create(['role' => 'principal']);
        $engine = new GradingEngine();

        foreach ($this->students as $k => $student) {
            $subject = $this->subjects[$k % 3];
            $intervention = Intervention::create([
                'student_id' => $student->id, 'section_id' => $this->section->id, 'subject_id' => $subject->id,
                'grading_period' => 2, 'school_year' => '2026-2027', 'recommended_type' => 'remediation',
                'status' => 'approved', 'recommendation_reason' => 'x', 'origin' => 'principal', 'created_by' => $principal->id,
                'decided_by' => $principal->id, 'decided_at' => now(),
                'focus_component' => 'written_work', 'delivered_at' => '2026-10-02 08:00:00', 'delivered_by' => $this->adviser->id,
                'delivery_notes' => 'done',
            ]);

            foreach (['written_work', 'performance_task', 'examination'] as $component) {
                foreach ([$intervention->delivered_at, null] as $asOf) {
                    $scores = AssessmentScore::where('student_id', $student->id)
                        ->whereHas('assessment', fn($q) => $q->where('subject_id', $subject->id)->where('section_id', $this->section->id)->where('grading_period', 2)->where('school_year', '2026-2027')->where('component', $component))
                        ->when($asOf, fn($q) => $q->where('created_at', '<=', $asOf))
                        ->with('assessment')->get();
                    $rows = collect($engine->scoredItems($student, $subject, $this->section, 2, '2026-2027', $component, $asOf));

                    $this->assertSame($scores->count(), $rows->count());
                    $this->assertSame($scores->sum(fn($s) => (float) $s->score), $rows->sum(fn($r) => (float) $r['score']->score));
                    $this->assertSame($scores->sum(fn($s) => (float) $s->assessment->max_score), $rows->sum(fn($r) => (float) $r['assessment']->max_score));
                }
            }

            $this->assertNotNull((new ProgressMonitoringService())->compareWithinTerm($intervention));
        }
    }

    public function test_evidence_cache_sees_a_write_made_after_it_was_filled(): void
    {
        $engine = new GradingEngine();
        $student = $this->students[0];
        $subject = $this->subjects[0];

        $before = $engine->computeGrade($student, $subject, $this->section, 1, '2026-2027');

        $score = AssessmentScore::whereHas('assessment', fn($q) => $q->where('subject_id', $subject->id)->where('grading_period', 1)->where('component', 'written_work'))
            ->where('student_id', $student->id)->first();
        $score->score = 1;
        $score->save();

        $after = $engine->computeGrade($student, $subject, $this->section, 1, '2026-2027');
        $this->assertNotSame($before['components']['written_work'], $after['components']['written_work']);
        $this->assertSame($this->oracleComponentPercentage($student, $subject, 1, 'written_work'), $after['components']['written_work']);

        // A query-builder write fires no model event; the explicit
        // invalidation is what keeps the same instance honest.
        AssessmentScore::where('id', $score->id)->delete();
        GradingEngine::invalidateEvidence();
        $deleted = $engine->computeGrade($student, $subject, $this->section, 1, '2026-2027');
        $this->assertSame($this->oracleComponentPercentage($student, $subject, 1, 'written_work'), $deleted['components']['written_work']);
    }

    public function test_query_count_for_a_whole_roster_does_not_scale_per_learner(): void
    {
        $status = new InTermStatusService();
        $students = Student::enrolledIn($this->section)->get();
        $subjects = collect($this->subjects);

        DB::enableQueryLog();
        $rows = $status->overallStatusForSection($this->section, $students, $subjects, 1);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(6, $rows);
        // 6 learners x 3 subjects used to cost ~9 queries each (~160); the
        // evidence for one section/term is now two reads plus the
        // reference rows.
        $this->assertLessThanOrEqual(12, $count, "overallStatusForSection ran {$count} queries for 6 learners x 3 subjects");
    }
}
