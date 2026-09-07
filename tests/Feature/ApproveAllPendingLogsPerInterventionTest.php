<?php

namespace Tests\Feature;

use App\Models\Intervention;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Master pass" PART 1.4b — one LogActivity entry per intervention
 * approved, never one entry for the whole batch, so the audit trail
 * names every individual record the same way every other action in
 * this system does (see VerifyAllLogsPerStudentTest for the same
 * discipline applied to Verify All Remaining).
 */
class ApproveAllPendingLogsPerInterventionTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_log_entry_is_created_per_approved_intervention(): void
    {
        $principal = User::factory()->principal()->create();
        $a = Intervention::factory()->create(['status' => 'recommended', 'decided_by' => null]);
        $b = Intervention::factory()->create(['status' => 'recommended', 'decided_by' => null]);
        $c = Intervention::factory()->create(['status' => 'recommended', 'decided_by' => null]);

        $this->actingAs($principal)->post('/principal/interventions/approve-pending', [
            'ids' => [$a->id, $b->id, $c->id],
        ])->assertSessionHas('success', '3 intervention(s) approved.');

        $this->assertDatabaseCount('activity_logs', 3);
        foreach ([$a, $b, $c] as $intervention) {
            $this->assertDatabaseHas('activity_logs', [
                'action' => 'update_intervention', 'user_id' => $principal->id, 'record_id' => $intervention->id,
            ]);
        }
    }

    public function test_zero_eligible_ids_produces_zero_log_entries(): void
    {
        $principal = User::factory()->principal()->create();
        $alreadyDecided = Intervention::factory()->decided()->create();

        $this->actingAs($principal)->post('/principal/interventions/approve-pending', [
            'ids' => [$alreadyDecided->id],
        ])->assertSessionHas('success', '0 intervention(s) approved.');

        $this->assertDatabaseCount('activity_logs', 0);
    }
}
