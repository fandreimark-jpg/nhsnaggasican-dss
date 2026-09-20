<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Grade;
use App\Models\Intervention;
use App\Models\RiskResult;
use App\Models\Section;
use App\Models\SectionSubject;
use App\Models\Specialization;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Track;
use App\Models\User;
use App\Services\RiskFeatureExtractor;
use App\Services\SectionElectiveStatus;
use App\Services\SubjectAnalysisService;
use App\Services\SubjectApplicabilityService;
use App\Services\SubjectOfferingService;
use App\Services\TermReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\BuildsEcrFixture;
use Tests\TestCase;

/**
 * "Subject applicability" refactor (2026-09-20) — THE test of record.
 *
 * Admin > Subjects configures where and when a subject applies (grade
 * level, track/specialization, TERMS TAUGHT); SubjectApplicabilityService
 * resolves every section's subjects from that, per term, and every
 * Adviser / Principal / Admin screen and write guard reads the same
 * answer. The scenario, from the work order:
 *
 *   General Mathematics      core      Term 1 only
 *   General Science          core      Term 1, Term 2
 *   Effective Communication  core      Term 1, Term 2, Term 3
 *   Entrepreneurship         core      Term 2, Term 3
 *   Basic Calculus           elective  Term 2 — chosen for Shakespeare only
 *   Biology                  elective  every term — available, chosen by nobody
 *
 * Shakespeare and Curie are both Grade 11 SSHS sections in 2026-2027.
 */
class SubjectApplicabilityTest extends TestCase
{
    use RefreshDatabase;
    use BuildsEcrFixture;

    private User $admin;
    private User $adviser;
    private User $curieAdviser;
    private User $principal;
    private Section $shakespeare;
    private Section $curie;
    private Track $track;
    private Subject $genMath;
    private Subject $genSci;
    private Subject $effComm;
    private Subject $entrep;
    private Subject $basicCalc;
    private Subject $biology;
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin        = User::factory()->admin()->create();
        $this->adviser      = User::factory()->create();
        $this->curieAdviser = User::factory()->create();
        $this->principal    = User::factory()->principal()->create();

        AcademicYear::create(['school_year' => '2026-2027', 'is_active' => true]);
        AcademicTerm::ensureExistFor('2026-2027');

        $this->track = Track::factory()->create(['code' => 'ACAD']);
        $this->shakespeare = Section::factory()->create([
            'name' => 'Shakespeare', 'grade_level' => 11, 'curriculum' => 'sshs', 'track_id' => $this->track->id,
            'specialization_id' => null, 'adviser_id' => $this->adviser->id, 'school_year' => '2026-2027',
        ]);
        $this->curie = Section::factory()->create([
            'name' => 'Curie', 'grade_level' => 11, 'curriculum' => 'sshs', 'track_id' => $this->track->id,
            'specialization_id' => null, 'adviser_id' => $this->curieAdviser->id, 'school_year' => '2026-2027',
        ]);

        $core = fn(string $name, array $terms) => Subject::factory()->taughtIn($terms)->create(['name' => $name, 'type' => 'core', 'grade_level' => 11, 'subject_group' => 'core_academic']);
        $elective = fn(string $name, array $terms) => Subject::factory()->taughtIn($terms)->create(['name' => $name, 'type' => 'elective', 'grade_level' => 11, 'track_id' => $this->track->id, 'subject_group' => 'academic_other']);

        $this->genMath   = $core('General Mathematics', [1]);
        $this->genSci    = $core('General Science', [1, 2]);
        $this->effComm   = $core('Effective Communication', [1, 2, 3]);
        $this->entrep    = $core('Entrepreneurship', [2, 3]);
        $this->basicCalc = $elective('Basic Calculus', [2]);
        $this->biology   = $elective('Biology', [1, 2, 3]);

        (new SubjectOfferingService())->chooseElective($this->shakespeare, $this->basicCalc);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function names(Section $section, ?int $term): array
    {
        return Subject::forSection($section, $term)->orderBy('name')->pluck('name')->all();
    }

    private function openTerm(int $term): void
    {
        AcademicTerm::where('school_year', '2026-2027')->update(['is_open' => false]);
        AcademicTerm::where('school_year', '2026-2027')->where('term', $term)->update(['is_open' => true]);
    }

    private function subjectForm(array $overrides = []): array
    {
        return array_merge([
            'name' => 'New Subject', 'type' => 'core', 'grade_level' => 11,
            'subject_group' => 'core_academic', 'terms' => [1],
        ], $overrides);
    }

    // -----------------------------------------------------------------
    // 1, 2, 3, 4 — Admin > Subjects: Terms Taught
    // -----------------------------------------------------------------

    public function test_admin_can_create_a_subject_taught_in_term_1_only(): void
    {
        $this->actingAs($this->admin)->post(route('admin.subjects.store'), $this->subjectForm(['name' => 'Term One Only', 'terms' => [1]]))
            ->assertRedirect(route('admin.subjects'))->assertSessionHas('success');

        $subject = Subject::where('name', 'Term One Only')->firstOrFail();
        $this->assertSame([1], $subject->termNumbers());
        $this->assertDatabaseHas('subject_terms', ['subject_id' => $subject->id, 'term' => 1]);
        $this->assertDatabaseMissing('subject_terms', ['subject_id' => $subject->id, 'term' => 2]);
    }

