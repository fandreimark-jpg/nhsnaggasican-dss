<?php

namespace Tests\Feature;

use App\Models\RiskResult;
use App\Models\Section;
use App\Models\Specialization;
use App\Models\Student;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Extends the at-risk widget's filters beyond grade-level/section
 * (CLAUDE.md lists track, specialization, risk level, and assessment
 * component among the Principal dashboard's filters) — but Track and
 * Specialization are explicitly NOT independent, manually-selectable
 * filters: CLAUDE.md requires them to be read-only values automatically
 * derived from whichever Section is selected. See
 * test_selecting_a_section_auto_displays_its_track_and_specialization_readonly
 * below for that behavior instead of a standalone ar_track/ar_specialization
 * filter.
 */
class AtRiskFilterTest extends TestCase
{
    use RefreshDatabase;

    private function makeAtRiskStudent(Section $section, string $riskLevel = 'high'): Student
    {
        $student = Student::factory()->create(['section_id' => $section->id]);
        RiskResult::create([
            'student_id' => $student->id, 'grading_period' => 1, 'average_grade' => 65,
            'risk_level' => $riskLevel, 'school_year' => $section->school_year, 'generated_at' => now(),
        ]);
        return $student;
    }

    public function test_selecting_a_section_narrows_the_at_risk_list_by_its_track_and_specialization(): void
    {
        $principal = User::factory()->principal()->create();
        $trackA = Track::factory()->create(['name' => 'Academic']);
        $trackB = Track::factory()->create(['name' => 'TechPro']);
        $sectionA = Section::factory()->create(['name' => 'Steve', 'track_id' => $trackA->id]);
        $sectionB = Section::factory()->create(['name' => 'Newton', 'track_id' => $trackB->id]);
        $this->makeAtRiskStudent($sectionA)->update(['last_name' => 'InSectionA']);
        $this->makeAtRiskStudent($sectionB)->update(['last_name' => 'InSectionB']);

        // Filtering by Section alone already narrows by its Track (and
        // Specialization) — there is no separate ar_track/ar_specialization
        // parameter to filter by.
        $response = $this->actingAs($principal)->get('/principal/dashboard?ar_section_search=' . $sectionA->name);

        $response->assertOk();
        $response->assertSee('InSectionA');
        $response->assertDontSee('InSectionB');
    }

    public function test_selecting_a_section_auto_displays_its_track_and_specialization_readonly(): void
    {
        $principal = User::factory()->principal()->create();
        $track = Track::factory()->create(['name' => 'Academic Track']);
        $spec  = Specialization::factory()->create(['name' => 'Humanities and Social Sciences']);
        $section = Section::factory()->create(['name' => 'Steve', 'track_id' => $track->id, 'specialization_id' => $spec->id]);
        $this->makeAtRiskStudent($section);

        $response = $this->actingAs($principal)->get('/principal/dashboard');

        $response->assertOk();
        // Track/Specialization are not independently selectable dropdowns.
        $response->assertDontSee('name="ar_track"', false);
        $response->assertDontSee('name="ar_specialization"', false);
        // But the Section option carries its Track/Specialization as data
        // for the page to auto-display read-only once that Section is chosen.
        $response->assertSee('data-track="' . $track->name . '"', false);
        $response->assertSee('data-specialization="' . $spec->name . '"', false);
    }

    public function test_risk_level_filter_narrows_the_at_risk_list(): void
    {
        $principal = User::factory()->principal()->create();
        $section = Section::factory()->create();
        $this->makeAtRiskStudent($section, 'high')->update(['last_name' => 'HighRiskStudent']);
        $this->makeAtRiskStudent($section, 'moderate')->update(['last_name' => 'ModerateRiskStudent']);

        $response = $this->actingAs($principal)->get('/principal/dashboard?ar_risk_level=high');

        $response->assertOk();
        $response->assertSee('HighRiskStudent');
        $response->assertDontSee('ModerateRiskStudent');
    }
}
