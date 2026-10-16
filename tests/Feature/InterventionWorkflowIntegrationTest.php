<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Intervention;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeachingAssignment;
use App\Models\User;
use App\Services\InterventionScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Scheduling inside the EXISTING Interventions module (2026-10-06): one
 * "Interventions" item per role, one record carrying decision, schedule,
 * completion, requests and history; old "Schedules & Completion" GET
 * bookmarks redirect; legacy delivery is shown as delivery, never as a
 * schedule completion; group delivery never covers a scheduled intervention.
 */
class InterventionWorkflowIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $principal;
    private User $adviser;
    private User $teacher;
    private User $outsider;
    private Section $section;
    private Section $other;
    private Subject $subject;
    private InterventionScheduleService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::create(2026, 10, 10, 8, 0, 0, 'Asia/Manila'));
        AcademicYear::create(['school_year' => '2026-2027', 'is_active' => true]);
        AcademicTerm::ensureExistFor('2026-2027');
        AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->update(['is_open' => true]);
        $this->svc = new InterventionScheduleService();
        $this->principal = User::factory()->create(['role' => 'principal']);
        $this->adviser = User::factory()->create(['role' => 'adviser', 'name' => 'Adviser, Ana']);
        $this->teacher = User::factory()->create(['role' => 'subject_teacher', 'name' => 'Teacher, Tom']);
        $this->outsider = User::factory()->create(['role' => 'adviser', 'name' => 'Adviser, Out']);
        $this->section = Section::factory()->create(['name' => 'Curie', 'grade_level' => 11, 'school_year' => '2026-2027', 'adviser_id' => $this->adviser->id]);
        $this->other = Section::factory()->create(['name' => 'Agila', 'grade_level' => 11, 'school_year' => '2026-2027', 'adviser_id' => $this->outsider->id]);
        $this->subject = Subject::factory()->create(['name' => 'Effective Communication', 'type' => 'core', 'grade_level' => 11, 'subject_group' => 'core_academic']);
        $this->subject->syncTerms([1, 2, 3]);
        TeachingAssignment::create(['user_id' => $this->teacher->id, 'section_id' => $this->section->id, 'subject_id' => $this->subject->id, 'school_year' => '2026-2027', 'grading_period' => 1]);
        // The Curie adviser ALSO teaches this subject in Agila (another section).
        TeachingAssignment::create(['user_id' => $this->adviser->id, 'section_id' => $this->other->id, 'subject_id' => $this->subject->id, 'school_year' => '2026-2027', 'grading_period' => 1]);
    }

    private function intervention(Section $section, string $last, array $extra = []): Intervention
    {
        $student = Student::factory()->create(['section_id' => $section->id, 'last_name' => $last]);

        return Intervention::create($extra + ['student_id' => $student->id, 'subject_id' => $this->subject->id, 'section_id' => $section->id, 'school_year' => '2026-2027',
            'grading_period' => 1, 'recommended_type' => 'remediation', 'status' => 'approved', 'origin' => 'principal', 'created_by' => $this->principal->id,
            'decided_by' => $this->principal->id, 'decided_at' => now()]);
    }

    private function scheduleFor(Intervention $iv, User $responsible, string $start = '2026-10-10 10:00', string $deadline = '2026-10-10 12:00'): void
    {
        // The section adviser is the responsible staff; THEY set the schedule (2026-10-07).
        $this->svc->transferToSectionAdviser($iv, $this->principal, 'test');
        $this->svc->schedule($iv, ['title' => 'Make-up activity', 'starts_at' => Carbon::parse($start), 'deadline_at' => Carbon::parse($deadline)], $responsible, $iv->fresh()->schedule_version);
    }

    public function test_each_role_has_exactly_one_interventions_navigation_item(): void
    {
        // Subject teachers have no intervention access (2026-10-07): no item at all.
        foreach ([[$this->principal, 'principal.dashboard', 1], [$this->adviser, 'adviser.dashboard', 1], [$this->teacher, 'teacher.classes', 0]] as [$user, $route, $items]) {
            $html = $this->actingAs($user)->get(route($route))->assertOk()->getContent();
            preg_match_all('#<a href="[^"]*"\s+class="nav-link[^"]*"[^>]*>\s*<i class="bi [^"]+" aria-hidden="true"></i>\s*<span>Interventions</span>#', $html, $m);
            $this->assertCount($items, $m[0], $user->role . ': Interventions items');
            $this->assertStringNotContainsString('Schedules &amp; Completion', $html, $user->role);
        }
    }

    public function test_old_schedule_and_completion_urls_reach_the_integrated_pages_and_keep_authorization(): void
    {
        $iv = $this->intervention($this->section, 'ABAD');
        $this->actingAs($this->adviser)->get(route('teacher.interventions', ['filter' => 'expired']))->assertRedirect(route('adviser.interventions', ['schedule' => 'expired']));
        $this->actingAs($this->adviser)->get(route('teacher.interventions'))->assertRedirect(route('adviser.interventions'));
        $this->actingAs($this->adviser)->get(route('teacher.interventions.show', $iv))->assertRedirect(route('adviser.interventions.show', $iv));
        $this->actingAs($this->outsider)->get(route('teacher.interventions.show', $iv))->assertForbidden();   // no redirect for out-of-scope ids
        $this->actingAs($this->outsider)->get(route('adviser.interventions.show', $iv))->assertForbidden();
        $this->actingAs($this->teacher)->get(route('adviser.interventions.show', $iv))->assertForbidden();   // role middleware
        $this->actingAs($this->teacher)->get(route('teacher.interventions.show', $iv))->assertForbidden();   // subject teacher: no intervention page at all
        $this->actingAs($this->teacher)->get(route('teacher.interventions'))->assertForbidden();
        foreach (['schedule', 'extend', 'reopen', 'complete', 'requests'] as $old) {                       // the former teacher write endpoints are gone
            $this->actingAs($this->teacher)->post('/teacher/interventions/' . $iv->id . '/' . $old, ['schedule_version' => 0])->assertNotFound();
        }
    }

    public function test_the_adviser_interventions_page_carries_decision_schedule_filter_open_link_and_other_scope(): void
    {
        $scheduled = $this->intervention($this->section, 'ABAD');
        $this->scheduleFor($scheduled, $this->adviser);
        $legacy = $this->intervention($this->section, 'BAUTISTA');
        // Still recorded as responsible in another section (e.g. before a reconciliation): visible, read-only.
        $elsewhere = $this->intervention($this->other, 'CRUZ', ['responsible_user_id' => $this->adviser->id]);

        $html = $this->actingAs($this->adviser)->get(route('adviser.interventions', ['grading_period' => 1]))->assertOk()->getContent();
        $this->assertStringContainsString('Decision / Schedule', $html);
        $this->assertStringContainsString(route('adviser.interventions.show', $scheduled), $html);
        $this->assertStringContainsString('data-lifecycle="scheduled"', $html);
        $this->assertStringContainsString('data-lifecycle="unscheduled"', $html);
        $this->assertStringNotContainsString('only the Principal moves an intervention', $html);
        $this->assertStringContainsString('data-other-row="' . $elsewhere->id . '"', $html, 'other class shown on the same page');

        $only = $this->actingAs($this->adviser)->get(route('adviser.interventions', ['grading_period' => 1, 'schedule' => 'unscheduled']))->assertOk()->getContent();
        $this->assertStringContainsString('BAUTISTA', $only);
        $this->assertStringNotContainsString('ABAD', $only);
        $mine = $this->actingAs($this->adviser)->get(route('adviser.interventions', ['grading_period' => 1, 'schedule' => 'mine']))->getContent();
        $this->assertStringContainsString('CRUZ', $mine);
        $this->assertStringNotContainsString('BAUTISTA', $mine);
    }

    public function test_the_principal_manages_decision_schedule_and_history_on_one_record(): void
    {
        $iv = $this->intervention($this->section, 'ABAD');
        $list = $this->actingAs($this->principal)->get(route('principal.interventions', ['grading_period' => 1]))->assertOk()->getContent();
        $this->assertStringContainsString('Decision / Schedule', $list);
        $this->assertStringContainsString('Open · assign section adviser', $list);

        // A submitted staff id is ignored: the section adviser is assigned.
        $this->actingAs($this->principal)->post(route('principal.interventions.responsible', $iv), ['responsible_user_id' => $this->teacher->id, 'schedule_version' => 0])
            ->assertRedirect(route('principal.interventions.show', $iv));
        $this->assertSame($this->adviser->id, $iv->fresh()->responsible_user_id);
        $this->actingAs($this->principal)->get(route('principal.interventions', ['grading_period' => 1]))->assertSee('Adviser, Ana sets the schedule');
        $this->actingAs($this->adviser)->post(route('adviser.interventions.schedule', $iv), ['title' => 'Make-up quiz', 'starts_at' => '2026-10-10T10:00',
            'deadline_at' => '2026-10-11T17:00', 'schedule_version' => 1])->assertRedirect(route('adviser.interventions.show', $iv));
        $this->actingAs($this->principal)->put(route('principal.interventions.update', $iv), ['status' => 'in_progress', 'principal_notes' => 'Follow up Friday', 'return_to' => 'show'])
            ->assertRedirect(route('principal.interventions.show', $iv));
        $page = $this->actingAs($this->principal)->get(route('principal.interventions.show', $iv))->assertOk();
        $page->assertSee('Your decision')->assertSee('In Progress')->assertSee('Follow up Friday')->assertSee('Make-up quiz')->assertSee('Oct 11, 2026 5:00 PM');
        $this->assertSame(1, Intervention::count(), 'one record, no second module record');

        // Each schedule-status filter agrees with the model's derived state.
        $done = $this->intervention($this->section, 'DONE');
        $this->scheduleFor($done, $this->adviser, '2026-10-10 10:30', '2026-10-10 18:00');
        $this->at('2026-10-10 11:00');
        $this->svc->recordCompletion($done, $this->adviser, now(), 'Held');
        $gone = $this->intervention($this->section, 'GONE');
        $this->svc->cancel($gone, $this->principal, 'Transferred');
        $soon = $this->intervention($this->section, 'SOON');
        $this->scheduleFor($soon, $this->adviser, '2026-10-12 08:00', '2026-10-12 17:00');
        foreach (['ongoing' => 'ABAD', 'completed' => 'DONE', 'cancelled' => 'GONE', 'scheduled' => 'SOON'] as $filter => $who) {
            $html = $this->actingAs($this->principal)->get(route('principal.interventions', ['grading_period' => 1, 'schedule' => $filter]))->getContent();
            $this->assertStringContainsString($who, $html, $filter);
            foreach (array_diff(['ABAD', 'DONE', 'GONE', 'SOON'], [$who]) as $notWho) {
                $this->assertStringNotContainsString($notWho . ',', $html, "{$filter} excludes {$notWho}");
            }
        }
    }

    private function at(string $t): void
    {
        $this->travelTo(Carbon::parse($t, 'Asia/Manila'));
    }

    public function test_legacy_delivery_is_shown_as_delivery_not_schedule_completion(): void
    {
        $iv = $this->intervention($this->section, 'ABAD', ['acknowledged_at' => now(), 'acknowledged_by' => $this->adviser->id]);
        $this->actingAs($this->adviser)->post(route('adviser.interventions.deliver', $iv), ['delivery_notes' => 'Session held'])->assertSessionHas('success');
        $iv->refresh();
        $this->assertSame(['completed', 'unscheduled'], [$iv->lifecycle(), $iv->scheduleState()], 'logic unchanged; display keeps "Schedule not set"');
        $this->actingAs($this->principal)->get(route('principal.interventions.show', $iv))->assertOk()
            ->assertSee('Schedule not set')->assertSee('Delivered (adviser record — not scheduled)')->assertDontSee('Completed (activity done)');
    }

    public function test_group_delivery_never_covers_a_scheduled_intervention(): void
    {
        $scheduled = $this->intervention($this->section, 'ABAD', ['acknowledged_at' => now(), 'acknowledged_by' => $this->adviser->id]);
        $this->scheduleFor($scheduled, $this->adviser);
        $legacy = $this->intervention($this->section, 'BAUTISTA', ['acknowledged_at' => now(), 'acknowledged_by' => $this->adviser->id]);
        $this->actingAs($this->adviser)->post(route('adviser.interventions.deliver-group'), ['intervention_ids' => [$scheduled->id, $legacy->id],
            'delivery_notes' => 'One re-teaching session for the group.'])->assertSessionHas('success');
        $this->assertNull($scheduled->fresh()->delivered_at, 'scheduled: completion only by its responsible staff');
        $this->assertNotNull($legacy->fresh()->delivered_at);
        $this->at('2026-10-10 11:00');
        $this->actingAs($this->adviser)->post(route('adviser.interventions.deliver', $scheduled), ['delivery_notes' => 'Held'])->assertSessionHas('error');
        $this->assertNull($scheduled->fresh()->delivered_at);
    }
}
