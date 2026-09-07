<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AcademicTerm;
use App\Models\Assessment;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Decision flow, report scoping, and dashboard pass" TASK 4a — each Data
 * Health check on the Admin dashboard, verified against both seeded GOOD
 * data (reports positively, zero state) and seeded BAD data (reports the
 * problem, names it, and links where it can be fixed).
 *
 * Two of the six checks in this file's setup ("learners not assigned to
 * any section", "duplicate LRNs") describe states this schema already
 * makes structurally impossible today — students.section_id is a
 * required (non-nullable) foreign key, and students.lrn carries a real
 * DB-level unique constraint (see the 2026_06_16_011935 migration). Both
 * checks are kept anyway as defense-in-depth (a future schema change
 * could reintroduce either gap) and are tested here only for their
 * always-true positive state, since the negative state cannot be
 * constructed through the ORM without bypassing a real DB constraint.
 */
class AdminDataHealthChecksTest extends TestCase
{
    use RefreshDatabase;

    private function healthySection(): Section
    {
        $section = Section::factory()->create(['grade_level' => 11]);
        Subject::factory()->create(['type' => 'core', 'grade_level' => 11]);
        Student::factory()->create(['section_id' => $section->id]);

        return $section;
    }

    // LogActivity::log() reads the AUTHENTICATED user (auth()->id()), not
    // a passed-in id, so it can't record a login for an arbitrary target
    // user from a test with no session for them — write the row directly.
    private function recordLogin(User $user): void
    {
        ActivityLog::create([
            'user_id' => $user->id, 'action' => 'login',
            'description' => 'Logged in', 'table_name' => 'users', 'record_id' => $user->id,
        ]);
    }

    public function test_healthy_data_shows_positive_zero_states(): void
    {
        $this->healthySection();
        $admin = User::factory()->admin()->create();
        $this->recordLogin($admin);

        $response = $this->actingAs($admin)->get('/admin/dashboard');

        $response->assertOk();
        $response->assertSee('All sections have an adviser');
        $response->assertSee('Every learner is assigned to a section');
        $response->assertSee('Every section resolves at least one subject');
        $response->assertSee('No duplicate LRNs found');
    }

    public function test_section_without_an_adviser_is_detected(): void
    {
        Section::factory()->create(['adviser_id' => null, 'name' => 'NoAdviserSection']);
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get('/admin/dashboard');

        $response->assertOk();
        // Count and label sit in separate DOM nodes (the count is
        // bolded) — assert the label text and the count value separately
        // rather than as one contiguous string.
        $response->assertSee('section with no assigned adviser');
        $response->assertSeeInOrder(['Data Health', '1', 'section with no assigned adviser']);
        $response->assertSee(route('admin.sections'), false);
    }

    /**
     * students.section_id has no nullable path through the ORM (a real
     * NOT NULL foreign key — see this class's docblock), so this only
     * verifies the always-true zero state plus that the dashboard's
     * deep link (?section_id=none) doesn't error even though nothing
     * can ever match it today.
     */
    public function test_learner_without_a_section_zero_state_and_filter_link_do_not_error(): void
    {
        $this->healthySection();
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get('/admin/dashboard');
        $response->assertOk();
        $response->assertSee('Every learner is assigned to a section');
        $response->assertDontSee(route('admin.students', ['section_id' => 'none']), false);

        $studentsPage = $this->actingAs($admin)->get(route('admin.students', ['section_id' => 'none']));
        $studentsPage->assertOk();
    }

    public function test_section_with_no_subjects_resolved_is_detected(): void
    {
        // Grade 12 section, but no Grade 12 subjects exist anywhere.
        Section::factory()->create(['grade_level' => 12, 'name' => 'NoSubjectsSection']);
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get('/admin/dashboard');

        $response->assertOk();
        $response->assertSee('section with no subjects resolved');
        $response->assertSee('NoSubjectsSection');
    }

    public function test_subject_with_no_assessments_in_the_open_term_is_detected(): void
    {
        $section = Section::factory()->create(['grade_level' => 11, 'school_year' => '2026-2027']);
        Subject::factory()->create(['type' => 'core', 'grade_level' => 11, 'name' => 'IdleSubject']);
        AcademicTerm::create(['school_year' => '2026-2027', 'term' => 1, 'is_open' => true]);
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get('/admin/dashboard');

        $response->assertOk();
        $response->assertSee('subject with no assessments in Term 1');
        $response->assertSee('IdleSubject');
    }

    public function test_subject_with_assessments_in_the_open_term_is_not_flagged(): void
    {
        $section = Section::factory()->create(['grade_level' => 11, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['type' => 'core', 'grade_level' => 11, 'name' => 'ActiveSubject']);
        Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id,
            'grading_period' => 1, 'school_year' => '2026-2027', 'component' => 'written_work',
        ]);
        AcademicTerm::create(['school_year' => '2026-2027', 'term' => 1, 'is_open' => true]);
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get('/admin/dashboard');

        $response->assertOk();
        $response->assertSee('Every subject in use has assessment evidence this term');
    }

    /**
     * students.lrn has a real DB-level unique constraint (see this
     * class's docblock) — only the always-true zero state is testable
     * through the ORM without bypassing that constraint directly.
     */
    public function test_no_duplicate_lrns_zero_state(): void
    {
        $this->healthySection();
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get('/admin/dashboard');

        $response->assertOk();
        $response->assertSee('No duplicate LRNs found');
    }

    public function test_user_who_never_logged_in_is_detected(): void
    {
        User::factory()->create(['name' => 'NeverLoggedInUser']);
        $admin = User::factory()->admin()->create();
        $this->recordLogin($admin);

        $response = $this->actingAs($admin)->get('/admin/dashboard');

        $response->assertOk();
        $response->assertSee('NeverLoggedInUser');
    }

    /**
     * Checked against the service directly rather than scraping the
     * rendered page — a user who logged in still legitimately appears
     * elsewhere on the same dashboard (the Recent Activity panel shows
     * their login event), so assertDontSee() on the raw HTML would be
     * asserting something false about an unrelated panel.
     */
    public function test_user_who_has_logged_in_is_excluded_from_the_never_logged_in_check(): void
    {
        $adviser = User::factory()->create(['name' => 'LoggedInAdviser']);
        $this->recordLogin($adviser);
        $admin = User::factory()->admin()->create();
        $this->recordLogin($admin);

        $checks = app(\App\Services\DashboardAnalyticsService::class)->getDataHealthChecks();

        $this->assertFalse($checks['usersNeverLoggedIn']->pluck('id')->contains($adviser->id));
        $this->assertFalse($checks['usersNeverLoggedIn']->pluck('id')->contains($admin->id));
    }
}
