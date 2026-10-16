<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Intervention;
use App\Models\InterventionEvent;
use App\Models\InterventionRequest;
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
 * Automatic adviser responsibility (2026-10-07): every new intervention gets
 * the learner's section adviser, resolved on the server; no valid adviser
 * means no record (never a fallback account); old records are reconciled only
 * by an explicit, audited write (dry run first).
 */
class InterventionResponsibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $principal;
    private User $adviser;
    private User $teacher;
    private Section $section;
    private Subject $subject;
    private Student $learner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::create(2026, 10, 10, 8, 0, 0, 'Asia/Manila'));
        AcademicYear::create(['school_year' => '2026-2027', 'is_active' => true]);
        AcademicTerm::ensureExistFor('2026-2027');
        AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->update(['is_open' => true]);
        $this->principal = User::factory()->create(['role' => 'principal']);
        $this->adviser = User::factory()->create(['role' => 'adviser', 'name' => 'Adviser, Ana']);
        $this->teacher = User::factory()->create(['role' => 'subject_teacher', 'name' => 'Teacher, Tom']);
        $this->section = Section::factory()->create(['name' => 'Curie', 'grade_level' => 11, 'school_year' => '2026-2027', 'adviser_id' => $this->adviser->id]);
        $this->subject = Subject::factory()->create(['name' => 'Effective Communication', 'type' => 'core', 'grade_level' => 11, 'subject_group' => 'core_academic']);
        $this->subject->syncTerms([1, 2, 3]);
        $this->learner = Student::factory()->create(['section_id' => $this->section->id, 'last_name' => 'ABAD']);
        TeachingAssignment::create(['user_id' => $this->teacher->id, 'section_id' => $this->section->id, 'subject_id' => $this->subject->id, 'school_year' => '2026-2027', 'grading_period' => 1]);
    }

    private function record(array $extra = [])
    {
        return $this->actingAs($this->principal)->post(route('principal.interventions.store'), $extra + [
            'student_id' => $this->learner->id, 'subject_id' => $this->subject->id, 'grading_period' => 1, 'recommended_type' => 'remediation',
        ]);
    }

    public function test_a_new_intervention_is_assigned_to_the_section_adviser_and_a_submitted_staff_id_is_ignored(): void
    {
        $this->record(['responsible_user_id' => $this->teacher->id])->assertSessionHas('success', fn($m) => str_contains($m, 'Adviser, Ana (section adviser) is responsible'));
        $iv = Intervention::sole();
        $this->assertSame([$this->adviser->id, $this->section->id, 'approved'], [$iv->responsible_user_id, $iv->section_id, $iv->status]);
        $e = InterventionEvent::sole();
        $this->assertSame(['reassigned', $this->principal->id, $this->adviser->id, 'created'], [$e->event, $e->actor_id, $e->new_values['responsible_user_id'], $e->dedupe_key]);
        $this->actingAs($this->principal)->get(route('principal.interventions.show', $iv))->assertOk()
            ->assertSee('Responsible adviser')->assertSee('Adviser, Ana')->assertDontSee('name="responsible_user_id"', false);
        // The adviser can manage it immediately; the class's subject teacher cannot even see it.
        $this->actingAs($this->adviser)->get(route('adviser.interventions.show', $iv))->assertOk()->assertSee('data-form="schedule"', false);
        $this->actingAs($this->teacher)->get(route('adviser.interventions.show', $iv))->assertForbidden();
    }

    public function test_no_disabled_non_adviser_or_ambiguous_adviser_refuses_recording_with_a_clear_reason(): void
    {
        $cases = [
            'has no adviser assigned' => fn() => $this->section->update(['adviser_id' => null]),
            'is not an active Adviser account' => fn() => $this->adviser->forceFill(['is_active' => false])->save(),
            'is not an active Adviser account ' => fn() => $this->section->forceFill(['adviser_id' => $this->teacher->id])->save(),
            'ambiguous' => fn() => Section::factory()->create(['name' => 'Twin', 'grade_level' => 11, 'school_year' => '2026-2027', 'adviser_id' => $this->adviser->id]),
        ];
        foreach ($cases as $message => $break) {
            $break();
            $this->record()->assertSessionHas('error', fn($m) => str_contains($m, trim($message)) && str_contains($m, 'Admin › Sections') && str_contains($m, 'not recorded'));
            $this->assertSame(0, Intervention::count(), $message);
            // repair for the next case
            $this->adviser->forceFill(['is_active' => true])->save();
            $this->section->forceFill(['adviser_id' => $this->adviser->id])->save();
            Section::where('name', 'Twin')->delete();
        }
        $this->record()->assertSessionHas('success');
    }

    public function test_the_context_must_match_subject_taught_and_enrollment_this_year(): void
    {
        $other = Subject::factory()->create(['name' => 'Grade 12 Only', 'type' => 'core', 'grade_level' => 12, 'subject_group' => 'core_academic']);
        $other->syncTerms([1, 2, 3]);
        $this->record(['subject_id' => $other->id])->assertSessionHas('error', fn($m) => str_contains($m, 'not taught to section Curie'));
        $termTwoOnly = Subject::factory()->create(['name' => 'Term 2 Only', 'type' => 'core', 'grade_level' => 11, 'subject_group' => 'core_academic']);
        $termTwoOnly->syncTerms([2]);
        $this->record(['subject_id' => $termTwoOnly->id])->assertSessionHas('error', fn($m) => str_contains($m, 'Term 1'));
        $lastYear = Section::factory()->create(['name' => 'Old', 'grade_level' => 11, 'school_year' => '2025-2026', 'adviser_id' => $this->adviser->id]);
        $unenrolled = Student::factory()->create(['section_id' => $lastYear->id]); // enrolled last year only
        $this->record(['student_id' => $unenrolled->id])->assertSessionHas('error', fn($m) => str_contains($m, 'not enrolled'));
        $this->assertSame(0, Intervention::count());
    }

    public function test_approving_a_recommendation_revalidates_the_adviser_inside_the_write(): void
    {
        $this->record(['recommendation_only' => 1])->assertSessionHas('success');
        $iv = Intervention::sole();
        $this->assertSame($this->adviser->id, $iv->responsible_user_id);
        $this->adviser->forceFill(['is_active' => false])->save();
        $this->actingAs($this->principal)->put(route('principal.interventions.update', $iv), ['status' => 'approved'])
            ->assertSessionHas('error', fn($m) => str_contains($m, 'not approved'));
        $this->assertSame('recommended', $iv->fresh()->status);
        $this->actingAs($this->principal)->post(route('principal.interventions.approve-pending'), ['ids' => [$iv->id], 'grading_period' => 1])
            ->assertSessionHas('error');
        $this->assertSame('recommended', $iv->fresh()->status);

        // A new adviser was named meanwhile: approval moves responsibility to them, audited.
        $newAdviser = User::factory()->create(['role' => 'adviser', 'name' => 'Adviser, Bea']);
        $this->section->forceFill(['adviser_id' => $newAdviser->id])->save();
        $this->actingAs($this->principal)->put(route('principal.interventions.update', $iv), ['status' => 'approved'])->assertSessionHas('success');
        $this->assertSame(['approved', $newAdviser->id], [$iv->fresh()->status, $iv->fresh()->responsible_user_id]);
        $this->assertSame(2, InterventionEvent::where('event', 'reassigned')->count());
    }

    public function test_bulk_recording_skips_a_section_without_a_valid_adviser_and_says_why(): void
    {
        $orphan = Section::factory()->create(['name' => 'Orphan', 'grade_level' => 11, 'school_year' => '2026-2027', 'adviser_id' => null]);
        $other = Student::factory()->create(['section_id' => $orphan->id]);
        $this->actingAs($this->principal)->post(route('principal.interventions.bulk-store'), ['subject_id' => $this->subject->id, 'grading_period' => 1,
            'included' => [$this->learner->id, $other->id], 'types' => [$this->learner->id => 'remediation', $other->id => 'remediation'],
            'statuses' => [$this->learner->id => 'Failing', $other->id => 'Failing']])
            ->assertSessionHas('success', fn($m) => str_contains($m, 'Recorded and approved 1'))
            ->assertSessionHas('error', fn($m) => str_contains($m, 'Section Orphan has no adviser'));
        $this->assertSame([$this->adviser->id], Intervention::pluck('responsible_user_id')->all());
    }

    public function test_reconciliation_is_a_dry_run_by_default_and_apply_moves_only_actionable_records_with_history(): void
    {
        $base = ['subject_id' => $this->subject->id, 'section_id' => $this->section->id, 'school_year' => '2026-2027', 'grading_period' => 1,
            'recommended_type' => 'remediation', 'status' => 'approved', 'origin' => 'principal', 'created_by' => $this->principal->id,
            'decided_by' => $this->principal->id, 'decided_at' => now()];
        // Former rule: the class's subject teacher was named responsible and scheduled it.
        $active = Intervention::create($base + ['student_id' => $this->learner->id, 'responsible_user_id' => $this->teacher->id,
            'starts_at' => '2026-10-10 10:00:00', 'deadline_at' => '2026-10-12 12:00:00', 'schedule_version' => 2]);
        InterventionRequest::create(['intervention_id' => $active->id, 'type' => 'extension', 'requested_by' => $this->teacher->id, 'reason' => 'Old request',
            'proposed_deadline_at' => '2026-10-13 12:00:00', 'based_on_version' => 2]);
        $done = Intervention::create($base + ['student_id' => Student::factory()->create(['section_id' => $this->section->id])->id, 'responsible_user_id' => $this->teacher->id,
            'starts_at' => '2026-10-09 10:00:00', 'deadline_at' => '2026-10-09 12:00:00', 'completed_at' => '2026-10-09 11:00:00', 'delivered_at' => '2026-10-09 11:05:00', 'delivered_by' => $this->teacher->id]);
        $unassigned = Intervention::create($base + ['student_id' => Student::factory()->create(['section_id' => $this->section->id])->id]);
        $orphanSection = Section::factory()->create(['name' => 'Orphan', 'grade_level' => 11, 'school_year' => '2026-2027', 'adviser_id' => null]);
        $blocked = Intervention::create(['section_id' => $orphanSection->id, 'student_id' => Student::factory()->create(['section_id' => $orphanSection->id])->id] + $base);

        $before = Intervention::orderBy('id')->get()->map->getAttributes()->all();
        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('dss:reconcile-intervention-staff'));
        $out = \Illuminate\Support\Facades\Artisan::output();
        foreach (['DRY RUN — nothing is written: 3 actionable intervention(s)', 'Teacher, Tom (subject_teacher)', '| Adviser, Ana', 'transfer valid',
                  '1 (extension)', 'BLOCKED: Section Orphan has no adviser'] as $expected) {
            $this->assertStringContainsString($expected, $out);
        }
        $this->assertStringNotContainsString('| ' . $done->id . ' ', $out, 'completed records are not in scope');
        $this->assertSame($before, Intervention::orderBy('id')->get()->map->getAttributes()->all(), 'dry run writes nothing');
        $this->assertSame(0, InterventionEvent::count());

        $this->artisan('dss:reconcile-intervention-staff --apply')->expectsOutputToContain('Transferred 2 intervention(s).')->assertExitCode(0);
        $a = $active->fresh();
        $this->assertSame([$this->adviser->id, '2026-10-12 12:00:00', 3], [$a->responsible_user_id, $a->getRawOriginal('deadline_at'), $a->schedule_version], 'schedule kept, version moved');
        $e = InterventionEvent::where('intervention_id', $active->id)->sole();
        $this->assertSame([$this->teacher->id, $this->adviser->id, null], [$e->old_values['responsible_user_id'], $e->new_values['responsible_user_id'], $e->actor_id]);
        $this->assertSame($this->adviser->id, $unassigned->fresh()->responsible_user_id);
        $this->assertSame([$this->teacher->id, $this->teacher->id], [$done->fresh()->responsible_user_id, $done->fresh()->delivered_by], 'completed: historical attribution kept');
        $this->assertNull($blocked->fresh()->responsible_user_id, 'blocked: nothing guessed');
        $this->assertSame('pending', InterventionRequest::sole()->status, 'a transfer alone never approves or drops a legacy request');

        // Rerun: idempotent.
        $this->artisan('dss:reconcile-intervention-staff --apply')->expectsOutputToContain('Transferred 0 intervention(s).')->assertExitCode(0);
        $this->assertSame(2, InterventionEvent::count());

        // The legacy request never blocks the adviser; their direct change supersedes it.
        $this->actingAs($this->adviser)->post(route('adviser.interventions.extend', $active), ['deadline_at' => '2026-10-14T12:00', 'reason' => 'Needs another day', 'schedule_version' => 3])
            ->assertSessionHas('success');
        $this->assertSame('superseded', InterventionRequest::sole()->status);
        // And the former subject teacher has no way back in.
        $this->actingAs($this->teacher)->post(route('adviser.interventions.extend', $active), ['deadline_at' => '2026-10-15T12:00', 'reason' => 'x x x x x', 'schedule_version' => 4])->assertForbidden();
    }

    public function test_intervention_actions_never_touch_scores_grades_or_concerns(): void
    {
        $this->record()->assertSessionHas('success');
        $iv = Intervention::sole();
        $tables = fn() => [\App\Models\AssessmentScore::count(), \App\Models\Grade::count(), \App\Models\AcademicConcern::count(), \App\Models\AssessmentLearnerStatus::count()];
        $before = $tables();
        $svc = new InterventionScheduleService();
        $svc->schedule($iv, ['title' => 'T', 'starts_at' => now()->addHour(), 'deadline_at' => now()->addDay()], $this->adviser, $iv->fresh()->schedule_version);
        $svc->extend($iv, $this->adviser, now()->addDays(2), 'More time needed', $iv->fresh()->schedule_version);
        $this->assertSame($before, $tables());
    }
}