    public function test_admin_can_create_a_subject_taught_in_several_terms(): void
    {
        $this->actingAs($this->admin)->post(route('admin.subjects.store'), $this->subjectForm(['name' => 'Two Terms', 'terms' => ['3', '1']]))
            ->assertSessionHas('success');

        $this->assertSame([1, 3], Subject::where('name', 'Two Terms')->firstOrFail()->termNumbers(), 'stored as term numbers, ascending, whatever order the form sent them in');
    }

    public function test_terms_taught_is_required_and_must_name_existing_terms(): void
    {
        $this->actingAs($this->admin)->post(route('admin.subjects.store'), $this->subjectForm(['name' => 'No Terms', 'terms' => []]))
            ->assertSessionHasErrors('terms');
        $this->assertDatabaseMissing('subjects', ['name' => 'No Terms']);

        $this->actingAs($this->admin)->post(route('admin.subjects.store'), $this->subjectForm(['name' => 'Bad Term', 'terms' => [9]]))
            ->assertSessionHasErrors('terms.0');
        $this->assertDatabaseMissing('subjects', ['name' => 'Bad Term']);

        // The universe comes from academic_terms, not a constant.
        $this->assertSame([1, 2, 3], AcademicTerm::termNumbers());
    }

    public function test_term_rows_persist_and_follow_the_subject(): void
    {
        $subject = Subject::factory()->taughtIn([2, 3])->create(['grade_level' => 12, 'subject_group' => null]);
        $this->assertSame(2, DB::table('subject_terms')->where('subject_id', $subject->id)->count());
        $this->assertTrue($subject->isTaughtIn(3));
        $this->assertFalse($subject->isTaughtIn(1));
        $this->assertSame('T2, T3', $subject->termsLabel());

        // Editing keeps unchanged rows and adds/removes the difference.
        $subject->syncTerms([1, 3]);
        $this->assertSame([1, 3], $subject->fresh()->termNumbers());

        // A deleted subject takes its term rows with it (no orphans).
        $subject->delete();
        $this->assertSame(0, DB::table('subject_terms')->where('subject_id', $subject->id)->count());
    }

    public function test_the_subjects_list_shows_each_subjects_terms_taught(): void
    {
        $page = $this->actingAs($this->admin)->get(route('admin.subjects'));

        $page->assertOk()->assertSee('Terms Taught')->assertSee('General Mathematics');
        $this->assertStringContainsString('name="terms[]"', $page->getContent());
        // The Import Subjects upload is gone from the page.
        $page->assertDontSee('Import Subjects');
    }

    // -----------------------------------------------------------------
    // 5, 6, 7 — resolution
    // -----------------------------------------------------------------

    public function test_shakespeare_resolves_only_term_1_subjects_in_term_1(): void
    {
        $this->assertSame(['Effective Communication', 'General Mathematics', 'General Science'], $this->names($this->shakespeare, 1));
    }

    public function test_shakespeare_resolves_only_term_2_subjects_in_term_2(): void
    {
        $this->assertSame(['Basic Calculus', 'Effective Communication', 'Entrepreneurship', 'General Science'], $this->names($this->shakespeare, 2));
        $this->assertSame(['Effective Communication', 'Entrepreneurship'], $this->names($this->shakespeare, 3));
        // The year-wide union, for year-level views.
        $this->assertSame(['Basic Calculus', 'Effective Communication', 'Entrepreneurship', 'General Mathematics', 'General Science'], $this->names($this->shakespeare, null));
    }

    public function test_curie_resolves_the_same_core_subjects_without_shakespeares_elective_choice(): void
    {
        $this->assertSame(['Effective Communication', 'General Mathematics', 'General Science'], $this->names($this->curie, 1));
        $this->assertSame(['Effective Communication', 'Entrepreneurship', 'General Science'], $this->names($this->curie, 2));
        $this->assertFalse(Subject::isOfferedTo($this->curie, 2, $this->basicCalc->id));
        // Biology is available in the track but chosen by nobody — Curie
        // is flagged as still needing its elective choice, never as "no
        // electives".
        $this->assertFalse((new SectionElectiveStatus())->isFullyConfigured($this->curie));
        $this->assertTrue((new SectionElectiveStatus())->isFullyConfigured($this->shakespeare));
    }

