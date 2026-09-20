<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Section;
use App\Models\Specialization;
use App\Models\Student;
use App\Models\Subject;
use App\Models\SubjectGroupWeight;
use App\Models\Track;
use App\Models\User;
use App\Services\GradingEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Subject form consistency" pass + "Subject Group for both grade levels"
 * pass (2026-09-20) — Admin > Subjects > Add/Edit Subject.
 *
 * The form's Subject Group and Specialization labels are the bare words
 * (no DO 015 explanatory phrase, no "(optional)", no "Not applicable to
 * Grade 12"), and Subject Group is ONE control that is required, active
 * and persisted for Grade 11 and Grade 12 alike — grade level never
 * hides, disables or clears it. Every combination the form offers
 * round-trips through store/update and back into the Edit modal's data.
 *
 * What is deliberately unchanged, and pinned here: Grade 12 GRADING.
 * DO 8, s. 2015 weighs by the SECTION's track (GradingEngine::
 * resolveDo8GroupKey()) and never reads subject_group, so a Grade 12
 * subject computes the identical grade with or without a group.
 * Specialization stays nullable: "— All specializations in track —" is a
 * legitimate choice (the elective applies to the whole track).
 */
class SubjectFormConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Track $track;
    private Specialization $spec;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        $this->track = Track::factory()->create(['code' => 'ACAD', 'name' => 'Academic Track']);
        $this->spec  = Specialization::factory()->create(['track_id' => $this->track->id, 'code' => 'HUMSS', 'name' => 'Humanities and Social Sciences']);
    }

    private function form(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Form Subject', 'type' => 'core', 'grade_level' => 11,
            'subject_group' => 'core_academic', 'terms' => [1, 2, 3],
        ], $overrides);
    }

    // ------------------------------------------------------------------
    // A + C — the labels
    // ------------------------------------------------------------------

    public function test_the_subject_group_label_is_the_bare_words_with_no_explanatory_phrase(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.subjects'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '~<label class="form-label" for="subjectGroupField">\s*Subject Group\s*</label>~',
            $html,
            'The Subject Group label must read exactly "Subject Group".'
        );
        $this->assertStringNotContainsString('DO 015, s. 2026 grading weight group', $html);
        $this->assertStringNotContainsString('Grade 12 weighs by section track instead', $html);
    }

    public function test_the_specialization_label_is_the_bare_word_with_no_optional_suffix(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.subjects'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '~<label class="form-label" for="subjectSpec">\s*Specialization\s*</label>~',
            $html,
            'The Specialization label must read exactly "Specialization".'
        );
        $this->assertStringNotContainsString('(optional)', $html);
        // The whole-track choice is still offered — removing "(optional)" removed a word, not the option.
        $this->assertStringContainsString('— All specializations in track —', $html);
    }

    // ------------------------------------------------------------------
    // B + F — one Subject Group control, required and active at both grade levels
    // ------------------------------------------------------------------

    public function test_the_form_renders_one_required_subject_group_control_with_no_grade_12_restriction(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.subjects'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'id="subjectGroupField"'), 'Exactly one Subject Group control — never duplicated per grade level.');
        $this->assertMatchesRegularExpression('~<select name="subject_group" id="subjectGroupField" required~', $html, 'Required in the markup for every grade level.');
        $this->assertStringNotContainsString('Not applicable to Grade 12', $html);
        $this->assertStringNotContainsString('subjectGroupNotApplicable', $html);
        $this->assertStringNotContainsString('Grade 11 only', $html);
        $this->assertStringNotContainsString('weighs by section track instead', $html);
        // Grade Level has no hook into the Subject Group control at all.
        $this->assertDoesNotMatchRegularExpression('~id="subjectGrade"[^>]*onchange~', $html);

        // Every group is offered from the one authoritative source, tagged with its type.
        foreach (SubjectGroupWeight::allGroups() as $group) {
            $this->assertStringContainsString('value="' . $group . '" data-for-type="', $html);
        }
        $this->assertStringNotContainsString('value="do8_', $html, 'No do8_* row may be human-selectable.');
    }

    public function test_the_modal_script_has_no_grade_level_rule_for_subject_group(): void
    {
        $js = file_get_contents(base_path('resources/js/modal.js'));
        $start = strpos($js, 'window.refreshSubjectGroupField = function');
        $end   = strpos($js, 'window.setSubjectTerms = function');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $fn = substr($js, $start, $end - $start);

        $this->assertStringNotContainsString('subjectGrade', $fn, 'Grade level must not be read by the Subject Group logic.');
        $this->assertStringNotContainsString('"12"', $fn);
        $this->assertStringNotContainsString('classList.add("hidden")', $fn, 'Nothing hides the control.');
        $this->assertStringNotContainsString('disabled = true', $fn, 'Nothing disables the control.');
        $this->assertStringNotContainsString('stashedGroup', $fn);
        $this->assertStringContainsString('opt.dataset.forType === type', $fn, 'The only dynamic rule is the Type filter.');

        // Edit restores the saved group unconditionally.
        $this->assertStringContainsString('document.getElementById("subjectGroupField").value = subject.subject_group || "";', $js);
    }

    // ------------------------------------------------------------------
    // Add / Edit round-trips — every combination the form offers
    // ------------------------------------------------------------------

    public function test_grade_11_core_persists_its_subject_group_and_the_edit_button_carries_it(): void
    {
        $this->actingAs($this->admin)->post(route('admin.subjects.store'), $this->form(['name' => 'G11 Core']))
            ->assertRedirect(route('admin.subjects'))->assertSessionHasNoErrors();

        $subject = Subject::where('name', 'G11 Core')->firstOrFail();
        $this->assertSame('core_academic', $subject->subject_group);
        $this->assertNull($subject->track_id);
        $this->assertNull($subject->specialization_id);
        $this->assertSame([1, 2, 3], $subject->termNumbers());

        $this->assertEditButtonCarries($subject, ['subject_group' => 'core_academic', 'track_id' => null, 'specialization_id' => null], [1, 2, 3]);
    }

    public function test_grade_11_elective_with_a_track_only_persists_a_null_specialization(): void
    {
        $this->actingAs($this->admin)->post(route('admin.subjects.store'), $this->form([
            'name' => 'G11 Elective Whole Track', 'type' => 'elective', 'subject_group' => 'academic_other',
            'track_id' => $this->track->id, 'specialization_id' => '', 'terms' => [2, 3],
        ]))->assertRedirect(route('admin.subjects'))->assertSessionHasNoErrors();

        $subject = Subject::where('name', 'G11 Elective Whole Track')->firstOrFail();
        $this->assertSame('academic_other', $subject->subject_group);
        $this->assertSame($this->track->id, $subject->track_id);
        $this->assertNull($subject->specialization_id, 'Specialization stays nullable — "(optional)" left the label, not the rule.');
        $this->assertSame([2, 3], $subject->termNumbers());

        $this->assertEditButtonCarries($subject, ['subject_group' => 'academic_other', 'track_id' => $this->track->id, 'specialization_id' => null], [2, 3]);
    }

    public function test_grade_11_elective_with_a_specialization_persists_it(): void
    {
        $this->actingAs($this->admin)->post(route('admin.subjects.store'), $this->form([
            'name' => 'G11 Elective Strand', 'type' => 'elective', 'subject_group' => 'arts_sports_wellness',
            'track_id' => $this->track->id, 'specialization_id' => $this->spec->id,
        ]))->assertRedirect(route('admin.subjects'))->assertSessionHasNoErrors();

        $subject = Subject::where('name', 'G11 Elective Strand')->firstOrFail();
        $this->assertSame($this->spec->id, $subject->specialization_id);
        $this->assertEditButtonCarries($subject, ['subject_group' => 'arts_sports_wellness', 'track_id' => $this->track->id, 'specialization_id' => $this->spec->id], [1, 2, 3]);
    }

    public function test_grade_12_core_persists_its_subject_group_and_restores_it_on_edit(): void
    {
        $this->actingAs($this->admin)->post(route('admin.subjects.store'), $this->form(['name' => 'G12 Core', 'grade_level' => 12]))
            ->assertRedirect(route('admin.subjects'))->assertSessionHasNoErrors();

        $subject = Subject::where('name', 'G12 Core')->firstOrFail();
        $this->assertSame(12, (int) $subject->grade_level);
        $this->assertSame('core_academic', $subject->subject_group);
        $this->assertNull($subject->track_id);
        $this->assertNull($subject->specialization_id);

        // Reload: the list shows the group; the Edit button carries it.
        $this->actingAs($this->admin)->get(route('admin.subjects'))->assertOk()
            ->assertSee('data-subject-group="core_academic"', false);
        $this->assertEditButtonCarries($subject, ['subject_group' => 'core_academic', 'grade_level' => 12, 'track_id' => null, 'specialization_id' => null], [1, 2, 3]);

        // Edit: re-save unchanged — the group is not lost because grade_level = 12.
        $this->actingAs($this->admin)->put(route('admin.subjects.update', $subject->id), $this->form(['name' => 'G12 Core', 'grade_level' => 12]))
            ->assertRedirect(route('admin.subjects'))->assertSessionHasNoErrors();
        $this->assertSame('core_academic', $subject->fresh()->subject_group);
    }

    public function test_grade_12_without_a_subject_group_is_rejected_exactly_like_grade_11(): void
    {
        foreach ([11, 12] as $grade) {
            foreach ([[], ['subject_group' => '']] as $groupField) {
                $this->actingAs($this->admin)->from(route('admin.subjects'))->post(route('admin.subjects.store'), array_merge(
                    $this->form(['name' => "Unclassified G{$grade}", 'grade_level' => $grade, 'subject_group' => null]), $groupField
                ))->assertRedirect(route('admin.subjects'))->assertSessionHasErrors('subject_group');
                $this->assertDatabaseMissing('subjects', ['name' => "Unclassified G{$grade}"]);
            }
        }
    }

    public function test_grade_12_elective_persists_track_and_specialization_and_restores_them_on_edit(): void
    {
        $this->actingAs($this->admin)->post(route('admin.subjects.store'), $this->form([
            'name' => 'G12 Elective', 'type' => 'elective', 'grade_level' => 12, 'subject_group' => 'academic_other',
            'track_id' => $this->track->id, 'specialization_id' => $this->spec->id, 'terms' => [1, 2],
        ]))->assertRedirect(route('admin.subjects'))->assertSessionHasNoErrors();

        $subject = Subject::where('name', 'G12 Elective')->firstOrFail();
        $this->assertSame('academic_other', $subject->subject_group);
        $this->assertSame($this->track->id, $subject->track_id);
        $this->assertSame($this->spec->id, $subject->specialization_id);
        $this->assertSame([1, 2], $subject->termNumbers());
        $this->assertEditButtonCarries($subject, ['subject_group' => 'academic_other', 'grade_level' => 12, 'track_id' => $this->track->id, 'specialization_id' => $this->spec->id], [1, 2]);

        // Edit: change the group, drop the strand to "all specializations
        // in track", widen terms — group, track and terms all move as asked.
        $this->actingAs($this->admin)->put(route('admin.subjects.update', $subject->id), $this->form([
            'name' => 'G12 Elective', 'type' => 'elective', 'grade_level' => 12, 'subject_group' => 'research_innovation',
            'track_id' => $this->track->id, 'specialization_id' => '', 'terms' => [1, 2, 3],
        ]))->assertRedirect(route('admin.subjects'))->assertSessionHasNoErrors();

        $subject->refresh();
        $this->assertSame('research_innovation', $subject->subject_group);
        $this->assertNull($subject->specialization_id);
        $this->assertSame($this->track->id, $subject->track_id);
        $this->assertSame([1, 2, 3], $subject->termNumbers());

        // Type consistency is enforced for Grade 12 exactly as for Grade 11.
        $this->actingAs($this->admin)->put(route('admin.subjects.update', $subject->id), $this->form([
            'name' => 'G12 Elective', 'type' => 'elective', 'grade_level' => 12, 'subject_group' => 'core_academic',
            'track_id' => $this->track->id, 'terms' => [1, 2, 3],
        ]))->assertSessionHasErrors('subject_group');
        $this->assertSame('research_innovation', $subject->fresh()->subject_group);
    }

    public function test_editing_a_grade_11_subject_keeps_its_subject_group_and_can_change_it(): void
    {
        $subject = Subject::factory()->create(['name' => 'G11 Editable', 'type' => 'elective', 'grade_level' => 11, 'subject_group' => 'academic_other', 'track_id' => $this->track->id, 'specialization_id' => null]);
        $subject->syncTerms([1]);

        // Re-save unchanged: nothing moves.
        $this->actingAs($this->admin)->put(route('admin.subjects.update', $subject->id), $this->form([
            'name' => 'G11 Editable', 'type' => 'elective', 'subject_group' => 'academic_other', 'track_id' => $this->track->id, 'terms' => [1],
        ]))->assertRedirect(route('admin.subjects'))->assertSessionHasNoErrors();
        $this->assertSame('academic_other', $subject->fresh()->subject_group);

        // Change the group to another elective group.
        $this->actingAs($this->admin)->put(route('admin.subjects.update', $subject->id), $this->form([
            'name' => 'G11 Editable', 'type' => 'elective', 'subject_group' => 'research_innovation', 'track_id' => $this->track->id, 'terms' => [1],
        ]))->assertRedirect(route('admin.subjects'))->assertSessionHasNoErrors();
        $this->assertSame('research_innovation', $subject->fresh()->subject_group);
    }

    // ------------------------------------------------------------------
    // Switching — the server-side counterpart of TEST E / TEST F
    // ------------------------------------------------------------------

    public function test_moving_a_subject_between_grade_levels_keeps_its_subject_group(): void
    {
        $subject = Subject::factory()->create(['name' => 'Mover', 'type' => 'core', 'grade_level' => 11, 'subject_group' => 'core_academic']);
        $subject->syncTerms([1, 2, 3]);

        // 11 -> 12: the group travels with the subject.
        $this->actingAs($this->admin)
            ->put(route('admin.subjects.update', $subject->id), $this->form(['name' => 'Mover', 'grade_level' => 12, 'subject_group' => 'core_academic']))
            ->assertRedirect(route('admin.subjects'))->assertSessionHasNoErrors();
        $fresh = $subject->fresh();
        $this->assertSame(12, (int) $fresh->grade_level);
        $this->assertSame('core_academic', $fresh->subject_group);

        // 12 -> 11: likewise.
        $this->actingAs($this->admin)
            ->put(route('admin.subjects.update', $subject->id), $this->form(['name' => 'Mover', 'grade_level' => 11, 'subject_group' => 'core_academic']))
            ->assertRedirect(route('admin.subjects'))->assertSessionHasNoErrors();
        $fresh = $subject->fresh();
        $this->assertSame(11, (int) $fresh->grade_level);
        $this->assertSame('core_academic', $fresh->subject_group);

        // A blank group is refused at either grade level.
        foreach ([11, 12] as $grade) {
            $this->actingAs($this->admin)
                ->put(route('admin.subjects.update', $subject->id), $this->form(['name' => 'Mover', 'grade_level' => $grade, 'subject_group' => '']))
                ->assertSessionHasErrors('subject_group');
        }
        $this->assertSame('core_academic', $subject->fresh()->subject_group);
    }

    public function test_switching_an_elective_to_core_discards_track_and_specialization_server_side(): void
    {
        $subject = Subject::factory()->create(['name' => 'Switcher', 'type' => 'elective', 'grade_level' => 11, 'subject_group' => 'academic_other', 'track_id' => $this->track->id, 'specialization_id' => $this->spec->id]);
        $subject->syncTerms([1, 2, 3]);

        // Stale hidden track/spec values sent with type=core are not stored.
        $this->actingAs($this->admin)->put(route('admin.subjects.update', $subject->id), $this->form([
            'name' => 'Switcher', 'type' => 'core', 'subject_group' => 'core_academic',
            'track_id' => $this->track->id, 'specialization_id' => $this->spec->id,
        ]))->assertRedirect(route('admin.subjects'))->assertSessionHasNoErrors();

        $fresh = $subject->fresh();
        $this->assertSame('core', $fresh->type);
        $this->assertNull($fresh->track_id);
        $this->assertNull($fresh->specialization_id);
    }

    public function test_a_specialization_from_another_track_is_refused_but_no_specialization_is_fine(): void
    {
        $otherTrack = Track::factory()->create(['code' => 'TECHPRO', 'name' => 'Tech-Pro Track']);
        $otherSpec  = Specialization::factory()->create(['track_id' => $otherTrack->id, 'code' => 'ICTPROG']);

        $this->actingAs($this->admin)->post(route('admin.subjects.store'), $this->form([
            'name' => 'Cross Track', 'type' => 'elective', 'subject_group' => 'techpro',
            'track_id' => $this->track->id, 'specialization_id' => $otherSpec->id,
        ]))->assertSessionHasErrors('specialization_id');
        $this->assertDatabaseMissing('subjects', ['name' => 'Cross Track']);

        $this->actingAs($this->admin)->post(route('admin.subjects.store'), $this->form([
            'name' => 'Cross Track', 'type' => 'elective', 'subject_group' => 'techpro',
            'track_id' => $this->track->id,
        ]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('subjects', ['name' => 'Cross Track', 'specialization_id' => null]);
    }

    // ------------------------------------------------------------------
    // G — a validation error comes back to the page, visibly, with old input
    // ------------------------------------------------------------------

    public function test_a_subject_group_validation_error_is_rendered_on_the_subjects_page_with_old_input(): void
    {
        $response = $this->actingAs($this->admin)->from(route('admin.subjects'))
            ->post(route('admin.subjects.store'), $this->form(['name' => 'Unclassified', 'subject_group' => '']));

        $response->assertRedirect(route('admin.subjects'))
            ->assertSessionHasErrors('subject_group')
            ->assertSessionHasInput('name', 'Unclassified')
            ->assertSessionHasInput('grade_level', 11);

        // Before this pass the page's error banner listed three hand-picked
        // keys and a subject_group rejection was swallowed silently.
        $this->actingAs($this->admin)->get(route('admin.subjects'))
            ->assertOk()
            ->assertSee('Subject Group is required for a Grade 11 subject');
    }

    public function test_a_grade_12_subject_group_validation_error_is_rendered_on_the_subjects_page(): void
    {
        $this->actingAs($this->admin)->from(route('admin.subjects'))
            ->post(route('admin.subjects.store'), $this->form(['name' => 'Mismatch', 'type' => 'core', 'grade_level' => 12, 'subject_group' => 'work_immersion']))
            ->assertRedirect(route('admin.subjects'))->assertSessionHasErrors('subject_group')
            ->assertSessionHasInput('grade_level', 12);

        $this->actingAs($this->admin)->get(route('admin.subjects'))
            ->assertOk()
            ->assertSee("A Core subject must use the &#039;core_academic&#039; subject group", false);
    }

    // ------------------------------------------------------------------
    // Subjects list — the group is shown for both grade levels; a legacy
    // row with none is a configuration-needed state, never a guess
    // ------------------------------------------------------------------

    public function test_the_subjects_list_shows_the_group_for_grade_11_and_grade_12_and_flags_a_legacy_row_with_none(): void
    {
        $g11 = Subject::factory()->create(['name' => 'Listed G11', 'type' => 'elective', 'grade_level' => 11, 'subject_group' => 'arts_sports_wellness', 'track_id' => $this->track->id]);
        $g12 = Subject::factory()->create(['name' => 'Listed G12', 'type' => 'core', 'grade_level' => 12, 'subject_group' => 'core_academic']);
        // Only reachable for data created before the group became required
        // for Grade 12 — the factory bypasses the form's validation.
        $legacy = Subject::factory()->create(['name' => 'Legacy G12', 'type' => 'core', 'grade_level' => 12, 'subject_group' => null]);
        foreach ([$g11, $g12, $legacy] as $s) { $s->syncTerms([1, 2, 3]); }

        $html = $this->actingAs($this->admin)->get(route('admin.subjects'))->assertOk()->getContent();

        $this->assertStringContainsString('data-subject-group="arts_sports_wellness"', $html);
        $this->assertStringContainsString('data-subject-group="core_academic"', $html);
        $this->assertSame(1, substr_count($html, 'Subject Group needed'), 'Exactly the legacy row is flagged.');
        $this->assertStringNotContainsString('N/A', $html);
        $this->assertNull($legacy->fresh()->subject_group, 'Rendering the list never backfills a value.');

        // The grading-policy column shows the engine's figures — a Grade 12
        // core subject in SY 2026-2027 with no explicit curriculum grades under
        // DO 015 exactly like Grade 11 ("SSHS ECR grading correction").
        $this->assertStringContainsString('data-grading-key="core_academic"', $html);
        $this->assertStringNotContainsString('by section track', $html);
    }

    // ------------------------------------------------------------------
    // Grading safety — a Grade 12 subject's group changes NO figure
    // ------------------------------------------------------------------

    public function test_a_grade_12_subject_computes_the_identical_grade_with_and_without_a_subject_group(): void
    {
        $section = Section::factory()->create(['grade_level' => 12, 'curriculum' => 'k12_2013', 'track_id' => $this->track->id, 'school_year' => '2026-2027']);
        $student = Student::factory()->create(['section_id' => $section->id]);
        $subject = Subject::factory()->create(['name' => 'G12 Graded Elective', 'type' => 'elective', 'grade_level' => 12, 'track_id' => $this->track->id, 'subject_group' => null]);

        foreach ([['written_work', 38.0, 45.0], ['performance_task', 51.0, 60.0], ['examination', 35.0, 50.0]] as [$component, $earned, $max]) {
            $assessment = Assessment::factory()->create([
                'subject_id' => $subject->id, 'section_id' => $section->id, 'grading_period' => 1,
                'school_year' => $section->school_year, 'name' => $component, 'component' => $component, 'max_score' => $max,
            ]);
            AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => $earned]);
        }

        $before = (new GradingEngine())->computeGrade($student, $subject, $section, 1, $section->school_year);
        $profileBefore = (new GradingEngine())->resolveWeightProfile($section, $subject);
        $this->assertTrue($before['complete']);
        $this->assertSame('do8_academic_other', $profileBefore['group_key']);
        // DO 8 Academic "all other": 25/45/30 of 84.44 / 85.00 / 70.00.
        $this->assertEqualsWithDelta(21.11 + 38.25 + 21.00, $before['computed_grade'], 0.02);

        foreach (SubjectGroupWeight::allGroups() as $group) {
            if ($group === 'core_academic') { continue; }
            $subject->update(['subject_group' => $group]);

            $engine = new GradingEngine();
            $after = $engine->computeGrade($student, $subject->fresh(), $section, 1, $section->school_year);
            $profileAfter = $engine->resolveWeightProfile($section, $subject->fresh());

            $this->assertSame($profileBefore['group_key'], $profileAfter['group_key'], "DO 8 bucket unchanged by group '{$group}'");
            $this->assertSame($before['computed_grade'], $after['computed_grade'], "Computed grade unchanged by group '{$group}'");
            $this->assertSame($before['components'], $after['components'], "Component percentages unchanged by group '{$group}'");
        }

        // The one non-engine reader (Principal dashboard trend) resolves the
        // scheme's 'all' row for a DO 015 group name under do8_2015 — the
        // same row a null reaches.
        $this->assertSame(
            SubjectGroupWeight::resolve('do8_2015', null)->id,
            SubjectGroupWeight::resolve('do8_2015', 'academic_other')->id
        );
    }

    // ------------------------------------------------------------------

    /**
     * The Edit button hands openEditSubjectModal() the subject as JSON and
     * its term numbers as a second argument; assert the saved values are
     * what that JSON carries, so the modal can restore them.
     */
    private function assertEditButtonCarries(Subject $subject, array $expected, array $terms): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.subjects'))->assertOk()->getContent();

        preg_match_all("~openEditSubjectModal\((\{.*?\}), (\[[^\]]*\])\)~s", $html, $matches, PREG_SET_ORDER);
        $row = collect($matches)->first(fn($m) => (json_decode($m[1], true)['id'] ?? null) === $subject->id);
        $this->assertNotNull($row, "No Edit button found for subject #{$subject->id}.");

        $json = json_decode($row[1], true);
        foreach ($expected as $key => $value) {
            $this->assertArrayHasKey($key, $json);
            $this->assertSame($value, $json[$key], "Edit button JSON for '{$key}'");
        }
        $this->assertSame($terms, json_decode($row[2], true));
    }
}
