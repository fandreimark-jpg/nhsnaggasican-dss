<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Grade;
use App\Models\Intervention;
use App\Models\ReportSubmission;
use App\Models\RiskResult;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\User;
use App\Services\StudentEnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * "Multi-school-year academic history" work order, PART 20 — the
 * regression suite for the school-year lifecycle. The scenario every
 * test builds on: SY 2026-2027 (Grade 11, Section Narra, three terms,
 * grades/report/risk/intervention on file) followed by SY 2027-2028
 * being created and activated, with the learner promoted into Grade 12
 * Section Agila. Nothing from 2026-2027 may change, move, or be
 * relabelled by any of that.
 */
class AcademicHistoryArchitectureTest extends TestCase
{
    use RefreshDatabase;

    private const OLD = '2026-2027';
    private const NEW = '2027-2028';

    private User $admin;
    private User $adviserNarra;
    private User $principal;
    private Section $narra;
    private Student $learner;
    private Subject $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin        = User::factory()->admin()->create();
        $this->principal    = User::factory()->principal()->create();
        $this->adviserNarra = User::factory()->create();

        AcademicYear::create(['school_year' => self::OLD, 'is_active' => true]);
        AcademicTerm::ensureExistFor(self::OLD);

        $this->narra = Section::factory()->create([
            'name' => 'Narra', 'grade_level' => 11, 'school_year' => self::OLD,
            'adviser_id' => $this->adviserNarra->id, 'curriculum' => 'sshs',
        ]);
        $this->learner = Student::factory()->create(['section_id' => $this->narra->id, 'last_name' => 'Learner', 'first_name' => 'Demo']);
        $this->subject = Subject::factory()->create(['grade_level' => 11, 'type' => 'core', 'name' => 'General Mathematics']);
    }

    /** Puts a full Term 1 record on file for 2026-2027: grade, report, risk result, intervention. */
    private function recordOldYearHistory(): array
    {
        $grade = Grade::create([
            'student_id' => $this->learner->id, 'subject_id' => $this->subject->id, 'section_id' => $this->narra->id,
            'encoded_by' => $this->adviserNarra->id, 'grading_period' => 1, 'grade' => 80,
            'school_year' => self::OLD, 'is_verified' => true, 'is_provisional' => false,
        ]);
        $report = ReportSubmission::create([
            'section_id' => $this->narra->id, 'submitted_by' => $this->adviserNarra->id, 'grading_period' => 1,
            'status' => 'submitted', 'school_year' => self::OLD, 'submitted_at' => now(),
        ]);
        $risk = RiskResult::create([
            'student_id' => $this->learner->id, 'grading_period' => 1, 'average_grade' => 70,
            'risk_level' => 'high', 'school_year' => self::OLD, 'generated_at' => now(),
        ]);
        $intervention = Intervention::create([
            'student_id' => $this->learner->id, 'subject_id' => $this->subject->id, 'grading_period' => 1,
            'risk_result_id' => $risk->id, 'recommended_type' => 'remediation', 'recommendation_reason' => 'Test',
            'status' => 'approved', 'created_by' => $this->principal->id, 'decided_by' => $this->principal->id,
            'decided_at' => now(), 'origin' => 'principal',
        ]);

        return compact('grade', 'report', 'risk', 'intervention');
    }

    /** Creates 2027-2028 (inactive), a Grade 12 section Agila in it, and returns both. */
    private function createNewYearWithAgila(?User $adviser = null): array
    {
        $year = AcademicYear::create(['school_year' => self::NEW, 'is_active' => false]);
        $agila = Section::factory()->create([
            'name' => 'Agila', 'grade_level' => 12, 'school_year' => self::NEW,
            'adviser_id' => $adviser?->id, 'curriculum' => 'k12_2013',
        ]);

        return [$year, $agila];
    }

    private function activateNewYear(): AcademicYear
    {
        [$year] = $this->createNewYearWithAgila();
        $this->actingAs($this->admin)->post(route('admin.academic-years.activate', $year))->assertSessionHas('success');

        return $year->fresh();
    }

    private function promoteLearner(): StudentEnrollment
    {
        $agila = Section::where('school_year', self::NEW)->firstOrFail();

        return app(StudentEnrollmentService::class)->enroll($this->learner->fresh(), $agila);
    }

    // ------------------------------------------------------------------
    // TEST 1 — academic year history survives activation of the next year
    // ------------------------------------------------------------------
    public function test_1_records_created_under_the_old_year_still_belong_to_it_after_the_new_year_is_activated(): void
    {
        $old = $this->recordOldYearHistory();
        $oldSnapshot = [
            'grade'  => Grade::find($old['grade']->id)->only(['school_year', 'section_id', 'grade', 'grading_period']),
            'report' => ReportSubmission::find($old['report']->id)->only(['school_year', 'section_id', 'grading_period']),
            'risk'   => RiskResult::find($old['risk']->id)->only(['school_year', 'section_id', 'risk_level']),
            'iv'     => Intervention::find($old['intervention']->id)->only(['school_year', 'section_id', 'grading_period']),
        ];

        $this->activateNewYear();
        $this->promoteLearner();

        $this->assertSame($oldSnapshot['grade'],  Grade::find($old['grade']->id)->only(['school_year', 'section_id', 'grade', 'grading_period']));
        $this->assertSame($oldSnapshot['report'], ReportSubmission::find($old['report']->id)->only(['school_year', 'section_id', 'grading_period']));
        $this->assertSame($oldSnapshot['risk'],   RiskResult::find($old['risk']->id)->only(['school_year', 'section_id', 'risk_level']));
        $this->assertSame($oldSnapshot['iv'],     Intervention::find($old['intervention']->id)->only(['school_year', 'section_id', 'grading_period']));

        $this->assertSame(self::OLD, $oldSnapshot['grade']['school_year']);
        $this->assertSame($this->narra->id, $oldSnapshot['risk']['section_id']);
        $this->assertSame($this->narra->id, $oldSnapshot['iv']['section_id']);
        $this->assertSame(self::OLD, $oldSnapshot['iv']['school_year']);

        // The old year row itself was not renamed.
        $this->assertDatabaseHas('academic_years', ['school_year' => self::OLD, 'is_active' => false]);
        $this->assertDatabaseHas('academic_years', ['school_year' => self::NEW, 'is_active' => true]);
        $this->assertSame(2, AcademicYear::count());
    }

    // ------------------------------------------------------------------
    // TEST 2 — only one active academic year
    // ------------------------------------------------------------------
    public function test_2_activating_the_new_year_leaves_exactly_one_active_year(): void
    {
        $this->activateNewYear();

        $this->assertSame(1, AcademicYear::where('is_active', true)->count());
        $this->assertFalse(AcademicYear::where('school_year', self::OLD)->first()->is_active);
        $this->assertTrue(AcademicYear::where('school_year', self::NEW)->first()->is_active);
        $this->assertSame(self::NEW, Section::activeSchoolYear());
        $this->assertSame('Completed', AcademicYear::where('school_year', self::OLD)->first()->lifecycleStatus());

        // The outgoing year's open term was closed (auditable), the new year's Term 1 is open.
        $this->assertFalse(AcademicTerm::isOpen(self::OLD, 1));
        $this->assertNotNull(AcademicTerm::where('school_year', self::OLD)->where('term', 1)->first()->closed_at);
        $this->assertTrue(AcademicTerm::isOpen(self::NEW, 1));
        $this->assertDatabaseHas('activity_logs', ['action' => 'activate_academic_year']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'close_term']);
    }

    public function test_2b_activating_an_already_active_year_is_a_no_op_with_an_error_message(): void
    {
        $year = AcademicYear::where('school_year', self::OLD)->first();

        $this->actingAs($this->admin)->post(route('admin.academic-years.activate', $year))->assertSessionHas('error');

        $this->assertSame(1, AcademicYear::where('is_active', true)->count());
        $this->assertTrue(AcademicTerm::isOpen(self::OLD, 1));
    }

    // ------------------------------------------------------------------
    // TEST 3 — terms are year-specific
    // ------------------------------------------------------------------
    public function test_3_term_1_of_each_year_is_a_distinct_record_linked_to_its_own_academic_year(): void
    {
        $this->activateNewYear();

        $oldTerm1 = AcademicTerm::where('school_year', self::OLD)->where('term', 1)->firstOrFail();
        $newTerm1 = AcademicTerm::where('school_year', self::NEW)->where('term', 1)->firstOrFail();

        $this->assertNotSame($oldTerm1->id, $newTerm1->id);
        $this->assertNotNull($oldTerm1->academic_year_id);
        $this->assertNotNull($newTerm1->academic_year_id);
        $this->assertNotSame($oldTerm1->academic_year_id, $newTerm1->academic_year_id);
        $this->assertSame(self::OLD, $oldTerm1->academicYear->school_year);
        $this->assertSame(self::NEW, $newTerm1->academicYear->school_year);
        $this->assertCount(3, AcademicYear::where('school_year', self::OLD)->first()->terms);
        $this->assertCount(3, AcademicYear::where('school_year', self::NEW)->first()->terms);
        $this->assertSame(6, AcademicTerm::count());
    }

    // ------------------------------------------------------------------
    // TEST 4 — historical report keeps its year / grade / section
    // ------------------------------------------------------------------
    public function test_4_historical_report_still_shows_grade_11_narra_after_the_learner_is_promoted_to_grade_12_agila(): void
    {
        $this->recordOldYearHistory();
        $this->activateNewYear();
        $this->promoteLearner();

        // The learner's CURRENT section is now Agila...
        $this->assertSame('Agila', $this->learner->fresh()->section->name);

        // ...but the 2026-2027 report resolves to the section it was submitted for.
        $report = ReportSubmission::where('school_year', self::OLD)->firstOrFail();
        $this->assertSame(self::OLD, $report->school_year);
        $this->assertSame('Narra', $report->section->name);
        $this->assertSame(11, (int) $report->section->grade_level);
        $this->assertSame(self::OLD, $report->academicYear->school_year);

        // And the Reports page for 2026-2027 renders it under Grade 11 / Narra, roster included.
        $response = $this->actingAs($this->principal)->get('/principal/reports?school_year=' . self::OLD);
        $response->assertOk();
        $response->assertSee('Section Narra');
        $response->assertSee('School Year 2026-2027');
        $response->assertSee('Grade 11');
        $response->assertSee('Learner, Demo');
        $response->assertDontSee('Section Agila');
        $response->assertSee('Historical Record');

        // The learner drill-down for 2026-2027 shows Grade 11 Narra, not Grade 12 Agila.
        $detail = $this->actingAs($this->principal)->get('/principal/students/' . $this->learner->id . '?school_year=' . self::OLD);
        $detail->assertOk();
        $detail->assertSee('Grade 11');
        $detail->assertSee('Narra');
        $detail->assertSee('School Year 2026-2027');
    }

    // ------------------------------------------------------------------
    // TEST 5 — historical grade
    // ------------------------------------------------------------------
    public function test_5_a_grade_created_in_the_old_year_remains_associated_with_it_after_activation(): void
    {
        $old = $this->recordOldYearHistory();
        $this->activateNewYear();
        $this->promoteLearner();

        $grade = Grade::find($old['grade']->id);
        $this->assertSame(self::OLD, $grade->school_year);
        $this->assertSame($this->narra->id, $grade->section_id);
        $this->assertSame('Narra', $grade->section->name);
        $this->assertSame(self::OLD, $grade->academicYear->school_year);
        $this->assertSame(1, AcademicYear::where('school_year', self::OLD)->first()->grades()->count());
        $this->assertSame(0, AcademicYear::where('school_year', self::NEW)->first()->grades()->count());
    }

    // ------------------------------------------------------------------
    // TEST 6 — historical risk
    // ------------------------------------------------------------------
    public function test_6_risk_results_from_two_school_years_exist_independently_with_their_own_section(): void
    {
        $this->recordOldYearHistory(); // 2026-2027 Term 1 -> high
        $this->activateNewYear();
        $this->promoteLearner();

        $new = RiskResult::create([
            'student_id' => $this->learner->id, 'grading_period' => 1, 'average_grade' => 82,
            'risk_level' => 'moderate', 'school_year' => self::NEW, 'generated_at' => now(),
        ]);

        $old = RiskResult::where('student_id', $this->learner->id)->where('school_year', self::OLD)->firstOrFail();
        $this->assertSame('high', $old->risk_level);
        $this->assertSame('Narra', $old->section->name);
        $this->assertSame('moderate', $new->fresh()->risk_level);
        $this->assertSame('Agila', $new->fresh()->section->name);
        $this->assertSame(2, RiskResult::where('student_id', $this->learner->id)->count());

        // The Principal dashboard counts only the selected year's results.
        $this->actingAs($this->principal)->get('/principal/dashboard')->assertOk()->assertSee('School Year:');
        $dash = app(\App\Services\DashboardAnalyticsService::class);
        request()->merge(['school_year' => self::OLD]);
        $this->assertSame(1, $dash->getSummaryData()['highRisk']);
        $this->assertSame(0, $dash->getSummaryData()['moderateRisk']);
        request()->merge(['school_year' => self::NEW]);
        $this->assertSame(0, $dash->getSummaryData()['highRisk']);
        $this->assertSame(1, $dash->getSummaryData()['moderateRisk']);
    }

    // ------------------------------------------------------------------
    // TEST 7 — historical intervention
    // ------------------------------------------------------------------
    public function test_7_an_intervention_from_the_old_year_stays_under_the_old_year_and_section(): void
    {
        $old = $this->recordOldYearHistory();
        $this->activateNewYear();
        $this->promoteLearner();

        $iv = Intervention::find($old['intervention']->id);
        $this->assertSame(self::OLD, $iv->school_year);
        $this->assertSame('Narra', $iv->section->name);
        $this->assertSame('Narra', $iv->contextSection()->name);

        $oldPage = $this->actingAs($this->principal)->get('/principal/interventions?school_year=' . self::OLD . '&grading_period=');
        $oldPage->assertOk()->assertSee('Learner, Demo')->assertSee('Narra')->assertSee('SY 2026-2027');

        $newPage = $this->actingAs($this->principal)->get('/principal/interventions?grading_period=');
        $newPage->assertOk()->assertDontSee('Learner, Demo');
    }

    public function test_7b_a_new_intervention_is_stamped_with_the_active_year_and_the_learners_section_in_it(): void
    {
        $this->activateNewYear();
        $this->promoteLearner();

        $response = $this->actingAs($this->principal)->post(route('principal.interventions.store'), [
            'student_id' => $this->learner->id, 'subject_id' => $this->subject->id, 'grading_period' => 1,
            'recommended_type' => 'teacher_monitoring', 'recommendation_reason' => 'Test reason',
        ]);
        $response->assertSessionHasNoErrors();

        $iv = Intervention::latest('id')->first();
        $this->assertSame(self::NEW, $iv->school_year);
        $this->assertSame('Agila', $iv->section->name);
    }

    // ------------------------------------------------------------------
    // TEST 8 — reports filter
    // ------------------------------------------------------------------
    public function test_8_filtering_reports_by_old_year_and_term_1_shows_no_new_year_report(): void
    {
        $this->recordOldYearHistory();
        $this->activateNewYear();
        $this->promoteLearner();
        $agila = Section::where('school_year', self::NEW)->firstOrFail();
        $agila->update(['adviser_id' => User::factory()->create()->id]);
        ReportSubmission::create([
            'section_id' => $agila->id, 'submitted_by' => $agila->adviser_id, 'grading_period' => 1,
            'status' => 'submitted', 'school_year' => self::NEW, 'submitted_at' => now()->addDay(),
        ]);

        $old = $this->actingAs($this->principal)->get('/principal/reports?school_year=' . self::OLD . '&grading_period=1');
        $old->assertOk()->assertSee('Section Narra')->assertDontSee('Section Agila')->assertSee('Term 1');
        // Only the selected term's column renders (the Term 2 option in the filter dropdown is not a column).
        $this->assertStringNotContainsString('Term 2 — Not submitted', $old->getContent());
        $this->assertStringContainsString('Term 1', substr($old->getContent(), strpos($old->getContent(), 'Section Narra')));

        $new = $this->actingAs($this->principal)->get('/principal/reports?school_year=' . self::NEW);
        $new->assertOk()->assertSee('Section Agila')->assertDontSee('Section Narra');

        // Section + subject filters compose with the year.
        $filtered = $this->actingAs($this->admin)->get('/admin/reports?school_year=' . self::OLD . '&grading_period=1&section_id=' . $this->narra->id . '&subject_id=' . $this->subject->id);
        $filtered->assertOk()->assertSee('Section Narra')->assertSee('General Mathematics')->assertSee('80.00');

        // A tampered year silently falls back to the active year, never to "all".
        $tampered = $this->actingAs($this->principal)->get('/principal/reports?school_year=9999-0000');
        $tampered->assertOk()->assertSee('Section Agila')->assertDontSee('Section Narra');
    }

    // ------------------------------------------------------------------
    // TEST 9 — closed term / completed year write protection
    // ------------------------------------------------------------------
    public function test_9_adviser_writes_into_a_closed_term_are_rejected_and_records_unchanged(): void
    {
        $this->actingAs($this->admin)->post('/admin/academic-terms/1/close');
        $this->assertFalse(AcademicTerm::isOpen(self::OLD, 1));

        $this->assertAdviserWritesRejected($this->adviserNarra, 'is closed');
    }

    public function test_9b_adviser_writes_into_a_completed_year_are_rejected_even_if_its_term_flag_was_left_open(): void
    {
        $this->recordOldYearHistory();
        $this->activateNewYear();
        // Deliberately re-flag the old term open to prove the year check is independent.
        AcademicTerm::where('school_year', self::OLD)->where('term', 1)->update(['is_open' => true]);
        $this->assertTrue(AcademicTerm::isOpen(self::OLD, 1));
        $this->assertFalse(AcademicTerm::acceptsWrites(self::OLD, 1));

        $this->assertAdviserWritesRejected($this->adviserNarra, 'not the active school year');
    }

    private function assertAdviserWritesRejected(User $adviser, string $expectedMessageFragment): void
    {
        $gradesBefore = Grade::count();
        $scoresBefore = AssessmentScore::count();
        $reportsBefore = ReportSubmission::count();
        $assessmentsBefore = Assessment::count();

        $grade = $this->actingAs($adviser)->post('/adviser/grades', [
            'grading_period' => 1,
            'grades' => [['student_id' => $this->learner->id, 'subject_id' => $this->subject->id, 'grade' => 95]],
        ]);
        $grade->assertSessionHas('error');
        $this->assertStringContainsString($expectedMessageFragment, session('error'));

        $item = $this->actingAs($adviser)->post(route('adviser.assessments.item.store'), [
            'grading_period' => 1, 'subject_id' => $this->subject->id, 'item_name' => 'Quiz 9',
            'component' => 'written_work', 'max_score' => 10,
        ]);
        $item->assertSessionHasErrors([], null, 'addItem');

        $report = $this->actingAs($adviser)->post('/adviser/submit-report', ['grading_period' => 1]);
        $report->assertSessionHas('error');

        $this->assertSame($gradesBefore, Grade::count());
        $this->assertSame($scoresBefore, AssessmentScore::count());
        $this->assertSame($reportsBefore, ReportSubmission::count());
        $this->assertSame($assessmentsBefore, Assessment::count());
    }

    public function test_9c_historical_records_remain_viewable_by_the_adviser_and_principal(): void
    {
        $this->recordOldYearHistory();
        $this->activateNewYear();

        // The adviser has no 2027-2028 section: their old section is shown read-only.
        $this->actingAs($this->adviserNarra)->get('/adviser/dashboard')->assertOk()->assertSee('Historical Record')->assertSee('2026-2027');
        $this->actingAs($this->adviserNarra)->get('/adviser/grades?period=1')->assertOk()->assertSee('read-only');
        $this->actingAs($this->adviserNarra)->get('/adviser/assessments?period=1')->assertOk()->assertSee('read-only');

        $this->actingAs($this->principal)->get('/principal/students/' . $this->learner->id . '?school_year=' . self::OLD)
            ->assertOk()->assertSee('Historical Record')->assertSee('Narra');
    }

    // ------------------------------------------------------------------
    // TEST 10 — authorization: no cross-section access by URL/request
    // ------------------------------------------------------------------
    public function test_10_adviser_a_cannot_reach_adviser_bs_section_by_tampering_with_the_request(): void
    {
        $adviserB = User::factory()->create();
        $sectionB = Section::factory()->create(['school_year' => self::OLD, 'grade_level' => 11, 'adviser_id' => $adviserB->id, 'name' => 'Molave']);
        $studentB = Student::factory()->create(['section_id' => $sectionB->id, 'last_name' => 'Other', 'first_name' => 'Section']);

        // Grades: a student_id from B's section is refused even with A's open term.
        $this->actingAs($this->adviserNarra)->post('/adviser/grades', [
            'grading_period' => 1,
            'grades' => [['student_id' => $studentB->id, 'subject_id' => $this->subject->id, 'grade' => 90]],
        ]);
        $this->assertDatabaseMissing('grades', ['student_id' => $studentB->id]);

        // Student edit: B's learner id is not in A's roster.
        $this->actingAs($this->adviserNarra)->put('/adviser/students/' . $studentB->id, [
            'last_name' => 'Hacked', 'first_name' => 'Name', 'gender' => 'male',
        ])->assertNotFound();
        $this->assertSame('Other', $studentB->fresh()->last_name);

        // Pages never list B's learner, and a section_id parameter is ignored.
        $this->actingAs($this->adviserNarra)->get('/adviser/students?section_id=' . $sectionB->id)->assertOk()->assertDontSee('Other, Section');

        // Principal can view DSS pages but not Admin CRUD; adviser cannot manage years.
        $this->actingAs($this->principal)->get('/admin/students')->assertForbidden();
        $this->actingAs($this->principal)->post('/admin/students/' . $this->learner->id . '/enroll', ['section_id' => $sectionB->id])->assertForbidden();
        $this->actingAs($this->adviserNarra)->post('/admin/students/' . $this->learner->id . '/enroll', ['section_id' => $sectionB->id])->assertForbidden();
        $this->actingAs($this->adviserNarra)->post('/admin/academic-years/1/activate')->assertForbidden();
    }

    public function test_10b_an_adviser_assigned_to_a_new_year_section_sees_that_section_not_the_old_one(): void
    {
        $this->activateNewYear();
        $agila = Section::where('school_year', self::NEW)->firstOrFail();
        $agila->update(['adviser_id' => $this->adviserNarra->id]);

        $this->assertSame($agila->id, Section::forAdviser($this->adviserNarra->id)->id);
        $this->actingAs($this->adviserNarra)->get('/adviser/dashboard')->assertOk()->assertSee('Agila')->assertSee('Active school year');
    }

    // ------------------------------------------------------------------
    // TEST 11 — promotion / enrollment
    // ------------------------------------------------------------------
    public function test_11_promotion_creates_a_second_enrollment_context_for_one_learner_identity(): void
    {
        $this->activateNewYear();
        $agila = Section::where('school_year', self::NEW)->firstOrFail();
        $lrn = $this->learner->lrn;
        $oldEnrollment = $this->learner->enrollmentFor(self::OLD);
        $this->assertNotNull($oldEnrollment);
        $this->assertSame(11, $oldEnrollment->grade_level);

        $response = $this->actingAs($this->admin)->post('/admin/students/' . $this->learner->id . '/enroll', ['section_id' => $agila->id]);
        $response->assertRedirect(route('admin.students'))->assertSessionHas('success');

        $this->assertSame(1, Student::where('lrn', $lrn)->count());
        $this->assertSame(2, StudentEnrollment::where('student_id', $this->learner->id)->count());

        $old = $this->learner->fresh()->enrollmentFor(self::OLD);
        $new = $this->learner->fresh()->enrollmentFor(self::NEW);
        $this->assertSame($oldEnrollment->id, $old->id);
        $this->assertSame($this->narra->id, $old->section_id);
        $this->assertSame(11, $old->grade_level);
        $this->assertSame('sshs', $old->curriculum);
        $this->assertSame($agila->id, $new->section_id);
        $this->assertSame(12, $new->grade_level);
        $this->assertSame('k12_2013', $new->curriculum);
        $this->assertSame(AcademicYear::where('school_year', self::NEW)->first()->id, $new->academic_year_id);

        // Current pointer follows the active year; the old section still lists the learner as enrolled.
        $this->assertSame($agila->id, $this->learner->fresh()->section_id);
        $this->assertTrue($this->narra->enrolledStudents()->where('students.id', $this->learner->id)->exists());
        $this->assertTrue(Student::enrolledIn($this->narra)->where('id', $this->learner->id)->exists());
        $this->assertDatabaseHas('activity_logs', ['action' => 'enroll_student']);

        // A second enrollment for the same year is refused, not silently overwritten.
        $again = $this->actingAs($this->admin)->post('/admin/students/' . $this->learner->id . '/enroll', ['section_id' => $agila->id]);
        $again->assertSessionHasErrors([], null, 'enroll');
        $this->assertSame(2, StudentEnrollment::where('student_id', $this->learner->id)->count());
    }

    public function test_11b_enrolling_into_a_section_whose_year_is_not_configured_is_refused(): void
    {
        $stray = Section::factory()->create(['school_year' => '2031-2032', 'grade_level' => 12]);

        $this->expectException(ValidationException::class);
        app(StudentEnrollmentService::class)->enroll($this->learner, $stray);
    }

    public function test_11c_creating_or_moving_a_student_keeps_the_enrollment_row_in_sync(): void
    {
        $fresh = Student::factory()->create(['section_id' => $this->narra->id]);
        $this->assertDatabaseHas('student_enrollments', ['student_id' => $fresh->id, 'section_id' => $this->narra->id, 'school_year' => self::OLD, 'grade_level' => 11]);

        // Same-year correction updates the SAME row; no duplicate.
        $molave = Section::factory()->create(['school_year' => self::OLD, 'grade_level' => 11, 'name' => 'Molave']);
        $fresh->update(['section_id' => $molave->id]);
        $this->assertSame(1, StudentEnrollment::where('student_id', $fresh->id)->count());
        $this->assertSame($molave->id, $fresh->enrollmentFor(self::OLD)->section_id);
    }

    // ------------------------------------------------------------------
    // TEST 12 — student with history cannot be destructively deleted
    // ------------------------------------------------------------------
    public function test_12_a_student_with_academic_history_is_not_deleted_through_the_admin_ui(): void
    {
        $old = $this->recordOldYearHistory();

        $response = $this->actingAs($this->admin)->delete('/admin/students/' . $this->learner->id);
        $response->assertSessionHasErrors('deletion');

        $this->assertDatabaseHas('students', ['id' => $this->learner->id]);
        $this->assertDatabaseHas('grades', ['id' => $old['grade']->id]);
        $this->assertDatabaseHas('risk_results', ['id' => $old['risk']->id]);
        $this->assertDatabaseHas('interventions', ['id' => $old['intervention']->id]);
        $this->assertDatabaseHas('report_submissions', ['id' => $old['report']->id]);
        $this->assertDatabaseHas('student_enrollments', ['student_id' => $this->learner->id]);
    }

    // ------------------------------------------------------------------
    // TEST 13 — no delete for academic years / terms
    // ------------------------------------------------------------------
    public function test_13_there_is_no_route_that_deletes_an_academic_year_or_term(): void
    {
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (str_contains($uri, 'academic-year') || str_contains($uri, 'academic-term')) {
                $this->assertNotContains('DELETE', $route->methods(), "Delete route found: {$uri}");
            }
        }

        $this->assertNull(Route::getRoutes()->getByName('admin.academic-years.destroy'));
        $this->assertNull(Route::getRoutes()->getByName('admin.academic-terms.destroy'));

        // Nor does the page render a removal action for either — and the
        // confirmations it does render carry action-specific wording
        // (PART 19), never the Delete modal's.
        $this->createNewYearWithAgila();
        $page = $this->actingAs($this->admin)->get('/admin/academic-terms');
        $page->assertOk();
        $html = $page->getContent();
        $this->assertStringNotContainsString('/admin/academic-years/1/delete', $html);
        $this->assertStringNotContainsString('data-confirm="', substr($html, strpos($html, 'Academic Years'), strpos($html, 'EDIT ACADEMIC YEAR MODAL') - strpos($html, 'Academic Years')));
        $this->assertStringContainsString('data-action-title="Confirm Activation"', $html);
        $this->assertStringContainsString('data-action-label="Yes, Activate"', $html);
        $this->assertStringContainsString('data-action-title="Confirm Close"', $html);
        $this->assertStringContainsString('data-action-label="Yes, Close"', $html);
    }

    // ------------------------------------------------------------------
    // TEST 14 — current-year default, historical selectable
    // ------------------------------------------------------------------
    public function test_14_principal_and_report_views_default_to_the_active_year_and_keep_the_old_year_selectable(): void
    {
        $this->recordOldYearHistory();
        $this->activateNewYear();
        $this->promoteLearner();

        $dashboard = $this->actingAs($this->principal)->get('/principal/dashboard');
        $dashboard->assertOk()->assertSee('2027-2028 (active)')->assertSee('<option value="2026-2027"', false);

        $reports = $this->actingAs($this->principal)->get('/principal/reports');
        $reports->assertOk()->assertSee('School Year 2027-2028')->assertSee('Section Agila')->assertDontSee('Section Narra');
        $this->assertStringContainsString('<option value="2026-2027"', $reports->getContent());

        $students = $this->actingAs($this->principal)->get('/principal/students');
        $students->assertOk()->assertSee('2027-2028 (active)');

        $oldStudents = $this->actingAs($this->principal)->get('/principal/students?school_year=' . self::OLD);
        $oldStudents->assertOk()->assertSee('Historical Record');

        $terms = $this->actingAs($this->admin)->get('/admin/academic-terms');
        $terms->assertOk()->assertSee('Term Control — School Year 2027-2028')->assertSee('Completed — Historical Record');

        $oldTerms = $this->actingAs($this->admin)->get('/admin/academic-terms?school_year=' . self::OLD);
        $oldTerms->assertOk()->assertSee('Term Control — School Year 2026-2027')->assertSee('read-only');
        $this->assertStringNotContainsString('Open Term 2', $oldTerms->getContent());
    }

    public function test_14b_the_active_year_is_read_from_the_database_not_a_hardcoded_string(): void
    {
        $this->assertSame(self::OLD, Section::activeSchoolYear());
        AcademicYear::create(['school_year' => '2030-2031', 'is_active' => false])->activate();
        $this->assertSame('2030-2031', Section::activeSchoolYear());
        $this->assertSame('2030-2031', AcademicYear::resolveSelected(null));
        $this->assertSame(self::OLD, AcademicYear::resolveSelected(self::OLD));
        $this->assertSame('2030-2031', AcademicYear::resolveSelected('not-a-year'));
    }

    // ------------------------------------------------------------------
    // Section master data: adviser uniqueness is per school year; a
    // section with history cannot be moved to another year.
    // ------------------------------------------------------------------
    public function test_an_adviser_can_be_assigned_a_section_in_the_new_year_but_a_section_with_history_cannot_change_year(): void
    {
        $this->recordOldYearHistory();
        [, $agila] = $this->createNewYearWithAgila();

        $payload = fn(Section $s, array $o = []) => array_merge([
            'name' => $s->name, 'grade_level' => $s->grade_level, 'track_id' => \App\Models\Track::factory()->create()->id,
            'specialization_id' => null, 'school_year' => $s->school_year, 'adviser_id' => '',
        ], $o);
        $track = \App\Models\Track::factory()->create();
        $spec = \App\Models\Specialization::factory()->create(['track_id' => $track->id]);

        // Same adviser, different year: allowed.
        $ok = $this->actingAs($this->admin)->put('/admin/sections/' . $agila->id, $payload($agila, [
            'track_id' => $track->id, 'specialization_id' => $spec->id, 'adviser_id' => $this->adviserNarra->id,
        ]));
        $ok->assertSessionHasNoErrors();
        $this->assertSame($this->adviserNarra->id, $agila->fresh()->adviser_id);

        // Same adviser, same year, second section: refused.
        $molave = Section::factory()->create(['school_year' => self::OLD, 'grade_level' => 11, 'adviser_id' => null]);
        $dup = $this->actingAs($this->admin)->put('/admin/sections/' . $molave->id, $payload($molave, [
            'track_id' => $track->id, 'specialization_id' => $spec->id, 'adviser_id' => $this->adviserNarra->id,
        ]));
        $dup->assertSessionHasErrors('adviser_id');

        // Narra has history: its school year cannot be changed.
        $move = $this->actingAs($this->admin)->put('/admin/sections/' . $this->narra->id, $payload($this->narra, [
            'track_id' => $track->id, 'specialization_id' => $spec->id, 'school_year' => self::NEW,
        ]));
        $move->assertSessionHasErrors('school_year');
        $this->assertSame(self::OLD, $this->narra->fresh()->school_year);
    }
}