    public function test_the_sql_resolver_and_the_php_predicate_agree_on_every_combination(): void
    {
        $service = new SubjectApplicabilityService();
        $specialization = Specialization::factory()->create(['track_id' => $this->track->id]);
        $k12 = Section::factory()->create(['name' => 'Molave', 'grade_level' => 12, 'curriculum' => 'k12_2013', 'track_id' => $this->track->id, 'specialization_id' => $specialization->id, 'school_year' => '2026-2027']);
        $strandElective = Subject::factory()->taughtIn([1, 2])->create(['name' => 'Strand Elective', 'type' => 'elective', 'grade_level' => 12, 'track_id' => $this->track->id, 'specialization_id' => $specialization->id, 'subject_group' => null]);
        $otherStrand = Subject::factory()->create(['name' => 'Other Strand', 'type' => 'elective', 'grade_level' => 12, 'track_id' => $this->track->id, 'specialization_id' => Specialization::factory()->create(['track_id' => $this->track->id, 'name' => 'Humanities and Social Sciences', 'code' => 'HUMSS'])->id, 'subject_group' => null]);
        $g12Core = Subject::factory()->create(['name' => 'Grade 12 Core', 'type' => 'core', 'grade_level' => 12, 'subject_group' => null]);

        $compared = 0;
        foreach ([$this->shakespeare, $this->curie, $k12] as $section) {
            $chosen = SectionSubject::forSection($section)->pluck('subject_id')->flip();
            foreach ([null, 1, 2, 3] as $term) {
                $resolved = $service->query($section, $term)->pluck('id')->flip();
                foreach (Subject::with('terms')->get() as $subject) {
                    $this->assertSame(
                        $resolved->has($subject->id),
                        $service->appliesTo($service->attributesOf($subject), $section, $term, $chosen->has($subject->id)),
                        "{$subject->name} for {$section->name} term " . ($term ?? 'any')
                    );
                    $compared++;
                }
            }
        }
        $this->assertGreaterThan(100, $compared);

        // The strand mechanism still works for a k12_2013 section, and only there.
        $this->assertSame(['Grade 12 Core', 'Strand Elective'], $this->names($k12, 1));
        $this->assertSame(['Grade 12 Core'], $this->names($k12, 3));
        $this->assertSame([$k12->id], $service->sectionsOffering($strandElective, 1, '2026-2027')->pluck('id')->all());
        $this->assertSame([], $service->sectionsOffering($otherStrand, 1, '2026-2027')->pluck('id')->all());
        $this->assertSame([$this->shakespeare->id], $service->sectionsOffering($this->basicCalc, 2, '2026-2027')->pluck('id')->all());
        $this->assertSame([], $service->sectionsOffering($this->basicCalc, 1, '2026-2027')->pluck('id')->all());
    }

    public function test_the_section_subjects_page_is_a_resolved_view_and_names_the_source(): void
    {
        $page = $this->actingAs($this->admin)->get("/admin/sections/{$this->shakespeare->id}/subjects?term=2");

        $page->assertOk()
            ->assertSee('Basic Calculus')->assertSee('Section choice')
            ->assertSee('Effective Communication')->assertSee('Entrepreneurship')
            ->assertDontSee('General Mathematics')
            ->assertSee('Source: subject configuration')
            ->assertDontSee('has no term-specific subject assignments')
            ->assertDontSee('curriculum default')
            ->assertDontSee('Assign Subject');

        $termOne = $this->actingAs($this->admin)->get("/admin/sections/{$this->shakespeare->id}/subjects?term=1");
        $termOne->assertOk()->assertSee('General Mathematics')->assertDontSee('Basic Calculus');
    }

    public function test_an_elective_choice_applies_in_every_term_the_subject_is_taught_and_can_be_removed_while_unused(): void
    {
        $this->actingAs($this->admin)->post("/admin/sections/{$this->curie->id}/subjects", ['subject_id' => $this->biology->id, 'term' => 1])
            ->assertRedirect("/admin/sections/{$this->curie->id}/subjects?term=1")->assertSessionHas('success');

        $this->assertSame(3, SectionSubject::forSection($this->curie)->count(), 'one row per term the elective is taught in');
        foreach ([1, 2, 3] as $term) {
            $this->assertTrue(Subject::isOfferedTo($this->curie, $term, $this->biology->id));
        }
        $this->assertFalse(Subject::isOfferedTo($this->shakespeare, 1, $this->biology->id), 'a choice is per section');

        // A core subject is never a "choice", nor a duplicate, nor another grade level.
        $this->actingAs($this->admin)->post("/admin/sections/{$this->curie->id}/subjects", ['subject_id' => $this->genMath->id])
            ->assertSessionHasErrors(['subject_id'], null, 'assign');
        $this->actingAs($this->admin)->post("/admin/sections/{$this->curie->id}/subjects", ['subject_id' => $this->biology->id])
            ->assertSessionHasErrors(['subject_id'], null, 'assign');

        $this->actingAs($this->admin)->delete("/admin/sections/{$this->curie->id}/subjects/{$this->biology->id}")
            ->assertSessionHas('success');
        $this->assertSame(0, SectionSubject::forSection($this->curie)->count());
    }

    // -----------------------------------------------------------------
    // 8, 9, 15 — Adviser visibility and backend enforcement
    // -----------------------------------------------------------------

