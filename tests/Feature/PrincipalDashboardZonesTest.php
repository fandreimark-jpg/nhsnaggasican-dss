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
 * TASK 1 of "dashboard structure and upload safeguards" — the Principal
 * dashboard grouped into three labelled, ordered zones. Nothing about
 * WHAT is computed changes here (see the untouched
 * DashboardAnalyticsService/InTermStatusService tests) — only how it's
 * arranged and labelled, so these tests check structure/order, not the
 * underlying numbers (already covered elsewhere).
 */
class PrincipalDashboardZonesTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shows_the_three_numbered_zone_headings_in_order(): void
    {
        $principal = User::factory()->principal()->create();

        $response = $this->actingAs($principal)->get('/principal/dashboard');
        $response->assertOk();
        $content = $response->getContent();

        $zone1 = strpos($content, '1. Where the school stands now');
        $zone2 = strpos($content, '2. What needs a decision');
        $zone3 = strpos($content, '3. Trend and outcomes');

        $this->assertNotFalse($zone1);
        $this->assertNotFalse($zone2);
        $this->assertNotFalse($zone3);
        $this->assertLessThan($zone2, $zone1, 'Zone 1 must render before Zone 2.');
        $this->assertLessThan($zone3, $zone2, 'Zone 2 must render before Zone 3.');
    }

    public function test_zone_1_contains_total_students_assessment_completion_and_in_term_status(): void
    {
        $principal = User::factory()->principal()->create();

        $response = $this->actingAs($principal)->get('/principal/dashboard');
        $content = $response->getContent();

        $zone1Start = strpos($content, '1. Where the school stands now');
        $zone2Start = strpos($content, '2. What needs a decision');
        $zone1 = substr($content, $zone1Start, $zone2Start - $zone1Start);

        $this->assertStringContainsString('Total Students', $zone1);
        $this->assertStringContainsString('Assessment Completion', $zone1);
        $this->assertStringContainsString('In-Term Status', $zone1);
    }

    public function test_zone_2_collapses_to_one_line_when_nothing_needs_a_decision(): void
    {
        $principal = User::factory()->principal()->create();

        $response = $this->actingAs($principal)->get('/principal/dashboard');
        $response->assertOk();
        $response->assertSee('No interventions need attention right now.');
        // Zero enabled cards for this zone when collapsed.
        $response->assertDontSee('Under Intervention');
        $response->assertDontSee('Awaiting Your Decision');
    }

    public function test_zone_2_shows_three_cards_when_something_needs_a_decision(): void
    {
        $principal = User::factory()->principal()->create();
        $student = Student::factory()->create();
        Intervention::factory()->create(['student_id' => $student->id, 'status' => 'recommended']);

        $response = $this->actingAs($principal)->get('/principal/dashboard');

        $response->assertOk();
        $response->assertSee('Under Intervention');
        $response->assertSee('Awaiting Your Decision');
        $response->assertSee('Ready to Close');
        $response->assertDontSee('No interventions need attention right now.');
    }

    public function test_zone_3_contains_risk_level_and_recommendations(): void
    {
        $principal = User::factory()->principal()->create();

        $response = $this->actingAs($principal)->get('/principal/dashboard');
        $content = $response->getContent();

        $zone3Start = strpos($content, '3. Trend and outcomes');
        $this->assertNotFalse($zone3Start);
        $zone3 = substr($content, $zone3Start);

        $this->assertStringContainsString('Risk Level', $zone3);
        $this->assertStringContainsString('Recommendations', $zone3);
    }

    /** Regression guard for the restructuring: intervention cards must never appear before Zone 2's own heading. */
    public function test_intervention_cards_do_not_appear_before_zone_2_heading(): void
    {
        $principal = User::factory()->principal()->create();
        $student = Student::factory()->create();
        Intervention::factory()->create(['student_id' => $student->id, 'status' => 'recommended']);

        $response = $this->actingAs($principal)->get('/principal/dashboard');
        $content = $response->getContent();

        $zone2 = strpos($content, '2. What needs a decision');
        $underIntervention = strpos($content, 'Under Intervention');

        $this->assertNotFalse($zone2);
        $this->assertNotFalse($underIntervention);
        $this->assertLessThan($underIntervention, $zone2);
    }
}
