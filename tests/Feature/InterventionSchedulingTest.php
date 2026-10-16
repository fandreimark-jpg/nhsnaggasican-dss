<?php

namespace Tests\Feature;

use App\Models\AcademicConcern;
use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\Intervention;
use App\Models\InterventionEvent;
use App\Models\InterventionRequest;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeachingAssignment;
use App\Models\User;
use App\Services\AcademicMonitoringService;
use App\Services\AssessmentStatusService;
use App\Services\InterventionScheduleService;
use App\Services\MonitoringClassificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Intervention scheduling, deadlines, completion, requests and expiry
 * (2026-10-06); schedule authority moved to the responsible staff, with
 * no Principal approval step (2026-10-07). Every time-dependent case runs on a controlled clock
 * (travelTo, Asia/Manila) — the computer's clock is never changed.
 */
class InterventionSchedulingTest extends TestCase
{
    use RefreshDatabase;

    private User $principal;
    private User $adviser;
    private User $teacher;
    private User $otherTeacher;
    private User $adviserB;
    private Section $section;
    private Subject $subject;
    private Student $learner;
    private Intervention $iv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::create(2026, 10, 10, 8, 0, 0, 'Asia/Manila'));
        AcademicYear::create(['school_year' => '2026-2027', 'is_active' => true]);
        AcademicTerm::ensureExistFor('2026-2027');
        AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->update(['is_open' => true]);

        $this->principal = User::factory()->create(['role' => 'principal']);
        $this->adviser = User::factory()->create(['role' => 'adviser', 'name' => 'Adviser, Ana']);
        $this->adviserB = User::factory()->create(['role' => 'adviser', 'name' => 'Adviser, Bea']);
        $this->teacher = User::factory()->create(['role' => 'subject_teacher', 'name' => 'Teacher, Tom']);
        $this->otherTeacher = User::factory()->create(['role' => 'subject_teacher', 'name' => 'Teacher, Other']);
        $this->section = Section::factory()->create(['name' => 'Curie', 'grade_level' => 11, 'school_year' => '2026-2027', 'adviser_id' => $this->adviser->id]);
        Section::factory()->create(['name' => 'Other', 'grade_level' => 11, 'school_year' => '2026-2027', 'adviser_id' => $this->adviserB->id]);
        $this->subject = Subject::factory()->create(['name' => 'Effective Communication', 'type' => 'core', 'grade_level' => 11, 'subject_group' => 'core_academic']);
        $this->subject->syncTerms([1, 2, 3]);
        $this->learner = Student::factory()->create(['section_id' => $this->section->id]);
        TeachingAssignment::create(['user_id' => $this->teacher->id, 'section_id' => $this->section->id, 'subject_id' => $this->subject->id, 'school_year' => '2026-2027', 'grading_period' => 1]);
        $this->iv = Intervention::create(['student_id' => $this->learner->id, 'subject_id' => $this->subject->id, 'section_id' => $this->section->id,
            'school_year' => '2026-2027', 'grading_period' => 1, 'recommended_type' => 'remediation', 'status' => 'approved', 'origin' => 'principal',
            'created_by' => $this->principal->id, 'decided_by' => $this->principal->id, 'decided_at' => now()]);
    }

    private function at(string $time): void
    {
        $this->travelTo(Carbon::parse($time, 'Asia/Manila'));
    }

    /**
     * Principal "Transfer to section adviser" — the only Principal action on
     * responsibility (2026-10-07). The assignee is always the section's
     * current adviser; a submitted staff id is ignored.
     */
    private function assign(?User $ignored = null)
    {
        return $this->actingAs($this->principal)->post(route('principal.interventions.responsible', $this->iv), [
            'responsible_user_id' => $ignored?->id, 'schedule_version' => $this->iv->fresh()->schedule_version,
        ]);
    }

    /** Admin › Sections changes Curie's adviser (the existing workflow), which moves open interventions. */
    private function replaceAdviser(?User $new)
    {
        $admin = User::firstWhere('role', 'admin') ?? User::factory()->create(['role' => 'admin']);
        if (!$this->section->fresh()->specialization_id) {
            $spec = \App\Models\Specialization::factory()->create();
            $this->section->forceFill(['track_id' => $spec->track_id, 'specialization_id' => $spec->id])->save();
        }
        $s = $this->section->fresh();

        return $this->actingAs($admin)->put(route('admin.sections.update', $s->id), ['name' => $s->name, 'grade_level' => $s->grade_level,
            'track_id' => $s->track_id, 'specialization_id' => $s->specialization_id, 'school_year' => $s->school_year, 'adviser_id' => $new?->id]);
    }

    /**
     * The RESPONSIBLE staff set (or, with the reason, reschedule) the
     * schedule directly — no approval step (2026-10-07). Names the
     * responsible staff first when needed.
     */
    private function schedule(string $start = '2026-10-10T10:00', string $deadline = '2026-10-10T12:00', ?User $responsible = null, array $extra = [], ?User $as = null)
    {
        $responsible ??= $this->adviser;
        if ($this->iv->fresh()->responsible_user_id === null) {
            $this->assign();
        }

        return $this->actingAs($as ?? $responsible)->post(route('adviser.interventions.schedule', $this->iv), $extra + [
            'title' => 'Re-teaching session', 'starts_at' => $start, 'deadline_at' => $deadline, 'instructions' => 'Bring the module.',
            'completion_criteria' => 'Attended and finished the worksheet.', 'reason' => 'Moved to suit the class',
            'schedule_version' => $this->iv->fresh()->schedule_version,
        ]);
    }

    private function extend(string $deadline, string $reason = 'Learner was absent', ?User $as = null, array $extra = [])
    {
        return $this->actingAs($as ?? $this->adviser)->post(route('adviser.interventions.extend', $this->iv), $extra + [
            'deadline_at' => $deadline, 'reason' => $reason, 'schedule_version' => $this->iv->fresh()->schedule_version,
        ]);
    }

    private function reopen(string $start, string $deadline, string $reason = 'Learner was sick', ?User $as = null)
    {
        return $this->actingAs($as ?? $this->adviser)->post(route('adviser.interventions.reopen', $this->iv), [
            'starts_at' => $start, 'deadline_at' => $deadline, 'reason' => $reason, 'schedule_version' => $this->iv->fresh()->schedule_version,
        ]);
    }

    /** Service-level: transfer $iv to its section adviser ($who) and let them set the schedule. */
    private function svcSchedule(Intervention $iv, User $who, string $start, string $deadline): void
    {
        $svc = new InterventionScheduleService();
        $svc->transferToSectionAdviser($iv, $this->principal, 'test');
        $svc->schedule($iv, ['title' => 'S', 'starts_at' => Carbon::parse($start), 'deadline_at' => Carbon::parse($deadline)], $who, $iv->fresh()->schedule_version);
    }

    /** A spare adviser with no section yet (the replacement in Admin › Sections). */
    private function adviserC(): User
    {
        return User::firstWhere('name', 'Adviser, Cora') ?? User::factory()->create(['role' => 'adviser', 'name' => 'Adviser, Cora']);
    }

    /** Messages in one form's error bag plus the flash error. */
    private function errorsOf(string $bag): string
    {
        return collect(session('errors')?->getBag($bag)->all() ?? [])->push(session('error'))->filter()->implode(' ');
    }

    private function complete(User $as, string $at = '2026-10-10T11:00')
    {
        return $this->actingAs($as)->post(route('adviser.interventions.complete', $this->iv), ['completed_at' => $at, 'notes' => 'Session held; worksheet done.']);
    }

    private function expire(): void
    {
        $this->artisan('dss:expire-interventions')->assertExitCode(0);
    }

    // --- Lifecycle boundaries, timezone -----------------------------------------------------------

    public function test_lifecycle_boundaries_follow_the_server_clock_in_manila(): void
    {
        $this->schedule()->assertSessionHas('success');
        $iv = $this->iv->fresh();
        $this->assertSame('2026-10-10 10:00:00', $iv->getRawOriginal('starts_at'), 'stored as Manila wall-clock (app timezone)');
        $this->assertSame('2026-10-10 12:00:00', $iv->getRawOriginal('deadline_at'));

        foreach (['2026-10-10 09:59:59' => 'scheduled', '2026-10-10 10:00:00' => 'ongoing', '2026-10-10 11:59:59' => 'ongoing',
                  '2026-10-10 12:00:00' => 'ongoing', '2026-10-10 12:00:01' => 'expired'] as $t => $state) {
            $this->assertSame($state, $iv->lifecycle(Carbon::parse($t, 'Asia/Manila')), $t);
        }
        $this->actingAs($this->principal)->get(route('principal.interventions.show', $this->iv))->assertOk()
            ->assertSee('Oct 10, 2026 10:00 AM')->assertSee('Oct 10, 2026 12:00 PM')->assertSee('Manila time');
    }

    public function test_invalid_schedules_are_refused_and_nothing_is_saved(): void
    {
        $this->assign($this->adviser)->assertSessionHas('success');
        foreach ([['2026-10-10T12:00', '2026-10-10T10:00', 'later than the start'], ['2026-10-10T10:00', '2026-10-10T10:00', 'later than the start'],
                  ['2026-10-09T08:00', '2026-10-09T09:00', 'in the past'], ['2026-10-10T07:59', '2026-10-10T09:00', 'in the past'],
                  ['2026-02-30T10:00', '2026-03-02T10:00', 'valid date'], ['2026-10-10', '2026-10-11', 'valid date'], ['2026-10-10T10:00', 'tomorrow', 'valid date']] as [$s, $d, $msg]) {
            $this->schedule($s, $d);
            $this->assertStringContainsString($msg, $this->errorsOf('schedule'), "{$s} → {$d}");
        }
        $this->assertNull($this->iv->fresh()->deadline_at);
        $this->assertSame(['reassigned'], InterventionEvent::pluck('event')->all(), 'only the naming of the responsible staff');

        // A start in the current minute is not "the past" (server clock, minute precision).
        $this->schedule('2026-10-10T08:00', '2026-10-10T09:00')->assertSessionHas('success');

        // A submitted staff id never chooses the assignee (it stays the section adviser), and with
        // no valid adviser (disabled account) nothing is assigned — never a fallback account.
        $this->assign($this->otherTeacher)->assertSessionHas('success');
        $this->assertSame($this->adviser->id, $this->iv->fresh()->responsible_user_id);
        $this->adviser->forceFill(['is_active' => false])->save();
        $this->assign($this->adviserB)->assertSessionHas('error', fn($m) => str_contains($m, 'Admin › Sections'));
        $this->assertSame($this->adviser->id, $this->iv->fresh()->responsible_user_id);
        $this->assertSame(1, InterventionEvent::where('event', 'reassigned')->count());
    }

    // --- Completion and permissions -------------------------------------------------------------

    public function test_only_the_responsible_staff_records_completion_while_ongoing_and_at_the_deadline_is_on_time(): void
    {
        $this->schedule();
        $this->at('2026-10-10 09:30');
        $this->complete($this->adviser, '2026-10-10T09:30')->assertSessionHas('error'); // not started

        $this->at('2026-10-10 12:00:00'); // exactly the deadline
        $this->complete($this->teacher)->assertForbidden();                // the class's subject teacher: no intervention access
        $this->complete($this->otherTeacher)->assertForbidden();           // out of scope
        $this->actingAs($this->principal)->post(route('adviser.interventions.complete', $this->iv), ['completed_at' => '2026-10-10T11:00', 'notes' => 'x x x x x'])->assertForbidden(); // wrong role
        $this->complete($this->adviser, '2026-10-10T12:30')->assertSessionHas('error'); // in the future
        $this->complete($this->adviser, '2026-10-10T12:00')->assertSessionHas('success');

        $iv = $this->iv->fresh();
        $this->assertSame('completed', $iv->lifecycle(Carbon::parse('2026-10-11', 'Asia/Manila')));
        $this->assertSame($this->adviser->id, $iv->delivered_by);
        $this->complete($this->adviser, '2026-10-10T12:00')->assertSessionHas('error'); // second time: kept, not duplicated
        $this->assertSame(1, InterventionEvent::where('event', 'completed')->count());

        $this->at('2026-10-11 08:00');
        $this->expire();
        $this->assertSame('approved', $this->iv->fresh()->status, 'a completed intervention is never expired');
    }

    public function test_out_of_scope_staff_cannot_see_and_responsibility_does_not_open_other_learners(): void
    {
        $this->schedule();
        $this->actingAs($this->adviserB)->get(route('adviser.interventions.show', $this->iv))->assertForbidden();
        $this->actingAs($this->otherTeacher)->get(route('adviser.interventions.show', $this->iv))->assertForbidden();
        $this->extend('2026-10-12T12:00', as: $this->otherTeacher)->assertForbidden();
        $this->actingAs($this->adviser)->get(route('adviser.interventions.show', $this->iv))->assertOk()->assertDontSee('Record completion</button>', false); // not started yet
        $this->at('2026-10-10 10:30');
        $this->actingAs($this->adviser)->get(route('adviser.interventions.show', $this->iv))->assertOk()->assertSee('Record completion');
        $this->actingAs($this->teacher)->get(route('adviser.interventions.show', $this->iv))->assertForbidden(); // subject teacher: no intervention access
        $this->actingAs($this->adviser)->get(route('adviser.interventions'))->assertOk()->assertSee($this->learner->last_name);
        $this->actingAs($this->adviserB)->get(route('adviser.interventions'))->assertOk()->assertDontSee($this->learner->last_name);
        $this->actingAs($this->teacher)->get(route('adviser.interventions'))->assertForbidden();
        $this->actingAs($this->teacher)->get(route('teacher.interventions'))->assertForbidden();
        $this->actingAs($this->adviserB)->get(route('adviser.interventions.show', $this->iv))->assertForbidden();
        // Responsibility is not a teaching grant: the teacher still cannot open another section's class record.
        $other = Section::where('name', 'Other')->first();
        $this->actingAs($this->adviser)->get(route('teacher.class-record', ['section_id' => $other->id, 'subject_id' => $this->subject->id, 'period' => 1]))->assertForbidden();
    }

    public function test_an_adviser_removed_or_replaced_in_admin_sections_loses_access_and_the_new_adviser_takes_over(): void
    {
        $this->schedule();
        $this->at('2026-10-10 11:00');
        // Section left without an adviser: nothing is reassigned, nobody can act, the Principal is told why.
        $this->replaceAdviser(null)->assertSessionHas('warning');
        $this->assertSame($this->adviser->id, $this->iv->fresh()->responsible_user_id, 'history kept, not silently reassigned');
        $this->complete($this->adviser)->assertForbidden();
        $this->actingAs($this->principal)->get(route('principal.interventions.show', $this->iv))->assertOk()
            ->assertSee('has no adviser assigned')->assertSee('Admin › Sections');

        // A new adviser in Admin › Sections: the open intervention moves, audited, in the same write.
        $this->replaceAdviser($this->adviserC())->assertSessionHas('success', fn($m) => str_contains($m, '1 open intervention'));
        $this->assertSame($this->adviserC()->id, $this->iv->fresh()->responsible_user_id);
        $moved = InterventionEvent::where('event', 'reassigned')->latest('id')->first();
        $this->assertSame([$this->adviser->id, $this->adviserC()->id], [$moved->old_values['responsible_user_id'], $moved->new_values['responsible_user_id']]);
        $this->assertSame(2, InterventionEvent::where('event', 'reassigned')->count(), 'automatic assignment + transfer');
        $this->complete($this->adviser)->assertForbidden();
        $this->actingAs($this->adviser)->get(route('adviser.interventions.show', $this->iv))->assertForbidden(); // no longer advises it, no longer responsible
        $this->complete($this->adviserC())->assertSessionHas('success');
        $this->assertSame($this->adviserC()->id, $this->iv->fresh()->delivered_by);
        // The earlier schedule still credits the former adviser.
        $this->assertSame($this->adviser->id, InterventionEvent::where('event', 'scheduled')->sole()->actor_id);
    }

    // --- Direct schedule authority (2026-10-07) ---------------------------------------------------

    public function test_the_section_adviser_sets_the_schedule_directly_with_no_approval(): void
    {
        $this->actingAs($this->adviser)->get(route('adviser.interventions.show', $this->iv))->assertOk()
            ->assertSee('No responsible adviser is assigned yet')->assertDontSee('data-form="schedule"', false);
        $this->assign()->assertSessionHas('success');
        $this->actingAs($this->adviser)->get(route('adviser.interventions.show', $this->iv))->assertOk()->assertSee('data-form="schedule"', false)->assertSee('Set schedule');
        $this->schedule()->assertRedirect(route('adviser.interventions.show', $this->iv))->assertSessionHas('success', 'Schedule set.');
        $iv = $this->iv->fresh();
        $this->assertSame(['2026-10-10 10:00:00', '2026-10-10 12:00:00', 'scheduled', 'approved', 2],
            [$iv->getRawOriginal('starts_at'), $iv->getRawOriginal('deadline_at'), $iv->lifecycle(), $iv->status, $iv->schedule_version]);
        $set = InterventionEvent::where('event', 'scheduled')->sole();
        $this->assertSame([$this->adviser->id, null, 2], [$set->actor_id, $set->old_values['deadline_at'], $set->new_values['schedule_version']]);
        $this->assertSame(0, InterventionRequest::count(), 'no request, no approval step');
        $this->assertDatabaseHas('activity_logs', ['action' => 'schedule_intervention', 'user_id' => $this->adviser->id]);

        // Reschedule before the start: reason required; history keeps old and new.
        $this->schedule('2026-10-10T13:00', '2026-10-10T15:00', extra: ['reason' => ''])->assertSessionHas('error');
        $this->assertStringContainsString('reason', $this->errorsOf('schedule'));
        $this->schedule('2026-10-10T13:00', '2026-10-10T15:00', extra: ['reason' => 'Room not free at 10'])->assertSessionHas('success', 'Schedule changed.');
        $changed = InterventionEvent::where('event', 'schedule_changed')->sole();
        $this->assertSame(['2026-10-10 12:00:00', '2026-10-10 15:00:00', 'Room not free at 10', 3],
            [$changed->old_values['deadline_at'], $changed->new_values['deadline_at'], $changed->reason, $changed->new_values['schedule_version']]);

        // Once ongoing the start is history: rescheduling is refused, extension is the way.
        $this->at('2026-10-10 13:30');
        $this->schedule('2026-10-10T14:00', '2026-10-10T16:00')->assertSessionHas('error', fn($m) => str_contains($m, 'Extend deadline'));

        // The subject teacher of the class and another section's adviser have no access at all.
        $this->actingAs($this->teacher)->get(route('adviser.interventions.show', $this->iv))->assertForbidden();
        $this->actingAs($this->teacher)->post(route('adviser.interventions.extend', $this->iv), ['deadline_at' => '2026-10-12T11:00', 'reason' => 'More time', 'schedule_version' => 3])->assertForbidden();
        $this->actingAs($this->adviserB)->post(route('adviser.interventions.extend', $this->iv), ['deadline_at' => '2026-10-12T11:00', 'reason' => 'More time', 'schedule_version' => 3])->assertForbidden();
        $this->assertSame('2026-10-10 15:00:00', $this->iv->fresh()->getRawOriginal('deadline_at'));
    }

    public function test_the_responsible_staff_extends_the_deadline_directly_and_the_start_is_kept(): void
    {
        $this->schedule();
        $this->at('2026-10-10 11:00'); // ongoing
        $this->extend('2026-10-12T12:00', as: $this->teacher)->assertForbidden();          // in scope, not responsible
        $this->extend('2026-10-12T12:00', '')->assertSessionHas('errors');                   // reason required
        $this->assertStringContainsString('reason', $this->errorsOf('extend'));
        $this->extend('2026-10-10T12:00')->assertSessionHas('error', fn($m) => str_contains($m, 'later than the current one'));
        $this->extend('2026-10-10T11:30')->assertSessionHas('error');                        // earlier than the current deadline
        $this->extend('2026-10-11T x')->assertSessionHas('errors');
        $this->assertSame(['2026-10-10 12:00:00', 2], [$this->iv->fresh()->getRawOriginal('deadline_at'), $this->iv->fresh()->schedule_version]);

        $this->extend('2026-10-12T12:00', 'Learner was absent today')->assertSessionHas('success', 'Deadline extended.');
        $iv = $this->iv->fresh();
        $this->assertSame(['2026-10-10 10:00:00', '2026-10-12 12:00:00', 3, 'ongoing'],
            [$iv->getRawOriginal('starts_at'), $iv->getRawOriginal('deadline_at'), $iv->schedule_version, $iv->lifecycle()]);
        $e = InterventionEvent::where('event', 'extended')->sole();
        $this->assertSame([$this->adviser->id, '2026-10-10 12:00:00', '2026-10-12 12:00:00', 'Learner was absent today', 3],
            [$e->actor_id, $e->old_values['deadline_at'], $e->new_values['deadline_at'], $e->reason, $e->new_values['schedule_version']]);
        $this->assertNotNull($e->recorded_at);
        $this->assertDatabaseHas('activity_logs', ['action' => 'extend_intervention', 'user_id' => $this->adviser->id]);
        $this->actingAs($this->principal)->get(route('principal.interventions.show', $this->iv))->assertOk()->assertSee('Deadline extended')->assertSee('Learner was absent today');

        // Deadline boundary: extendable AT the deadline (still ongoing), not one second after.
        $this->at('2026-10-12 12:00:00');
        $this->extend('2026-10-12T15:00')->assertSessionHas('success');
        $this->at('2026-10-12 15:00:01');
        $this->extend('2026-10-13T15:00')->assertSessionHas('error', fn($m) => str_contains($m, 'Reopen and set schedule'));
        // The new deadline must also be in the future.
        $this->assertSame('2026-10-12 15:00:00', $this->iv->fresh()->getRawOriginal('deadline_at'));
    }

    public function test_an_extension_to_a_past_moment_is_refused_even_when_later_than_the_deadline(): void
    {
        $this->schedule('2026-10-10T08:00', '2026-10-10T09:00');
        $this->at('2026-10-10 08:59');
        $this->extend('2026-10-10T08:59')->assertSessionHas('error'); // not later than the current deadline
        $this->travelTo(Carbon::parse('2026-10-10 08:59:30', 'Asia/Manila'));
        $this->assertSame('ongoing', $this->iv->fresh()->lifecycle());
        $this->extend('2026-10-10T09:30')->assertSessionHas('success');
    }

    public function test_an_unapproved_or_unassigned_intervention_cannot_be_scheduled(): void
    {
        $this->iv->forceFill(['status' => 'recommended', 'decided_by' => null, 'decided_at' => null])->save();
        // Responsibility is assigned at recording, before any decision — but scheduling still needs the approval.
        $this->assign()->assertSessionHas('success');
        $this->assertSame($this->adviser->id, $this->iv->fresh()->responsible_user_id);
        $this->actingAs($this->adviser)->get(route('adviser.interventions.show', $this->iv))->assertOk()->assertSee('Not approved yet')->assertDontSee('data-form="schedule"', false);
        $this->schedule()->assertSessionHas('error', fn($m) => str_contains($m, 'not approved'));
        $this->assertNull($this->iv->fresh()->deadline_at);

        // Approved but nobody named: nobody can schedule.
        $this->iv->forceFill(['status' => 'approved', 'decided_by' => $this->principal->id, 'decided_at' => now(), 'responsible_user_id' => null])->save();
        $this->actingAs($this->adviser)->post(route('adviser.interventions.schedule', $this->iv), ['starts_at' => '2026-10-10T10:00', 'deadline_at' => '2026-10-10T12:00', 'schedule_version' => 0])->assertForbidden();
        $this->actingAs($this->teacher)->post(route('adviser.interventions.schedule', $this->iv), ['starts_at' => '2026-10-10T10:00', 'deadline_at' => '2026-10-10T12:00', 'schedule_version' => 0])->assertForbidden();
        $this->assertNull($this->iv->fresh()->deadline_at);
        $this->assertSame(['reassigned'], InterventionEvent::pluck('event')->all(), 'only the assignment');
    }

    public function test_the_principal_old_schedule_and_reopen_endpoints_cannot_bypass_the_responsible_staff(): void
    {
        $before = $this->iv->fresh()->getAttributes();
        $old = ['title' => 'X', 'responsible_user_id' => $this->adviser->id, 'starts_at' => '2026-10-10T10:00', 'deadline_at' => '2026-10-10T12:00', 'schedule_version' => 0];
        $this->actingAs($this->principal)->post(route('principal.interventions.schedule', $this->iv), $old)
            ->assertRedirect(route('principal.interventions.show', $this->iv))->assertSessionHas('error', fn($m) => str_contains($m, 'responsible adviser'));
        $this->actingAs($this->principal)->postJson(route('principal.interventions.schedule', $this->iv), $old)->assertStatus(410);
        $this->actingAs($this->principal)->post(route('principal.interventions.reopen', $this->iv), ['deadline_at' => '2026-10-12T12:00', 'reason' => 'One more try', 'schedule_version' => 0])
            ->assertSessionHas('error');
        $this->assertSame($before, $this->iv->fresh()->getAttributes(), 'nothing written');
        // Nor can the Principal use the staff endpoints.
        foreach (['schedule', 'extend', 'reopen', 'complete', 'requests'] as $action) {
            $this->actingAs($this->principal)->post(route('adviser.interventions.' . $action, $this->iv), $old + ['reason' => 'x x x x x'])->assertForbidden();
        }
        // Staff cannot self-assign through a schedule payload.
        $this->assign($this->adviser);
        $this->schedule(extra: ['responsible_user_id' => $this->teacher->id])->assertSessionHas('success');
        $this->assertSame($this->adviser->id, $this->iv->fresh()->responsible_user_id);
        $this->actingAs($this->adviser)->post(route('principal.interventions.responsible', $this->iv), ['responsible_user_id' => $this->adviser->id, 'schedule_version' => 2])->assertForbidden();
        $this->actingAs($this->principal)->get(route('principal.interventions.show', $this->iv))->assertOk()
            ->assertDontSee('data-form="schedule"', false)->assertDontSee('data-form="extend"', false)
            ->assertSee('data-responsible-card', false)->assertDontSee('name="responsible_user_id"', false); // read-only: no staff picker
    }

    public function test_replacing_the_section_adviser_ends_the_previous_advisers_access_at_once(): void
    {
        $this->schedule();
        $version = $this->iv->fresh()->schedule_version;
        $this->replaceAdviser($this->adviserC())->assertSessionHas('success');
        $this->assertSame($version + 1, $this->iv->fresh()->schedule_version, 'a form the previous adviser still has open is refused');
        $this->extend('2026-10-11T12:00', 'Still want more time', extra: ['schedule_version' => $version])->assertForbidden();
        $this->schedule('2026-10-10T11:00', '2026-10-10T13:00', $this->adviserC(), as: $this->adviser)->assertForbidden();
        $this->assertSame('2026-10-10 12:00:00', $this->iv->fresh()->getRawOriginal('deadline_at'));
        // The new adviser sees the current version (a stale one is refused, 409-style) and reschedules.
        $this->schedule('2026-10-10T11:00', '2026-10-10T13:00', $this->adviserC(), extra: ['schedule_version' => $version])->assertSessionHas('error');
        $this->schedule('2026-10-10T11:00', '2026-10-10T13:00', $this->adviserC())->assertSessionHas('success');
    }

    public function test_completed_or_cancelled_interventions_refuse_every_schedule_edit(): void
    {
        $this->schedule();
        $this->at('2026-10-10 11:00');
        $this->complete($this->adviser)->assertSessionHas('success');
        $this->extend('2026-10-11T12:00')->assertSessionHas('error');
        $this->schedule('2026-10-10T13:00', '2026-10-10T15:00')->assertSessionHas('error');
        $this->reopen('2026-10-10T13:00', '2026-10-10T15:00')->assertSessionHas('error');
        $this->assign($this->teacher)->assertSessionHas('error');
        $this->actingAs($this->adviser)->get(route('adviser.interventions.show', $this->iv))->assertOk()->assertSee('Completed: the schedule can no longer change');

        $this->iv = Intervention::create(['student_id' => Student::factory()->create(['section_id' => $this->section->id])->id, 'subject_id' => $this->subject->id,
            'section_id' => $this->section->id, 'school_year' => '2026-2027', 'grading_period' => 1, 'recommended_type' => 'remediation', 'status' => 'approved',
            'origin' => 'principal', 'created_by' => $this->principal->id, 'decided_by' => $this->principal->id]);
        $this->schedule('2026-10-10T12:00', '2026-10-10T14:00');
        $this->actingAs($this->principal)->post(route('principal.interventions.cancel', $this->iv), ['reason' => 'Learner transferred'])->assertSessionHas('success');
        $this->extend('2026-10-11T12:00')->assertSessionHas('error');
        $this->schedule('2026-10-10T13:00', '2026-10-10T15:00')->assertSessionHas('error');
        $this->actingAs($this->adviser)->get(route('adviser.interventions.show', $this->iv))->assertOk()->assertSee('Cancelled: the schedule can no longer change');
        $this->assertSame('2026-10-10 14:00:00', $this->iv->fresh()->getRawOriginal('deadline_at'));
    }

    public function test_each_validation_error_is_shown_once_in_its_own_form(): void
    {
        $this->schedule();
        $this->at('2026-10-10 11:00');
        $this->from(route('adviser.interventions.show', $this->iv))->extend('2026-10-12T12:00', '')->assertRedirect(route('adviser.interventions.show', $this->iv));
        $html = $this->actingAs($this->adviser)->get(route('adviser.interventions.show', $this->iv))->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'The reason field is required.'), 'one message, in the Extend form');
        $this->assertSame(1, substr_count($html, 'data-form="extend"'));

        // A refused action (not a field error) is shown inside its form, once, and not in the auto-dismissing toast.
        $this->from(route('adviser.interventions.show', $this->iv))->extend('2026-10-10T11:30', extra: ['_form' => 'extend']);
        $html = $this->actingAs($this->adviser)->get(route('adviser.interventions.show', $this->iv))->getContent();
        $this->assertSame(1, substr_count($html, 'later than the current one'));
        $this->assertStringContainsString('data-form-error', $html);
        $this->assertStringNotContainsString('id="flashToast"', $html);

        // When that form is no longer offered (the deadline passed meanwhile), the reason is still shown, at the top.
        $this->at('2026-10-10 12:00:01');
        $this->from(route('adviser.interventions.show', $this->iv))->extend('2026-10-11T12:00', extra: ['_form' => 'extend']);
        $html = $this->actingAs($this->adviser)->get(route('adviser.interventions.show', $this->iv))->getContent();
        $this->assertSame(1, substr_count($html, 'The deadline has already passed'));
        $this->assertStringNotContainsString('data-form="extend"', $html);

        // A direct POST without the page's form marker keeps the ordinary toast.
        $this->from(route('adviser.interventions.show', $this->iv))->extend('2026-10-11T12:00');
        $this->assertStringContainsString('id="flashToast"', $this->actingAs($this->adviser)->get(route('adviser.interventions.show', $this->iv))->getContent());
    }

    public function test_a_principal_cannot_close_a_scheduled_intervention_as_completed_without_a_recorded_completion(): void
    {
        $this->schedule();
        $html = $this->actingAs($this->principal)->get(route('principal.interventions.show', $this->iv))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<option value="completed"\s+disabled[^>]*>[^<]*needs a recorded completion#', $html);
        $this->actingAs($this->principal)->put(route('principal.interventions.update', $this->iv), ['status' => 'completed'])->assertSessionHas('error');
        $this->assertSame('approved', $this->iv->fresh()->status);
    }

    // --- Expiry --------------------------------------------------------------------------------

    public function test_expiry_needs_no_login_is_idempotent_and_catches_up_after_downtime(): void
    {
        $this->schedule();
        $second = Intervention::create(['student_id' => Student::factory()->create(['section_id' => $this->section->id])->id, 'subject_id' => $this->subject->id,
            'section_id' => $this->section->id, 'school_year' => '2026-2027', 'grading_period' => 1, 'recommended_type' => 'remediation', 'status' => 'in_progress',
            'origin' => 'principal', 'created_by' => $this->principal->id, 'decided_by' => $this->principal->id]);
        $legacy = Intervention::create(['student_id' => Student::factory()->create(['section_id' => $this->section->id])->id, 'subject_id' => $this->subject->id,
            'section_id' => $this->section->id, 'school_year' => '2026-2027', 'grading_period' => 1, 'recommended_type' => 'remediation', 'status' => 'approved',
            'origin' => 'principal', 'created_by' => $this->principal->id, 'decided_by' => $this->principal->id]);
        $this->svcSchedule($second, $this->adviser, '2026-10-10 09:00', '2026-10-11 09:00');

        $this->at('2026-10-10 12:00:00');
        $this->expire();
        $this->assertSame('approved', $this->iv->fresh()->status, 'not expired AT the deadline');

        // Scheduler was down for days: both overdue rows are caught up in one run.
        $this->at('2026-10-14 07:00');
        auth()->logout();
        $this->expire();
        $this->expire(); // rerun: no change, no second event
        $iv = $this->iv->fresh();
        $this->assertSame(['expired', 'approved', '2026-10-10 12:00:00', '2026-10-14 07:00:00'],
            [$iv->status, $iv->status_before_expiry, $iv->getRawOriginal('expired_at'), $iv->getRawOriginal('expiry_processed_at')]);
        $this->assertSame(['expired', 'in_progress'], [$second->fresh()->status, $second->fresh()->status_before_expiry]);
        $this->assertSame(2, InterventionEvent::where('event', 'expired')->count());
        $event = InterventionEvent::where('event', 'expired')->where('intervention_id', $this->iv->id)->sole();
        $this->assertNull($event->actor_id);
        $this->assertSame(['2026-10-10 12:00:00', '2026-10-14 07:00:00'], [$event->effective_at->format('Y-m-d H:i:s'), $event->recorded_at->format('Y-m-d H:i:s')]);
        $this->assertSame('approved', $legacy->fresh()->status, 'a legacy intervention without a schedule never expires');
        $this->assertSame('unscheduled', $legacy->fresh()->lifecycle());

        // A cancelled one is never expired either.
        $this->assertSame(0, Intervention::where('status', 'cancelled')->count());
    }

    public function test_an_overdue_intervention_reads_expired_before_the_job_runs_and_a_get_writes_nothing(): void
    {
        $this->schedule();
        $this->at('2026-10-10 13:00');
        $this->actingAs($this->adviser)->get(route('adviser.interventions.show', $this->iv))->assertOk()->assertSee('Expired — Not completed')->assertSee('data-form="reopen"', false);
        $this->assertSame('approved', $this->iv->fresh()->status, 'no hidden write on a GET');
        $this->complete($this->adviser, '2026-10-10T11:30')->assertSessionHas('error'); // the delayed job does not allow late completion
        $this->assertNull($this->iv->fresh()->delivered_at);
    }

    public function test_cancelled_requires_a_reason_and_is_never_expired_or_completed(): void
    {
        $this->schedule();
        $this->actingAs($this->principal)->post(route('principal.interventions.cancel', $this->iv), ['reason' => ''])->assertSessionHasErrors('reason', null, 'cancel');
        $this->actingAs($this->adviser)->post(route('principal.interventions.cancel', $this->iv), ['reason' => 'No longer needed'])->assertForbidden();
        $this->actingAs($this->principal)->post(route('principal.interventions.cancel', $this->iv), ['reason' => 'Learner transferred out'])->assertSessionHas('success');
        $this->at('2026-10-12 08:00');
        $this->expire();
        $this->assertSame('cancelled', $this->iv->fresh()->status);
        $this->complete($this->adviser)->assertSessionHas('error');
        // An ordinary status edit cannot bring it back.
        $this->actingAs($this->principal)->put(route('principal.interventions.update', $this->iv), ['status' => 'approved'])->assertSessionHas('error');
        $this->actingAs($this->principal)->put(route('principal.interventions.update', $this->iv), ['status' => 'expired'])->assertSessionHasErrors('status');
        $this->assertSame('cancelled', $this->iv->fresh()->status);
    }

    // --- Legacy requests, reopening ----------------------------------------------------------------

    public function test_legacy_schedule_requests_are_kept_never_block_and_are_superseded_by_a_direct_change(): void
    {
        $this->schedule();
        // Pending requests left over from the previous approval workflow.
        $ext = InterventionRequest::create(['intervention_id' => $this->iv->id, 'type' => 'extension', 'requested_by' => $this->adviser->id, 'reason' => 'Old request',
            'proposed_deadline_at' => Carbon::parse('2026-10-20 12:00'), 'based_on_version' => $this->iv->fresh()->schedule_version]);
        $old = InterventionRequest::create(['intervention_id' => $this->iv->id, 'type' => 'schedule', 'requested_by' => $this->teacher->id, 'reason' => 'Old proposal',
            'proposed_starts_at' => Carbon::parse('2026-10-19 09:00'), 'proposed_deadline_at' => Carbon::parse('2026-10-19 12:00'), 'based_on_version' => 1]);
        $page = $this->actingAs($this->principal)->get(route('principal.interventions.show', $this->iv))->assertOk();
        $page->assertSee('Extension request (previous approval workflow)')->assertSee('Dismiss (Reject)')->assertSee('Does not block the responsible staff');
        $this->assertStringNotContainsString('value="approve"', $page->getContent(), 'a legacy request has no Approve button');

        // Approving one directly is refused and executes nothing.
        $this->actingAs($this->principal)->post(route('principal.intervention-requests.decide', $ext), ['decision' => 'approve'])
            ->assertSessionHas('error', fn($m) => str_contains($m, 'previous approval workflow'));
        $this->assertSame(['pending', '2026-10-10 12:00:00'], [$ext->fresh()->status, $this->iv->fresh()->getRawOriginal('deadline_at')]);
        // Staff can no longer create one.
        $this->actingAs($this->adviser)->post(route('adviser.interventions.requests', $this->iv), ['type' => 'extension', 'proposed_deadline_at' => '2026-10-12T12:00', 'reason' => 'More time'])
            ->assertSessionHas('error', fn($m) => str_contains($m, 'no longer used'));
        $this->assertThrows(fn() => (new InterventionScheduleService())->submitRequest($this->iv, $this->adviser, 'schedule', ['reason' => 'x x x']), \App\Services\InterventionTransitionException::class);
        $this->assertSame(2, InterventionRequest::count());

        // They do not block: the responsible staff extend directly, which supersedes both atomically.
        $this->at('2026-10-10 11:00');
        $this->extend('2026-10-11T12:00', 'One more session')->assertSessionHas('success');
        $this->assertSame(['superseded', 'superseded'], [$ext->fresh()->status, $old->fresh()->status]);
        $this->assertStringContainsString('previous approval workflow', $ext->fresh()->decision_notes);
        $this->assertSame('2026-10-11 12:00:00', $this->iv->fresh()->getRawOriginal('deadline_at'), 'the direct change, never the old proposal');
        $this->assertSame(2, InterventionEvent::where('event', 'request_superseded')->count());

        // A dismissal (Reject) of a legacy request is still allowed.
        $third = InterventionRequest::create(['intervention_id' => $this->iv->id, 'type' => 'extension', 'requested_by' => $this->adviser->id, 'reason' => 'Old',
            'proposed_deadline_at' => Carbon::parse('2026-10-20 12:00'), 'based_on_version' => 1]);
        $this->actingAs($this->principal)->post(route('principal.intervention-requests.decide', $third), ['decision' => 'reject', 'decision_notes' => 'Handled directly'])->assertSessionHas('success');
        $this->assertSame('rejected', $third->fresh()->status);
    }

    public function test_the_responsible_staff_reopen_an_expired_intervention_and_history_keeps_both(): void
    {
        $this->schedule();
        $this->at('2026-10-11 08:00');
        $this->expire();
        // Neither a reschedule nor an extension reopens it.
        $this->schedule('2026-10-11T09:00', '2026-10-12T12:00')->assertSessionHas('error', fn($m) => str_contains($m, 'Reopen and set schedule'));
        $this->extend('2026-10-12T12:00')->assertSessionHas('error');
        $this->assertSame('expired', $this->iv->fresh()->status);

        $this->reopen('2026-10-11T09:00', '2026-10-12T12:00', as: $this->teacher)->assertForbidden();  // in scope, not responsible
        $this->reopen('2026-10-11T09:00', '2026-10-12T12:00', '')->assertSessionHas('errors');
        $this->assertStringContainsString('reason', $this->errorsOf('reopen'));
        $this->reopen('2026-10-11T07:00', '2026-10-12T12:00')->assertSessionHas('error', fn($m) => str_contains($m, 'in the past'));
        $this->reopen('2026-10-11T08:00', '2026-10-10T12:00')->assertSessionHas('error');                // not later than the lapsed deadline
        $this->reopen('2026-10-11T08:00', '2026-10-11T08:00')->assertSessionHas('error');                // not later than now / the start
        $this->assertSame('expired', $this->iv->fresh()->status);

        $this->reopen('2026-10-11T09:00', '2026-10-12T12:00', 'Learner was sick')->assertSessionHas('success');
        $iv = $this->iv->fresh();
        $this->assertSame(['approved', '2026-10-11 09:00:00', '2026-10-12 12:00:00', 'scheduled'], [$iv->status, $iv->getRawOriginal('starts_at'), $iv->getRawOriginal('deadline_at'), $iv->lifecycle()]);
        $this->assertSame(['reassigned', 'scheduled', 'expired', 'reopened'], InterventionEvent::orderBy('id')->pluck('event')->all());
        $re = InterventionEvent::where('event', 'reopened')->sole();
        $this->assertSame([$this->adviser->id, 'expired', '2026-10-10 12:00:00', 'Learner was sick', $iv->schedule_version],
            [$re->actor_id, $re->old_values['status'], $re->old_values['deadline_at'], $re->reason, $re->new_values['schedule_version']]);
        $this->assertSame('2026-10-10 12:00:00', $iv->getRawOriginal('expired_at'), 'the lapsed deadline stays on record');

        // A second expiry, NOT yet recorded by the job: reopening records it first, once.
        $this->at('2026-10-13 08:00');
        $this->assertSame('approved', $this->iv->fresh()->status);
        $this->reopen('2026-10-13T09:00', '2026-10-14T12:00', 'One more try')->assertSessionHas('success');
        $this->expire();
        $this->assertSame(2, InterventionEvent::where('event', 'expired')->count(), 'no duplicate expiry');
        $this->assertSame(2, InterventionEvent::where('event', 'reopened')->count());
        $this->assertSame('2026-10-12 12:00:00', InterventionEvent::where('event', 'expired')->latest('id')->first()->effective_at->format('Y-m-d H:i:s'));
    }

    public function test_on_time_completion_recorded_late_needs_principal_review_and_late_work_cannot_be_backdated(): void
    {
        $this->schedule();
        $this->at('2026-10-11 08:00');
        $this->expire();
        $late = fn(string $at, User $as = null) => $this->actingAs($as ?? $this->adviser)->post(route('adviser.interventions.requests', $this->iv),
            ['type' => 'late_completion', 'actual_completed_at' => $at, 'reason' => 'Forgot to record it', 'evidence_notes' => 'Attendance sheet on file']);

        $late('2026-10-10T12:30')->assertSessionHas('error');             // after the deadline: not on-time
        $late('2026-10-10T11:00', $this->teacher)->assertForbidden(); // in scope, but not the responsible staff
        $late('2026-10-10T11:00')->assertSessionHas('success');
        $this->assertSame('expired', $this->iv->fresh()->status, 'pending: still expired, never assumed completed');

        $this->actingAs($this->principal)->post(route('principal.intervention-requests.decide', InterventionRequest::sole()), ['decision' => 'approve'])->assertSessionHas('success');
        $iv = $this->iv->fresh();
        $this->assertSame(['completed', '2026-10-10 11:00:00', 'approved'], [$iv->lifecycle(), $iv->getRawOriginal('completed_at'), $iv->status]);
        $this->assertSame(1, InterventionEvent::where('event', 'expired')->count(), 'the original expiry stays in the history');
        $done = InterventionEvent::where('event', 'completed')->sole();
        $this->assertTrue($done->new_values['recorded_late']);
        $this->assertSame('2026-10-10 11:00:00', $done->effective_at->format('Y-m-d H:i:s'));
    }

    // --- Races ------------------------------------------------------------------------------

    public function test_completion_extension_and_expiry_racing_end_in_one_valid_state(): void
    {
        $svc = new InterventionScheduleService();
        $this->schedule();

        // Completion at the deadline wins; the job (late, same instant) leaves it.
        $this->at('2026-10-10 12:00:00');
        $svc->recordCompletion($this->iv, $this->adviser, Carbon::parse('2026-10-10 12:00', 'Asia/Manila'), 'Done');
        $this->assertSame(0, $svc->expireOverdue(Carbon::parse('2026-10-10 12:05', 'Asia/Manila')));

        // Extension saved first; an expiry pass with the OLD clock reading re-checks the current deadline.
        $other = $this->freshIntervention();
        $this->svcSchedule($other, $this->adviser, '2026-10-10 12:00:30', '2026-10-10 13:00');
        $this->at('2026-10-10 12:30');
        $svc->extend($other, $this->adviser, Carbon::parse('2026-10-11 13:00'), 'More time', $other->fresh()->schedule_version);
        $this->assertSame(0, $svc->expireOverdue(Carbon::parse('2026-10-10 13:30', 'Asia/Manila')));
        $this->assertSame('approved', $other->fresh()->status);

        // Expiry first; a completion or an extension arriving after is refused.
        $this->at('2026-10-11 13:00:01');
        $this->assertSame(1, $svc->expireOverdue());
        $this->assertThrows(fn() => $svc->extend($other, $this->adviser, Carbon::parse('2026-10-12 13:00'), 'Too late', $other->fresh()->schedule_version), \App\Services\InterventionTransitionException::class);
        $this->expectException(\App\Services\InterventionTransitionException::class);
        $svc->recordCompletion($other, $this->adviser, Carbon::parse('2026-10-11 12:00', 'Asia/Manila'), 'Done');
    }

    public function test_a_stale_schedule_form_is_refused_instead_of_overwriting(): void
    {
        $this->schedule();
        $stale = $this->iv->fresh()->schedule_version;
        $this->schedule('2026-10-10T10:00', '2026-10-11T12:00');
        $this->schedule('2026-10-10T10:00', '2026-10-15T12:00', extra: ['schedule_version' => $stale])->assertSessionHas('error', fn($m) => str_contains($m, 'changed by someone else'));
        $this->extend('2026-10-16T12:00', extra: ['schedule_version' => $stale])->assertSessionHas('error', fn($m) => str_contains($m, 'changed by someone else'));
        $this->assertSame('2026-10-11 12:00:00', $this->iv->fresh()->getRawOriginal('deadline_at'));
        $this->assertSame(1, InterventionEvent::where('event', 'schedule_changed')->count());
    }

    private function freshIntervention(): Intervention
    {
        return Intervention::create(['student_id' => Student::factory()->create(['section_id' => $this->section->id])->id, 'subject_id' => $this->subject->id,
            'section_id' => $this->section->id, 'school_year' => '2026-2027', 'grading_period' => 1, 'recommended_type' => 'remediation', 'status' => 'approved',
            'origin' => 'principal', 'created_by' => $this->principal->id, 'decided_by' => $this->principal->id]);
    }

    // --- Activation follow-up (2026-10-06) ---------------------------------------------------

    public function test_reopening_or_late_approval_is_refused_while_another_open_intervention_exists(): void
    {
        $this->schedule();
        $this->at('2026-10-11 08:00');
        $this->expire();
        // After the expiry the Principal recorded a NEW open intervention (allowed: the expired one is not open).
        $this->actingAs($this->principal)->post(route('principal.interventions.store'), ['student_id' => $this->learner->id, 'subject_id' => $this->subject->id,
            'grading_period' => 1, 'recommended_type' => 'other'])->assertSessionHas('success');
        $this->assertSame(1, Intervention::where('student_id', $this->learner->id)->whereIn('status', Intervention::OPEN_STATUSES)->count());

        $this->actingAs($this->adviser)->get(route('adviser.interventions.show', $this->iv))->assertOk()
            ->assertSee('Another open intervention exists')->assertDontSee('data-form="reopen"', false);
        $this->reopen('2026-10-11T09:00', '2026-10-15T12:00', 'Retry')->assertSessionHas('error', fn($m) => str_contains($m, 'Another open intervention'));
        // The correction form is hidden, but a direct POST is still checked when the Principal reviews it.
        $this->actingAs($this->adviser)->post(route('adviser.interventions.requests', $this->iv), ['type' => 'late_completion', 'actual_completed_at' => '2026-10-10T11:00', 'reason' => 'Done on time']);
        $this->actingAs($this->principal)->post(route('principal.intervention-requests.decide', InterventionRequest::sole()), ['decision' => 'approve'])
            ->assertSessionHas('error', fn($m) => str_contains($m, 'Another open intervention'));
        $this->assertSame(['expired', 'pending'], [$this->iv->fresh()->status, InterventionRequest::sole()->status]);
        $this->assertSame(1, Intervention::where('student_id', $this->learner->id)->whereIn('status', Intervention::OPEN_STATUSES)->count(), 'never two open');
        $this->assertSame(0, InterventionEvent::where('event', 'reopened')->count());
    }

    public function test_late_completion_is_judged_against_the_deadline_that_actually_lapsed(): void
    {
        $svc = new InterventionScheduleService();
        $this->schedule(); // 10:00–12:00 on Oct 10
        $this->at('2026-10-11 08:00');
        $this->expire();
        // A late-completion request made against THIS expiry…
        $old = $svc->submitRequest($this->iv->fresh(), $this->adviser, 'late_completion', ['actual_completed_at' => Carbon::parse('2026-10-10 11:30'), 'reason' => 'Done on time']);
        // …is superseded when the responsible staff reopen with a new deadline (Oct 12 12:00).
        $svc->reopen($this->iv->fresh(), $this->adviser, Carbon::parse('2026-10-11 09:00'), Carbon::parse('2026-10-12 12:00'), 'One more session', $this->iv->fresh()->schedule_version);
        $this->assertSame('superseded', $old->fresh()->status, 'a request on the old schedule can no longer be approved');

        // It lapses again; the new request is checked against the SECOND deadline.
        $this->at('2026-10-13 08:00');
        $this->expire();
        $this->assertSame('2026-10-12 12:00:00', $this->iv->fresh()->getRawOriginal('expired_at'));
        $tooLate = fn() => $svc->submitRequest($this->iv->fresh(), $this->adviser, 'late_completion', ['actual_completed_at' => Carbon::parse('2026-10-12 12:01'), 'reason' => 'x x x']);
        $this->assertThrows($tooLate, \App\Services\InterventionTransitionException::class);
        $ok = $svc->submitRequest($this->iv->fresh(), $this->adviser, 'late_completion', ['actual_completed_at' => Carbon::parse('2026-10-12 12:00'), 'reason' => 'Done at the deadline']);
        $svc->decideRequest($ok, $this->principal, true, null);
        $done = InterventionEvent::where('event', 'completed')->sole();
        $this->assertSame(['2026-10-12 12:00:00', '2026-10-12 12:00:00', $this->iv->fresh()->schedule_version],
            [$done->new_values['completed_at'], $done->new_values['judged_against_deadline'], $done->new_values['schedule_version']]);
        $this->assertSame(2, InterventionEvent::where('event', 'expired')->count(), 'both expiries stay in the history');
    }

    public function test_schedule_changes_expiry_completion_and_cancellation_leave_scores_grades_and_concerns_untouched(): void
    {
        $item = Assessment::factory()->create(['subject_id' => $this->subject->id, 'section_id' => $this->section->id, 'grading_period' => 1, 'school_year' => '2026-2027', 'name' => 'Quiz 1', 'component' => 'written_work', 'max_score' => 20]);
        $item2 = Assessment::factory()->create(['subject_id' => $this->subject->id, 'section_id' => $this->section->id, 'grading_period' => 1, 'school_year' => '2026-2027', 'name' => 'Quiz 2', 'component' => 'written_work', 'max_score' => 20]);
        \App\Models\AssessmentScore::create(['assessment_id' => $item->id, 'student_id' => $this->learner->id, 'score' => 12]);
        (new AssessmentStatusService())->markMissing($item2, $this->learner->id, $this->teacher->id);
        (new AcademicMonitoringService())->evaluate('2026-2027', 1);
        \App\Models\Grade::create(['student_id' => $this->learner->id, 'subject_id' => $this->subject->id, 'section_id' => $this->section->id, 'grading_period' => 1,
            'school_year' => '2026-2027', 'grade' => 80, 'computed_grade' => 70, 'is_verified' => true, 'verified_at' => now(), 'is_provisional' => false, 'encoded_by' => $this->teacher->id]);
        $snapshot = fn() => [\App\Models\AssessmentScore::orderBy('id')->get(['assessment_id', 'student_id', 'score'])->toArray(),
            \App\Models\AssessmentLearnerStatus::orderBy('id')->get(['assessment_id', 'student_id', 'status'])->toArray(),
            \App\Models\Grade::orderBy('id')->get(['grade', 'computed_grade', 'is_verified'])->toArray(),
            AcademicConcern::orderBy('id')->get(['id', 'status', 'rule_key'])->toArray()];
        $before = $snapshot();

        $this->schedule();
        $this->schedule('2026-10-10T09:00', '2026-10-10T11:00', extra: ['reason' => 'Earlier slot free']);
        $this->at('2026-10-10 10:00');
        $this->extend('2026-10-10T12:00')->assertSessionHas('success');
        $this->at('2026-10-11 08:00');
        $this->expire();
        $this->reopen('2026-10-11T09:00', '2026-10-11T12:00')->assertSessionHas('success');
        $this->at('2026-10-11 13:00');
        $this->expire();
        $this->actingAs($this->adviser)->post(route('adviser.interventions.requests', $this->iv), ['type' => 'late_completion', 'actual_completed_at' => '2026-10-11T11:00', 'reason' => 'Done on time']);
        $this->actingAs($this->principal)->post(route('principal.intervention-requests.decide', InterventionRequest::where('status', 'pending')->sole()), ['decision' => 'approve'])->assertSessionHas('success');
        $other = $this->freshIntervention();
        (new InterventionScheduleService())->cancel($other, $this->principal, 'Not needed');

        $this->assertSame($before, $snapshot(), 'no score, marker, grade or concern changed');
    }

    public function test_disabled_or_replaced_responsible_staff_can_do_nothing_on_the_intervention(): void
    {
        $this->schedule();
        $this->at('2026-10-10 11:00');
        $this->adviser->forceFill(['is_active' => false])->save();
        $this->complete($this->adviser)->assertRedirect(route('login')); // a disabled account is signed out by EnsureAccountIsActive
        $svc = new InterventionScheduleService();
        $this->assertThrows(fn() => $svc->recordCompletion($this->iv, $this->adviser->fresh(), now(), 'x'), \App\Services\InterventionTransitionException::class);
        $this->assertThrows(fn() => $svc->extend($this->iv, $this->adviser->fresh(), now()->addDay(), 'x x x x x', $this->iv->fresh()->schedule_version), \App\Services\InterventionTransitionException::class);
        $this->assertThrows(fn() => $svc->schedule($this->iv, ['title' => null, 'starts_at' => now()->addHour(), 'deadline_at' => now()->addDay()], $this->adviser->fresh(), $this->iv->fresh()->schedule_version), \App\Services\InterventionTransitionException::class);
        $this->actingAs($this->principal)->get(route('principal.interventions.show', $this->iv))->assertOk()
            ->assertSee('is not an active Adviser account')->assertSee('Admin › Sections')->assertDontSee('data-form="responsible"', false);
        $this->assign()->assertSessionHas('error'); // no valid adviser: nothing is silently assigned
        $this->assertSame($this->adviser->id, $this->iv->fresh()->responsible_user_id);

        // Re-enabled but replaced as section adviser (Admin › Sections): no schedule change, no completion, no correction.
        $this->adviser->forceFill(['is_active' => true])->save();
        $this->replaceAdviser($this->adviserC())->assertSessionHas('success');
        $this->assertSame($this->adviserC()->id, $this->iv->fresh()->responsible_user_id);
        $this->complete($this->adviser)->assertForbidden();
        $this->extend('2026-10-11T12:00')->assertForbidden();
        $this->at('2026-10-11 08:00');
        $this->expire();
        $this->reopen('2026-10-11T09:00', '2026-10-12T12:00')->assertForbidden();
        $this->actingAs($this->adviser)->post(route('adviser.interventions.requests', $this->iv), ['type' => 'late_completion', 'actual_completed_at' => '2026-10-10T11:00', 'reason' => 'Done on time'])->assertForbidden();
        $this->assertSame(0, InterventionRequest::count());
        $this->assertNull($this->iv->fresh()->delivered_at);
        $this->assertSame('2026-10-10 12:00:00', $this->iv->fresh()->getRawOriginal('deadline_at'));
    }

    public function test_the_expiry_command_is_registered_every_minute_and_has_a_dry_run(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('dss:expire-interventions')->assertExitCode(0);
        $this->schedule();
        $this->at('2026-10-10 13:00');
        $this->artisan('dss:expire-interventions --dry-run')->expectsOutputToContain('1 intervention(s) would expire')->assertExitCode(0);
        $this->assertSame('approved', $this->iv->fresh()->status, 'dry run changes nothing');
    }

    // --- Legacy, academic independence, terms -----------------------------------------------

    public function test_legacy_unscheduled_interventions_keep_their_status_and_old_delivery_path(): void
    {
        $this->actingAs($this->principal)->get(route('principal.interventions', ['schedule' => 'unset', 'grading_period' => 1]))->assertOk()->assertSee($this->learner->last_name);
        $this->assertSame('unscheduled', $this->iv->lifecycle());
        $this->iv->update(['acknowledged_at' => now(), 'acknowledged_by' => $this->adviser->id]);
        $this->actingAs($this->adviser)->post(route('adviser.interventions.deliver', $this->iv), ['delivery_notes' => 'Held a session'])->assertSessionHas('success');
        // A legacy completed (delivered) record reads Completed and is never touched by expiry.
        $this->assertSame('completed', $this->iv->fresh()->lifecycle());
        $this->at('2027-01-01 08:00');
        $this->expire();
        $this->assertSame(['approved', null], [$this->iv->fresh()->status, $this->iv->fresh()->expired_at]);
        $this->at('2026-10-10 08:00');

        // Once scheduled, adviser delivery is refused (completion belongs to the responsible staff).
        $iv2 = $this->freshIntervention();
        $iv2->update(['acknowledged_at' => now(), 'acknowledged_by' => $this->adviser->id]);
        $this->svcSchedule($iv2, $this->adviser, '2026-10-10 08:00', '2026-10-11 08:00');
        $this->actingAs($this->adviser)->post(route('adviser.interventions.deliver', $iv2), ['delivery_notes' => 'Held a session'])->assertSessionHas('error');
        $this->assertNull($iv2->fresh()->delivered_at);
    }

    public function test_intervention_status_never_resolves_or_changes_academic_concerns(): void
    {
        $item = Assessment::factory()->create(['subject_id' => $this->subject->id, 'section_id' => $this->section->id, 'grading_period' => 1, 'school_year' => '2026-2027', 'name' => 'Quiz 1', 'component' => 'written_work', 'max_score' => 20]);
        (new AssessmentStatusService())->markMissing($item, $this->learner->id, $this->teacher->id);
        (new AcademicMonitoringService())->evaluate('2026-2027', 1);
        $concern = AcademicConcern::sole();
        $this->iv->update(['academic_concern_id' => $concern->id]);
        $classify = fn() => (new MonitoringClassificationService())->forStudent($this->learner->id, '2026-2027', 1);

        $this->schedule();
        $this->at('2026-10-10 11:00');
        $this->complete($this->adviser)->assertSessionHas('success');
        $this->assertSame('open', $concern->fresh()->status, 'completed activity, requirement still Missing: concern stays');
        $this->assertTrue($classify()->isForReview());

        $second = $this->freshIntervention();
        $second->update(['student_id' => $this->learner->id, 'subject_id' => $this->subject->id, 'grading_period' => 2]);
        (new InterventionScheduleService())->cancel($second, $this->principal, 'Duplicate request');
        $this->assertSame('open', $concern->fresh()->status);
        $this->assertSame(0, AcademicConcern::where('id', '!=', $concern->id)->count(), 'no new concern from intervention changes');
    }

    public function test_a_closed_term_keeps_academic_writes_locked_and_the_page_says_so(): void
    {
        $this->schedule();
        AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->update(['is_open' => false]);
        $this->actingAs($this->adviser)->get(route('adviser.interventions.show', $this->iv))->assertOk()->assertSee('closed for academic recording');
        $item = Assessment::factory()->create(['subject_id' => $this->subject->id, 'section_id' => $this->section->id, 'grading_period' => 1, 'school_year' => '2026-2027', 'name' => 'Quiz 9', 'component' => 'written_work', 'max_score' => 20]);
        $this->actingAs($this->teacher)->postJson(route('teacher.class-record.save'), ['section_id' => $this->section->id, 'subject_id' => $this->subject->id, 'period' => 1,
            'cells' => [['assessment_id' => $item->id, 'student_id' => $this->learner->id, 'score' => '10', 'base_score' => null, 'base_missing' => false]]])->assertStatus(423);
        $this->assertFalse((bool) AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->value('is_open'), 'the term is never reopened by intervention actions');
    }
}
