<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Final audit (2026-09-28): Principal > Interventions put its empty state in
 * a table cell spanning the wide scrolling table, so at tablet width the
 * message was clipped and only readable by scrolling sideways; its hint also
 * named only the Students page. The empty state now renders outside the
 * table and names both places interventions are recorded from.
 */
class FinalAuditUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_interventions_empty_state_is_outside_the_table_and_accurate(): void
    {
        AcademicYear::create(['school_year' => '2026-2027', 'is_active' => true]);
        AcademicTerm::ensureExistFor('2026-2027');
        $html = $this->actingAs(User::factory()->principal()->create())->get(route('principal.interventions'))->assertOk()
            ->assertSee('data-interventions-empty', false)
            ->assertSee('Interventions are recorded from Academic Monitoring (for an open concern) or the Students page.')
            ->assertDontSee('Interventions are created from the Students page, not here.')
            ->getContent();
        $empty = strpos($html, 'data-interventions-empty');
        $table = strpos($html, '<table class="tbl tbl-sticky">');
        $this->assertTrue($table === false || $table < $empty, 'no scrolling table wraps the empty state');
    }
}