    public function test_adviser_sees_only_the_selected_terms_applicable_subjects(): void
    {
        $termOne = $this->actingAs($this->adviser)->get('/adviser/assessments?period=1');
        $termOne->assertOk();
        $termOne->assertViewHas('subjects', fn($s) => $s->pluck('name')->sort()->values()->all() === ['Effective Communication', 'General Mathematics', 'General Science']);

        $termTwo = $this->actingAs($this->adviser)->get('/adviser/assessments?period=2');
        $termTwo->assertViewHas('subjects', fn($s) => $s->pluck('name')->sort()->values()->all() === ['Basic Calculus', 'Effective Communication', 'Entrepreneurship', 'General Science']);

        $grades = $this->actingAs($this->adviser)->get('/adviser/grades?period=2');
        $grades->assertOk()->assertSee('Entrepreneurship')->assertDontSee('General Mathematics');

        $gradesOne = $this->actingAs($this->adviser)->get('/adviser/grades?period=1');
        $gradesOne->assertOk()->assertSee('General Mathematics')->assertDontSee('Entrepreneurship');

        // Curie's adviser never sees Shakespeare's elective choice.
        $this->actingAs($this->curieAdviser)->get('/adviser/assessments?period=2')
            ->assertViewHas('subjects', fn($s) => !$s->contains('name', 'Basic Calculus'));
    }

    public function test_adviser_cannot_use_a_term_2_only_subject_in_term_1_through_a_crafted_request(): void
    {
        $this->openTerm(1);
        $student = Student::factory()->create(['section_id' => $this->shakespeare->id, 'lrn' => '100000000021']);

        // Upload path — refused before the file is read.
        $upload = $this->actingAs($this->adviser)->post('/adviser/assessments/detect', [
            'subject_id'     => $this->entrep->id,
            'grading_period' => 1,
            'file'           => UploadedFile::fake()->createWithContent('a.csv', "lrn,last_name,first_name,Quiz 1\n100000000021,X,Y,10\n"),
        ]);
        $upload->assertRedirect();
        $upload->assertSessionHas('error', 'Entrepreneurship is not applicable to Shakespeare in Term 1.');
        $this->assertDatabaseCount('assessments', 0);

        // Manual item path.
        $item = $this->actingAs($this->adviser)->post('/adviser/assessments/item', [
            'subject_id' => $this->entrep->id, 'grading_period' => 1, 'item_name' => 'Quiz 1',
            'component' => 'written_work', 'max_score' => 10, 'scores' => [$student->id => 8],
        ]);
        $item->assertSessionHasErrors(['item_name'], null, 'addItem');
        $this->assertDatabaseCount('assessments', 0);

        // Official grade path — the foreign subject_id is skipped, never saved.
        $this->actingAs($this->adviser)->post('/adviser/grades', [
            'grading_period' => 1,
            'grades' => [['student_id' => $student->id, 'subject_id' => $this->entrep->id, 'grade' => 90]],
        ]);
        $this->assertDatabaseCount('grades', 0);

        // Verify-all path — 403, same as any foreign subject.
        $this->actingAs($this->adviser)->post('/adviser/grades/verify-all', [
            'subject_id' => $this->entrep->id, 'grading_period' => 1,
        ])->assertStatus(403);

        // And the same subject IS usable in Term 2 once that term is open.
        $this->openTerm(2);
        $this->actingAs($this->adviser)->post('/adviser/grades', [
            'grading_period' => 2,
            'grades' => [['student_id' => $student->id, 'subject_id' => $this->entrep->id, 'grade' => 90]],
        ])->assertSessionHas('success');
        $this->assertDatabaseHas('grades', ['subject_id' => $this->entrep->id, 'grading_period' => 2, 'grade' => 90]);
    }

    public function test_applicability_and_term_status_are_separate_a_closed_term_still_refuses_writes(): void
    {
        $this->openTerm(2);
        $student = Student::factory()->create(['section_id' => $this->shakespeare->id]);

        // General Mathematics IS taught in Term 1 — but Term 1 is closed.
        $this->assertTrue(Subject::isOfferedTo($this->shakespeare, 1, $this->genMath->id));
        $this->actingAs($this->adviser)->post('/adviser/grades', [
            'grading_period' => 1,
            'grades' => [['student_id' => $student->id, 'subject_id' => $this->genMath->id, 'grade' => 90]],
        ])->assertSessionHas('error');
        $this->assertDatabaseCount('grades', 0);

        // It is still fully VIEWABLE for Term 1.
        $this->actingAs($this->adviser)->get('/adviser/grades?period=1')->assertOk()->assertSee('General Mathematics');
    }

    public function test_adviser_cannot_reach_another_sections_elective_choice_through_the_request(): void
    {
        $this->openTerm(2);
        $response = $this->actingAs($this->curieAdviser)->post('/adviser/assessments/detect', [
            'subject_id'     => $this->basicCalc->id,
            'grading_period' => 2,
            'file'           => UploadedFile::fake()->createWithContent('a.csv', "lrn,last_name,first_name,Quiz 1\n"),
        ]);
        $response->assertSessionHas('error', 'Basic Calculus is not applicable to Curie in Term 2.');
    }

    // -----------------------------------------------------------------
    // 10, 13 — the prescribed ECR
    // -----------------------------------------------------------------

