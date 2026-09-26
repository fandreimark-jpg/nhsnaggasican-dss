<?php

namespace Tests\Feature;

use App\Models\AcademicConcern;
use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\Intervention;
use App\Models\RiskResult;
use App\Models\Section;
use App\Models\Specialization;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Track;
use App\Models\User;
use App\Services\AcademicMonitoringService;
use App\Services\AssessmentStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * "Rule-based academic monitoring" Phase 2D — the Principal Academic
 * Monitoring page. Read-only; source of truth is academic_concerns.
 *
 * The fixture reproduces the live Phase 2C validation state through the
 * real services (no hand-written concern rows): one Grade 12 section,
 * Quiz 1, five concerns —
 *   Fernandez  resolved (score 14 recorded)
 *   Galang     resolved (marker removed, no score) + open (marked again)
 *   Hilario    resolved (score 0 recorded)
 *   Ilagan     open
 */
class PrincipalAcademicMonitoringTest extends TestCase
{
    use RefreshDatabase;

    private User $principal;
    private User $adviser;
    private Section $agila;
    private Subject $cesc;
    private Assessment $quiz1;
    private array $learners = [];
    private array $concernIds = [];
    private AssessmentStatusService $statuses;
    private AcademicMonitoringService $monitoring;

    protected function setUp(): void
    {
        parent::setUp();

        AcademicYear::create(['school_year' => '2026-2027', 'is_active' => true]);
        AcademicTerm::ensureExistFor('2026-2027');
        AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->update(['is_open' => true]);

        $this->principal = User::factory()->principal()->create();
        $this->adviser = User::factory()->create(['role' => 'adviser']);

        $track = Track::factory()->create();
        $humss = Specialization::factory()->create([
            'track_id' => $track->id, 'name' => 'Humanities and Social Sciences', 'code' => 'HUMSS', 'curriculum' => 'k12_2013',
        ]);
        $this->agila = Section::factory()->create([
            'name' => 'Agila', 'grade_level' => 12, 'school_year' => '2026-2027',
            'track_id' => $track->id, 'specialization_id' => $humss->id, 'adviser_id' => $this->adviser->id,
        ]);
        $this->cesc = Subject::factory()->create([
            'name' => 'Community Engagement Solidarity and Citizenship', 'type' => 'core', 'grade_level' => 12, 'subject_group' => 'core_academic',
        ]);

        foreach (['FERNANDEZ' => 'KYLA', 'GALANG' => 'VINCENT', 'HILARIO' => 'JASMINE', 'ILAGAN' => 'RENZO', 'NAVARRO' => 'AIRA'] as $last => $first) {
            $this->learners[$last] = Student::factory()->create([
                'section_id' => $this->agila->id, 'last_name' => $last, 'first_name' => $first,
                'lrn' => '1200000000' . str_pad((string) (count($this->learners) + 1), 2, '0', STR_PAD_LEFT),
            ]);
        }

        $this->quiz1 = $this->item($this->agila, $this->cesc, 'Quiz 1');
        $this->statuses = new AssessmentStatusService();
        $this->monitoring = app(AcademicMonitoringService::class);

        // The live A-D sequence, through the real services.
        $this->statuses->recordScore($this->quiz1, $this->learners['NAVARRO']->id, 15);
        foreach (['FERNANDEZ', 'GALANG', 'HILARIO', 'ILAGAN'] as $last) {
            $this->statuses->markMissing($this->quiz1, $this->learners[$last]->id, $this->adviser->id);
        }
        $this->sync($this->quiz1);
        $this->statuses->recordScore($this->quiz1, $this->learners['FERNANDEZ']->id, 14);          // Test A
        $this->sync($this->quiz1);
        $this->statuses->clearMissing($this->quiz1->id, $this->learners['GALANG']->id);             // Test B
        $this->sync($this->quiz1);
        $this->statuses->markMissing($this->quiz1, $this->learners['GALANG']->id, $this->adviser->id); // Test C
        $this->sync($this->quiz1);
        $this->statuses->recordScore($this->quiz1, $this->learners['HILARIO']->id, 0);             // Test D
        $this->sync($this->quiz1);

        foreach (AcademicConcern::orderBy('id')->get() as $c) {
            $this->concernIds[$c->student->last_name . '|' . $c->status][] = $c->id;
        }
    }

