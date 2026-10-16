<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\ActivityLog;
use App\Models\Grade;
use App\Models\ReportSubmission;
use App\Models\ReportSubmissionVersion;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\ReportSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Report submission boundary (2026-10-07): the adviser's page is a private
 * draft preview; recipients (Principal, Admin) see a term only after an
 * explicit submission, and then exactly the frozen version submitted.
 */
class ReportSubmissionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $adviser;
    private User $principal;
    private User $admin;
    private Section $section;
    private Subject $math;
    private Subject $english;
    private Student $ana;
    private Student $ben;

    protected function setUp(): void
    {
        parent::setUp();
        AcademicYear::create(['school_year' => '2026-2027', 'is_active' => true]);
        AcademicTerm::ensureExistFor('2026-2027');
        AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->update(['is_open' => true]);
        $this->adviser = User::factory()->create(['role' => 'adviser', 'name' => 'Adviser, Ana']);
        $this->principal = User::factory()->create(['role' => 'principal']);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->section = Section::factory()->create(['name' => 'Curie', 'grade_level' => 11, 'school_year' => '2026-2027', 'adviser_id' => $this->adviser->id, 'curriculum' => 'k12_2013']);
        $this->math = Subject::factory()->create(['name' => 'General Mathematics', 'type' => 'core', 'grade_level' => 11]);
        $this->english = Subject::factory()->create(['name' => 'Oral Communication', 'type' => 'core', 'grade_level' => 11]);
        $this->ana = Student::factory()->create(['section_id' => $this->section->id, 'last_name' => 'ABAD', 'first_name' => 'ANA']);
        $this->ben = Student::factory()->create(['section_id' => $this->section->id, 'last_name' => 'BAUTISTA', 'first_name' => 'BEN']);
        foreach ([[$this->ana, $this->math, 81.11], [$this->ana, $this->english, 88], [$this->ben, $this->math, 72], [$this->ben, $this->english, 90]] as [$s, $sub, $g]) {
            Grade::create(['student_id' => $s->id, 'subject_id' => $sub->id, 'section_id' => $this->section->id, 'grading_period' => 1, 'school_year' => '2026-2027',
                'grade' => $g, 'is_verified' => true, 'is_provisional' => false, 'encoded_by' => $this->adviser->id]);
        }
    }

    private function submit(int $expected = 0, array $extra = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->adviser)->post(route('adviser.submit.report.post'), $extra + ['grading_period' => 1, 'expected_version' => $expected]);
    }

    private function reports(User $as, array $q = []): string
    {
        return $this->actingAs($as)->get(route($as->role . '.reports', $q))->assertOk()->getContent();
    }

    public function test_the_draft_is_private_until_submitted_and_viewing_writes_nothing(): void
    {
        $this->actingAs($this->adviser)->get(route('adviser.submit.report'))->assertOk()
            ->assertSee('Draft preview — only you see this page')->assertSee('84.56');
        $this->assertSame(0, ReportSubmission::count(), 'opening the page never submits');
        foreach ([$this->principal, $this->admin] as $recipient) {
            $html = $this->reports($recipient);
            $this->assertStringContainsString('data-not-submitted', $html);
            foreach (['81.11', '72.00', '88.00', 'General Mathematics — 72.00'] as $value) {
                $this->assertStringNotContainsString($value, $html, "{$recipient->role} must not see draft figures");
            }
            $this->assertStringNotContainsString('Submitted · v', $html);
        }
        $this->assertFalse(collect((new \App\Services\DashboardAnalyticsService())->getOpenTermPanel()['sections'])->contains('submitted', true), 'admin dashboard counts no submission');
    }

    public function test_explicit_submission_shows_recipients_the_frozen_version_with_server_side_attribution(): void
    {
        $other = User::factory()->create(['role' => 'adviser']);
        $this->submit(0, ['submitted_by' => $other->id, 'status' => 'approved', 'submitted_at' => '2020-01-01 00:00:00'])
            ->assertRedirect(route('adviser.submit.report'))->assertSessionHas('success', fn($m) => str_contains($m, 'version 1'));
        $row = ReportSubmission::sole();
        $v = ReportSubmissionVersion::sole();
        $this->assertSame(['submitted', $this->adviser->id, $this->adviser->id, 1], [$row->status, $row->submitted_by, $v->submitted_by, $v->version]);
        $this->assertTrue($v->submitted_at->isToday(), 'server time, not the posted one');
        $this->assertSame(1, ActivityLog::where('action', 'submit_report')->count());

        foreach ([$this->principal, $this->admin] as $recipient) {
            $html = $this->reports($recipient, ['grading_period' => 1]);
            $this->assertStringContainsString('Submitted · v1', $html);
            $this->assertStringContainsString('84.56', $html, 'ana: (81.11 + 88) / 2');
            $this->assertStringContainsString('General Mathematics — 72.00 (Term 1)', $html);
        }
    }

    public function test_a_double_click_or_retry_creates_one_version_and_one_log(): void
    {
        $this->submit(0)->assertSessionHas('success');
        $this->submit(0)->assertSessionHas('error', fn($m) => str_contains($m, 'nothing was submitted'));
        $this->assertSame([1, 1, 1], [ReportSubmission::count(), ReportSubmissionVersion::count(), ActivityLog::whereIn('action', ['submit_report', 'resubmit_report'])->count()]);
        $this->submit(5)->assertSessionHas('error'); // a tampered/stale version number never writes
        $this->assertSame(1, ReportSubmissionVersion::count());
    }

    public function test_later_grade_changes_do_not_alter_the_submission_and_resubmission_keeps_the_previous_version(): void
    {
        $this->submit(0)->assertSessionHas('success');
        Grade::where('student_id', $this->ben->id)->where('subject_id', $this->math->id)->update(['grade' => 79]);

        // The adviser's draft shows the correction; recipients still see the submitted 72.
        $this->actingAs($this->adviser)->get(route('adviser.submit.report'))->assertOk()->assertSee('84.50')->assertSee('(what recipients see)', false);
        $html = $this->reports($this->principal, ['grading_period' => 1]);
        $this->assertStringContainsString('General Mathematics — 72.00 (Term 1)', $html);
        $this->assertStringNotContainsString('79.00', $html);

        $this->submit(1, ['resubmit' => 1])->assertSessionHas('success', fn($m) => str_contains($m, 're-submitted (version 2)'));
        $html = $this->reports($this->principal, ['grading_period' => 1]);
        $this->assertStringContainsString('Submitted · v2', $html);
        $this->assertStringNotContainsString('General Mathematics — 72.00', $html);
        $versions = ReportSubmissionVersion::orderBy('version')->get();
        $this->assertSame([1, 2], $versions->pluck('version')->all());
        $mathOf = fn($v) => collect($v->snapshot['students'][$this->ben->id]['grades'])->firstWhere('subject_id', $this->math->id)['grade'];
        $this->assertEquals([72, 79], [$mathOf($versions[0]), $mathOf($versions[1])], 'version 1 kept exactly as submitted');
        $this->assertSame(1, ActivityLog::where('action', 'resubmit_report')->count());

        // A tab still showing version 1 cannot overwrite version 2.
        $this->submit(1, ['resubmit' => 1])->assertSessionHas('error');
        $this->assertSame(2, ReportSubmissionVersion::count());
    }

    public function test_the_original_submitter_stays_after_the_adviser_changes_and_the_former_adviser_cannot_submit(): void
    {
        $this->submit(0)->assertSessionHas('success');
        $new = User::factory()->create(['role' => 'adviser', 'name' => 'Adviser, Bea']);
        $this->section->update(['adviser_id' => $new->id]);
        $html = $this->reports($this->principal, ['grading_period' => 1]);
        $this->assertStringContainsString('Adviser, Ana', substr($html, strpos($html, 'data-term-status="1"'), 600), 'historical author kept');

        $this->submit(1, ['resubmit' => 1])->assertNotFound(); // no longer advises a section
        $this->assertSame(1, ReportSubmissionVersion::count());
        $this->adviser->forceFill(['is_active' => false])->save();
        $this->submit(1)->assertRedirect(route('login'));
        foreach ([User::factory()->create(['role' => 'subject_teacher']), $this->principal, $this->admin] as $who) {
            $this->actingAs($who)->post(route('adviser.submit.report.post'), ['grading_period' => 1, 'expected_version' => 1])->assertForbidden();
        }
        $this->assertSame(1, ReportSubmissionVersion::count());
    }

    public function test_an_ineligible_submission_or_a_failed_snapshot_leaves_nothing_visible(): void
    {
        // Bound before the first request: the route keeps its controller instance afterwards.
        $this->app->bind(ReportSnapshotService::class, fn() => new class extends ReportSnapshotService {
            public function build(Section $section, int $term): array { throw new \RuntimeException('disk full'); }
        });
        Grade::where('student_id', $this->ben->id)->where('subject_id', $this->english->id)->delete();
        $this->submit(0)->assertSessionHas('error', fn($m) => str_contains($m, '1 grade(s) remaining'));
        $this->assertSame(0, ReportSubmission::count());

        Grade::create(['student_id' => $this->ben->id, 'subject_id' => $this->english->id, 'section_id' => $this->section->id, 'grading_period' => 1,
            'school_year' => '2026-2027', 'grade' => 90, 'is_verified' => false, 'is_provisional' => false, 'encoded_by' => $this->adviser->id]);
        $this->withoutExceptionHandling();
        try {
            $this->submit(0);
            $this->fail('the failure must surface, not read as success');
        } catch (\RuntimeException $e) {
            $this->assertSame('disk full', $e->getMessage(), get_class($e) . ': ' . $e->getMessage());
        }
        $this->assertSame([0, 0, 0], [ReportSubmission::count(), ReportSubmissionVersion::count(), ActivityLog::where('action', 'submit_report')->count()]);
        $this->assertStringContainsString('data-not-submitted', $this->reports($this->principal));
    }

    public function test_a_report_may_describe_incomplete_evidence_when_existing_rules_allow(): void
    {
        // An encoded but unverified grade satisfies the existing "every expected grade encoded" rule;
        // the submitted version records it honestly as not yet official.
        Grade::where('student_id', $this->ben->id)->where('subject_id', $this->math->id)->update(['is_verified' => false]);
        $this->submit(0)->assertSessionHas('success');
        $this->assertStringContainsString('1 not yet official', $this->reports($this->admin, ['grading_period' => 1]));
    }

    public function test_a_legacy_submission_without_preserved_contents_shows_no_figures(): void
    {
        ReportSubmission::create(['section_id' => $this->section->id, 'submitted_by' => $this->adviser->id, 'grading_period' => 1,
            'school_year' => '2026-2027', 'status' => 'submitted', 'submitted_at' => now()]);
        $html = $this->reports($this->principal, ['grading_period' => 1]);
        $this->assertStringContainsString('contents not preserved', $html);
        $this->assertStringNotContainsString('81.11', $html);
        // A row in any state other than an explicit submission is never shown as submitted.
        ReportSubmission::query()->update(['status' => 'pending', 'submitted_at' => null]);
        $status = strip_tags(substr($h = $this->reports($this->admin, ['grading_period' => 1]), strpos($h, 'data-term-status="1"'), 400));
        $this->assertStringContainsString('Not Submitted', $status);
        $this->assertStringNotContainsString('contents not preserved', $h);
    }

    public function test_live_academic_monitoring_is_independent_of_report_submission(): void
    {
        $this->actingAs($this->principal)->get(route('principal.students.show', ['student' => $this->ben->id, 'period' => 1]))
            ->assertOk()->assertSee('72.00');
        $this->actingAs($this->principal)->get(route('principal.academic-monitoring'))->assertOk();
        $this->assertSame(0, ReportSubmission::count());
    }
}