    public function test_a_prescribed_ecr_for_a_term_2_only_subject_is_refused_for_term_1_and_changes_nothing(): void
    {
        $this->openTerm(1);
        Student::factory()->create(['section_id' => $this->shakespeare->id, 'lrn' => '110000000001', 'last_name' => 'Agbayani', 'first_name' => 'Rhea Mae', 'gender' => 'female']);

        $path = $this->buildFilledEcrCopy(
            sectionName: 'Shakespeare', courseTitle: 'Entrepreneurship',
            roster: [['lrn' => '110000000001', 'name' => 'Agbayani, Rhea Mae', 'gender' => 'female']],
            scores: ['D' => [$this->femaleTermRow(0) => 18]], maxScores: ['D' => 20],
            gradingPeriod: 1, schoolYearStart: '2026', termBlockLabel: 'SECOND TERM',
        );
        $this->tempFiles[] = $path;

        $termsBefore = DB::table('subject_terms')->count();
        $choicesBefore = SectionSubject::count();

        $response = $this->actingAs($this->adviser)->post('/adviser/assessments/detect', [
            'subject_id' => $this->entrep->id, 'grading_period' => 1,
            'file' => new UploadedFile($path, 'ecr.xlsx', null, null, true),
        ]);

        $response->assertRedirect()->assertSessionHas('error', 'Entrepreneurship is not applicable to Shakespeare in Term 1.');
        $this->assertDatabaseCount('assessments', 0);
        $this->assertSame($termsBefore, DB::table('subject_terms')->count());
        $this->assertSame($choicesBefore, SectionSubject::count());
        $this->assertSame([2, 3], $this->entrep->fresh()->termNumbers(), 'an upload never rewrites Terms Taught');
    }

    // -----------------------------------------------------------------
    // 11, 12 — ECR wrong-section / wrong-subject validation still works
    // (the file's own metadata is checked AFTER applicability)
    // -----------------------------------------------------------------

    public function test_an_ecr_naming_another_section_or_subject_is_still_refused(): void
    {
        $this->openTerm(1);
        Student::factory()->create(['section_id' => $this->shakespeare->id, 'lrn' => '110000000001', 'last_name' => 'Agbayani', 'first_name' => 'Rhea Mae', 'gender' => 'female']);
        $roster = [['lrn' => '110000000001', 'name' => 'Agbayani, Rhea Mae', 'gender' => 'female']];

        $wrongSection = $this->buildFilledEcrCopy(sectionName: 'Curie', courseTitle: 'General Mathematics', roster: $roster, scores: ['D' => [$this->femaleTermRow(0) => 18]], maxScores: ['D' => 20], gradingPeriod: 1, schoolYearStart: '2026');
        $wrongSubject = $this->buildFilledEcrCopy(sectionName: 'Shakespeare', courseTitle: 'General Science', roster: $roster, scores: ['D' => [$this->femaleTermRow(0) => 18]], maxScores: ['D' => 20], gradingPeriod: 1, schoolYearStart: '2026');
        $this->tempFiles[] = $wrongSection;
        $this->tempFiles[] = $wrongSubject;

        $this->actingAs($this->adviser)->post('/adviser/assessments/detect', [
            'subject_id' => $this->genMath->id, 'grading_period' => 1,
            'file' => new UploadedFile($wrongSection, 'ecr.xlsx', null, null, true),
        ])->assertRedirect()->assertSessionHas('error', fn($m) => str_contains($m, 'Curie') && str_contains($m, 'Shakespeare'));

        $this->actingAs($this->adviser)->post('/adviser/assessments/detect', [
            'subject_id' => $this->genMath->id, 'grading_period' => 1,
            'file' => new UploadedFile($wrongSubject, 'ecr.xlsx', null, null, true),
        ])->assertRedirect()->assertSessionHas('error', fn($m) => str_contains($m, 'General Science') && str_contains($m, 'General Mathematics'));

        $this->assertDatabaseCount('assessments', 0);
    }

    // -----------------------------------------------------------------
    // 16, 17 — history: visible, and protected against an edit
    // -----------------------------------------------------------------

    public function test_historical_term_1_records_remain_visible_after_term_2_opens(): void
    {
        $student = Student::factory()->create(['section_id' => $this->shakespeare->id]);
        Grade::create(['student_id' => $student->id, 'subject_id' => $this->genMath->id, 'section_id' => $this->shakespeare->id, 'grading_period' => 1, 'grade' => 85, 'school_year' => '2026-2027', 'is_verified' => true]);
        $this->openTerm(2);

        $this->actingAs($this->adviser)->get('/adviser/grades?period=1')->assertOk()->assertSee('General Mathematics');
        $this->actingAs($this->principal)->get('/principal/subject-analysis?section_id=' . $this->shakespeare->id . '&term=1')->assertOk()->assertSee('General Mathematics');
        $this->actingAs($this->principal)->get('/principal/students/' . $student->id . '?period=1')->assertOk()->assertSee('General Mathematics');
        // And Term 2 does not pretend General Mathematics is taught.
        $this->actingAs($this->adviser)->get('/adviser/grades?period=2')->assertOk()->assertDontSee('General Mathematics');
    }

