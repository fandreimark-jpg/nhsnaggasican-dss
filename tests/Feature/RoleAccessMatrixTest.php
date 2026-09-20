<?php

namespace Tests\Feature;

use App\Models\{AcademicTerm, AcademicYear, Assessment, AssessmentScore, Grade, Intervention, RiskResult, Section, Specialization, Student, Subject, Track, User};
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
        $intB = Intervention::create(['student_id' => $stuB->id, 'section_id' => $secB->id, 'school_year' => '2026-2027', 'grading_period' => 1, 'subject_id' => $subject->id, 'recommended_type' => 'remediation', 'status' => 'approved', 'origin' => 'principal', 'created_by' => $principal->id, 'decided_by' => $principal->id]);


        $this->fx = compact('year', 'term', 'admin', 'principal', 'adviserA', 'adviserB', 'disabled', 'track', 'secA', 'secB', 'stuA', 'stuB', 'subject', 'asA', 'asB', 'intA', 'intB');
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
        foreach (Route::getRoutes() as $route) {
            if (!in_array('GET', $route->methods())) continue;
            $uri = $route->uri();
            $owner = match (true) {
                str_starts_with($uri, 'admin/') => 'admin',
                str_starts_with($uri, 'adviser/') => 'adviser',
                str_starts_with($uri, 'principal/') => 'principal',
                default => null,
            };
            if ($owner === null) continue;
            $url = '/' . preg_replace_callback('/\{(\w+)\??\}/', fn($m) => $params[$m[1]] ?? 1, $uri);

            $this->assertTrue($this->as(null)->get($url)->isRedirect(route('login')), "guest -> {$uri} must redirect to login");
            $this->assertTrue($this->as($disabled)->get($url)->isRedirect(route('login')), "disabled -> {$uri} must be bounced to login");

            foreach (['admin' => $admin, 'adviser' => $adviserA, 'principal' => $principal] as $role => $user) {
                $status = $this->as($user)->get($url)->getStatusCode();
                if ($role === $owner) {
                    $this->assertContains($status, [200, 302, 404], "{$role} -> {$uri} must be reachable (got {$status})");
                    $this->assertNotSame(403, $status, "{$role} -> {$uri} must not be forbidden to its own role");
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
        $refused($adviserB, 'put', "/adviser/assessments/item/{$asA->id}", ['name' => 'Z', 'max_score' => 10, 'component' => 'written_work']);
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
        // Laravel's local-disk serve routes only honour SIGNED urls.
        $this->assertSame(403, $this->as($adviserA)->get('/storage/temp_assessment_uploads/x.xlsx')->getStatusCode());
        $this->assertSame(403, $this->as($adviserA)->put('/storage/temp_assessment_uploads/x.xlsx')->getStatusCode());

        $this->assertNull(Grade::where('student_id', $stuA->id)->where('grade', 90)->first(), 'No probe wrote a grade.');
        $this->assertSame('approved', $intA->fresh()->status);
    }
}
