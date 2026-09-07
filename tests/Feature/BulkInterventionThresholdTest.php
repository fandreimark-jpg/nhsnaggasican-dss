<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Intervention;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK 2 of "subject scoping and bulk threshold" — the bulk-record
 * dialog's threshold control (At Risk only / At Risk + Needs Attention).
 * "At Risk only" is the default; see
 * Principal\StudentController::buildBulkInterventionCandidates() and the
 * dialog's applyBulkThreshold()/updateBulkInterventionSummary() JS.
 */
class BulkInterventionThresholdTest extends TestCase
{
    use RefreshDatabase;

    private function makeAtRiskStudent(Section $section, Subject $subject, string $lastName): Student
    {
        $student = Student::factory()->create(['section_id' => $section->id, 'last_name' => $lastName]);
        foreach (['written_work', 'performance_task'] as $component) {
            $assessment = Assessment::factory()->create([
                'subject_id' => $subject->id, 'section_id' => $section->id, 'grading_period' => 1,
                'school_year' => $section->school_year, 'name' => $component . '-' . uniqid(),
                'component' => $component, 'max_score' => 20,
            ]);
            AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => 8]); // 40% -> below target twice
        }
        return $student;
    }

    private function makeNeedsAttentionStudent(Section $section, Subject $subject, string $lastName): Student
    {
        $student = Student::factory()->create(['section_id' => $section->id, 'last_name' => $lastName]);
        $assessment = Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id, 'grading_period' => 1,
            'school_year' => $section->school_year, 'name' => 'performance_task-' . uniqid(),
            'component' => 'performance_task', 'max_score' => 20,
        ]);
        AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => 8]); // 40% -> below target once only
        return $student;
    }

    /** The default render: only At Risk rows are visible/checked; Needs Attention rows are present but out of scope. */
    public function test_dialog_defaults_to_at_risk_only_hiding_and_disabling_needs_attention_rows(): void
    {
        $principal = User::factory()->principal()->create();
        $section = Section::factory()->create(['grade_level' => 11, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => 11, 'type' => 'core']);

        $this->makeAtRiskStudent($section, $subject, 'AtRiskA');
        $this->makeAtRiskStudent($section, $subject, 'AtRiskB');
        $this->makeNeedsAttentionStudent($section, $subject, 'NeedsA');
        $this->makeNeedsAttentionStudent($section, $subject, 'NeedsB');

        $response = $this->actingAs($principal)->get('/principal/students?subject_id=' . $subject->id . '&period=1');
        $response->assertOk();
        $content = $response->getContent();

        // Banner names both counts.
        $response->assertSee('2 At Risk, 2 Needs Attention', false);
        // Default summary reflects At Risk only.
        $response->assertSee('2 match, 0 skipped', false);
        $response->assertSee('Record 2 intervention', false);

        // All four candidates are in the DOM (so the threshold toggle has
        // something to reveal) but the two Needs Attention rows are hidden
        // and their checkboxes disabled by default.
        // 'biv-row"' (double-quoted) matches only the HTML class attribute,
        // not the JS '.biv-row' selectors (single-quoted) further down the page.
        $this->assertSame(4, substr_count($content, 'biv-row"'));
        $this->assertSame(2, substr_count($content, 'data-status="needs_attention"'));
        $this->assertMatchesRegularExpression('/data-status="needs_attention"[\s\S]{0,120}hidden>/', $content);
    }

    /** Submitting a Needs Attention student's id (what the widened threshold would send) records it. */
    public function test_submitting_a_needs_attention_students_id_records_an_intervention_for_them(): void
    {
        $principal = User::factory()->principal()->create();
        $section = Section::factory()->create(['grade_level' => 11, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => 11, 'type' => 'core']);
        $student = $this->makeNeedsAttentionStudent($section, $subject, 'WideNet');

        $response = $this->actingAs($principal)->post('/principal/interventions/bulk', [
            'subject_id'     => $subject->id,
            'grading_period' => 1,
            'included'       => [$student->id],
            'types'          => [$student->id => 'additional_performance_task'],
            'statuses'       => [$student->id => 'Needs Attention'],
        ]);

        $response->assertSessionHas('success');
        $this->assertDatabaseHas('interventions', ['student_id' => $student->id, 'subject_id' => $subject->id]);
        $intervention = Intervention::where('student_id', $student->id)->first();
        $this->assertStringContainsString('Needs Attention', $intervention->recommendation_reason);
    }

    /** The default (narrow) path never submits a Needs Attention student — confirmed by the dialog's own rendering. */
    public function test_default_threshold_render_never_includes_a_checked_needs_attention_checkbox(): void
    {
        $principal = User::factory()->principal()->create();
        $section = Section::factory()->create(['grade_level' => 11, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => 11, 'type' => 'core']);
        $this->makeAtRiskStudent($section, $subject, 'AtRiskOnly');
        $needsAttention = $this->makeNeedsAttentionStudent($section, $subject, 'NeedsOnly');

        $response = $this->actingAs($principal)->get('/principal/students?subject_id=' . $subject->id . '&period=1');
        $content = $response->getContent();

        // The Needs Attention student's checkbox is disabled in the
        // server-rendered HTML — an actual browser submit with no JS
        // interaction would never include it.
        $checkboxPos = strpos($content, 'bivInclude' . $needsAttention->id . '"');
        $this->assertNotFalse($checkboxPos);
        $checkboxTag = substr($content, $checkboxPos - 20, 400);
        $this->assertStringContainsString('disabled', $checkboxTag);
    }

    public function test_confirm_button_is_disabled_when_the_default_threshold_has_nothing_recordable_but_the_wider_one_does(): void
    {
        $principal = User::factory()->principal()->create();
        $section = Section::factory()->create(['grade_level' => 11, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => 11, 'type' => 'core']);
        // Only a Needs Attention candidate exists — At Risk alone has nothing.
        $this->makeNeedsAttentionStudent($section, $subject, 'OnlyNeeds');

        $response = $this->actingAs($principal)->get('/principal/students?subject_id=' . $subject->id . '&period=1');
        $response->assertOk();

        $response->assertSee('id="bivConfirmBtn" disabled', false);
        $response->assertSee('Nothing to record', false);
    }
}