    public function test_removing_a_term_that_already_has_academic_records_is_blocked(): void
    {
        $student = Student::factory()->create(['section_id' => $this->shakespeare->id]);
        Grade::create(['student_id' => $student->id, 'subject_id' => $this->genSci->id, 'section_id' => $this->shakespeare->id, 'grading_period' => 2, 'grade' => 85, 'school_year' => '2026-2027']);

        // General Science: Term 1, Term 2 -> try to uncheck Term 2.
        $response = $this->actingAs($this->admin)->put(route('admin.subjects.update', $this->genSci->id), [
            'name' => 'General Science', 'type' => 'core', 'grade_level' => 11, 'subject_group' => 'core_academic', 'terms' => [1],
        ]);

        $response->assertSessionHasErrors('terms');
        $this->assertStringContainsString('Shakespeare Term 2', session('errors')->first('terms'));
        $this->assertStringContainsString('1 grades', session('errors')->first('terms'));
        $this->assertSame([1, 2], $this->genSci->fresh()->termNumbers(), 'nothing changed');
        $this->assertDatabaseHas('grades', ['subject_id' => $this->genSci->id, 'grading_period' => 2]);

        // Adding a term is always fine; so is any edit that keeps Term 2.
        $this->actingAs($this->admin)->put(route('admin.subjects.update', $this->genSci->id), [
            'name' => 'General Science', 'type' => 'core', 'grade_level' => 11, 'subject_group' => 'core_academic', 'terms' => [1, 2, 3],
        ])->assertSessionHasNoErrors();
        $this->assertSame([1, 2, 3], $this->genSci->fresh()->termNumbers());
    }

    public function test_changing_grade_level_or_track_away_from_existing_records_is_blocked_too(): void
    {
        $student = Student::factory()->create(['section_id' => $this->shakespeare->id]);
        Assessment::factory()->create(['subject_id' => $this->basicCalc->id, 'section_id' => $this->shakespeare->id, 'grading_period' => 2, 'school_year' => '2026-2027']);
        Grade::create(['student_id' => $student->id, 'subject_id' => $this->genMath->id, 'section_id' => $this->shakespeare->id, 'grading_period' => 1, 'grade' => 80, 'school_year' => '2026-2027']);

        // Grade level: General Mathematics (Grade 11, Term 1 records) -> Grade 12.
        // (subject_group travels with the subject — required at both grade
        // levels since the "Subject Group for both grade levels" pass — so
        // the refusal below is the history check, not a classification one.)
        $this->actingAs($this->admin)->put(route('admin.subjects.update', $this->genMath->id), [
            'name' => 'General Mathematics', 'type' => 'core', 'grade_level' => 12, 'subject_group' => 'core_academic', 'terms' => [1],
        ])->assertSessionHasErrors('terms');
        $this->assertSame(11, (int) $this->genMath->fresh()->grade_level);

        // Elective choice survives an edit that keeps the choice valid...
        $this->actingAs($this->admin)->put(route('admin.subjects.update', $this->basicCalc->id), [
            'name' => 'Basic Calculus', 'type' => 'elective', 'grade_level' => 11, 'subject_group' => 'academic_other',
            'track_id' => $this->track->id, 'terms' => [2, 3],
        ])->assertSessionHasNoErrors();
        // ...but not a grade-level change that would strand its Term 2 assessment.
        $this->actingAs($this->admin)->put(route('admin.subjects.update', $this->basicCalc->id), [
            'name' => 'Basic Calculus', 'type' => 'elective', 'grade_level' => 12, 'subject_group' => 'academic_other', 'track_id' => $this->track->id, 'terms' => [2, 3],
        ])->assertSessionHasErrors('terms');
        $this->assertDatabaseHas('assessments', ['subject_id' => $this->basicCalc->id, 'grading_period' => 2]);
    }

    public function test_removing_an_elective_choice_with_records_is_blocked(): void
    {
        Assessment::factory()->create(['subject_id' => $this->basicCalc->id, 'section_id' => $this->shakespeare->id, 'grading_period' => 2, 'school_year' => '2026-2027']);

        $this->actingAs($this->admin)->delete("/admin/sections/{$this->shakespeare->id}/subjects/{$this->basicCalc->id}")
            ->assertSessionHas('error', fn($m) => str_contains($m, 'cannot be removed') && str_contains($m, 'assessments'));
        $this->assertSame(1, SectionSubject::forSection($this->shakespeare)->count());
        $this->assertDatabaseHas('assessments', ['subject_id' => $this->basicCalc->id]);
    }

    // -----------------------------------------------------------------
    // 18 — Principal Subject Analysis
    // -----------------------------------------------------------------

