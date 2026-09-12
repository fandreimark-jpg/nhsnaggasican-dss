<?php

namespace Tests\Feature;

use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SYSTEM_FIXES_AND_ML_AUDIT.md, "Subject Analysis" -- the page itself
 * (filters render, failure rate / at-risk columns show), not just the
 * underlying service (see SubjectAnalysisServiceTest).
 */
class PrincipalSubjectAnalysisPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_renders_with_section_and_term_filters(): void
    {
        $principal = User::factory()->principal()->create();
        $section = Section::factory()->create(['school_year' => '2026-2027', 'name' => 'Narra']);

        $response = $this->actingAs($principal)->get('/principal/subject-analysis');

        $response->assertOk();
        $response->assertSee('Section');
        $response->assertSee('Narra');
        $response->assertSee('Failure Rate');
        $response->assertSee('At-Risk Count');
    }

    public function test_section_filter_is_accepted_via_query_string(): void
    {
        $principal = User::factory()->principal()->create();
        $section = Section::factory()->create(['school_year' => '2026-2027']);
        Subject::factory()->create();

        $response = $this->actingAs($principal)->get('/principal/subject-analysis?section_id=' . $section->id);

        $response->assertOk();
    }

    public function test_adviser_cannot_access_principal_subject_analysis(): void
    {
        $adviser = User::factory()->create(['role' => 'adviser']);

        $this->actingAs($adviser)->get('/principal/subject-analysis')->assertForbidden();
    }

    public function test_admin_cannot_access_principal_subject_analysis(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/principal/subject-analysis')->assertForbidden();
    }
}
