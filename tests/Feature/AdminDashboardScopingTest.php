<?php

namespace Tests\Feature;

use App\Models\RiskResult;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DashboardAnalyticsService's risk-count widgets must only reflect the
 * ACTIVE school year. Before the fix, "latest risk result per student" was
 * picked via MAX(id) with no school_year filter at all — a student whose
 * ONLY risk result is from a PRIOR school year (e.g. classified at the end
 * of last year, not yet re-classified this year) would still have that
 * stale result counted into this year's dashboard totals, since it's the
 * only (and therefore "latest") row for that student_id.
 *
 * This risk data lives exclusively on the Principal dashboard now (the
 * Admin dashboard is master-data-only — see Admin\DashboardController) —
 * the underlying scoping bug and its regression coverage still apply,
 * just against /principal/dashboard instead of /admin/dashboard.
 */
class AdminDashboardScopingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_students_stale_prior_year_risk_result_is_not_counted_in_the_active_years_totals(): void
    {
        $principal = User::factory()->principal()->create();

        // The student's only risk result is from LAST school year.
        $oldSection = Section::factory()->create(['school_year' => '2025-2026']);
        $student    = Student::factory()->create(['section_id' => $oldSection->id]);
        RiskResult::create([
            'student_id'     => $student->id,
            'grading_period' => 3,
            'average_grade'  => 60,
            'risk_level'     => 'high',
            'school_year'    => '2025-2026',
            'generated_at'   => now(),
        ]);

        // A section created afterward makes '2026-2027' the active school
        // year (Section::activeSchoolYear() = most recently created), but
        // nobody has been classified for it yet.
        Section::factory()->create(['school_year' => '2026-2027']);

        $response = $this->actingAs($principal)->get('/principal/dashboard');

        $response->assertOk();
        // The stale 2025-2026 "high" result must not leak into this year's count.
        $response->assertViewHas('highRisk', 0);
    }

    /** Final pre-demo audit (2026-09-20): the Subjects bulk import is gone, so no dashboard control may still offer it. */
    public function test_the_admin_dashboard_offers_no_import_subjects_quick_action(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get('/admin/dashboard')->assertOk()
            ->assertDontSee('Import Subjects')
            ->assertSee('Manage Subjects');
    }
}