    public function test_principal_subject_analysis_uses_the_terms_subjects(): void
    {
        $student = Student::factory()->create(['section_id' => $this->shakespeare->id]);
        foreach ([[1, $this->genMath], [1, $this->effComm], [2, $this->basicCalc], [2, $this->effComm]] as [$term, $subject]) {
            Grade::create(['student_id' => $student->id, 'subject_id' => $subject->id, 'section_id' => $this->shakespeare->id, 'grading_period' => $term, 'grade' => 80, 'school_year' => '2026-2027', 'is_verified' => true]);
        }

        $service = new SubjectAnalysisService();
        $termOne = collect($service->getSubjectSummaries('2026-2027', $this->shakespeare->id, 1))->map(fn($r) => $r['subject']->name)->sort()->values()->all();
        $termTwo = collect($service->getSubjectSummaries('2026-2027', $this->shakespeare->id, 2))->map(fn($r) => $r['subject']->name)->sort()->values()->all();
        $this->assertSame(['Effective Communication', 'General Mathematics'], $termOne);
        $this->assertSame(['Basic Calculus', 'Effective Communication'], $termTwo);

        // Applicable in Term 2 but nothing recorded yet — said as such.
        $offeredWithoutData = $service->offeredWithoutData('2026-2027', $this->shakespeare->id, 2, $service->getSubjectSummaries('2026-2027', $this->shakespeare->id, 2));
        $this->assertSame(['Entrepreneurship', 'General Science'], $offeredWithoutData->pluck('name')->sort()->values()->all());

        $page = $this->actingAs($this->principal)->get('/principal/subject-analysis?section_id=' . $this->shakespeare->id . '&term=2');
        $page->assertOk()->assertSee('Basic Calculus')->assertDontSee('General Mathematics')->assertSee('Entrepreneurship');

        // Principal Students scopes sections by the same rule: Basic
        // Calculus in Term 2 reaches Shakespeare only.
        $students = $this->actingAs($this->principal)->get('/principal/students?period=2&subject_id=' . $this->basicCalc->id);
        $students->assertOk();
        $this->assertSame([$student->id], $students->viewData('students')->getCollection()->pluck('student.id')->all());
    }

    // -----------------------------------------------------------------
    // 19, 20, 21 — Submit Report / risk features use the term's universe
    // -----------------------------------------------------------------

