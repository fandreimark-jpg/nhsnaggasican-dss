<?php

namespace Tests\Feature;

use App\Models\DepedSubjectCatalog;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SYSTEM_FIXES_AND_ML_AUDIT.md, "Grading details per subject" -- the
 * Admin Subjects page must show the actual WW/PT/Exam weights a subject's
 * grade computes with, not just its subject_group slug. Resolved the same
 * way GradingEngine actually resolves it (catalog row wins over
 * subject_group) rather than a second copy of the arithmetic -- see
 * SubjectController::index().
 */
class AdminSubjectGradingWeightsDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_grade_11_core_academic_subject_shows_its_resolved_weights(): void
    {
        $admin = User::factory()->admin()->create();
        Subject::factory()->create([
            'name' => 'General Mathematics', 'type' => 'core', 'grade_level' => 11, 'subject_group' => 'core_academic',
        ]);

        $response = $this->actingAs($admin)->get('/admin/subjects');

        $response->assertOk();
        // core_academic is seeded as 20/50/30 (see SubjectGroupWeightsSeeder).
        $response->assertSee('20%');
        $response->assertSee('50%');
        $response->assertSee('30%');
        $response->assertSee('DO 015, s. 2026');
    }

    public function test_a_subject_linked_to_a_catalog_row_shows_the_catalog_weights_not_the_group_default(): void
    {
        $admin = User::factory()->admin()->create();
        $catalog = DepedSubjectCatalog::create([
            'scheme' => 'do015_2026', 'track' => 'ACADEMIC', 'cluster' => 'STEM',
            'course_title' => 'Fictional Test Subject For Weights Display', 'grade_levels' => '11',
            'ww_weight' => 20, 'pt_weight' => 50, 'ex_weight' => 100,
            'teacher_supplied' => false,
        ]);
        Subject::factory()->create([
            'name' => 'Fictional Test Subject For Weights Display', 'type' => 'core', 'grade_level' => 11,
            'subject_group' => 'techpro', // deliberately WRONG group -- catalog must win, not this
            'catalog_id' => $catalog->id,
        ]);

        $response = $this->actingAs($admin)->get('/admin/subjects');

        $response->assertOk();
        $response->assertSee('100'); // the catalog's ex_weight, not techpro's 20
        $response->assertSee('DepEd Strengthened SHS catalog');
    }

    /**
     * "Grading policy display" + "SSHS ECR grading correction" passes
     * (2026-09-20) — a Grade 12 subject shows the figures GradingEngine::
     * resolveWeightProfile() would grade it with, never a placeholder and
     * never a guess: DO 015 in SY 2026-2027 unless a section is explicitly
     * on the 2013 curriculum, and both contexts when both kinds exist.
     */
    public function test_a_grade_12_subject_shows_the_engines_do015_figures_and_do8_only_for_an_explicit_2013_section(): void
    {
        $admin = User::factory()->admin()->create();
        Subject::factory()->create(['name' => 'Oral Communication', 'type' => 'core', 'grade_level' => 12, 'subject_group' => 'core_academic']);

        // SY 2026-2027, no explicit curriculum: DO 015 for Grade 12 as for
        // Grade 11 ("SSHS ECR grading correction") — Core is 20/50/30.
        $html = $this->actingAs($admin)->get('/admin/subjects')->assertOk()->getContent();
        $this->assertStringNotContainsString('by section track', $html);
        $this->assertStringContainsString('data-grading-key="core_academic"', $html);
        $this->assertStringContainsString('DO 015, s. 2026', $html);

        // Only an EXPLICIT 2013-curriculum section brings DO 8 into view. When
        // every Grade 12 section is such a section, DO 8 IS the single answer...
        $track = \App\Models\Track::factory()->create(['code' => 'ACAD']);
        $year = \App\Models\Section::activeSchoolYear();
        \App\Models\Section::factory()->create(['grade_level' => 12, 'school_year' => $year, 'curriculum' => 'k12_2013', 'track_id' => $track->id]);
        $html = $this->actingAs($admin)->get('/admin/subjects')->assertOk()->getContent();
        $this->assertStringContainsString('data-grading-key="do8_core"', $html);
        $this->assertStringContainsString('Core Subjects', $html);

        // ...and once an SSHS (or unset) section exists beside it, both
        // contexts are shown rather than one figure being picked.
        \App\Models\Section::factory()->create(['grade_level' => 12, 'school_year' => $year, 'curriculum' => null, 'track_id' => $track->id]);
        $html = $this->actingAs($admin)->get('/admin/subjects')->assertOk()->getContent();
        $this->assertStringContainsString('Resolved by section context', $html);
        $this->assertStringContainsString('2013 curriculum', $html);
    }
}
