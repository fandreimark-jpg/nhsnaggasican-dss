<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeachingAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Final audit (2026-09-28) — save safety defects found and fixed:
 *  1. A JSON save from an account disabled mid-session was answered with a
 *     redirect to /login; fetch() followed it to a 200 HTML page and the
 *     Class Record reported "Saved" while nothing was written. It is now a
 *     401 JSON refusal (and the page only accepts a confirmed save).
 *  2. Retrying a save whose first response was lost (server committed,
 *     browser never heard back) was reported as a conflict against the
 *     user's own values. A cell that already holds exactly the value being
 *     saved is now an idempotent retry; a genuinely different value is
 *     still a conflict.
 */
class FinalAuditSaveSafetyTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;
    private Section $section;
    private Subject $subject;
    private Student $ana;
    private Assessment $quiz;

    protected function setUp(): void
    {
        parent::setUp();
        AcademicYear::create(['school_year' => '2026-2027', 'is_active' => true]);
        AcademicTerm::ensureExistFor('2026-2027');
        AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->update(['is_open' => true]);
        $this->teacher = User::factory()->create(['role' => 'subject_teacher']);
        $this->section = Section::factory()->create(['grade_level' => 11, 'curriculum' => 'sshs', 'school_year' => '2026-2027']);
        $this->subject = Subject::factory()->create(['type' => 'core', 'grade_level' => 11, 'subject_group' => 'core_academic']);
        $this->subject->syncTerms([1]);
        $this->ana = Student::factory()->create(['section_id' => $this->section->id]);
        TeachingAssignment::create(['user_id' => $this->teacher->id, 'section_id' => $this->section->id, 'subject_id' => $this->subject->id, 'school_year' => '2026-2027', 'grading_period' => 1]);
        $this->quiz = Assessment::factory()->create(['subject_id' => $this->subject->id, 'section_id' => $this->section->id, 'grading_period' => 1,
            'school_year' => '2026-2027', 'name' => 'Quiz 1', 'component' => 'written_work', 'exam_role' => null, 'max_score' => 20, 'uploaded_by' => $this->teacher->id]);
    }

    private function save(?string $score, ?string $baseScore, User $as = null)
    {
        return $this->actingAs($as ?? $this->teacher)->postJson(route('teacher.class-record.save'), [
            'section_id' => $this->section->id, 'subject_id' => $this->subject->id, 'period' => 1,
            'cells' => [['assessment_id' => $this->quiz->id, 'student_id' => $this->ana->id, 'score' => $score,
                'missing' => false, 'base_score' => $baseScore, 'base_missing' => false]],
        ]);
    }

    public function test_a_disabled_accounts_open_tab_gets_a_json_refusal_not_a_redirect(): void
    {
        $this->teacher->is_active = false;
        $this->teacher->save();

        $response = $this->save('15', null);
        $response->assertStatus(401)->assertJsonFragment(['message' => 'Your account has been disabled or its role no longer allows this. Nothing was saved. Contact the administrator.']);
        $this->assertFalse($response->isRedirect());
        $this->assertSame(0, AssessmentScore::count());
        $this->assertGuest();

        // An ordinary page request still redirects to the sign-in page with the message.
        $this->teacher->refresh();
        $this->actingAs($this->teacher)->get(route('teacher.classes'))->assertRedirect(route('login'));
    }

    public function test_a_signed_out_json_save_is_a_401_not_a_redirect(): void
    {
        $this->postJson(route('teacher.class-record.save'), ['section_id' => $this->section->id])->assertStatus(401);
        $this->assertSame(0, AssessmentScore::count());
    }

    public function test_a_retry_after_a_lost_response_is_idempotent_but_a_different_value_is_still_a_conflict(): void
    {
        $this->save('15', null)->assertOk()->assertJsonPath('changed', 1);

        // Same request again (the browser never saw the first response): accepted, nothing rewritten.
        $this->save('15', null)->assertOk()->assertJsonPath('changed', 0);
        $this->assertSame(1, AssessmentScore::count());
        $this->assertSame(1, \App\Models\AssessmentEvidenceAudit::count(), 'no duplicate history');

        // Someone else's different value on a stale base is refused, and nothing is overwritten.
        $this->save('18', null)->assertStatus(409)->assertJsonPath('conflicts.' . $this->quiz->id . ':' . $this->ana->id . '.current_score', '15');
        $this->assertSame(15.0, (float) AssessmentScore::sole()->score);
    }

    public function test_the_class_record_script_only_accepts_a_confirmed_save(): void
    {
        $js = file_get_contents(resource_path('js/class-record.js'));
        $this->assertStringContainsString("const confirmed = response.ok && !response.redirected && typeof data.changed === 'number';", $js);
        $this->assertStringContainsString('if (confirmed) {', $js);
        $this->assertStringNotContainsString("if (response.ok) {\n            payload.cells", $js);
    }
}
