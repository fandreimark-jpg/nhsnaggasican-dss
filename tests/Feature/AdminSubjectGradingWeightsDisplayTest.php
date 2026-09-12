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
        $response->assertSee('100%'); // the catalog's ex_weight, not techpro's 20%
        $response->assertSee('from DepEd SSHS catalog');
    }

    public function test_a_grade_12_subject_does_not_guess_a_do8_weight(): void
    {
        $admin = User::factory()->admin()->create();
        Subject::factory()->create(['name' => 'Oral Communication', 'type' => 'core', 'grade_level' => 12]);

        $response = $this->actingAs($admin)->get('/admin/subjects');

        $response->assertOk();
        $response->assertSee('depends on section');
    }
}
