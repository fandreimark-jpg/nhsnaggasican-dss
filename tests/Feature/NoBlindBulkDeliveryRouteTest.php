<?php

namespace Tests\Feature;

use App\Models\Intervention;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * "Clarity, progress, and visual design pass" TASK 3f — replaces
 * MarkDeliveredRemainsPerStudentTest now that a genuine group-delivery
 * route exists (see Adviser\InterventionController::markDeliveredGroup()).
 * The rule is no longer "exactly one delivery route exists" — it's "every
 * delivery route, individual or group, always requires a real written
 * note before anything is marked delivered, and nothing ever marks
 * delivered without that note step." This is a guard rail: it should keep
 * failing loudly if a future change ever adds a delivery path that skips
 * the note requirement.
 */
class NoBlindBulkDeliveryRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_exactly_two_delivery_routes_exist_individual_and_group(): void
    {
        $deliveryRoutes = collect(Route::getRoutes())->filter(function ($route) {
            return str_contains($route->uri(), 'interventions') && str_contains($route->uri(), 'deliver');
        });

        $this->assertCount(2, $deliveryRoutes, 'Exactly two delivery routes should exist: one individual, one group.');

        $names = $deliveryRoutes->map(fn($r) => $r->getName())->values()->all();
        $this->assertContains('adviser.interventions.deliver', $names);
        $this->assertContains('adviser.interventions.deliver-group', $names);

        $individual = $deliveryRoutes->first(fn($r) => $r->getName() === 'adviser.interventions.deliver');
        $this->assertStringContainsString('{intervention}', $individual->uri(), 'The individual delivery route must be scoped to a single bound Intervention.');

        $group = $deliveryRoutes->first(fn($r) => $r->getName() === 'adviser.interventions.deliver-group');
        $this->assertStringNotContainsString('{intervention}', $group->uri(), 'The group delivery route takes an array of ids in the request body, never a single bound model.');
    }

    public function test_posting_multiple_intervention_ids_to_the_individual_route_only_ever_delivers_the_one_in_the_url(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $subject = Subject::factory()->create();

        $studentA = Student::factory()->create(['section_id' => $section->id]);
        $studentB = Student::factory()->create(['section_id' => $section->id]);
        $ivA = Intervention::factory()->decided()->create(['student_id' => $studentA->id, 'subject_id' => $subject->id]);
        $ivB = Intervention::factory()->decided()->create(['student_id' => $studentB->id, 'subject_id' => $subject->id]);

        $this->actingAs($adviser)->post("/adviser/interventions/{$ivA->id}/acknowledge");
        $this->actingAs($adviser)->post("/adviser/interventions/{$ivB->id}/acknowledge");

        // Even if a crafted request tries to smuggle a second intervention
        // id into the body, the route only binds ONE model from the URL
        // segment — the individual delivery path reads no "intervention_ids"
        // array at all.
        $this->actingAs($adviser)->post("/adviser/interventions/{$ivA->id}/deliver", [
            'delivery_notes'   => 'Delivered for A only.',
            'intervention_id'  => $ivB->id,
            'intervention_ids' => [$ivA->id, $ivB->id],
        ]);

        $ivA->refresh();
        $ivB->refresh();

        $this->assertNotNull($ivA->delivered_at);
        $this->assertSame('Delivered for A only.', $ivA->delivery_notes);
        $this->assertNull($ivB->delivered_at, 'A delivery request naming a second intervention id in the body must never deliver it too.');
    }

    public function test_the_group_route_never_marks_anything_delivered_without_delivery_notes_present_at_all(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $subject = Subject::factory()->create();

        $students = Student::factory()->count(4)->create(['section_id' => $section->id]);
        $interventions = $students->map(function ($student) use ($subject) {
            $iv = Intervention::factory()->decided()->create(['student_id' => $student->id, 'subject_id' => $subject->id]);
            $iv->update(['acknowledged_at' => now(), 'acknowledged_by' => $iv->created_by]);
            return $iv;
        });

        // A "select all and deliver" request with NO note field at all —
        // exactly the shortcut TASK 3f forbids — must fail validation,
        // not silently deliver with an empty note.
        $response = $this->actingAs($adviser)->post('/adviser/interventions/deliver-group', [
            'intervention_ids' => $interventions->pluck('id')->all(),
        ]);

        $response->assertSessionHasErrors('delivery_notes');
        foreach ($interventions as $iv) {
            $this->assertNull($iv->fresh()->delivered_at);
        }
    }

    public function test_group_route_never_copies_one_students_note_onto_a_student_who_was_not_selected(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $subject = Subject::factory()->create();

        $selected = Student::factory()->count(2)->create(['section_id' => $section->id]);
        $notSelected = Student::factory()->create(['section_id' => $section->id]);

        $selectedIvs = $selected->map(function ($student) use ($subject) {
            $iv = Intervention::factory()->decided()->create(['student_id' => $student->id, 'subject_id' => $subject->id]);
            $iv->update(['acknowledged_at' => now(), 'acknowledged_by' => $iv->created_by]);
            return $iv;
        });
        $notSelectedIv = Intervention::factory()->decided()->create(['student_id' => $notSelected->id, 'subject_id' => $subject->id]);
        $notSelectedIv->update(['acknowledged_at' => now(), 'acknowledged_by' => $notSelectedIv->created_by]);

        $this->actingAs($adviser)->post('/adviser/interventions/deliver-group', [
            'intervention_ids' => $selectedIvs->pluck('id')->all(),
            'delivery_notes'   => 'This activity only covered the two selected students.',
        ]);

        $this->assertNull($notSelectedIv->fresh()->delivered_at, 'An intervention never included in intervention_ids must never be marked delivered.');
    }
}
