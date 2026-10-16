<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\DepedSubjectCatalog;
use App\Models\Grade;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeachingAssignment;
use App\Models\User;
use App\Services\ExaminationRoles;
use App\Services\GradingEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "OTHER ELECTIVE / SPECIAL CURRICULAR PROGRAM" (HELPER rows 93 / 147): the
 * workbook takes this subject's weights and Examination shares from what the
 * teacher types in INPUT DATA!F43:F48 (empty in the template). The system has
 * no source for those figures and never takes a teacher-typed weight, so the
 * profile is UNSUPPORTED: a clear configuration message everywhere, no figure,
 * no official grade — and never a silent fallback to the subject_group row.
 */
class OtherElectiveProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;
    private User $adviser;
    private Section $section;
    private Subject $subject;
    private Student $learner;

    protected function setUp(): void
    {
        parent::setUp();
        AcademicYear::create(['school_year' => '2026-2027', 'is_active' => true]);
        AcademicTerm::ensureExistFor('2026-2027');
        AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->update(['is_open' => true]);
        $this->seed(\Database\Seeders\ExamRoleSharesSeeder::class);
        $this->seed(\Database\Seeders\Do015TransmutationSeeder::class);

        $this->teacher = User::factory()->create(['role' => 'subject_teacher']);
        $this->adviser = User::factory()->create(['role' => 'adviser']);
        $this->section = Section::factory()->create(['grade_level' => 11, 'curriculum' => 'sshs', 'school_year' => '2026-2027', 'adviser_id' => $this->adviser->id]);
        $row = DepedSubjectCatalog::where('teacher_supplied', true)->firstOrFail();
        // subject_group is set on purpose: a silent fallback would grade by it.
        $this->subject = Subject::factory()->create(['name' => 'Special Program', 'type' => 'core', 'grade_level' => 11,
            'subject_group' => 'core_academic', 'catalog_id' => $row->id]);
        $this->subject->syncTerms([1, 2, 3]);
        $this->learner = Student::factory()->create(['section_id' => $this->section->id]);
        TeachingAssignment::create(['user_id' => $this->teacher->id, 'section_id' => $this->section->id, 'subject_id' => $this->subject->id,
            'school_year' => '2026-2027', 'grading_period' => 1]);

        foreach ([['Quiz 1', 'written_work', null], ['PT 1', 'performance_task', null], ['ST 1', 'examination', 'st1'],
                  ['ST 2', 'examination', 'st2'], ['TE', 'examination', 'term_exam']] as [$name, $component, $role]) {
            $item = Assessment::factory()->create(['subject_id' => $this->subject->id, 'section_id' => $this->section->id, 'grading_period' => 1,
                'school_year' => '2026-2027', 'name' => $name, 'component' => $component, 'exam_role' => $role, 'max_score' => 50, 'uploaded_by' => $this->teacher->id]);
            AssessmentScore::create(['assessment_id' => $item->id, 'student_id' => $this->learner->id, 'score' => 40]);
        }
    }

    public function test_full_evidence_still_yields_no_figure_and_names_the_configuration_problem(): void
    {
        $engine = new GradingEngine();
        $profile = $engine->resolveWeightProfile($this->section, $this->subject);
        $this->assertSame('unconfigured', $profile['source']);
        $this->assertSame([null, null, null], [$profile['ww_weight'], $profile['pt_weight'], $profile['ex_weight']], 'never the subject_group weights');

        $g = $engine->computeGrade($this->learner, $this->subject, $this->section, 1, '2026-2027');
        $this->assertFalse($g['complete']);
        $this->assertNull($g['computed_grade']);
        $this->assertNull($g['transmuted_grade']);
        $this->assertNull($g['running_grade']);
        $this->assertStringContainsString('Other elective / special curricular program', $g['blockers'][0]);
    }

    public function test_every_page_shows_the_message_and_no_official_grade_can_be_made(): void
    {
        $scope = ['section_id' => $this->section->id, 'subject_id' => $this->subject->id, 'period' => 1];
        $this->actingAs($this->teacher)->get(route('teacher.class-record', $scope))->assertOk()
            ->assertSee('Grades cannot be computed for this subject.')->assertSee('no grade is computed and none can be verified');
        $this->actingAs($this->teacher)->get(route('teacher.class-record.export', $scope))->assertOk();
        $this->actingAs($this->teacher)->get(route('teacher.assessments.create', $scope))->assertOk();

        $this->actingAs($this->adviser)->get('/adviser/verify-grades?period=1&subject_id=' . $this->subject->id)->assertOk()
            ->assertSee('no grade is computed and none can be verified');
        $this->actingAs($this->adviser)->postJson(route('adviser.grades.verify'), ['student_id' => $this->learner->id, 'subject_id' => $this->subject->id, 'grading_period' => 1])
            ->assertStatus(422)->assertJsonFragment(['message' => 'Cannot verify Special Program — ' . (new GradingEngine())->computeGrade($this->learner, $this->subject, $this->section, 1, '2026-2027')['blockers'][0]]);
        $this->assertSame(0, Grade::count(), 'no official grade');

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('admin.subjects'))->assertOk();
        $this->actingAs($admin)->get(route('admin.sections.subjects', $this->section))->assertOk();
    }

    public function test_examination_items_can_still_be_recorded_with_the_three_types(): void
    {
        // Evidence may be recorded while the weights are unresolved; only the grade is withheld.
        $this->assertSame(['has_examination' => true, 'roles' => ExaminationRoles::ROLES], (new ExaminationRoles())->forContext($this->section, $this->subject));
    }
}
