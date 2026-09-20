<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Intervention;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK 3 and TASK 4 of "terminology, transmutation, and interface
 * cleanup" — a Subject + Term filter on both Interventions pages, and a
 * bulk "Acknowledge all" action (respecting whatever filters are active)
 * on the Adviser page.
 */
class InterventionFiltersAndBulkAcknowledgeTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdviserSection(): array
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'grade_level' => 11, 'school_year' => '2026-2027']);
        // Distinct names per call: subjects are unique on (name, grade_level)
        // at the database since the pre-demo audit, and one test builds two
        // adviser sections.
        $suffix = ' ' . Str::random(6);
        $subjectA = Subject::factory()->create(['grade_level' => 11, 'type' => 'core', 'name' => 'Subject A' . $suffix]);
        $subjectB = Subject::factory()->create(['grade_level' => 11, 'type' => 'core', 'name' => 'Subject B' . $suffix]);

        return compact('adviser', 'section', 'subjectA', 'subjectB');
    }

    public function test_adviser_interventions_page_can_filter_by_subject(): void
    {
        ['adviser' => $adviser, 'section' => $section, 'subjectA' => $subjectA, 'subjectB' => $subjectB] = $this->makeAdviserSection();
        $studentA = Student::factory()->create(['section_id' => $section->id, 'last_name' => 'FromSubjectA']);
        $studentB = Student::factory()->create(['section_id' => $section->id, 'last_name' => 'FromSubjectB']);

        Intervention::factory()->decided()->create(['student_id' => $studentA->id, 'subject_id' => $subjectA->id]);
        Intervention::factory()->decided()->create(['student_id' => $studentB->id, 'subject_id' => $subjectB->id]);

        $response = $this->actingAs($adviser)->get('/adviser/interventions?subject_id=' . $subjectA->id);

        $response->assertOk();
        $response->assertSee('FromSubjectA');
        $response->assertDontSee('FromSubjectB');
        $response->assertSee('1 intervention');
    }

    public function test_adviser_interventions_page_defaults_to_the_currently_open_term(): void
    {
        ['adviser' => $adviser, 'section' => $section, 'subjectA' => $subjectA] = $this->makeAdviserSection();
        AcademicTerm::ensureExistFor('2026-2027'); // opens Term 1
        $termOneStudent = Student::factory()->create(['section_id' => $section->id, 'last_name' => 'TermOneStudent']);
        $termTwoStudent = Student::factory()->create(['section_id' => $section->id, 'last_name' => 'TermTwoStudent']);

        Intervention::factory()->create(['student_id' => $termOneStudent->id, 'subject_id' => $subjectA->id, 'grading_period' => 1]);
        Intervention::factory()->create(['student_id' => $termTwoStudent->id, 'subject_id' => $subjectA->id, 'grading_period' => 2]);

        $default = $this->actingAs($adviser)->get('/adviser/interventions');
        $default->assertOk();
        $default->assertSee('TermOneStudent');
        $default->assertDontSee('TermTwoStudent');

        // Explicitly widening to Term 2 shows the other row instead.
        $termTwo = $this->actingAs($adviser)->get('/adviser/interventions?grading_period=2');
        $termTwo->assertSee('TermTwoStudent');
        $termTwo->assertDontSee('TermOneStudent');

        // Explicitly clearing the term (All terms) shows both.
        $all = $this->actingAs($adviser)->get('/adviser/interventions?grading_period=');
        $all->assertSee('TermOneStudent');
        $all->assertSee('TermTwoStudent');
    }

    /** An intervention with no recorded term is never hidden by the Term filter — see the controller's docblock. */
    public function test_term_filter_never_hides_an_intervention_with_no_recorded_term(): void
    {
        ['adviser' => $adviser, 'section' => $section] = $this->makeAdviserSection();
        AcademicTerm::ensureExistFor('2026-2027'); // opens Term 1
        $student = Student::factory()->create(['section_id' => $section->id, 'last_name' => 'NoTermRecorded']);

        Intervention::factory()->create(['student_id' => $student->id, 'subject_id' => null, 'grading_period' => null]);

        $response = $this->actingAs($adviser)->get('/adviser/interventions');

        $response->assertOk();
        $response->assertSee('NoTermRecorded');
    }

    public function test_principal_interventions_subject_dropdown_only_lists_subjects_with_interventions(): void
    {
        $principal = User::factory()->principal()->create();
        ['section' => $section, 'subjectA' => $subjectA, 'subjectB' => $subjectB] = $this->makeAdviserSection();
        $student = Student::factory()->create(['section_id' => $section->id]);

        Intervention::factory()->create(['student_id' => $student->id, 'subject_id' => $subjectA->id]);
        // subjectB has zero interventions — must not appear as an option.

        $response = $this->actingAs($principal)->get('/principal/interventions');

        $response->assertOk();
        $response->assertSee($subjectA->name);
        $response->assertDontSee($subjectB->name);
    }

    public function test_principal_interventions_filtering_by_subject_narrows_correctly_and_count_matches(): void
    {
        $principal = User::factory()->principal()->create();
        ['section' => $section, 'subjectA' => $subjectA, 'subjectB' => $subjectB] = $this->makeAdviserSection();
        $studentA = Student::factory()->create(['section_id' => $section->id, 'last_name' => 'AlphaStudent']);
        $studentB = Student::factory()->create(['section_id' => $section->id, 'last_name' => 'BetaStudent']);

        Intervention::factory()->create(['student_id' => $studentA->id, 'subject_id' => $subjectA->id]);
        Intervention::factory()->create(['student_id' => $studentB->id, 'subject_id' => $subjectB->id]);

        $response = $this->actingAs($principal)->get('/principal/interventions?subject_id=' . $subjectA->id);

        $response->assertOk();
        $response->assertSee('AlphaStudent');
        $response->assertDontSee('BetaStudent');
        $response->assertSee('1 intervention');
    }

    /** SYSTEM_FIXES_AND_ML_AUDIT.md, "Interventions" -- Student filter, by name. */
    public function test_principal_interventions_filtering_by_student_name_narrows_correctly(): void
    {
        $principal = User::factory()->principal()->create();
        ['section' => $section, 'subjectA' => $subjectA] = $this->makeAdviserSection();
        $studentA = Student::factory()->create(['section_id' => $section->id, 'last_name' => 'Delacruz', 'first_name' => 'Juan']);
        $studentB = Student::factory()->create(['section_id' => $section->id, 'last_name' => 'Santos', 'first_name' => 'Maria']);

        Intervention::factory()->create(['student_id' => $studentA->id, 'subject_id' => $subjectA->id]);
        Intervention::factory()->create(['student_id' => $studentB->id, 'subject_id' => $subjectA->id]);

        $response = $this->actingAs($principal)->get('/principal/interventions?student_search=Delacruz');

        $response->assertOk();
        $response->assertSee('Delacruz');
        $response->assertDontSee('Santos');
    }

    /** SYSTEM_FIXES_AND_ML_AUDIT.md, "Interventions" -- Risk Level filter, reading the linked risk_result. */
    public function test_principal_interventions_filtering_by_risk_level_narrows_correctly(): void
    {
        $principal = User::factory()->principal()->create();
        ['section' => $section, 'subjectA' => $subjectA] = $this->makeAdviserSection();
        $highRiskStudent = Student::factory()->create(['section_id' => $section->id, 'last_name' => 'HighRiskStudent']);
        $lowRiskStudent = Student::factory()->create(['section_id' => $section->id, 'last_name' => 'LowRiskStudent']);
        $noRiskResultStudent = Student::factory()->create(['section_id' => $section->id, 'last_name' => 'NoRiskResultStudent']);

        $highRisk = \App\Models\RiskResult::create([
            'student_id' => $highRiskStudent->id, 'grading_period' => 1, 'average_grade' => 60,
            'risk_level' => 'high', 'school_year' => '2026-2027', 'generated_at' => now(),
        ]);
        $lowRisk = \App\Models\RiskResult::create([
            'student_id' => $lowRiskStudent->id, 'grading_period' => 1, 'average_grade' => 95,
            'risk_level' => 'low', 'school_year' => '2026-2027', 'generated_at' => now(),
        ]);

        Intervention::factory()->create(['student_id' => $highRiskStudent->id, 'subject_id' => $subjectA->id, 'risk_result_id' => $highRisk->id]);
        Intervention::factory()->create(['student_id' => $lowRiskStudent->id, 'subject_id' => $subjectA->id, 'risk_result_id' => $lowRisk->id]);
        // No risk result at all -- must be excluded when the filter is active, not guessed into a bucket.
        Intervention::factory()->create(['student_id' => $noRiskResultStudent->id, 'subject_id' => $subjectA->id, 'risk_result_id' => null]);

        $response = $this->actingAs($principal)->get('/principal/interventions?risk_level=high');

        $response->assertOk();
        $response->assertSee('HighRiskStudent');
        $response->assertDontSee('LowRiskStudent');
        $response->assertDontSee('NoRiskResultStudent');
    }

    /**
     * TASK 4 — bulk acknowledge respects whatever filter is active: only
     * the filtered-in rows are stamped, everything else stays untouched.
     */
    public function test_acknowledge_all_with_a_subject_filter_only_stamps_the_filtered_rows(): void
    {
        ['adviser' => $adviser, 'section' => $section, 'subjectA' => $subjectA, 'subjectB' => $subjectB] = $this->makeAdviserSection();
        $studentA = Student::factory()->create(['section_id' => $section->id]);
        $studentB = Student::factory()->create(['section_id' => $section->id]);

        $ivA = Intervention::factory()->decided()->create(['student_id' => $studentA->id, 'subject_id' => $subjectA->id]);
        $ivB = Intervention::factory()->decided()->create(['student_id' => $studentB->id, 'subject_id' => $subjectB->id]);

        $response = $this->actingAs($adviser)->post('/adviser/interventions/acknowledge-all', [
            'subject_id' => $subjectA->id,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertNotNull($ivA->fresh()->acknowledged_at);
        $this->assertSame($adviser->id, $ivA->fresh()->acknowledged_by);
        $this->assertNull($ivB->fresh()->acknowledged_at, 'The subject filter must exclude subject B rows from the bulk action.');
    }

    public function test_acknowledge_all_never_touches_an_already_acknowledged_intervention_and_is_idempotent(): void
    {
        ['adviser' => $adviser, 'section' => $section, 'subjectA' => $subjectA] = $this->makeAdviserSection();
        $student = Student::factory()->create(['section_id' => $section->id]);

        $iv = Intervention::factory()->create([
            'student_id' => $student->id, 'subject_id' => $subjectA->id,
            'acknowledged_at' => now()->subDay(), 'acknowledged_by' => $adviser->id,
        ]);
        $originalTimestamp = $iv->acknowledged_at;

        $response = $this->actingAs($adviser)->post('/adviser/interventions/acknowledge-all');

        $response->assertSessionHas('success', 'Acknowledged 0 intervention(s).');
        $this->assertTrue($originalTimestamp->equalTo($iv->fresh()->acknowledged_at));
    }

    public function test_acknowledge_all_is_scoped_to_the_advisers_own_section(): void
    {
        ['adviser' => $ownAdviser] = $this->makeAdviserSection();
        ['section' => $otherSection, 'subjectA' => $otherSubject] = $this->makeAdviserSection();
        $otherStudent = Student::factory()->create(['section_id' => $otherSection->id]);
        $otherIv = Intervention::factory()->create(['student_id' => $otherStudent->id, 'subject_id' => $otherSubject->id]);

        $this->actingAs($ownAdviser)->post('/adviser/interventions/acknowledge-all');

        $this->assertNull($otherIv->fresh()->acknowledged_at, 'Bulk-acknowledging must never reach another adviser\'s section.');
    }
}
