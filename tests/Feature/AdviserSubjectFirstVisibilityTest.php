<?php

namespace Tests\Feature;

use App\Models\AcademicConcern;
use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\Grade;
use App\Models\Intervention;
use App\Models\RiskResult;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Track;
use App\Models\User;
use App\Services\AcademicMonitoringService;
use App\Services\AssessmentStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Adviser sees the SAME subject-first academic truth the Principal sees
 * (MonitoringClassificationService + SubjectResultSummary) — briefly on My
 * Students, in full on Submit Report's Official Term Results — without
 * gaining the Principal's decisions.
 */
class AdviserSubjectFirstVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $principal;
    private User $adviser;
    private Section $section;
    /** @var array<string, Subject> */
    private array $subjects = [];
    private Student $one;      // one failed subject, passing average
    private Student $many;     // three failed subjects
    private Student $missing;  // Missing Requirement only
    private Student $clean;    // nothing open

    protected function setUp(): void
    {
        parent::setUp();

        AcademicYear::create(['school_year' => '2026-2027', 'is_active' => true]);
        AcademicTerm::ensureExistFor('2026-2027');
        AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->update(['is_open' => true]);

        $this->principal = User::factory()->principal()->create();
        $this->adviser = User::factory()->create(['role' => 'adviser']);
        $this->section = Section::factory()->create([
            'name' => 'Curie', 'grade_level' => 11, 'school_year' => '2026-2027',
            'track_id' => Track::factory()->create()->id, 'adviser_id' => $this->adviser->id,
        ]);
        foreach (['Effective Communication', 'General Mathematics', 'General Science'] as $name) {
            $this->subjects[$name] = Subject::factory()->create(['name' => $name, 'type' => 'core', 'grade_level' => 11, 'subject_group' => 'core_academic']);
        }
        $mk = fn($last, $lrn) => Student::factory()->create(['section_id' => $this->section->id, 'last_name' => $last, 'first_name' => 'A', 'lrn' => $lrn]);
        $this->one = $mk('ONEFAIL', '110000000001');
        $this->many = $mk('MANYFAIL', '110000000002');
        $this->missing = $mk('MISSINGONLY', '110000000003');
        $this->clean = $mk('CLEAN', '110000000004');

        $this->grades($this->one, [92, 72, 93]);
        $this->grades($this->many, [74, 73, 74]);
        $this->grades($this->missing, [85, 86, 87]);
        $this->grades($this->clean, [88, 89, 90]);

        $item = Assessment::factory()->create([
            'subject_id' => $this->subjects['General Science']->id, 'section_id' => $this->section->id, 'grading_period' => 1,
            'school_year' => '2026-2027', 'name' => 'Performance Task 2', 'assessment_type' => 'Performance Task 2',
            'component' => 'performance_task', 'max_score' => 50, 'uploaded_by' => $this->adviser->id,
        ]);
        (new AssessmentStatusService())->markMissing($item, $this->missing->id, $this->adviser->id);
        app(AcademicMonitoringService::class)->synchronizeAssessments([$item->id]);
    }

    private function grades(Student $s, array $values): void
    {
        foreach (array_values($this->subjects) as $i => $subject) {
            Grade::create([
                'student_id' => $s->id, 'subject_id' => $subject->id, 'section_id' => $this->section->id, 'grading_period' => 1,
                'school_year' => '2026-2027', 'grade' => $values[$i], 'is_verified' => true, 'encoded_by' => $this->adviser->id,
            ]);
        }
    }

    /** A learner's row on My Students (the roster), found by LRN. */
    private function row(string $html, Student $s): string
    {
        $start = strpos($html, $s->lrn);
        $this->assertNotFalse($start, $s->last_name . ' is listed');

        return substr($html, $start, strpos($html, '</tr>', $start) - $start);
    }

    /** A learner's row in Submit Report's Official Term Results. */
    private function resultRow(string $html, Student $s): string
    {
        $start = strpos($html, 'id="learner-' . $s->id . '"');
        $this->assertNotFalse($start, $s->last_name . ' has an official results row');

        return substr($html, $start, strpos($html, '</tr>', $start) - $start);
    }

    private function myStudents()
    {
        return $this->actingAs($this->adviser)->get(route('adviser.students', ['period' => 1]))->assertOk();
    }

    private function submitReport()
    {
        return $this->actingAs($this->adviser)->get(route('adviser.submit.report', ['period' => 1]))->assertOk();
    }

    public function test_adviser_sees_failed_subject_even_when_overall_average_is_passing(): void
    {
        $page = $this->submitReport();
        $row = $this->resultRow($page->getContent(), $this->one);
        $r = $page->viewData('officialResults')[$this->one->id];

        $this->assertSame(85.67, $r['overall_average']);
        $this->assertSame([2, 1], [$r['passed'], $r['failed']]);
        $this->assertStringContainsString('85.67', $row);
        $this->assertStringContainsString('General Mathematics — 72.00 — Failed', $row);
        $this->assertSame(1, substr_count($row, 'data-failed-subject'));
        $this->assertStringContainsString('data-monitoring-status="for-review"', $row);
        $this->assertStringContainsString('Subject Performance Concern: General Mathematics — official grade 72.00 (Failed)', $row);

        // The roster says the same thing, briefly.
        $roster = $this->row($this->myStudents()->getContent(), $this->one);
        $this->assertStringContainsString('For Review · 1 concern', $roster);
        $this->assertStringContainsString('Subject Performance Concern', $roster);
    }

    public function test_multiple_failed_subjects_missing_requirement_and_a_clean_learner(): void
    {
        $page = $this->submitReport();
        $html = $page->getContent();

        $many = $this->resultRow($html, $this->many);
        $this->assertSame(3, $page->viewData('officialResults')[$this->many->id]['failed']);
        $this->assertStringContainsString('3 Open Concerns: Subject Performance Concern (3)', $many);
        foreach (['Effective Communication — 74.00 — Failed', 'General Mathematics — 73.00 — Failed', 'General Science — 74.00 — Failed'] as $line) {
            $this->assertStringContainsString($line, $many);
        }
        $this->assertStringNotContainsString('Risk', $many);

        $missing = $this->resultRow($html, $this->missing);
        $this->assertStringContainsString('Missing Requirement: Performance Task 2 — General Science', $missing);
        $this->assertSame(0, $page->viewData('officialResults')[$this->missing->id]['failed'], 'a Missing Requirement is not a failed subject');
        $this->assertStringNotContainsString('data-failed-subject', $missing);

        $clean = $this->resultRow($html, $this->clean);
        $this->assertStringContainsString('data-monitoring-status="none"', $clean);
        $this->assertStringContainsString('No Current Concern', $clean);

        $roster = $this->myStudents()->getContent();
        $this->assertStringContainsString('For Review · 3 concerns', $this->row($roster, $this->many));
        $this->assertStringContainsString('Missing Requirement', $this->row($roster, $this->missing));
        $this->assertStringContainsString('3 of 4 For Review', $roster);
    }

    public function test_adviser_and_principal_show_the_same_academic_truth(): void
    {
        $roster = $this->myStudents();
        $report = $this->submitReport();
        foreach ([$this->one, $this->many, $this->missing, $this->clean] as $s) {
            $principal = $this->actingAs($this->principal)->get(route('principal.students.show', ['student' => $s->id, 'period' => 1]))->assertOk();
            $this->assertSame($principal->viewData('subjectResults'), $report->viewData('officialResults')[$s->id], $s->last_name . ' results agree');
            $this->assertEquals($principal->viewData('monitoring'), $roster->viewData('monitoring')->get($s->id), $s->last_name . ' roster monitoring agrees');
            $this->assertEquals($principal->viewData('monitoring'), $report->viewData('monitoring')->get($s->id), $s->last_name . ' report monitoring agrees');
        }
    }

    public function test_unofficial_grades_attendance_and_risk_results_change_nothing_on_the_adviser_side(): void
    {
        Grade::where('student_id', $this->clean->id)->where('subject_id', $this->subjects['General Science']->id)
            ->update(['grade' => 70, 'is_verified' => false]); // not yet official: never Failed
        RiskResult::create([
            'student_id' => $this->clean->id, 'section_id' => $this->section->id, 'grading_period' => 1,
            'average_grade' => 50, 'risk_level' => 'high', 'school_year' => '2026-2027', 'generated_at' => now(),
        ]);

        $page = $this->submitReport();
        $row = $this->resultRow($page->getContent(), $this->clean);
        $this->assertSame(0, $page->viewData('officialResults')[$this->clean->id]['failed']);
        $this->assertStringContainsString('1 not yet official', $row);
        $this->assertStringContainsString('No Current Concern', $row);
        $page->assertDontSee('Attendance');
        $this->myStudents()->assertDontSee('Attendance');
    }

    public function test_the_adviser_sees_but_cannot_decide(): void
    {
        $concern = AcademicConcern::where('student_id', $this->one->id)->sole();

        $this->actingAs($this->adviser)->post(route('principal.academic-monitoring.review', $concern))->assertForbidden();
        $this->actingAs($this->adviser)->post(route('principal.interventions.store'), [
            'academic_concern_id' => $concern->id, 'student_id' => $this->one->id,
            'subject_id' => $this->subjects['General Mathematics']->id, 'grading_period' => 1, 'recommended_type' => 'remediation',
        ])->assertForbidden();
        $this->assertSame(0, Intervention::count());
        $this->assertNull($concern->fresh()->reviewed_at);
        $this->assertTrue($concern->fresh()->isOpen());

        // Neither adviser page offers a decision control.
        $this->myStudents()->assertDontSee('Mark as Reviewed')->assertDontSee('Record Intervention');
        $this->submitReport()->assertDontSee('Mark as Reviewed')->assertDontSee('Record Intervention');

        // Another adviser never sees this section's learners.
        $other = User::factory()->create(['role' => 'adviser']);
        Section::factory()->create(['school_year' => '2026-2027', 'grade_level' => 11, 'adviser_id' => $other->id]);
        $this->actingAs($other)->get(route('adviser.students'))->assertOk()->assertDontSee($this->one->lrn);
        $this->actingAs($other)->get(route('adviser.submit.report'))->assertOk()->assertDontSee($this->one->lrn)->assertDontSee('ONEFAIL');
    }
}
