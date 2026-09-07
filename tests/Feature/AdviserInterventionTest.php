<?php

namespace Tests\Feature;

use App\Models\Intervention;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 1 of "close the intervention loop": the Principal's decision must
 * actually reach the adviser who delivers it. Read + acknowledge only —
 * the adviser cannot create, approve, or delete an intervention (see
 * Adviser\InterventionController's docblock and RoleAuthorizationTest).
 */
class AdviserInterventionTest extends TestCase
{
    use RefreshDatabase;

    public function test_adviser_sees_interventions_recorded_for_their_own_section(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $student = Student::factory()->create(['section_id' => $section->id, 'last_name' => 'Mine']);
        $subject = Subject::factory()->create();

        Intervention::factory()->create([
            'student_id' => $student->id, 'subject_id' => $subject->id, 'recommended_type' => 'remediation',
        ]);

        $response = $this->actingAs($adviser)->get('/adviser/interventions');

        $response->assertOk();
        $response->assertSee('Mine');
        // TASK 1 of "terminology, transmutation, and interface cleanup" —
        // 'remediation' is still the stored enum value (see the factory
        // call above), but its display label no longer claims to be
        // DepEd's formal remediation — see CLAUDE.md's Known limitations.
        $response->assertSee('Additional Practice and Re-teaching');
    }

    public function test_adviser_of_a_different_section_does_not_see_it(): void
    {
        $ownAdviser = User::factory()->create();
        Section::factory()->create(['adviser_id' => $ownAdviser->id]);

        $otherSection = Section::factory()->create();
        $otherStudent = Student::factory()->create(['section_id' => $otherSection->id, 'last_name' => 'NotMine']);
        $subject = Subject::factory()->create();

        Intervention::factory()->create(['student_id' => $otherStudent->id, 'subject_id' => $subject->id]);

        $response = $this->actingAs($ownAdviser)->get('/adviser/interventions');

        $response->assertOk();
        $response->assertDontSee('NotMine');
    }

    public function test_adviser_with_no_section_sees_an_empty_state_not_an_error(): void
    {
        $adviser = User::factory()->create();

        $response = $this->actingAs($adviser)->get('/adviser/interventions');

        $response->assertOk();
        $response->assertSee('No section assigned yet.');
    }

    public function test_mark_as_acknowledged_records_who_and_when(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create();
        $intervention = Intervention::factory()->decided()->create(['student_id' => $student->id, 'subject_id' => $subject->id]);

        $response = $this->actingAs($adviser)->post("/adviser/interventions/{$intervention->id}/acknowledge");

        $response->assertRedirect();
        $intervention->refresh();
        $this->assertNotNull($intervention->acknowledged_at);
        $this->assertSame($adviser->id, $intervention->acknowledged_by);
    }

    public function test_acknowledge_never_changes_status_type_or_notes(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create();
        // 'approved' (not 'recommended') — acknowledging now requires a
        // Principal decision to have already happened; see
        // Intervention::awaitingDecision().
        $intervention = Intervention::factory()->decided('approved')->create([
            'student_id' => $student->id, 'subject_id' => $subject->id,
            'recommended_type' => 'remediation', 'principal_notes' => 'Original note',
        ]);

        // Even if a crafted request tried to smuggle these fields in, the
        // route/controller never reads them — acknowledge() only ever
        // sets acknowledged_at/acknowledged_by.
        $this->actingAs($adviser)->post("/adviser/interventions/{$intervention->id}/acknowledge", [
            'status' => 'completed', 'recommended_type' => 'other', 'principal_notes' => 'Tampered',
        ]);

        $intervention->refresh();
        $this->assertSame('approved', $intervention->status);
        $this->assertSame('remediation', $intervention->recommended_type);
        $this->assertSame('Original note', $intervention->principal_notes);
    }

    public function test_acknowledging_another_sections_intervention_is_forbidden(): void
    {
        $ownAdviser = User::factory()->create();
        Section::factory()->create(['adviser_id' => $ownAdviser->id]);

        $otherSection = Section::factory()->create();
        $otherStudent = Student::factory()->create(['section_id' => $otherSection->id]);
        $subject = Subject::factory()->create();
        $intervention = Intervention::factory()->create(['student_id' => $otherStudent->id, 'subject_id' => $subject->id]);

        $response = $this->actingAs($ownAdviser)->post("/adviser/interventions/{$intervention->id}/acknowledge");

        $response->assertForbidden();
        $this->assertNull($intervention->fresh()->acknowledged_at);
    }

    public function test_adviser_dashboard_shows_the_panel_with_a_plain_message_when_nothing_is_unacknowledged(): void
    {
        $adviser = User::factory()->create();
        Section::factory()->create(['adviser_id' => $adviser->id]);

        $response = $this->actingAs($adviser)->get('/adviser/dashboard');

        $response->assertOk();
        $response->assertSee('Interventions Needing Your Attention');
        $response->assertSee('No unacknowledged interventions right now.');
    }

    public function test_adviser_dashboard_shows_unacknowledged_count_and_list(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $student = Student::factory()->create(['section_id' => $section->id, 'last_name' => 'Pending']);
        $subject = Subject::factory()->create();
        Intervention::factory()->decided()->create(['student_id' => $student->id, 'subject_id' => $subject->id]);

        $response = $this->actingAs($adviser)->get('/adviser/dashboard');

        $response->assertOk();
        $response->assertSee('1 intervention awaiting your acknowledgement.');
        $response->assertSee('Pending');
    }

    public function test_principal_interventions_page_shows_acknowledgement_status(): void
    {
        $principal = User::factory()->principal()->create();
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create();
        $intervention = Intervention::factory()->decided()->create(['student_id' => $student->id, 'subject_id' => $subject->id]);

        $unacknowledgedView = $this->actingAs($principal)->get('/principal/interventions');
        $unacknowledgedView->assertSee('Not yet acknowledged');

        $this->actingAs($adviser)->post("/adviser/interventions/{$intervention->id}/acknowledge");

        $acknowledgedView = $this->actingAs($principal)->get('/principal/interventions');
        $acknowledgedView->assertSee('Acknowledged') ;
        $acknowledgedView->assertDontSee('Not yet acknowledged');
    }
}
