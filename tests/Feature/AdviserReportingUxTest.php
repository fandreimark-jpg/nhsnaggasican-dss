<?php

namespace Tests\Feature;

use App\Models\AcademicConcern;
use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Grade;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Track;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Final Adviser reporting UX (2026-09-27):
 *  - My Students is a concise, view-only roster: identity + a short
 *    Monitoring reading + "View details". No averages, no Passed/Failed, no
 *    evidence dump, and no Edit — learner master data is Admin-owned.
 *  - Submit Report carries the detailed Official Term Results: Overall
 *    Average, Passed/Failed counts, every failed subject and grade, what is
 *    not yet official, and the full Monitoring evidence.
 * The professor scenario (a passing average that hides one failed subject)
 * is built from data and read back from the page — nothing is hard-coded.
 */
class AdviserReportingUxTest extends TestCase
{
    use RefreshDatabase;

    private User $adviser;
    private Section $section;
    /** @var array<string, Subject> */
    private array $subjects = [];
    private Student $navarro;     // average 77.00, General Science 74.00 — Failed
    private Student $evangelista; // three failed subjects
    private Student $lazaro;      // clean

    protected function setUp(): void
    {
        parent::setUp();

        AcademicYear::create(['school_year' => '2026-2027', 'is_active' => true]);
        AcademicTerm::ensureExistFor('2026-2027');
        AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->update(['is_open' => true]);

        $this->adviser = User::factory()->create(['role' => 'adviser']);
        $this->section = Section::factory()->create([
            'name' => 'Shakespeare', 'grade_level' => 11, 'school_year' => '2026-2027',
            'track_id' => Track::factory()->create()->id, 'adviser_id' => $this->adviser->id,
        ]);
        foreach (['Effective Communication', 'General Mathematics', 'General Science'] as $name) {
            $this->subjects[$name] = Subject::factory()->create(['name' => $name, 'type' => 'core', 'grade_level' => 11, 'subject_group' => 'core_academic']);
        }
        $this->navarro = Student::factory()->create(['section_id' => $this->section->id, 'last_name' => 'Navarro', 'first_name' => 'Maria', 'middle_name' => 'Cruz', 'lrn' => '120000000001']);
        $this->evangelista = Student::factory()->create(['section_id' => $this->section->id, 'last_name' => 'Evangelista', 'first_name' => 'Daniel', 'middle_name' => null, 'lrn' => '120000000002']);
        $this->lazaro = Student::factory()->create(['section_id' => $this->section->id, 'last_name' => 'Lazaro', 'first_name' => 'James', 'lrn' => '120000000003']);

        $this->grades($this->navarro, [80, 77, 74]);
        $this->grades($this->evangelista, [74, 74, 74]);
        $this->grades($this->lazaro, [95, 95, 95]);
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

    private function rosterRow(string $html, Student $s): string
    {
        $start = strpos($html, 'data-student-row="' . $s->id . '"');
        $this->assertNotFalse($start);

        return substr($html, $start, strpos($html, '</tr>', $start) - $start);
    }

    private function resultRow(string $html, Student $s): string
    {
        $start = strpos($html, 'id="learner-' . $s->id . '"');
        $this->assertNotFalse($start);

        return substr($html, $start, strpos($html, '</tr>', $start) - $start);
    }

    public function test_my_students_is_a_concise_view_only_roster(): void
    {
        $page = $this->actingAs($this->adviser)->get(route('adviser.students', ['period' => 1]))->assertOk();
        $html = $page->getContent();

        foreach (['LRN', 'Last Name', 'First Name', 'M.I.', 'Sex', 'Monitoring', 'Action'] as $header) {
            $this->assertMatchesRegularExpression('/<th[^>]*>\s*' . preg_quote($header, '/') . '/', $html, $header . ' column');
        }
        // No report dump on the roster.
        foreach (['Overall Average', 'Official Results', 'Passed', 'data-failed-subject', 'data-monitoring-evidence', 'official grade 74.00'] as $absent) {
            $this->assertStringNotContainsString($absent, $html, $absent . ' is not on My Students');
        }
        $this->assertStringNotContainsString('Edit', $html);

        $navarro = $this->rosterRow($html, $this->navarro);
        $this->assertStringContainsString('120000000001', $navarro);
        $this->assertStringContainsString('C.', $navarro, 'middle initial');
        $this->assertStringContainsString('For Review · 1 concern', $navarro);
        $this->assertStringContainsString('Subject Performance Concern', $navarro);
        $this->assertStringNotContainsString('74.00', $navarro);

        $this->assertStringContainsString('For Review · 3 concerns', $this->rosterRow($html, $this->evangelista));
        $this->assertStringContainsString('—', $this->rosterRow($html, $this->evangelista), 'no middle name reads as a dash');

        $lazaro = $this->rosterRow($html, $this->lazaro);
        $this->assertStringContainsString('No Current Concern', $lazaro);

        // "View details" lands on the learner's row in Submit Report, same term.
        $this->assertStringContainsString(e(route('adviser.submit.report', ['period' => 1])) . '#learner-' . $this->navarro->id, $navarro);
        $this->assertStringContainsString('2 of 3 For Review', $html);
    }

    public function test_the_professor_scenario_reads_from_the_data_on_submit_report(): void
    {
        $page = $this->actingAs($this->adviser)->get(route('adviser.submit.report', ['period' => 1]))->assertOk();
        $html = $page->getContent();
        $r = $page->viewData('officialResults')[$this->navarro->id];

        $this->assertSame(77.0, $r['overall_average']);
        $this->assertSame(2, $r['passed']);
        $this->assertSame(1, $r['failed']);
        $this->assertSame([['subject' => 'General Science', 'term' => 1, 'grade' => 74.0]], $r['failed_subjects']);

        $row = $this->resultRow($html, $this->navarro);
        $this->assertStringContainsString('77.00', $row);
        $this->assertStringContainsString('General Science — 74.00 — Failed', $row);
        $this->assertStringContainsString('data-monitoring-status="for-review"', $row);
        $this->assertStringContainsString('General Science — official grade 74.00 (Failed)', $row);
        $this->assertStringContainsString('Official Term Results — Term 1', $html);
        $this->assertStringContainsString('never hides a failed subject', $html);

        // Every failed subject is named, not just counted.
        $many = $this->resultRow($html, $this->evangelista);
        $this->assertSame(3, substr_count($many, 'data-failed-subject'));
        foreach ($this->subjects as $name => $_) {
            $this->assertStringContainsString($name . ' — 74.00 — Failed', $many);
        }

        $this->assertStringContainsString('None', $this->resultRow($html, $this->lazaro));
        $this->assertStringContainsString('No Current Concern', $this->resultRow($html, $this->lazaro));
        $page->assertDontSee('Attendance')->assertDontSee('Risk Level');
    }

    public function test_a_missing_requirement_is_not_an_official_failed_subject_and_unverified_is_not_official(): void
    {
        $item = \App\Models\Assessment::factory()->create([
            'subject_id' => $this->subjects['General Mathematics']->id, 'section_id' => $this->section->id, 'grading_period' => 1,
            'school_year' => '2026-2027', 'name' => 'Quiz 1', 'assessment_type' => 'Quiz 1',
            'component' => 'written_work', 'max_score' => 20, 'uploaded_by' => $this->adviser->id,
        ]);
        (new \App\Services\AssessmentStatusService())->markMissing($item, $this->lazaro->id, $this->adviser->id);
        app(\App\Services\AcademicMonitoringService::class)->synchronizeAssessments([$item->id]);
        Grade::where('student_id', $this->lazaro->id)->where('subject_id', $this->subjects['General Science']->id)
            ->update(['grade' => 60, 'is_verified' => false]);

        $page = $this->actingAs($this->adviser)->get(route('adviser.submit.report', ['period' => 1]))->assertOk();
        $r = $page->viewData('officialResults')[$this->lazaro->id];
        $this->assertSame(0, $r['failed']);
        $this->assertSame(1, $r['not_official']);

        $row = $this->resultRow($page->getContent(), $this->lazaro);
        $this->assertStringNotContainsString('data-failed-subject', $row);
        $this->assertStringContainsString('1 not yet official', $row);
        $this->assertStringContainsString('Missing Requirement: Quiz 1 — General Mathematics', $row);
        $this->assertSame(0, AcademicConcern::where('student_id', $this->lazaro->id)->where('rule_key', 'official_failing_subject_grade')->count());
    }

    public function test_the_term_selector_shows_a_term_with_no_grades_honestly(): void
    {
        $page = $this->actingAs($this->adviser)->get(route('adviser.submit.report', ['period' => 2]))->assertOk();
        $this->assertSame(2, $page->viewData('resultsPeriod'));
        $row = $this->resultRow($page->getContent(), $this->navarro);
        $this->assertStringContainsString('No grades for Term 2 yet', $row);
        $this->assertStringContainsString('No Current Concern', $row);

        // An out-of-range term falls back, never errors.
        $this->actingAs($this->adviser)->get(route('adviser.submit.report', ['period' => 9]))->assertOk()->assertViewHas('resultsPeriod', 1);
    }

    public function test_the_adviser_cannot_edit_add_or_upload_learner_master_data(): void
    {
        $this->assertFalse(Route::has('adviser.students.update'));
        $this->assertFalse(Route::has('adviser.students.store'));
        $this->assertFalse(Route::has('adviser.students.import'));
        $before = $this->navarro->only(['lrn', 'last_name', 'first_name', 'middle_name', 'gender', 'section_id']);

        $this->actingAs($this->adviser)->put('/adviser/students/' . $this->navarro->id, [
            'last_name' => 'Changed', 'first_name' => 'Changed', 'gender' => 'male',
        ])->assertNotFound();
        $this->actingAs($this->adviser)->post('/adviser/students', ['lrn' => '120000000099', 'last_name' => 'X', 'first_name' => 'Y', 'gender' => 'male'])
            ->assertStatus(405);
        $this->actingAs($this->adviser)->post(route('admin.students.store'), [
            'lrn' => '120000000099', 'last_name' => 'X', 'first_name' => 'Y', 'gender' => 'male', 'section_id' => $this->section->id,
        ])->assertForbidden();
        $this->actingAs($this->adviser)->put(route('admin.students.update', $this->navarro->id), [
            'lrn' => $this->navarro->lrn, 'last_name' => 'Changed', 'first_name' => 'Changed', 'gender' => 'male', 'section_id' => $this->section->id,
        ])->assertForbidden();
        $this->actingAs($this->adviser)->post(route('admin.students.import'), [
            'section_id' => $this->section->id,
            'file' => UploadedFile::fake()->createWithContent('l.csv', "lrn,last_name,first_name,middle_name,gender\n120000000098,A,B,,male\n"),
        ])->assertForbidden();

        $this->assertSame($before, $this->navarro->fresh()->only(array_keys($before)));
        $this->assertSame(3, Student::count());
    }

    public function test_other_pages_are_unchanged_and_layouts_scroll_on_small_screens(): void
    {
        $this->actingAs($this->adviser)->get(route('adviser.dashboard'))->assertOk();
        $this->actingAs($this->adviser)->get(route('adviser.grades'))->assertOk();

        $roster = $this->actingAs($this->adviser)->get(route('adviser.students'))->getContent();
        $this->assertStringContainsString('card overflow-x-auto', $roster);
        $report = $this->actingAs($this->adviser)->get(route('adviser.submit.report'))->getContent();
        $this->assertMatchesRegularExpression('/data-official-term-results>.*?class="tbl-scroll/s', $report);
    }
}
