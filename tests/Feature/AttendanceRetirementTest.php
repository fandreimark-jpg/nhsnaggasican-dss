<?php

namespace Tests\Feature;

use App\Models\AcademicConcern;
use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\AttendanceRecord;
use App\Models\Intervention;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Monitoring\MonitoringStatus;
use App\Services\AcademicMonitoringService;
use App\Services\MonitoringClassificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Attendance is RETIRED from the operational DSS (no approved basis for
 * using it as a monitoring criterion). Its history stays in the database —
 * attendance_records and any stored Attendance Concern rows — but nothing
 * reads it: no page, no route, no rule, no count, no new intervention type.
 */
class AttendanceRetirementTest extends TestCase
{
    use RefreshDatabase;

    private User $principal;
    private User $adviser;
    private Section $section;
    private Student $ana;
    private Subject $subject;
    private AcademicConcern $legacy;

    protected function setUp(): void
    {
        parent::setUp();

        AcademicYear::create(['school_year' => '2026-2027', 'is_active' => true]);
        AcademicTerm::ensureExistFor('2026-2027');
        AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->update(['is_open' => true]);

        $this->principal = User::factory()->principal()->create();
        $this->adviser = User::factory()->create(['role' => 'adviser']);
        $this->section = Section::factory()->create(['grade_level' => 11, 'school_year' => '2026-2027', 'adviser_id' => $this->adviser->id]);
        $this->subject = Subject::factory()->create(['type' => 'core', 'grade_level' => 11, 'subject_group' => 'core_academic']);
        $this->ana = Student::factory()->create(['section_id' => $this->section->id]);

        // History as the retired feature left it: a record and an OPEN concern.
        AttendanceRecord::create([
            'student_id' => $this->ana->id, 'section_id' => $this->section->id, 'school_year' => '2026-2027', 'grading_period' => 1,
            'school_days' => 40, 'days_present' => 15, 'days_absent' => 25, 'days_tardy' => 10,
            'concern_flagged' => true, 'concern_note' => 'Legacy flag.', 'recorded_by' => $this->adviser->id,
        ]);
        $this->legacy = AcademicConcern::create([
            'student_id' => $this->ana->id, 'subject_id' => null, 'section_id' => $this->section->id,
            'school_year' => '2026-2027', 'grading_period' => 1, 'rule_key' => 'explicit_attendance_concern', 'rule_version' => 1,
            'context_key' => 'attendance:2026-2027:1', 'open_key' => 'explicit_attendance_concern|' . $this->ana->id . '|attendance:2026-2027:1',
            'evidence' => ['concern' => 'Attendance Concern'], 'status' => 'open', 'first_detected_at' => now(), 'last_evaluated_at' => now(),
        ]);
    }

    public function test_the_attendance_page_routes_and_navigation_are_gone(): void
    {
        $this->assertFalse(Route::has('adviser.attendance'));
        $this->assertFalse(Route::has('adviser.attendance.store'));
        $this->actingAs($this->adviser)->get('/adviser/attendance')->assertNotFound();
        $this->actingAs($this->adviser)->post('/adviser/attendance', ['period' => 1, 'rows' => []])->assertNotFound();

        $this->actingAs($this->adviser)->get(route('adviser.dashboard'))->assertOk()
            ->assertDontSee('Term Attendance Summary')->assertDontSee('Attendance Concern');
        $this->assertFalse(class_exists(\App\Http\Controllers\Adviser\AttendanceController::class));
        $this->assertFalse(class_exists(\App\Monitoring\Rules\AttendanceConcernRule::class));
    }

    public function test_history_is_kept_but_never_makes_a_learner_for_review(): void
    {
        $this->assertSame(1, AttendanceRecord::count(), 'records are not deleted');
        $this->assertNotContains('explicit_attendance_concern', AcademicMonitoringService::enabledRuleKeys());

        $status = app(MonitoringClassificationService::class)->forStudent($this->ana->id, '2026-2027', 1);
        $this->assertSame(MonitoringStatus::NO_CURRENT_CONCERN, $status->classification());
        $this->assertSame(0, app(MonitoringClassificationService::class)->summary('2026-2027')['open_concerns']);

        // A full re-evaluation neither resolves, rewrites nor counts it.
        app(AcademicMonitoringService::class)->evaluate('2026-2027', 1);
        $this->assertTrue($this->legacy->fresh()->isOpen(), 'history is not silently rewritten');
        $this->assertSame(1, AttendanceRecord::count());
    }

    public function test_principal_pages_do_not_show_or_filter_attendance(): void
    {
        $page = $this->actingAs($this->principal)->get(route('principal.academic-monitoring', ['status' => 'all']))->assertOk();
        $page->assertDontSee('Attendance')->assertDontSee('data-concern-id="' . $this->legacy->id . '"', false);
        $this->assertEqualsCanonicalizing(['subject' => 'Subject Performance Concern', 'missing' => 'Missing Requirement'], $page->viewData('concernTypes'));
        $this->assertSame(0, $page->viewData('counts')['open']);

        $this->actingAs($this->principal)->get(route('principal.dashboard'))->assertOk()->assertDontSee('Attendance');
        $this->actingAs($this->principal)->get(route('principal.subject-analysis'))->assertOk()->assertDontSee('Attendance');
    }

    public function test_attendance_follow_up_is_no_longer_a_choice_and_legacy_concerns_cannot_be_linked(): void
    {
        $this->assertNotContains('attendance_monitoring', Intervention::SELECTABLE_TYPES);
        $this->assertSame('Attendance Follow-up (retired)', Intervention::typeLabel('attendance_monitoring'), 'an old record still reads in words');

        $this->actingAs($this->principal)->from(route('principal.academic-monitoring'))
            ->post(route('principal.interventions.store'), [
                'student_id' => $this->ana->id, 'subject_id' => $this->subject->id, 'grading_period' => 1, 'recommended_type' => 'attendance_monitoring',
            ])->assertSessionHasErrors('recommended_type');

        $this->actingAs($this->principal)->from(route('principal.academic-monitoring'))
            ->post(route('principal.interventions.store'), [
                'academic_concern_id' => $this->legacy->id, 'student_id' => $this->ana->id, 'subject_id' => $this->subject->id,
                'grading_period' => 1, 'recommended_type' => 'teacher_monitoring',
            ])->assertSessionHas('error');
        $this->assertSame(0, Intervention::count());

        $this->actingAs($this->principal)->get(route('principal.students', ['subject_id' => $this->subject->id, 'period' => 1]))
            ->assertOk()->assertDontSee('Attendance Follow-up');
    }
}
