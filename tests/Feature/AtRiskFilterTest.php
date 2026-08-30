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
 * component among the Principal dashboard's filters).
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

    public function test_track_filter_narrows_the_at_risk_list(): void
    {
        $principal = User::factory()->principal()->create();
        $trackA = Track::factory()->create(['name' => 'Academic']);
        $trackB = Track::factory()->create(['name' => 'TechPro']);
        $sectionA = Section::factory()->create(['track_id' => $trackA->id]);
        $sectionB = Section::factory()->create(['track_id' => $trackB->id]);
        $this->makeAtRiskStudent($sectionA)->update(['last_name' => 'InTrackA']);
        $this->makeAtRiskStudent($sectionB)->update(['last_name' => 'InTrackB']);

        $response = $this->actingAs($principal)->get('/principal/dashboard?ar_track=' . $trackA->id);

        $response->assertOk();
        $response->assertSee('InTrackA');
        $response->assertDontSee('InTrackB');
    }

    public function test_specialization_filter_narrows_the_at_risk_list(): void
    {
        $principal = User::factory()->principal()->create();
        $specA = Specialization::factory()->create(['name' => 'STEM']);
        $specB = Specialization::factory()->create(['name' => 'HUMSS']);
        $sectionA = Section::factory()->create(['specialization_id' => $specA->id]);
        $sectionB = Section::factory()->create(['specialization_id' => $specB->id]);
        $this->makeAtRiskStudent($sectionA)->update(['last_name' => 'InSpecA']);
        $this->makeAtRiskStudent($sectionB)->update(['last_name' => 'InSpecB']);

        $response = $this->actingAs($principal)->get('/principal/dashboard?ar_specialization=' . $specA->id);

        $response->assertOk();
        $response->assertSee('InSpecA');
        $response->assertDontSee('InSpecB');
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