    public function test_future_term_subjects_do_not_contaminate_current_term_expectations_or_risk_features(): void
    {
        $this->openTerm(1);
        $students = Student::factory()->count(2)->create(['section_id' => $this->shakespeare->id]);

        // Expected grades for Term 1: 2 learners x the 3 Term 1 subjects —
        // Entrepreneurship (Term 2/3) and Basic Calculus (Term 2) do not count.
        $status = new SectionElectiveStatus();
        $this->assertSame(6, $status->expectedGradeCount($this->shakespeare, 1));
        $this->assertSame(8, $status->expectedGradeCount($this->shakespeare, 2));

        // Term 1 evidence for the Term 1 subjects only.
        foreach ([$this->genMath, $this->genSci, $this->effComm] as $subject) {
            foreach (['written_work' => 20, 'performance_task' => 50, 'examination' => 30] as $component => $max) {
                $assessment = Assessment::factory()->create(['subject_id' => $subject->id, 'section_id' => $this->shakespeare->id, 'grading_period' => 1, 'school_year' => '2026-2027', 'name' => ucfirst($component) . ' 1', 'component' => $component, 'max_score' => $max]);
                foreach ($students as $student) {
                    AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => $max]);
                }
            }
            foreach ($students as $student) {
                Grade::create(['student_id' => $student->id, 'subject_id' => $subject->id, 'section_id' => $this->shakespeare->id, 'grading_period' => 1, 'grade' => 90, 'school_year' => '2026-2027', 'is_verified' => true]);
            }
        }

        $readiness = (new TermReadinessService())->assessmentEvidenceStatus($this->shakespeare, 1);
        $this->assertSame(6, $readiness['expected']);
        $this->assertSame(6, $readiness['complete']);
        $this->assertTrue($readiness['ready'], 'a Term-2-only subject must not hold Term 1 back');

        $features = (new RiskFeatureExtractor())->extract($students[0], $this->shakespeare, 1, '2026-2027', 90.0);
        $this->assertSame(0, $features['missing_assessment_count'], 'nothing is missing — the future subjects have no Term 1 items to be missing');
        $this->assertSame(0, $features['weak_component_count']);
        $this->assertEquals(100.0, $features['ww_mean']);

        // Submit Report's own failing-subject count comes from the term's
        // grades — Entrepreneurship, ungraded because it is not taught yet,
        // is not a failing subject. Built the way submit() builds it.
        $termGrades = Grade::where('section_id', $this->shakespeare->id)->where('grading_period', 1)->where('school_year', '2026-2027')->get()->groupBy('student_id');
        $failing = $termGrades->get($students[0]->id)->where('grade', '<', 75)->count();
        $this->assertSame(0, $failing);
        $this->assertSame(3, $termGrades->get($students[0]->id)->count(), 'exactly the three Term 1 subjects');
    }

    public function test_submit_report_evaluates_only_the_terms_applicable_subjects_end_to_end(): void
    {
        $python = config('services.python_path');
        $probe = new \Symfony\Component\Process\Process([$python, '-c', 'import sklearn']);
        $probe->run();
        if (!$probe->isSuccessful()) {
            $this->markTestSkipped("Python interpreter '{$python}' with scikit-learn is not runnable in this environment.");
        }

        $this->openTerm(1);
        $student = Student::factory()->create(['section_id' => $this->shakespeare->id]);
        // General Mathematics failing, the other two Term 1 subjects fine;
        // Entrepreneurship and Basic Calculus have no Term 1 grade and must
        // neither block submission nor appear as failing.
        foreach ([[$this->genMath, 60], [$this->genSci, 85], [$this->effComm, 88]] as [$subject, $grade]) {
            Grade::create(['student_id' => $student->id, 'subject_id' => $subject->id, 'section_id' => $this->shakespeare->id, 'grading_period' => 1, 'grade' => $grade, 'school_year' => '2026-2027', 'is_verified' => true]);
        }

        $this->actingAs($this->adviser)->post('/adviser/submit-report', ['grading_period' => 1])
            ->assertRedirect(route('adviser.submit.report'))->assertSessionHas('success');

        $risk = RiskResult::where('student_id', $student->id)->where('grading_period', 1)->firstOrFail();
        $this->assertSame(1, count($risk->failing_subjects));
        $this->assertSame('General Mathematics', $risk->failing_subjects[0]['name']);
        $this->assertSame('General Mathematics', $risk->weakest_subject);
        $this->assertEquals(77.67, (float) $risk->average_grade, 'the average of the THREE Term 1 grades, not five subjects');
        $this->assertContains($risk->risk_level, ['moderate', 'high'], 'one failing subject floors the level at moderate');
    }

    // -----------------------------------------------------------------
    // 22 — the intervention workflow is unchanged
    // -----------------------------------------------------------------

    public function test_a_principal_can_still_record_an_intervention_for_an_applicable_subject(): void
    {
        $student = Student::factory()->create(['section_id' => $this->shakespeare->id]);

        $this->actingAs($this->principal)->post(route('principal.interventions.store'), [
            'student_id' => $student->id, 'subject_id' => $this->genMath->id, 'grading_period' => 1,
            'recommended_type' => 'remediation', 'recommendation_reason' => 'Written Work below target',
            'status' => 'approved',
        ]);

        $this->assertDatabaseHas('interventions', ['student_id' => $student->id, 'subject_id' => $this->genMath->id, 'grading_period' => 1, 'section_id' => $this->shakespeare->id]);
        $this->assertSame(1, Intervention::count());
        $this->actingAs($this->adviser)->get('/adviser/interventions?period=1')->assertOk()->assertSee('General Mathematics');
    }

    // -----------------------------------------------------------------
    // 24, 25 — removed imports and authorization
    // -----------------------------------------------------------------

    public function test_no_master_data_import_ui_or_route_remains_but_the_learner_import_does(): void
    {
        foreach (['admin.sections.import', 'admin.specializations.import', 'admin.subjects.import', 'admin.tracks.import'] as $name) {
            $this->assertFalse(Route::has($name), "{$name} must be gone");
        }
        $this->assertTrue(Route::has('admin.students.import'), 'the learner roster import is a supported format and stays');
        $this->assertTrue(Route::has('adviser.assessments.detect'), 'the prescribed ECR upload stays');

        $this->actingAs($this->admin)->get(route('admin.tracks'))->assertOk()->assertDontSee('Import Tracks');
        $this->actingAs($this->admin)->get(route('admin.specializations'))->assertOk()->assertDontSee('Import Specializations');
        $this->actingAs($this->admin)->get(route('admin.sections'))->assertOk()->assertDontSee('Import Sections')->assertSee('Add Section');
        $this->actingAs($this->admin)->get(route('admin.subjects'))->assertOk()->assertDontSee('Import Subjects')->assertSee('Add Subject');
    }

    public function test_only_admin_manages_subject_configuration_and_section_choices(): void
    {
        foreach ([$this->adviser, $this->principal] as $user) {
            $this->actingAs($user)->post(route('admin.subjects.store'), $this->subjectForm(['name' => 'Forbidden']))->assertForbidden();
            $this->actingAs($user)->put(route('admin.subjects.update', $this->genMath->id), $this->subjectForm(['name' => 'General Mathematics', 'terms' => [1, 2, 3]]))->assertForbidden();
            $this->actingAs($user)->get("/admin/sections/{$this->shakespeare->id}/subjects")->assertForbidden();
            $this->actingAs($user)->post("/admin/sections/{$this->curie->id}/subjects", ['subject_id' => $this->biology->id])->assertForbidden();
            $this->actingAs($user)->delete("/admin/sections/{$this->shakespeare->id}/subjects/{$this->basicCalc->id}")->assertForbidden();
        }
        $this->assertDatabaseMissing('subjects', ['name' => 'Forbidden']);
        $this->assertSame([1], $this->genMath->fresh()->termNumbers());
        $this->assertSame(1, SectionSubject::count());
    }
}
