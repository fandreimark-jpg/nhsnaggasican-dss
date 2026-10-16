<?php

namespace Tests\Feature;

use App\Models\AcademicConcern;
use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\ActivityLog;
use App\Models\Assessment;
use App\Models\AssessmentEvidenceAudit;
use App\Models\AssessmentLearnerStatus;
use App\Models\AssessmentScore;
use App\Models\Grade;
use App\Models\Intervention;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeachingAssignment;
use App\Models\User;
use App\Http\Controllers\Admin\TeachingAssignmentController;
use App\Monitoring\Rules\MissingRequirementRule;
use App\Monitoring\Rules\OfficialFailingSubjectRule;
use App\Services\ClassRecordService;
use App\Services\MonitoringClassificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The final demo walkthrough (release-readiness pass, 2026-09-28), steps A-O
 * plus teacher replacement and a closed term, through the REAL HTTP routes,
 * in one continuous synthetic scenario. Nothing here is new behaviour; this
 * pins the demo exactly as it is meant to be shown.
 */
class DemoFlowEndToEndTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\UsesClassRecord;

    public function test_the_full_demo_flow_from_assignment_to_official_grade(): void
    {
        AcademicYear::create(['school_year' => '2026-2027', 'is_active' => true]);
        AcademicTerm::ensureExistFor('2026-2027');
        AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->update(['is_open' => true]);
        $this->seed(\Database\Seeders\TransmutationRangesSeeder::class);
        $this->seed(\Database\Seeders\Do015TransmutationSeeder::class);

        $admin = User::factory()->admin()->create();
        $principal = User::factory()->principal()->create();
        $bong = User::factory()->create(['role' => 'adviser', 'last_name' => 'Adviser', 'first_name' => 'Demo']);
        $carla = User::factory()->create(['role' => 'subject_teacher', 'last_name' => 'Teacher', 'first_name' => 'Demo']);
        $dino = User::factory()->create(['role' => 'subject_teacher', 'last_name' => 'Replacement', 'first_name' => 'Demo']);
        $agila = Section::factory()->create(['name' => 'Demo Section', 'grade_level' => 11, 'curriculum' => 'sshs', 'school_year' => '2026-2027', 'adviser_id' => $bong->id]);
        $other = Section::factory()->create(['name' => 'Other Section', 'grade_level' => 11, 'curriculum' => 'sshs', 'school_year' => '2026-2027']);
        [$science, $math] = collect(['Demo Science', 'Demo Mathematics'])->map(function ($name) {
            $s = Subject::factory()->create(['name' => $name, 'type' => 'core', 'grade_level' => 11, 'subject_group' => 'core_academic']);
            $s->syncTerms([1, 2, 3]);
            return $s;
        })->all();
        $learners = collect(['AAA', 'BBB', 'CCC', 'DDD'])->mapWithKeys(fn($n, $i) => [$n => Student::factory()->create([
            'section_id' => $agila->id, 'last_name' => $n, 'first_name' => 'LEARNER', 'lrn' => '10000000000' . $i])]);
        $outsider = Student::factory()->create(['section_id' => $other->id, 'lrn' => '100000000099']);

        // A. Admin records the teaching assignments.
        $this->actingAs($admin)->post(route('admin.teaching-assignments.store'), ['user_id' => $carla->id, 'section_id' => $agila->id, 'subject_id' => $science->id, 'terms' => [1]])
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('admin.teaching-assignments.store'), ['user_id' => $bong->id, 'section_id' => $agila->id, 'subject_id' => $math->id, 'terms' => [1]])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, TeachingAssignment::count());

        // B. The assigned teacher opens the right class; others read or are refused.
        $scienceUrl = route('teacher.class-record', ['section_id' => $agila->id, 'subject_id' => $science->id, 'period' => 1]);
        $this->actingAs($carla)->get(route('teacher.classes'))->assertOk()->assertSee('Demo Science');
        $this->actingAs($carla)->get($scienceUrl)->assertOk()->assertSee('data-can-edit="1"', false);
        $this->actingAs($bong)->get($scienceUrl)->assertOk()->assertSee('data-can-edit="0"', false); // advisory read only
        $this->actingAs($carla)->get(route('teacher.class-record', ['section_id' => $agila->id, 'subject_id' => $math->id, 'period' => 1]))->assertForbidden();
        $this->actingAs($principal)->get($scienceUrl)->assertForbidden();

        // C. Add Assessment creates the definition only.
        $this->addAssessment($carla, $agila, $science, ['item_name' => 'Quiz 1', 'component' => 'written_work', 'max_score' => 20])
            ->assertRedirect()->assertSessionHas('success', 'Assessment added. Enter scores in the Class Record.');
        $quiz = Assessment::where('name', 'Quiz 1')->sole();
        $this->assertSame([0, 0, 0], [AssessmentScore::count(), AssessmentLearnerStatus::count(), AcademicConcern::count()]);

        // D/E. Scores, zero, blank and explicit Missing; persisted after a reload.
        $this->saveCells($carla, $quiz, [$learners['AAA']->id => '18', $learners['BBB']->id => '0', $learners['CCC']->id => '', $learners['DDD']->id => 'M'])->assertOk();
        $record = (new ClassRecordService())->build($agila, $science, 1);
        $this->assertSame(18.0, (float) $record['scores'][$quiz->id][$learners['AAA']->id]);
        $this->assertSame(0.0, (float) $record['scores'][$quiz->id][$learners['BBB']->id]);
        $this->assertArrayNotHasKey($learners['CCC']->id, $record['scores'][$quiz->id] ?? [], 'blank is not a zero');
        $this->assertArrayHasKey($learners['CCC']->id, $record['missing'][$quiz->id], 'a blank saved with the assessment is Missing (automatic, 2026-10-04)');
        $this->assertArrayHasKey($learners['DDD']->id, $record['missing'][$quiz->id]);
        $this->actingAs($carla)->get($scienceUrl)->assertOk()->assertSee('value="18"', false);

        // F. Invalid and forged cells are refused and nothing changes.
        $before = [AssessmentScore::sum('score'), AssessmentScore::count(), AssessmentLearnerStatus::count()];
        $this->saveCells($carla, $quiz, [$learners['AAA']->id => '25'])->assertStatus(422);
        $this->saveCells($carla, $quiz, [$outsider->id => '10'])->assertStatus(422)->assertJsonFragment(['message' => 'Nothing was saved. 1 cell(s) need correcting.']);
        $mathItem = Assessment::factory()->create(['subject_id' => $math->id, 'section_id' => $agila->id, 'grading_period' => 1, 'school_year' => '2026-2027',
            'name' => 'Math probe', 'component' => 'written_work', 'max_score' => 10, 'uploaded_by' => $bong->id]);
        $this->actingAs($carla)->postJson(route('teacher.class-record.save'), ['section_id' => $agila->id, 'subject_id' => $science->id, 'period' => 1,
            'cells' => [['assessment_id' => $mathItem->id, 'student_id' => $learners['AAA']->id, 'score' => '5', 'missing' => false, 'base_score' => null, 'base_missing' => false]]])
            ->assertStatus(422);
        $this->saveCells($carla, $mathItem, [$learners['AAA']->id => '5'])->assertForbidden(); // not her subject
        $mathItem->delete();
        $this->assertSame($before, [AssessmentScore::sum('score'), AssessmentScore::count(), AssessmentLearnerStatus::count()]);

        // G. The Principal sees the exact Missing evidence.
        // CCC's blank was saved as Missing too (automatic), so both have one.
        $this->assertEqualsCanonicalizing([$learners['CCC']->id, $learners['DDD']->id],
            AcademicConcern::where('rule_key', MissingRequirementRule::KEY)->pluck('student_id')->all());
        $concern = AcademicConcern::where('rule_key', MissingRequirementRule::KEY)->where('student_id', $learners['DDD']->id)->sole();
        $this->actingAs($principal)->get(route('principal.academic-monitoring'))->assertOk()
            ->assertSee('DDD')->assertSee('Missing Requirement')->assertSee('Quiz 1');

        // H. Review, then an intervention the Principal chooses.
        $this->actingAs($principal)->post(route('principal.academic-monitoring.review', $concern), ['review_note' => 'Checked'])->assertSessionHas('success');
        $this->actingAs($principal)->post(route('principal.interventions.store'), [
            'academic_concern_id' => $concern->id, 'student_id' => $learners['DDD']->id, 'subject_id' => $science->id, 'grading_period' => 1,
            'recommended_type' => 'additional_learning_activity', 'recommendation_reason' => 'Quiz 1 marked Missing.', 'principal_notes' => 'Catch-up activity.',
        ])->assertSessionHas('success');
        $iv = Intervention::sole();

        // I/J. The adviser acknowledges and delivers; the concern stays open.
        $this->actingAs($bong)->post(route('adviser.interventions.acknowledge', $iv))->assertSessionHas('success');
        $this->actingAs($bong)->postJson(route('adviser.interventions.deliver', $iv), ['delivery_notes' => 'Catch-up activity given in class.'])->assertOk();
        $this->assertNotNull($iv->fresh()->delivered_at);
        $this->assertNull($concern->fresh()->resolved_at, 'review, intervention and delivery never resolve a concern');

        // K. The corrected evidence resolves it.
        $this->saveCells($carla, $quiz, [$learners['DDD']->id => '15'])->assertOk();
        $this->assertNotNull($concern->fresh()->resolved_at);

        // L. Complete evidence, verified by the adviser (who does not teach Science).
        foreach ([['Project 1', 'performance_task', null], ['Term Exam', 'examination', 'term_exam']] as [$name, $component, $role]) {
            $this->addAssessment($carla, $agila, $science, array_filter(['item_name' => $name, 'component' => $component, 'max_score' => 20, 'exam_role' => $role]))->assertSessionHasNoErrors();
            $this->saveCells($carla, Assessment::where('name', $name)->sole(), [$learners['AAA']->id => '19'])->assertOk();
        }
        $this->actingAs($carla)->post(route('adviser.grades.verify'), ['grading_period' => 1, 'student_id' => $learners['AAA']->id, 'subject_id' => $science->id])->assertForbidden();
        $this->actingAs($bong)->post(route('adviser.grades.verify'), ['grading_period' => 1, 'student_id' => $learners['AAA']->id, 'subject_id' => $science->id])->assertSessionHas('success');
        $scienceGrade = Grade::where('subject_id', $science->id)->sole();
        $this->assertTrue((bool) $scienceGrade->is_verified);
        $this->assertGreaterThan(74, (float) $scienceGrade->grade);

        // M. An official failing subject opens its own concern although the average passes.
        foreach ([['M Quiz', 'written_work', null], ['M Project', 'performance_task', null], ['M Exam', 'examination', 'term_exam']] as [$name, $component, $role]) {
            $this->addAssessment($bong, $agila, $math, array_filter(['item_name' => $name, 'component' => $component, 'max_score' => 20, 'exam_role' => $role]))->assertSessionHasNoErrors();
            $this->saveCells($bong, Assessment::where('name', $name)->sole(), [$learners['AAA']->id => '9'])->assertOk();
        }
        $this->actingAs($bong)->post(route('adviser.grades.verify'), ['grading_period' => 1, 'student_id' => $learners['AAA']->id, 'subject_id' => $math->id])->assertSessionHas('success');
        $mathGrade = Grade::where('subject_id', $math->id)->sole();
        $this->assertLessThanOrEqual(74, (float) $mathGrade->grade);
        $this->assertGreaterThanOrEqual(75, ((float) $mathGrade->grade + (float) $scienceGrade->grade) / 2, 'fixture sanity: the average passes');
        $failing = AcademicConcern::where('rule_key', OfficialFailingSubjectRule::KEY)->whereNull('resolved_at')->sole();
        $this->assertSame([$learners['AAA']->id, $math->id], [$failing->student_id, $failing->subject_id]);

        // N. Two open concerns for one learner count once as a learner.
        $extra = Assessment::where('name', 'Quiz 1')->sole();
        $this->saveCells($carla, $extra, [$learners['AAA']->id => 'M'])->assertOk();
        $summary = app(MonitoringClassificationService::class)->summary('2026-2027', 1);
        $this->assertSame(2, AcademicConcern::whereNull('resolved_at')->where('student_id', $learners['AAA']->id)->count());
        // AAA (two open concerns) + CCC (the automatic Missing on Quiz 1) = 2 learners, 3 concerns.
        $this->assertSame(3, AcademicConcern::whereNull('resolved_at')->count());
        $this->assertSame(2, $summary['learners_for_review'], json_encode($summary));

        // O. Exports and reports follow the same authorization.
        $this->actingAs($carla)->get(route('teacher.class-record.export', ['section_id' => $agila->id, 'subject_id' => $science->id, 'period' => 1]))->assertOk();
        $this->actingAs($carla)->get(route('teacher.class-record.copy', ['section_id' => $agila->id, 'subject_id' => $science->id, 'period' => 1]))->assertOk();
        $this->actingAs($principal)->get(route('teacher.class-record.export', ['section_id' => $agila->id, 'subject_id' => $science->id, 'period' => 1]))->assertForbidden();
        $this->actingAs($carla)->get(route('teacher.learners.report', ['student' => $learners['AAA']->id, 'school_year' => '2026-2027', 'period' => 1]))
            ->assertOk()->assertSee('Demo Science')->assertDontSee('Demo Mathematics');
        $this->actingAs($bong)->get(route('teacher.learners.report', ['student' => $learners['AAA']->id, 'school_year' => '2026-2027', 'period' => 1]))
            ->assertOk()->assertSee('Demo Science')->assertSee('Demo Mathematics');
        $this->actingAs($carla)->get(route('teacher.learners.report', ['student' => $outsider->id, 'school_year' => '2026-2027', 'period' => 1]))->assertForbidden();

        // Teacher replacement: access moves at once; history keeps its actors.
        $assignment = TeachingAssignment::where('subject_id', $science->id)->sole();
        $this->actingAs($admin)->put(route('admin.teaching-assignments.update', $assignment->id), [
            'user_id' => $dino->id, 'section_id' => $agila->id, 'subject_id' => $science->id, 'grading_period' => 1,
            'reason' => 'Demo replacement', 'version' => TeachingAssignmentController::versionOf($assignment),
        ])->assertSessionHasNoErrors();
        $this->saveCells($carla, $quiz, [$learners['CCC']->id => '12'])->assertForbidden(); // a stale tab of the old teacher
        $this->actingAs($carla)->get($scienceUrl)->assertForbidden();
        $this->saveCells($dino, $quiz, [$learners['CCC']->id => '12'])->assertOk();
        $this->assertSame($carla->id, (int) $quiz->fresh()->uploaded_by, 'the original creator is kept');
        $this->assertTrue(AssessmentEvidenceAudit::where('assessment_id', $quiz->id)->where('student_id', $learners['AAA']->id)->where('changed_by', $carla->id)->exists());
        $this->actingAs($bong)->get($scienceUrl)->assertOk()->assertSee('data-can-edit="0"', false);

        // Closed term: every write refused, reading still works.
        AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->update(['is_open' => false]);
        $this->saveCells($dino, $quiz, [$learners['CCC']->id => '13'])->assertStatus(423);
        $this->actingAs($bong)->post(route('adviser.grades.verify'), ['grading_period' => 1, 'student_id' => $learners['BBB']->id, 'subject_id' => $science->id])->assertSessionHas('error');
        $this->actingAs($dino)->get($scienceUrl)->assertOk()->assertSee('data-can-edit="0"', false);
        $this->assertSame(12.0, (float) AssessmentScore::where('assessment_id', $quiz->id)->where('student_id', $learners['CCC']->id)->value('score'));
        $this->assertGreaterThan(0, ActivityLog::where('action', 'class_record_save')->count());
    }
}
