<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Track;
use App\Models\User;
use App\Services\TermReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "ECR alignment" work order, PART 6 — one test per consumer of "how many
 * grades should exist," proving each one reports NOT READY (not a
 * core-only "complete") when an SSHS section's electives exist but
 * haven't been assigned yet. This is the actual behavior change the
 * user's decision on "what a zero from section_subject means" produces --
 * see SectionElectiveStatus::isFullyConfigured() and CLAUDE.md.
 */
class SectionElectiveNotReadyTest extends TestCase
{
    use RefreshDatabase;

    private function unconfiguredSshsSection(User $adviser): Section
    {
        $track = Track::factory()->create(['code' => 'ACAD']);
        $section = Section::factory()->create([
            'adviser_id' => $adviser->id, 'curriculum' => 'sshs', 'grade_level' => 11,
            'track_id' => $track->id, 'specialization_id' => null, 'school_year' => '2026-2027',
        ]);

        // An elective exists for this track -- but is never assigned via
        // section_subject, which is the whole point of this test.
        Subject::factory()->create(['type' => 'elective', 'grade_level' => 11, 'track_id' => $track->id]);

        return $section;
    }

    public function test_academic_term_completion_status_reports_the_section_incomplete_with_a_reason(): void
    {
        $adviser = User::factory()->create();
        $section = $this->unconfiguredSshsSection($adviser);
        Student::factory()->create(['section_id' => $section->id]);

        $status = AcademicTerm::completionStatus($section->school_year, 1);

        $this->assertFalse($status['complete']);
        $entry = collect($status['incomplete_sections'])->firstWhere('section', $section->name . ' — Grade ' . $section->grade_level);
        $this->assertNotNull($entry);
        $this->assertSame('Electives not yet assigned for this section.', $entry['reason']);
        $this->assertNull($entry['expected']);
    }

    public function test_term_readiness_service_reports_not_configured_not_a_core_only_ready(): void
    {
        $adviser = User::factory()->create();
        $section = $this->unconfiguredSshsSection($adviser);
        Student::factory()->create(['section_id' => $section->id]);

        $result = (new TermReadinessService())->assessmentEvidenceStatus($section, 1);

        $this->assertFalse($result['configured']);
        $this->assertFalse($result['ready']);
        $this->assertSame(0, $result['expected']);
    }

    public function test_adviser_submit_report_page_shows_the_not_configured_note_and_cannot_be_submitted(): void
    {
        $adviser = User::factory()->create();
        $section = $this->unconfiguredSshsSection($adviser);
        Student::factory()->create(['section_id' => $section->id]);
        AcademicTerm::ensureExistFor($section->school_year);

        $response = $this->actingAs($adviser)->get('/adviser/submit-report');
        $response->assertOk();
        $response->assertSee('Electives not yet assigned');

        $submit = $this->actingAs($adviser)->post('/adviser/submit-report', ['grading_period' => 1]);
        $submit->assertSessionHas('error');
        $this->assertStringContainsString('electives have not been assigned yet', session('error'));
    }

    public function test_adviser_dashboard_shows_not_configured_instead_of_a_core_only_ready_state(): void
    {
        $adviser = User::factory()->create();
        $section = $this->unconfiguredSshsSection($adviser);
        Student::factory()->create(['section_id' => $section->id]);

        $response = $this->actingAs($adviser)->get('/adviser/dashboard');
        $response->assertOk();
        $response->assertSee('Not Configured');
        $response->assertSee('Electives not yet assigned for this section');
    }
}
