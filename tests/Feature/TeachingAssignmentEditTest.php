<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\TeachingAssignmentController;
use App\Models\AcademicConcern;
use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\AssessmentEvidenceAudit;
use App\Models\AssessmentScore;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeachingAssignment;
use App\Models\TeachingAssignmentHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin > Teaching Assignments — Edit / Change Teacher and Remove
 * ("Class Record refinements", 2026-09-28). Replacing a teacher keeps every
 * piece of evidence and its original attribution, moves encoding access to
 * the new teacher at once (old tabs included), and is recorded with the
 * Admin, time and reason. Scope can be corrected only while the class holds
 * no evidence; removal archives the assignment and never deletes a score.
 */
class TeachingAssignmentEditTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\UsesClassRecord;

    private User $admin;
    private User $bong;    // adviser of Agila
    private User $darien;  // current teacher of General Science in Agila
    private User $carla;   // replacement teacher (subject teacher)
    private Section $agila;
    private Subject $science;
    private Subject $cesc;
    private Student $ana;
    private TeachingAssignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        AcademicYear::create(['school_year' => '2026-2027', 'is_active' => true]);
        AcademicTerm::ensureExistFor('2026-2027');
        AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->update(['is_open' => true]);

        $this->admin = User::factory()->admin()->create();
        $this->bong = User::factory()->create(['role' => 'adviser', 'last_name' => 'Cruz', 'first_name' => 'Bong']);
        $this->darien = User::factory()->create(['role' => 'adviser', 'last_name' => 'Bosco', 'first_name' => 'Darien']);
        $this->carla = User::factory()->create(['role' => 'subject_teacher', 'last_name' => 'Diaz', 'first_name' => 'Carla']);
        $this->agila = Section::factory()->create(['name' => 'Agila', 'grade_level' => 11, 'curriculum' => 'sshs', 'school_year' => '2026-2027', 'adviser_id' => $this->bong->id]);
        foreach (['science' => 'General Science', 'cesc' => 'Community Engagement'] as $prop => $name) {
            $this->{$prop} = Subject::factory()->create(['name' => $name, 'type' => 'core', 'grade_level' => 11, 'subject_group' => 'core_academic']);
            $this->{$prop}->syncTerms([1, 2, 3]);
        }
        $this->ana = Student::factory()->create(['section_id' => $this->agila->id, 'lrn' => '012345678901']);

        $this->assignment = TeachingAssignment::create(['user_id' => $this->darien->id, 'section_id' => $this->agila->id,
            'subject_id' => $this->science->id, 'school_year' => '2026-2027', 'grading_period' => 1, 'assigned_by' => $this->admin->id]);
    }

    private function item(): Assessment
    {
        return Assessment::factory()->create(['subject_id' => $this->science->id, 'section_id' => $this->agila->id, 'grading_period' => 1,
            'school_year' => '2026-2027', 'name' => 'Quiz 1', 'component' => 'written_work', 'max_score' => 20, 'uploaded_by' => $this->darien->id]);
    }

    private function update(array $fields, ?TeachingAssignment $a = null)
    {
        $a ??= $this->assignment->fresh();

        return $this->actingAs($this->admin)->put(route('admin.teaching-assignments.update', $a->id), array_merge([
            'user_id' => $a->user_id, 'section_id' => $a->section_id, 'subject_id' => $a->subject_id,
            'grading_period' => $a->grading_period, 'reason' => 'Reassigned by the school head',
            'version' => TeachingAssignmentController::versionOf($a),
        ], $fields));
    }

    public function test_the_edit_page_shows_the_class_the_teacher_and_the_evidence_and_locks_scope_when_evidence_exists(): void
    {
        $this->item();
        $this->actingAs($this->admin)->get(route('admin.teaching-assignments.edit', $this->assignment->id))
            ->assertOk()->assertSee('2026-2027')->assertSee('Agila')->assertSee('General Science')->assertSee('Term 1')
            ->assertSee('Bosco, Darien')->assertSee('1 assessment(s) recorded')->assertSee('data-scope-locked', false);

        $this->actingAs($this->admin)->get(route('admin.teaching-assignments'))->assertOk()->assertSee('Edit / Change Teacher');
        foreach ([$this->bong, $this->darien, $this->carla] as $user) {
            $this->actingAs($user)->get(route('admin.teaching-assignments.edit', $this->assignment->id))->assertForbidden();
        }
    }

    public function test_replacing_the_teacher_keeps_evidence_and_attribution_and_moves_encoding_access(): void
    {
        $item = $this->item();
        $this->saveCells($this->darien, $item, [$this->ana->id => 'M'])->assertOk();
        $this->assertSame(1, AcademicConcern::open()->count());
        $auditBefore = AssessmentEvidenceAudit::orderBy('id')->get()->toArray();
        $openedByDarien = $this->assignment->fresh(); // Darien's old tab

        $this->update(['user_id' => $this->carla->id])->assertRedirect()->assertSessionHas('success');

        $row = $this->assignment->fresh();
        $this->assertSame([$this->carla->id, $this->assignment->id], [$row->user_id, $row->id], 'same assignment row, new teacher');
        $history = TeachingAssignmentHistory::where('action', TeachingAssignmentHistory::REASSIGNED)->sole();
        $this->assertSame([$this->darien->id, $this->carla->id, $this->admin->id, 'Reassigned by the school head', $this->agila->id, $this->science->id, 1],
            [$history->old_user_id, $history->new_user_id, $history->changed_by, $history->reason, $history->section_id, $history->subject_id, $history->grading_period]);
        $this->assertNotNull($history->changed_at);

        // Evidence untouched, attribution untouched.
        $this->assertSame($this->darien->id, $item->fresh()->uploaded_by);
        $this->assertSame($auditBefore, AssessmentEvidenceAudit::orderBy('id')->get()->toArray());
        $this->assertSame(1, AcademicConcern::open()->count());

        // The old teacher (and an old tab/draft) can no longer write; the new one can.
        $this->saveCells($this->darien, $item, [$this->ana->id => '15'])->assertForbidden();
        $this->addAssessment($this->darien, $this->agila, $this->science, ['item_name' => 'Quiz 2'])->assertForbidden(); // no link to Agila any more
        $this->actingAs($this->darien)->get(route('teacher.class-record', ['section_id' => $this->agila->id, 'subject_id' => $this->science->id, 'period' => 1]))->assertForbidden();
        $this->saveCells($this->carla, $item, [$this->ana->id => '15'])->assertOk();
        $this->assertSame(0, AcademicConcern::open()->count(), 'the new teacher\'s evidence resolves the concern');

        // Future changes are attributed to the new teacher only.
        $latest = AssessmentEvidenceAudit::orderByDesc('id')->first();
        $this->assertSame($this->carla->id, $latest->changed_by);
        $this->assertSame($this->darien->id, AssessmentEvidenceAudit::orderBy('id')->first()->changed_by);

        // The adviser keeps advisory read access, unchanged.
        $this->actingAs($this->bong)->get(route('teacher.class-record', ['section_id' => $this->agila->id, 'subject_id' => $this->science->id, 'period' => 1]))
            ->assertOk()->assertViewHas('canEdit', false)->assertSee('Diaz, Carla');

        // A stale edit form (opened before the change) is refused.
        $this->update(['user_id' => $this->bong->id], $openedByDarien)->assertSessionHasErrors('assignment');
        $this->assertSame($this->carla->id, $this->assignment->fresh()->user_id);
    }

    public function test_a_reason_and_an_active_eligible_teacher_are_required(): void
    {
        $this->update(['user_id' => $this->carla->id, 'reason' => ''])->assertSessionHasErrors('reason');
        $this->update(['user_id' => User::factory()->principal()->create()->id])->assertSessionHasErrors('user_id');
        $inactive = User::factory()->create(['role' => 'subject_teacher', 'is_active' => false]);
        $this->update(['user_id' => $inactive->id])->assertSessionHasErrors('user_id');
        $this->update([])->assertSessionHasErrors('assignment'); // nothing changed
        $this->assertSame($this->darien->id, $this->assignment->fresh()->user_id);
        $this->assertSame(0, TeachingAssignmentHistory::count());
    }

    public function test_scope_is_locked_once_evidence_exists_and_correctable_before(): void
    {
        // No evidence yet: a wrongly entered subject/term can be corrected, other terms untouched.
        $term2 = TeachingAssignment::create(['user_id' => $this->darien->id, 'section_id' => $this->agila->id, 'subject_id' => $this->science->id,
            'school_year' => '2026-2027', 'grading_period' => 2]);
        $this->update(['subject_id' => $this->cesc->id])->assertSessionHas('success');
        $this->assertSame($this->cesc->id, $this->assignment->fresh()->subject_id);
        $this->assertSame($this->science->id, $term2->fresh()->subject_id, 'the other term is not altered');
        $this->assertSame(1, TeachingAssignmentHistory::where('action', TeachingAssignmentHistory::SCOPE_CORRECTED)->count());
        $this->assertSame(['section_id' => $this->agila->id, 'subject_id' => $this->science->id, 'grading_period' => 1],
            TeachingAssignmentHistory::where('action', TeachingAssignmentHistory::SCOPE_CORRECTED)->sole()->previous_scope);

        // Moving onto a term that already has a teacher is refused.
        $this->update(['subject_id' => $this->science->id, 'grading_period' => 2])->assertSessionHasErrors('assignment');

        // Evidence exists now: section/subject/term are locked.
        Assessment::factory()->create(['subject_id' => $this->cesc->id, 'section_id' => $this->agila->id, 'grading_period' => 1,
            'school_year' => '2026-2027', 'name' => 'Quiz 1', 'component' => 'written_work', 'max_score' => 20, 'uploaded_by' => $this->darien->id]);
        $this->update(['subject_id' => $this->science->id, 'grading_period' => 3])->assertSessionHasErrors('assignment');
        $this->assertSame([$this->cesc->id, 1], [$this->assignment->fresh()->subject_id, (int) $this->assignment->fresh()->grading_period]);
        $this->assertSame(1, Assessment::count());
    }

    public function test_reassignment_does_not_reopen_a_closed_term_and_past_years_are_locked(): void
    {
        $item = $this->item();
        AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->update(['is_open' => false]);
        $this->update(['user_id' => $this->carla->id])->assertSessionHas('success');
        $this->assertFalse(AcademicTerm::isOpen('2026-2027', 1), 'the term stays closed');
        $this->saveCells($this->carla, $item, [$this->ana->id => '12'])->assertStatus(423);

        $old = Section::factory()->create(['name' => 'Old', 'grade_level' => 11, 'school_year' => '2025-2026']);
        $past = TeachingAssignment::create(['user_id' => $this->darien->id, 'section_id' => $old->id, 'subject_id' => $this->science->id,
            'school_year' => '2025-2026', 'grading_period' => 1]);
        $this->actingAs($this->admin)->get(route('admin.teaching-assignments.edit', $past->id))->assertForbidden();
        $this->update(['user_id' => $this->carla->id], $past)->assertSessionHas('error');
        $this->actingAs($this->admin)->delete(route('admin.teaching-assignments.destroy', $past->id), ['reason' => 'Cleanup attempt'])->assertSessionHas('error');
        $this->assertSame($this->darien->id, $past->fresh()->user_id);
    }

    public function test_removing_archives_the_assignment_and_never_deletes_evidence(): void
    {
        $item = $this->item();
        AssessmentScore::create(['assessment_id' => $item->id, 'student_id' => $this->ana->id, 'score' => 17]);

        $this->actingAs($this->admin)->delete(route('admin.teaching-assignments.destroy', $this->assignment->id))->assertSessionHasErrors('reason');
        $this->assertNotNull($this->assignment->fresh());

        $this->actingAs($this->admin)->delete(route('admin.teaching-assignments.destroy', $this->assignment->id), ['reason' => 'Teacher on leave'])
            ->assertSessionHas('success');
        $this->assertNull($this->assignment->fresh());
        $revoked = TeachingAssignmentHistory::where('action', TeachingAssignmentHistory::REVOKED)->sole();
        $this->assertSame([$this->darien->id, null, 'Teacher on leave', $this->admin->id], [$revoked->old_user_id, $revoked->new_user_id, $revoked->reason, $revoked->changed_by]);
        $this->assertSame(1, Assessment::count());
        $this->assertSame(1, AssessmentScore::count());

        // Nobody can encode the class until someone is assigned.
        $this->saveCells($this->darien, $item, [$this->ana->id => '10'])->assertForbidden();
        $this->actingAs($this->admin)->get(route('admin.teaching-assignments'))->assertSee('Recorded evidence with no teaching assignment')->assertSee('Removed');
    }
}
