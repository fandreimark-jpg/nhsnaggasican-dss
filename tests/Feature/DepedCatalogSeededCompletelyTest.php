<?php

namespace Tests\Feature;

use App\Models\DepedSubjectCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "ECR alignment" work order, PART 2a — the 141-row DepEd Strengthened SHS
 * catalog is seeded directly by the 2026_09_10_000001 migration (via
 * DepedSubjectCatalog::rowsFromCsv() reading database/seeders/
 * deped_sshs_catalog.csv), so a fresh RefreshDatabase test already has it —
 * no explicit seed() call needed. Counts here are the gate given for the
 * extraction itself: 141 total; 6/81/54 by track; the six Academic clusters
 * by name.
 */
class DepedCatalogSeededCompletelyTest extends TestCase
{
    use RefreshDatabase;

    public function test_141_rows_seeded(): void
    {
        $this->assertSame(141, DepedSubjectCatalog::count());
    }

    public function test_track_counts(): void
    {
        $this->assertSame(6, DepedSubjectCatalog::where('track', 'SSHS - CORE')->count());
        $this->assertSame(81, DepedSubjectCatalog::where('track', 'SSHS - ACADEMIC')->count());
        $this->assertSame(54, DepedSubjectCatalog::where('track', 'SSHS - TECH-PRO')->count());
    }

    public function test_academic_cluster_counts(): void
    {
        $this->assertSame(26, DepedSubjectCatalog::where('cluster', 'SCIENCE, TECHNOLOGY, ENGINEERING, AND MATHEMATICS')->count());
        $this->assertSame(25, DepedSubjectCatalog::where('cluster', 'ARTS, SOCIAL SCIENCES, AND HUMANITIES')->count());
        $this->assertSame(13, DepedSubjectCatalog::where('cluster', 'FIELD EXPERIENCE')->count());
        $this->assertSame(10, DepedSubjectCatalog::where('cluster', 'SPORTS, HEALTH, AND WELLNESS')->count());
        $this->assertSame(6, DepedSubjectCatalog::where('cluster', 'BUSINESS AND ENTREPRENEURSHIP')->count());
        // One "OTHER ELECTIVE / SPECIAL CURRICULAR PROGRAM" row per track (Academic + Tech-Pro) = 2 total.
        $this->assertSame(2, DepedSubjectCatalog::where('cluster', 'OTHER ELECTIVE / SPECIAL CURRICULAR PROGRAM')->count());
    }

    public function test_teacher_supplied_rows_have_null_weights_not_the_literal_string(): void
    {
        $rows = DepedSubjectCatalog::where('teacher_supplied', true)->get();

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertNull($row->ww_weight);
            $this->assertNull($row->pt_weight);
            $this->assertNull($row->ex_weight);
            $this->assertNull($row->st1_share);
            $this->assertNull($row->st2_share);
            $this->assertNull($row->te_share);
        }
    }

    public function test_a_subject_with_no_examination_component_is_null_not_zero(): void
    {
        $row = DepedSubjectCatalog::where('course_title', 'Research 1')->first();

        $this->assertNotNull($row);
        $this->assertSame(40.0, (float) $row->ww_weight);
        $this->assertSame(60.0, (float) $row->pt_weight);
        $this->assertNull($row->ex_weight);
    }

    public function test_the_nine_term_exam_only_subjects_have_no_summative_test_shares(): void
    {
        $teOnly = DepedSubjectCatalog::whereNotNull('ex_weight')
            ->whereNull('st1_share')
            ->whereNull('st2_share')
            ->where('te_share', 100)
            ->get();

        // 6 Field Experience + 2 STEM, per HANDOFF.md design decision 5's amendment.
        // "Work Immersion for Academic Track" is NOT among these 9 — its ex_weight
        // is null (no Examination component at all), so it's excluded by the
        // whereNotNull('ex_weight') filter above even though its te_share is
        // also printed as 100 in the source (a moot value — see DepedSubjectCatalog's docblock).
        $this->assertCount(8, $teOnly);

        $workImmersionAcademic = DepedSubjectCatalog::where('course_title', 'Work Immersion for Academic Track')->first();
        $this->assertNull($workImmersionAcademic->ex_weight);
        $this->assertEquals(100.0, (float) $workImmersionAcademic->te_share);
    }
}
