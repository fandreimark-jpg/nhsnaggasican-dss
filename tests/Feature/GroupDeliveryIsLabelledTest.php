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
 * "Clarity, progress, and visual design pass" TASK 3c/3d — a group
 * delivery must be honestly distinguishable from an individual one: it
 * carries delivery_mode = 'group' and a shared delivery_group_id, and
 * both the Adviser and Principal Interventions views must label it as a
 * group act ("Delivered as a group activity (N learners)") rather than
 * presenting the shared note as if it were written for one child alone.
 */
class GroupDeliveryIsLabelledTest extends TestCase
{
    use RefreshDatabase;

    public function test_group_delivered_interventions_are_tagged_group_mode_with_a_shared_group_id(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $subject = Subject::factory()->create();

        $students = Student::factory()->count(3)->create(['section_id' => $section->id]);
        $interventions = $students->map(function ($student) use ($subject) {
            $iv = Intervention::factory()->decided()->create(['student_id' => $student->id, 'subject_id' => $subject->id]);
            $iv->update(['acknowledged_at' => now(), 'acknowledged_by' => $iv->created_by]);
            return $iv;
        });

        $this->actingAs($adviser)->post('/adviser/interventions/deliver-group', [
            'intervention_ids' => $interventions->pluck('id')->all(),
            'delivery_notes'   => 'One shared re-teaching session covering all three learners at once.',
        ])->assertRedirect();

        $fresh = $interventions->map(fn($iv) => $iv->fresh());

        $this->assertTrue($fresh->every(fn($iv) => $iv->delivery_mode === 'group'));
        $groupIds = $fresh->pluck('delivery_group_id')->unique();
        $this->assertCount(1, $groupIds, 'Every intervention delivered in the same act must share one delivery_group_id.');
        $this->assertNotNull($groupIds->first());
    }

    public function test_individually_delivered_interventions_are_not_tagged_group_mode(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create();
        $intervention = Intervention::factory()->decided()->create(['student_id' => $student->id, 'subject_id' => $subject->id]);
        $intervention->update(['acknowledged_at' => now(), 'acknowledged_by' => $intervention->created_by]);

        $this->actingAs($adviser)->post("/adviser/interventions/{$intervention->id}/deliver", [
            'delivery_notes' => 'Gave the extra performance task individually.',
        ])->assertRedirect();

        $fresh = $intervention->fresh();
        $this->assertSame('individual', $fresh->delivery_mode);
        $this->assertNull($fresh->delivery_group_id);
    }

    public function test_adviser_interventions_page_labels_a_group_delivery(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $subject = Subject::factory()->create();

        $students = Student::factory()->count(3)->create(['section_id' => $section->id, 'last_name' => 'GroupCase']);
        $interventions = $students->map(function ($student) use ($subject) {
            $iv = Intervention::factory()->decided()->create(['student_id' => $student->id, 'subject_id' => $subject->id]);
            $iv->update(['acknowledged_at' => now(), 'acknowledged_by' => $iv->created_by]);
            return $iv;
        });

        $this->actingAs($adviser)->post('/adviser/interventions/deliver-group', [
            'intervention_ids' => $interventions->pluck('id')->all(),
            'delivery_notes'   => 'One shared re-teaching session covering all three learners at once.',
        ]);

        $response = $this->actingAs($adviser)->get('/adviser/interventions');

        $response->assertOk();
        $response->assertSee('Delivered as a group activity (3 learners)', false);
    }

    public function test_principal_interventions_page_labels_a_group_delivery(): void
    {
        $principal = User::factory()->principal()->create();
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $subject = Subject::factory()->create();

        $students = Student::factory()->count(2)->create(['section_id' => $section->id]);
        $interventions = $students->map(function ($student) use ($subject) {
            $iv = Intervention::factory()->decided()->create(['student_id' => $student->id, 'subject_id' => $subject->id]);
            $iv->update(['acknowledged_at' => now(), 'acknowledged_by' => $iv->created_by]);
            return $iv;
        });

        $this->actingAs($adviser)->post('/adviser/interventions/deliver-group', [
            'intervention_ids' => $interventions->pluck('id')->all(),
            'delivery_notes'   => 'One shared parent conference covering both learners together.',
        ]);

        $response = $this->actingAs($principal)->get('/principal/interventions');

        $response->assertOk();
        $response->assertSee('Delivered as a group activity (2 learners)', false);
    }
}
