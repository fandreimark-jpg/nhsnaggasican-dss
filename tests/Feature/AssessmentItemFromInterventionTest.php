<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Intervention;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK 2 of "add an assessment item by hand" — the link on the adviser
 * Interventions page (for a delivered intervention) into Add Assessment
 * Item, pre-filled with subject/term/component and a roster narrowed to
 * just the students who have an intervention for that subject/term. See
 * Intervention::focusComponent() and AdviserAssessmentController::index()'s
 * from_intervention handling.
 */
class AssessmentItemFromInterventionTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\AdvisersTeachTheirOwnSections;

    private function setUp2(): array
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => $section->grade_level, 'type' => 'core']);
        AcademicTerm::ensureExistFor($section->school_year);
        $student = Student::factory()->create(['section_id' => $section->id]);

        return compact('adviser', 'section', 'subject', 'student');
    }

    public function test_delivered_intervention_with_a_named_focus_area_offers_a_link_and_prefills_the_component(): void
    {
        ['adviser' => $adviser, 'subject' => $subject, 'student' => $student] = $this->setUp2();

        $intervention = Intervention::factory()->decided('approved')->create([
            'student_id'             => $student->id,
            'subject_id'             => $subject->id,
            'grading_period'         => 1,
            'recommended_type'       => 'remediation',
            'recommendation_reason'  => 'Recorded in bulk — Focus Area: Examination (In-Term Status: At Risk).',
            'acknowledged_at'        => now(),
            'acknowledged_by'        => $adviser->id,
            'delivered_at'           => now(),
            'delivered_by'           => $adviser->id,
            'delivery_notes'         => 'Gave remedial exam prep session.',
        ]);

        $this->assertSame('examination', $intervention->focusComponent());

        $listResponse = $this->actingAs($adviser)->get('/adviser/interventions');
        $listResponse->assertOk();
        // The assigned teacher's link goes straight to the details-only Add
        // Assessment page, Examination pre-selected (no retired page in between).
        $link = route('teacher.assessments.create', ['section_id' => $intervention->contextSection()->id, 'subject_id' => $subject->id, 'period' => 1, 'component' => 'examination']);
        $listResponse->assertSee('data-support-assessment-link', false);
        $listResponse->assertSee('href="' . e($link) . '"', false);
        $listResponse->assertSee('Add Assessment');
        $listResponse->assertDontSee('/adviser/assessments', false);

        // An old bookmark of the previous link still lands in the same place.
        $this->actingAs($adviser)->get('/adviser/assessments?period=1&subject_id=' . $subject->id . '&add_item=1&component=examination&from_intervention=1')
            ->assertRedirect($link);

        $form = $this->actingAs($adviser)->get($link);
        $form->assertOk();
        $form->assertViewHas('prefillComponent', 'examination');
        $this->assertMatchesRegularExpression('/<option value="examination"\s+selected/', $form->getContent());
    }

    public function test_the_add_assessment_page_reached_from_an_intervention_is_details_only(): void
    {
        ['adviser' => $adviser, 'section' => $section, 'subject' => $subject, 'student' => $namedStudent] = $this->setUp2();

        Intervention::factory()->create([
            'student_id' => $namedStudent->id, 'subject_id' => $subject->id, 'grading_period' => 1,
            'status' => 'approved', 'delivered_at' => now(), 'delivered_by' => $adviser->id,
        ]);

        // The Add page defines the assessment only; the learner's score is
        // entered afterwards in the Class Record, where every learner is listed.
        $html = $this->actingAs($adviser)->get(route('teacher.assessments.create', ['section_id' => $section->id, 'subject_id' => $subject->id, 'period' => 1]))
            ->assertOk()->getContent();
        $this->assertStringNotContainsString('name="scores[', $html);
        $this->assertStringNotContainsString($namedStudent->last_name . ', ' . $namedStudent->first_name, $html);

        $this->actingAs($adviser)->get(route('teacher.class-record', ['section_id' => $section->id, 'subject_id' => $subject->id, 'period' => 1]))
            ->assertOk()->assertSee($namedStudent->last_name . ', ' . $namedStudent->first_name);
    }

    public function test_no_link_when_the_intervention_has_no_subject_or_term(): void
    {
        ['adviser' => $adviser, 'student' => $student] = $this->setUp2();

        Intervention::factory()->create([
            'student_id' => $student->id, 'subject_id' => null, 'grading_period' => null,
            'status' => 'approved', 'delivered_at' => now(), 'delivered_by' => $adviser->id,
        ]);

        $response = $this->actingAs($adviser)->get('/adviser/interventions');
        $response->assertOk();
        $response->assertDontSee('Add Assessment Item');
    }

    public function test_no_link_before_delivery(): void
    {
        ['adviser' => $adviser, 'subject' => $subject, 'student' => $student] = $this->setUp2();

        Intervention::factory()->create([
            'student_id' => $student->id, 'subject_id' => $subject->id, 'grading_period' => 1,
            'status' => 'recommended',
        ]);

        $response = $this->actingAs($adviser)->get('/adviser/interventions');
        $response->assertOk();
        $response->assertDontSee('Add Assessment Item');
    }

    public function test_focus_component_falls_back_to_recommended_type_when_the_reason_names_no_component(): void
    {
        $intervention = Intervention::factory()->make([
            'recommended_type'      => 'additional_performance_task',
            'recommendation_reason' => 'Currently failing: Some Subject. Remediation is recommended.',
        ]);

        $this->assertSame('performance_task', $intervention->focusComponent());
    }

    public function test_focus_component_reads_the_single_record_recommenders_weakest_component_phrasing(): void
    {
        $intervention = Intervention::factory()->make([
            'recommended_type'      => 'additional_learning_activity',
            'recommendation_reason' => 'Weakest component in General Mathematics: Written Work at 45.0% (12.3 points below target) — this specific area, not the overall grade, is driving the risk.',
        ]);

        $this->assertSame('written_work', $intervention->focusComponent());
    }

    public function test_focus_component_is_null_when_neither_reason_nor_type_gives_a_signal(): void
    {
        $intervention = Intervention::factory()->make([
            'recommended_type'      => 'parent_conference',
            'recommendation_reason' => 'Classified High Risk overall. A parent/guardian conference is recommended to coordinate support.',
        ]);

        $this->assertNull($intervention->focusComponent());
    }
}