    // --- helpers ---------------------------------------------------------

    private function item(Section $section, Subject $subject, string $name, int $term = 1): Assessment
    {
        return Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id, 'grading_period' => $term,
            'school_year' => $section->school_year, 'name' => $name, 'assessment_type' => $name,
            'component' => 'written_work', 'max_score' => 20, 'uploaded_by' => $this->adviser->id,
        ]);
    }

    private function sync(Assessment $item): void
    {
        $this->monitoring->synchronizeAssessments([$item->id]);
    }

    private function concernId(string $last, string $status, int $n = 0): int
    {
        return $this->concernIds[$last . '|' . $status][$n];
    }

    private function page(array $query = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->principal)->get(route('principal.academic-monitoring', $query));
    }

    /** The HTML of one concern card, from its data-concern-id to the next card. */
    private function concernBlock(string $html, int $id): string
    {
        $start = strpos($html, 'data-concern-id="' . $id . '"');
        $this->assertNotFalse($start, "concern #{$id} is on the page");
        $next = strpos($html, 'data-concern-id="', $start + 20);

        return substr($html, $start, $next === false ? null : $next - $start);
    }

    private function snapshot(): array
    {
        return [
            'concerns'      => DB::table('academic_concerns')->orderBy('id')->get()->map(fn($r) => (array) $r)->all(),
            'interventions' => DB::table('interventions')->count(),
            'risk_results'  => DB::table('risk_results')->count(),
            'markers'       => DB::table('assessment_learner_statuses')->orderBy('id')->get()->map(fn($r) => (array) $r)->all(),
            'scores'        => DB::table('assessment_scores')->orderBy('id')->get()->map(fn($r) => (array) $r)->all(),
        ];
    }

    // --- 1-4, 27. Authorization ---------------------------------------------

    public function test_1_the_principal_can_open_academic_monitoring(): void
    {
        $this->page()->assertOk()->assertSee('Academic Monitoring');
    }

    public function test_2_3_4_adviser_admin_and_guest_are_refused(): void
    {
        $this->page([], $this->adviser)->assertForbidden();
        $this->page([], User::factory()->admin()->create())->assertForbidden();

        auth()->logout();
        $this->get(route('principal.academic-monitoring'))->assertRedirect(route('login'));
    }

    public function test_the_principal_navigation_links_to_it_by_route(): void
    {
        $this->actingAs($this->principal)->get(route('principal.dashboard'))
            ->assertOk()
            ->assertSee('href="' . route('principal.academic-monitoring') . '"', false);
    }

    // --- 5-9. Status views ----------------------------------------------------

    public function test_5_6_7_the_default_view_shows_only_current_open_concerns(): void
    {
        $html = $this->page()->assertOk()->getContent();

        $this->assertStringContainsString('data-concern-id="' . $this->concernId('GALANG', 'open') . '"', $html);
        $this->assertStringContainsString('data-concern-id="' . $this->concernId('ILAGAN', 'open') . '"', $html);
        $this->assertStringContainsString('GALANG, VINCENT', $html);
        $this->assertStringContainsString('ILAGAN, RENZO', $html);

        foreach ([$this->concernId('FERNANDEZ', 'resolved'), $this->concernId('GALANG', 'resolved'), $this->concernId('HILARIO', 'resolved')] as $id) {
            $this->assertStringNotContainsString('data-concern-id="' . $id . '"', $html);
        }
        $this->assertStringNotContainsString('FERNANDEZ, KYLA', $html);
        $this->assertStringNotContainsString('HILARIO, JASMINE', $html);
        $this->assertSame(2, substr_count($html, 'data-concern-id='));
    }

    public function test_8_the_resolved_filter_shows_only_resolved_concerns_with_reasons(): void
    {
        $html = $this->page(['status' => 'resolved'])->assertOk()->getContent();

        $this->assertSame(3, substr_count($html, 'data-concern-id='));
        $this->assertStringContainsString('A score was recorded for &quot;Quiz 1&quot;.', $this->concernBlock($html, $this->concernId('FERNANDEZ', 'resolved')));
        $this->assertStringContainsString('The Missing marker on &quot;Quiz 1&quot; was removed; no score has been recorded yet.',
            $this->concernBlock($html, $this->concernId('GALANG', 'resolved')));
        $this->assertStringContainsString('A score was recorded for &quot;Quiz 1&quot;.', $this->concernBlock($html, $this->concernId('HILARIO', 'resolved')));
        $this->assertStringNotContainsString('data-concern-id="' . $this->concernId('ILAGAN', 'open') . '"', $html);
    }

    public function test_9_the_all_filter_shows_open_and_resolved(): void
    {
        $html = $this->page(['status' => 'all'])->assertOk()->getContent();

        $this->assertSame(5, substr_count($html, 'data-concern-id='));
    }

    public function test_an_unrecognised_status_falls_back_to_for_review(): void
    {
        $html = $this->page(['status' => 'dismissed'])->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'data-concern-id='));
    }

    // --- 10-14. Filters ---------------------------------------------------------

    public function test_10_11_grade_and_section_filters(): void
    {
        $curie = Section::factory()->create(['name' => 'Curie', 'grade_level' => 11, 'school_year' => '2026-2027', 'curriculum' => 'sshs']);
        $math = Subject::factory()->create(['name' => 'General Mathematics', 'type' => 'core', 'grade_level' => 11, 'subject_group' => 'core_academic']);
        $carl = Student::factory()->create(['section_id' => $curie->id, 'last_name' => 'CRUZ', 'first_name' => 'CARL', 'lrn' => '110000000099']);
        $item = $this->item($curie, $math, 'Quiz A');
        $this->statuses->markMissing($item, $carl->id, $this->adviser->id);
        $this->sync($item);

        $this->assertSame(3, substr_count($this->page()->getContent(), 'data-concern-id='));

        $grade12 = $this->page(['grade_level' => 12])->getContent();
        $this->assertSame(2, substr_count($grade12, 'data-concern-id='));
        $this->assertStringNotContainsString('CRUZ, CARL', $grade12);

        $curieOnly = $this->page(['section_id' => $curie->id])->getContent();
        $this->assertSame(1, substr_count($curieOnly, 'data-concern-id='));
        $this->assertStringContainsString('CRUZ, CARL', $curieOnly);

        // A section that does not match the chosen grade is ignored, never
        // used to reach outside the filter.
        $mismatch = $this->page(['grade_level' => 12, 'section_id' => $curie->id])->getContent();
        $this->assertSame(2, substr_count($mismatch, 'data-concern-id='));
    }

    public function test_12_the_subject_filter(): void
    {
        $other = Subject::factory()->create(['name' => 'Philippine Politics and Governance', 'type' => 'core', 'grade_level' => 12, 'subject_group' => 'core_academic']);
        $item = $this->item($this->agila, $other, 'Quiz B');
        $this->statuses->markMissing($item, $this->learners['NAVARRO']->id, $this->adviser->id);
        $this->sync($item);

        $html = $this->page(['subject_id' => $other->id])->getContent();
        $this->assertSame(1, substr_count($html, 'data-concern-id='));
        $this->assertStringContainsString('NAVARRO, AIRA', $html);

        // A subject id with no concern in the year is ignored.
        $this->assertSame(3, substr_count($this->page(['subject_id' => 999999])->getContent(), 'data-concern-id='));
    }

    public function test_13_the_term_filter_defaults_to_the_open_term(): void
    {
        $term2 = $this->item($this->agila, $this->cesc, 'Quiz T2', term: 2);
        $this->statuses->markMissing($term2, $this->learners['NAVARRO']->id, $this->adviser->id);
        $this->sync($term2);

        $this->assertSame(2, substr_count($this->page()->getContent(), 'data-concern-id='), 'default = open Term 1');
        $this->assertSame(1, substr_count($this->page(['term' => 2])->getContent(), 'data-concern-id='));
        $this->assertSame(3, substr_count($this->page(['term' => ''])->getContent(), 'data-concern-id='), 'All terms');
    }

    public function test_14_the_school_year_filter(): void
    {
        AcademicYear::create(['school_year' => '2025-2026', 'is_active' => false]);
        $old = Section::factory()->create(['name' => 'Molave', 'grade_level' => 11, 'school_year' => '2025-2026']);
        $oldSubject = Subject::factory()->create(['name' => 'Earth Science', 'type' => 'core', 'grade_level' => 11, 'subject_group' => 'core_academic']);
        $learner = Student::factory()->create(['section_id' => $old->id, 'last_name' => 'OLDYEAR', 'first_name' => 'OLIVE', 'lrn' => '110000000098']);
        $item = $this->item($old, $oldSubject, 'Old Quiz');
        $this->statuses->markMissing($item, $learner->id, $this->adviser->id);
        $this->sync($item);

        $this->assertStringNotContainsString('OLDYEAR', $this->page()->getContent());
        $old2025 = $this->page(['school_year' => '2025-2026'])->getContent();
        $this->assertSame(1, substr_count($old2025, 'data-concern-id='), 'a historical year shows all its terms');
        $this->assertStringContainsString('OLDYEAR, OLIVE', $old2025);
        $this->assertStringNotContainsString('GALANG, VINCENT', $old2025);

        // An unknown year falls back to the active one, never "all years".
        $this->assertSame(2, substr_count($this->page(['school_year' => '1999-2000'])->getContent(), 'data-concern-id='));
    }

    // --- 15. Section placement ---------------------------------------------------

    public function test_15_track_and_specialization_come_from_the_section_and_are_not_filters(): void
    {
        $html = $this->page(['section_id' => $this->agila->id])->getContent();

        $this->assertStringContainsString('data-section-placement', $html);
        $this->assertStringContainsString('Academic Track · Humanities and Social Sciences', $html);
        $this->assertStringNotContainsString('name="track_id"', $html);
        $this->assertStringNotContainsString('name="specialization_id"', $html);
    }

    // --- 16-19. Labels and evidence -----------------------------------------------

    public function test_16_18_the_concern_is_human_readable_and_no_raw_keys_are_shown(): void
    {
        $html = $this->page(['status' => 'all'])->getContent();

        $this->assertStringContainsString('Missing Requirement', $html);
        $this->assertStringContainsString('For Review', $html);
        $this->assertStringNotContainsString('explicit_missing_requirement', $html);
        $this->assertStringNotContainsString('assessment:' . $this->quiz1->id, $html);
        foreach (['High Risk', 'Moderate Risk', 'Low Risk', 'Prediction', 'Confidence'] as $word) {
            $this->assertStringNotContainsString($word, $html);
        }
    }

    public function test_17_the_evidence_for_an_open_concern(): void
    {
        $block = $this->concernBlock($this->page()->getContent(), $this->concernId('GALANG', 'open'));

        $this->assertStringContainsString('data-evidence', $block);
        $this->assertStringContainsString('View Evidence', $block);
        $this->assertStringContainsString('Missing Requirement: the adviser explicitly marked Quiz 1 as Missing.', $block);
        $this->assertStringContainsString('Quiz 1 — Written Work', $block);
        $this->assertStringContainsString('Community Engagement Solidarity and Citizenship', $block);
        $this->assertStringContainsString('Agila — Grade 12', $block);
        $this->assertStringContainsString('<dd>20</dd>', $block);
        $this->assertStringContainsString('2026-2027 · Term 1', $block);
        $this->assertMatchesRegularExpression('/Current Assessment Status<\/dt>\s*<dd data-current-item-status>\s*Missing/', $block);
        $this->assertStringContainsString('Not recorded — review actions are not enabled yet.', $block);
    }

    public function test_19_a_score_of_zero_is_completed_not_missing(): void
    {
        $block = $this->concernBlock($this->page(['status' => 'resolved'])->getContent(), $this->concernId('HILARIO', 'resolved'));

        $this->assertStringContainsString('A score was recorded for &quot;Quiz 1&quot;.', $block);
        $this->assertMatchesRegularExpression('/data-current-item-status>\s*Completed/', $block);
        $this->assertStringNotContainsString('For Review', $block);
    }

    public function test_20_the_historical_galang_concern_stays_resolved_and_names_the_newer_one(): void
    {
        $before = AcademicConcern::find($this->concernId('GALANG', 'resolved'))->toArray();
        $block = $this->concernBlock($this->page(['status' => 'all'])->getContent(), $this->concernId('GALANG', 'resolved'));

        $this->assertStringContainsString('Resolved', $block);
        $this->assertStringContainsString('a newer open concern is listed for this item', $block);
        $this->assertSame($before, AcademicConcern::find($this->concernId('GALANG', 'resolved'))->toArray());
    }

    public function test_21_several_concerns_for_one_learner_are_grouped_without_touching_rows(): void
    {
        $quiz2 = $this->item($this->agila, $this->cesc, 'Quiz 2');
        $this->statuses->markMissing($quiz2, $this->learners['ILAGAN']->id, $this->adviser->id);
        $this->sync($quiz2);
        $rows = DB::table('academic_concerns')->count();

        $html = $this->page()->getContent();

        $this->assertStringContainsString('For Review · 2 open concerns', $html);
        $this->assertSame(2, substr_count($html, 'data-concern-group'), 'Galang and Ilagan — one group each');
        $this->assertSame(3, substr_count($html, 'data-concern-id='));
        $this->assertSame($rows, DB::table('academic_concerns')->count());
    }

    // --- 22-24. Viewing is read-only -----------------------------------------------

    public function test_22_23_24_viewing_and_filtering_writes_nothing(): void
    {
        $before = $this->snapshot();

        foreach ([[], ['status' => 'resolved'], ['status' => 'all'], ['term' => ''], ['grade_level' => 12], ['section_id' => $this->agila->id], ['subject_id' => $this->cesc->id]] as $query) {
            $this->page($query)->assertOk();
        }
        $this->actingAs($this->principal)->get(route('principal.dashboard'))->assertOk();

        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, Intervention::count());
        $this->assertSame(0, RiskResult::count());
    }

    // --- 25-26. URLs ------------------------------------------------------------------

    public function test_25_urls_follow_a_sub_directory_base(): void
    {
        $html = $this->actingAs($this->principal)
            ->withServerVariables([
                'SCRIPT_NAME'     => '/naggasican-dss/public/index.php',
                'SCRIPT_FILENAME' => public_path('index.php'),
                'PHP_SELF'        => '/naggasican-dss/public/index.php',
            ])
            ->get('http://school.example/naggasican-dss/public/principal/academic-monitoring')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('action="http://school.example/naggasican-dss/public/principal/academic-monitoring"', $html);
        $this->assertSame(2, substr_count($html, 'data-concern-id='));
    }

    public function test_26_no_root_relative_principal_url_is_rendered(): void
    {
        $html = $this->page(['status' => 'all'])->getContent();

        $this->assertDoesNotMatchRegularExpression('#(href|action)="/principal#', $html);
    }

    // --- Resolved-card wording (Phase 2D live validation, Test C) ---------------------

    /** A concern card's visible summary text — tags stripped, whitespace collapsed. */
    private function cardText(string $html, int $id): string
    {
        $block = $this->concernBlock($html, $id);
        $summary = substr($block, 0, strpos($block, '<details') ?: strlen($block));

        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($summary))));
    }

    public function test_a_resolved_card_says_was_missing_and_now_completed_never_a_bare_missing(): void
    {
        $html = $this->page(['status' => 'all'])->getContent();

        foreach (['FERNANDEZ', 'HILARIO'] as $last) {
            $card = $this->cardText($html, $this->concernId($last, 'resolved'));
            $this->assertStringContainsString('Resolved', $card, $last);
            $this->assertStringContainsString('Quiz 1 — Written Work · was Missing · now Completed', $card, $last);
            $this->assertStringNotContainsString('Written Work · Missing', $card, "$last: no bare trailing Missing that reads as current");
            $this->assertStringNotContainsString('For Review', $card, $last);
        }
    }

    public function test_hilarios_recorded_zero_is_completed_on_her_resolved_card(): void
    {
        $this->assertDatabaseHas('assessment_scores', [
            'assessment_id' => $this->quiz1->id, 'student_id' => $this->learners['HILARIO']->id, 'score' => 0,
        ]);
        $this->assertSame(AssessmentStatusService::COMPLETED, $this->statuses->statusFor($this->quiz1, $this->learners['HILARIO']->id));

        $card = $this->cardText($this->page(['status' => 'resolved'])->getContent(), $this->concernId('HILARIO', 'resolved'));

        $this->assertStringContainsString('now Completed', $card);
        foreach (['now Missing', 'now Not Yet Given', 'No Evidence Yet'] as $wrong) {
            $this->assertStringNotContainsString($wrong, $card);
        }
    }

    public function test_galangs_historical_card_says_missing_again_and_stays_separate_from_the_open_one(): void
    {
        $html = $this->page(['status' => 'all'])->getContent();
        $historical = $this->concernId('GALANG', 'resolved');
        $current = $this->concernId('GALANG', 'open');

        $old = $this->cardText($html, $historical);
        $this->assertStringContainsString('Resolved', $old);
        $this->assertStringContainsString('was Missing · now Missing again', $old);
        $this->assertStringContainsString('a newer open concern is listed for this item', $this->concernBlock($html, $historical));

        $new = $this->cardText($html, $current);
        $this->assertStringContainsString('For Review', $new);
        $this->assertNotSame($historical, $current);
        $this->assertSame(AcademicConcern::STATUS_RESOLVED, AcademicConcern::find($historical)->status);
        $this->assertSame(AcademicConcern::STATUS_OPEN, AcademicConcern::find($current)->status);
    }

    public function test_open_cards_are_unchanged_and_still_for_review(): void
    {
        $html = $this->page()->getContent();

        foreach (['GALANG', 'ILAGAN'] as $last) {
            $card = $this->cardText($html, $this->concernId($last, 'open'));
            $this->assertStringContainsString('For Review', $card, $last);
            $this->assertStringContainsString('Missing Requirement · Quiz 1 — Written Work · Missing', $card, $last);
            $this->assertStringNotContainsString(' was ', $card, $last);
            $this->assertStringNotContainsString(' now ', $card, $last);
        }
    }

    // --- Query safety -------------------------------------------------------------------

    public function test_the_query_count_does_not_grow_with_the_number_of_concerns(): void
    {
        $count = function (): int {
            $n = 0;
            DB::listen(function () use (&$n) { $n++; });
            $this->page(['status' => 'all'])->assertOk();
            return $n;
        };
        $before = $count();

        foreach (range(1, 6) as $i) {
            $learner = Student::factory()->create(['section_id' => $this->agila->id, 'lrn' => '12000000009' . $i]);
            $this->statuses->markMissing($this->quiz1, $learner->id, $this->adviser->id);
        }
        $this->sync($this->quiz1);
        $this->assertSame(11, AcademicConcern::count());

        $this->assertSame($before, $count(), 'no per-concern queries (eager-loaded, batched)');
    }

    // --- Dashboard card ------------------------------------------------------------------

    public function test_the_dashboard_for_review_card_counts_open_concerns_and_links_to_the_page(): void
    {
        $html = $this->actingAs($this->principal)->get(route('principal.dashboard'))->assertOk()->getContent();

        $start = strpos($html, 'data-academic-monitoring-card');
        $this->assertNotFalse($start);
        $card = substr($html, $start, 1500);
        $this->assertStringContainsString('For Review', $card);
        $this->assertMatchesRegularExpression('/>\s*2\s*</', $card);
        $this->assertStringContainsString(e(route('principal.academic-monitoring', ['school_year' => '2026-2027', 'term' => ''])), $card);
    }
}
