<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\AssessmentStatusService;
use App\Services\InTermStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * In-Term Status is a DESCRIPTIVE summary of recorded evidence against the
 * 75 component target. On screen it says what the evidence shows
 * ("Below Target: 2+ Components"), not an alarm or a prediction ("At
 * Risk"). The stored/URL keys are unchanged, so filters and links keep
 * working; only the words change.
 */
class InTermStatusLabelsTest extends TestCase
{
    use RefreshDatabase;

    private User $adviser;
    private User $principal;
    private Section $section;
    private Subject $subject;
    private Student $low;

    protected function setUp(): void
    {
        parent::setUp();

        AcademicYear::create(['school_year' => '2026-2027', 'is_active' => true]);
        AcademicTerm::ensureExistFor('2026-2027');
        AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->update(['is_open' => true]);

        $this->adviser = User::factory()->create(['role' => 'adviser']);
        $this->principal = User::factory()->principal()->create();
        $this->section = Section::factory()->create(['grade_level' => 11, 'school_year' => '2026-2027', 'adviser_id' => $this->adviser->id]);
        $this->subject = Subject::factory()->create(['grade_level' => 11, 'type' => 'core', 'subject_group' => 'core_academic']);
        $this->low = Student::factory()->create(['section_id' => $this->section->id, 'last_name' => 'LOWSCORE', 'lrn' => '110000000077']);

        // Two components well below the 75 target -> the 'At Risk' key.
        $statuses = new AssessmentStatusService();
        foreach (['written_work', 'performance_task'] as $component) {
            $item = Assessment::factory()->create([
                'subject_id' => $this->subject->id, 'section_id' => $this->section->id, 'grading_period' => 1,
                'school_year' => '2026-2027', 'name' => ucfirst($component) . ' 1', 'assessment_type' => 'Item',
                'component' => $component, 'max_score' => 20, 'uploaded_by' => $this->adviser->id,
            ]);
            $statuses->recordScore($item, $this->low->id, 5);
        }
    }

    public function test_labels_are_descriptive_and_keys_are_unchanged(): void
    {
        $this->assertSame('On Track', InTermStatusService::label('On Track'));
        $this->assertSame('Below Target: 1 Component', InTermStatusService::label('Needs Attention'));
        $this->assertSame('Below Target: 2+ Components', InTermStatusService::label('At Risk'));
        $this->assertSame('No Evidence Yet', InTermStatusService::label(InTermStatusService::NO_EVIDENCE));
        $this->assertSame('Failing', InTermStatusService::label('Failing'), 'an unknown key is shown as-is');

        $this->assertSame('Meets Target', InTermStatusService::componentLabel('On Track'));
        $this->assertSame('Below Target', InTermStatusService::componentLabel('Needs Attention'));

        // Stored/URL keys stay as they were.
        $this->assertSame(['On Track', 'Needs Attention', 'At Risk'], InTermStatusService::STATUSES);
        $status = (new InTermStatusService())->statusFor($this->low, $this->subject, $this->section, 1, '2026-2027');
        $this->assertSame('At Risk', $status['status']);
        $this->assertSame(2, $status['components_below_target']);
    }

    public function test_adviser_assessments_shows_the_descriptive_label_and_the_old_filter_value_still_works(): void
    {
        $response = $this->actingAs($this->adviser)
            ->get(route('adviser.verify-grades', ['period' => 1, 'subject_id' => $this->subject->id, 'status_filter' => 'At Risk']))
            ->assertOk();

        $this->assertSame(['LOWSCORE'], $response->viewData('performance')->map(fn($r) => $r['student']->last_name)->all());
        $response->assertSee('Below Target: 2+ Components')
            ->assertSee('<option value="At Risk" selected>Below Target: 2+ Components</option>', false)
            ->assertDontSee('>At Risk<', false)
            ->assertDontSee('early warning');
        $this->assertMatchesRegularExpression('#class="badge whitespace-nowrap bg-status-risk/10 text-status-risk">\s*Below Target: 2\+ Components#', $response->getContent(), 'the status badge itself carries the descriptive label');
    }

    public function test_principal_students_shows_the_descriptive_label_and_the_old_filter_value_still_works(): void
    {
        $response = $this->actingAs($this->principal)
            ->get(route('principal.students', ['period' => 1, 'subject_id' => $this->subject->id, 'status_filter' => 'At Risk']))
            ->assertOk();

        $this->assertSame(['LOWSCORE'], collect($response->viewData('students')->items())->map(fn($r) => $r['student']->last_name)->all());
        $response->assertSee('Below Target: 2+ Components')
            ->assertSee('Below target in 2+ components only')
            ->assertDontSee('>At Risk<', false)
            ->assertDontSee('early warning');
        $this->assertMatchesRegularExpression('#class="badge whitespace-nowrap bg-status-risk/10 text-status-risk">\s*Below Target: 2\+ Components#', $response->getContent(), 'the status badge itself carries the descriptive label');
    }

    public function test_dashboards_use_the_descriptive_labels(): void
    {
        $this->actingAs($this->adviser)->get('/adviser/dashboard')->assertOk()
            ->assertSee('1 Below Target: 2+ Components', false)
            ->assertSee('0 Below Target: 1 Component', false);

        $this->actingAs($this->principal)->get('/principal/dashboard')->assertOk()
            ->assertSee('Below Target: 2+ Components')
            ->assertSee('B1 = Below Target: 1 Component')
            ->assertDontSee('early warning');
    }
}
