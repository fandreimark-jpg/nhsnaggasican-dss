<?php

namespace Tests\Feature;

use App\Models\{AcademicTerm, AcademicYear, Assessment, AssessmentScore, Grade, Intervention, RiskResult, Section, Specialization, Student, Subject, TeachingAssignment, Track, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * "Pre-demo full-system audit" (2026-09-20), Phases 2-5 — the whole route
 * table exercised as every principal: guest, admin, adviser, principal and a
 * DISABLED account. Every GET route under a role prefix must be reachable
 * by exactly that role, 403 for the other two, a login redirect for a guest
 * and for a disabled session (EnsureAccountIsActive re-reads the account on
 * every request). Then a set of cross-object probes: adviser B against
 * adviser A's learner, assessment, intervention and section; adviser and
 * principal against Admin mutations; unsigned access to the local-disk
 * serve routes. Hiding a button is not authorization — this asserts the
 * server's answer for every route, so a new route that forgets its role
 * middleware fails here.
 */
class RoleAccessMatrixTest extends TestCase
{
    use RefreshDatabase;

    private array $fx = [];

    private function fixture(): void
    {
        $year = AcademicYear::create(['school_year' => '2026-2027', 'is_active' => true]);
        AcademicTerm::ensureExistFor('2026-2027');
        $term = AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->first();
        $term->update(['is_open' => true]);

        $admin = User::factory()->admin()->create(['email' => 'a@x.test']);
        $principal = User::factory()->principal()->create(['email' => 'p@x.test']);
        $adviserA = User::factory()->create(['role' => 'adviser', 'email' => 'aa@x.test']);
        $adviserB = User::factory()->create(['role' => 'adviser', 'email' => 'ab@x.test']);
        $disabled = User::factory()->create(['role' => 'adviser', 'email' => 'd@x.test', 'is_active' => false]);
        $teacher = User::factory()->create(['role' => 'subject_teacher', 'email' => 't@x.test']);

        $track = Track::factory()->create(['code' => 'ACAD']);
        $spec = Specialization::factory()->create(['track_id' => $track->id]);
        $secA = Section::factory()->create(['name' => 'SecA', 'grade_level' => 11, 'curriculum' => 'sshs', 'track_id' => $track->id, 'adviser_id' => $adviserA->id, 'school_year' => '2026-2027']);
        $secB = Section::factory()->create(['name' => 'SecB', 'grade_level' => 11, 'curriculum' => 'sshs', 'track_id' => $track->id, 'adviser_id' => $adviserB->id, 'school_year' => '2026-2027']);
        $stuA = Student::factory()->create(['section_id' => $secA->id]);
        $stuB = Student::factory()->create(['section_id' => $secB->id]);
        $subject = Subject::factory()->create(['type' => 'core', 'grade_level' => 11, 'subject_group' => 'core_academic']);
        $subject->syncTerms([1, 2, 3]);
        $asA = Assessment::factory()->create(['subject_id' => $subject->id, 'section_id' => $secA->id, 'grading_period' => 1, 'school_year' => '2026-2027', 'component' => 'written_work', 'max_score' => 10]);
        $asB = Assessment::factory()->create(['subject_id' => $subject->id, 'section_id' => $secB->id, 'grading_period' => 1, 'school_year' => '2026-2027', 'component' => 'written_work', 'max_score' => 10]);
        AssessmentScore::factory()->create(['assessment_id' => $asA->id, 'student_id' => $stuA->id, 'score' => 7]);
        AssessmentScore::factory()->create(['assessment_id' => $asB->id, 'student_id' => $stuB->id, 'score' => 7]);
        Grade::create(['student_id' => $stuA->id, 'subject_id' => $subject->id, 'section_id' => $secA->id, 'grading_period' => 1, 'grade' => 80, 'school_year' => '2026-2027', 'encoded_by' => $adviserA->id]);
        $rrA = RiskResult::create(['student_id' => $stuA->id, 'section_id' => $secA->id, 'grading_period' => 1, 'school_year' => '2026-2027', 'risk_level' => 'high', 'ml_risk_level' => 'high', 'average_grade' => 70, 'confidence' => 80, 'generated_at' => now()]);
        $intA = Intervention::create(['student_id' => $stuA->id, 'section_id' => $secA->id, 'school_year' => '2026-2027', 'grading_period' => 1, 'subject_id' => $subject->id, 'recommended_type' => 'remediation', 'status' => 'approved', 'origin' => 'principal', 'created_by' => $principal->id, 'decided_by' => $principal->id, 'risk_result_id' => $rrA->id]);
        // Teaching assignments ("Subject Teacher and Class Record" pass):
        // adviser A teaches the subject in their own section; the subject
        // teacher teaches a second subject in section A.
        $subjectT = Subject::factory()->create(['type' => 'core', 'grade_level' => 11, 'subject_group' => 'core_academic', 'name' => 'Teacher Subject']);
        $subjectT->syncTerms([1, 2, 3]);
        TeachingAssignment::create(['user_id' => $adviserA->id, 'section_id' => $secA->id, 'subject_id' => $subject->id, 'school_year' => '2026-2027', 'grading_period' => 1]);
        TeachingAssignment::create(['user_id' => $teacher->id, 'section_id' => $secA->id, 'subject_id' => $subjectT->id, 'school_year' => '2026-2027', 'grading_period' => 1]);
        $asT = Assessment::factory()->create(['subject_id' => $subjectT->id, 'section_id' => $secA->id, 'grading_period' => 1, 'school_year' => '2026-2027', 'component' => 'written_work', 'max_score' => 10]);
        $intB = Intervention::create(['student_id' => $stuB->id, 'section_id' => $secB->id, 'school_year' => '2026-2027', 'grading_period' => 1, 'subject_id' => $subject->id, 'recommended_type' => 'remediation', 'status' => 'approved', 'origin' => 'principal', 'created_by' => $principal->id, 'decided_by' => $principal->id]);


        // An intervention in the subject teacher's OWN class — still refused (no intervention access, 2026-10-07).
        $intT = Intervention::create(['student_id' => $stuA->id, 'section_id' => $secA->id, 'school_year' => '2026-2027', 'grading_period' => 1, 'subject_id' => $subjectT->id, 'recommended_type' => 'remediation', 'status' => 'approved', 'origin' => 'principal', 'created_by' => $principal->id, 'decided_by' => $principal->id]);

        $this->fx = compact('year', 'term', 'admin', 'principal', 'adviserA', 'adviserB', 'disabled', 'teacher', 'track', 'secA', 'secB', 'stuA', 'stuB', 'subject', 'subjectT', 'asA', 'asB', 'asT', 'intA', 'intB', 'intT');
    }

    private function as(?User $user)
    {
        $this->app->make('auth')->forgetGuards();
        $this->flushSession();
        return $user ? $this->actingAs($user) : $this;
    }

    public function test_every_get_route_answers_each_role_correctly(): void
    {
        $this->fixture();
        extract($this->fx);
        $params = ['section' => $secA->id, 'student' => $stuA->id, 'id' => 1, 'subject' => $subject->id, 'trackId' => $track->id,
            'academicTerm' => $term->id, 'term' => $term->id, 'academicYear' => $year->id, 'intervention' => $intA->id,
            'assessment' => $asA->id, 'token' => 'x', 'path' => 'temp_x/y.xlsx', 'hash' => 'h'];


        $checked = 0;
        $prefixOwner = ['admin/' => 'admin', 'adviser/' => 'adviser', 'principal/' => 'principal', 'teacher/' => 'subject_teacher'];
        foreach (Route::getRoutes() as $route) {
            if (!in_array('GET', $route->methods())) continue;
            $uri = $route->uri();
            $prefix = collect(array_keys($prefixOwner))->first(fn($p) => str_starts_with($uri, $p));
            if ($prefix === null) continue;

            // The roles a route admits are read from its OWN role middleware,
            // and the prefix's owning role must be among them — so a route
            // that forgets its middleware (no roles) fails here.
            $roles = collect($route->gatherMiddleware())
                ->filter(fn($m) => is_string($m) && str_starts_with($m, 'role:'))
                ->flatMap(fn($m) => explode(',', substr($m, 5)))->unique()->values()->all();
            $this->assertNotEmpty($roles, "{$uri} has no role middleware");
            if (str_starts_with($uri, 'teacher/interventions')) {
                // Old intervention bookmarks (2026-10-07): ADVISERS only (redirect);
                // subject teachers have no intervention access at all.
                $this->assertSame(['adviser'], $roles, "{$uri} must be adviser-only");
            } else {
                $this->assertContains($prefixOwner[$prefix], $roles, "{$uri} must admit the {$prefix} role");
            }
            if ($prefix === 'teacher/' && !str_starts_with($uri, 'teacher/interventions')) {
                $this->assertEqualsCanonicalizing(['adviser', 'subject_teacher'], $roles, "{$uri} is the teaching workspace: Adviser and Subject Teacher only");
            }

            $asUser = fn(string $role) => $role === 'subject_teacher' ? ['assessment' => $asT->id, 'intervention' => $intT->id] : [];
            foreach (['admin' => $admin, 'adviser' => $adviserA, 'principal' => $principal, 'subject_teacher' => $teacher] as $role => $user) {
                $p = array_merge($params, $asUser($role));
                $url = '/' . preg_replace_callback('/\{(\w+)\??\}/', fn($m) => $p[$m[1]] ?? 1, $uri);
                if ($role === 'admin') {
                    $this->assertTrue($this->as(null)->get($url)->isRedirect(route('login')), "guest -> {$uri} must redirect to login");
                    $this->assertTrue($this->as($disabled)->get($url)->isRedirect(route('login')), "disabled -> {$uri} must be bounced to login");
                }
                $status = $this->as($user)->get($url)->getStatusCode();
                if (in_array($role, $roles, true)) {
                    $this->assertContains($status, [200, 302, 404], "{$role} -> {$uri} must be reachable (got {$status})");
                } else {
                    $this->assertSame(403, $status, "{$role} -> {$uri} must be 403");
                }
            }
            $checked++;
        }
        $this->assertGreaterThanOrEqual(27, $checked, 'The role-prefixed GET routes were actually exercised.');
    }

    public function test_cross_object_and_cross_role_mutations_are_refused(): void
    {
        $this->fixture();
        extract($this->fx);

        $refused = function (User $u, string $method, string $url, array $data = []) {
            $r = $this->as($u)->from('/probe-origin')->{$method}($url, $data);
            $status = $r->getStatusCode();
            if ($status === 302) {
                $session = $r->getSession();
                $this->assertTrue($session->has('errors') || $session->has('error'), "{$method} {$url} redirected without any error — was it accepted?");
                return;
            }
            $this->assertContains($status, [403, 404, 422], "{$method} {$url} returned {$status}");
        };

        $refused($adviserB, 'put', "/adviser/students/{$stuA->id}", ['first_name' => 'X', 'last_name' => 'Y', 'lrn' => $stuA->lrn, 'gender' => 'male']);
        $refused($adviserB, 'put', "/teacher/assessments/{$asA->id}", ['max_score' => 10, 'component' => 'written_work', 'loaded_fingerprint' => 'x']);
        $refused($adviserB, 'post', "/adviser/interventions/{$intA->id}/acknowledge");
        $refused($adviserB, 'post', "/adviser/interventions/{$intA->id}/deliver", ['delivery_notes' => 'done']);
        // The batch grade form skips a learner outside the adviser's own
        // section silently (the form never lists one) — the assertion that
        // matters is that nothing is written.
        $before = Grade::count();
        $this->as($adviserB)->post('/adviser/grades', ['grading_period' => 1, 'grades' => [['student_id' => $stuA->id, 'subject_id' => $subject->id, 'grade' => 90]]]);
        $this->assertSame($before, Grade::count(), 'Adviser B must not be able to write a grade for the other adviser’s learner.');
        $refused($principal, 'get', '/admin/users');
        $refused($principal, 'post', '/admin/users', ['name' => 'x', 'email' => 'q@q.q', 'password' => 'Password123!', 'role' => 'admin']);
        $refused($principal, 'delete', "/admin/subjects/{$subject->id}");
        $refused($adviserA, 'post', '/principal/interventions', ['student_id' => $stuA->id, 'type' => 'remediation']);
        $refused($adviserA, 'put', "/principal/interventions/{$intA->id}", ['status' => 'approved']);
        $refused($adviserA, 'get', "/principal/students/{$stuA->id}");
        $refused($admin, 'post', '/adviser/grades', ['grading_period' => 1, 'grades' => [['student_id' => $stuA->id, 'subject_id' => $subject->id, 'grade' => 90]]]);
        // Teaching scope ("Subject Teacher and Class Record" pass): advising
        // a section grants no edit; a subject teacher edits only their own
        // assigned subject, and never learner master data.
        $refused($adviserA, 'put', "/teacher/assessments/{$asT->id}", ['max_score' => 10, 'component' => 'written_work', 'loaded_fingerprint' => 'x']);
        $refused($teacher, 'put', "/teacher/assessments/{$asA->id}", ['max_score' => 10, 'component' => 'written_work', 'loaded_fingerprint' => 'x']);
        $refused($teacher, 'post', '/teacher/assessments', ['section_id' => $secA->id, 'subject_id' => $subject->id, 'grading_period' => 1, 'item_name' => 'X', 'component' => 'written_work', 'max_score' => 10]);
        $refused($teacher, 'post', '/teacher/class-record/save', ['section_id' => $secA->id, 'subject_id' => $subject->id, 'period' => 1,
            'cells' => [['assessment_id' => $asA->id, 'student_id' => $stuA->id, 'score' => '1', 'missing' => false, 'base_score' => '7', 'base_missing' => false]]]);
        $refused($teacher, 'post', '/admin/students', ['lrn' => '123456789012', 'last_name' => 'X', 'first_name' => 'Y', 'gender' => 'male']);
        $refused($teacher, 'get', '/adviser/students');
        $refused($teacher, 'post', '/principal/interventions', ['student_id' => $stuA->id, 'type' => 'remediation']);
        $refused($adviserB, 'get', "/teacher/class-record?section_id={$secA->id}");
        $refused($adviserB, 'get', "/teacher/learners/{$stuA->id}/report?school_year=2026-2027&period=1");
        $refused($adviserB, 'get', "/teacher/class-record/export?section_id={$secA->id}&subject_id={$subject->id}&period=1");
        $this->assertSame('7.00', (string) AssessmentScore::where('assessment_id', $asA->id)->value('score'), 'No probe changed a score.');

        // Private uploads are never served: Laravel's local-disk serve routes
        // are switched off (config/filesystems.php, 2026-10-06), so no route.
        $this->assertFalse(collect(\Illuminate\Support\Facades\Route::getRoutes())->contains(fn($r) => str_starts_with($r->uri(), 'storage/')));
        $this->assertSame(404, $this->as($adviserA)->get('/storage/temp_assessment_uploads/x.xlsx')->getStatusCode());
        $this->assertSame(404, $this->as($adviserA)->put('/storage/temp_assessment_uploads/x.xlsx')->getStatusCode());

        $this->assertNull(Grade::where('student_id', $stuA->id)->where('grade', 90)->first(), 'No probe wrote a grade.');
        $this->assertSame('approved', $intA->fresh()->status);
    }
}
