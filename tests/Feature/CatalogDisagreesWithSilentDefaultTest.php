<?php

namespace Tests\Feature;

use App\Models\DepedSubjectCatalog;
use App\Models\SubjectGroupWeight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "ECR alignment" work order, PART 2a — this is the real "catalog vs. Table
 * 10" question, not a check that both are built from the same six patterns
 * (which is trivially true and proves nothing). subjects.subject_group has
 * no cluster-aware assignment logic anywhere in this codebase — every
 * subject silently defaults to 'core_academic' unless something explicitly
 * overrides it — so "what the pre-Part-2 6-bucket scheme resolves to" for
 * any of the 141 catalog subjects, absent a catalog link, genuinely IS that
 * default. This asserts the real, counted divergence between the two: 139
 * comparable rows (141 minus the 2 teacher-supplied, which have no Table 10
 * weight to compare against at all), 38 agreeing, 101 disagreeing — this is
 * the actual case for Part 2 existing, not colour commentary.
 */
class CatalogDisagreesWithSilentDefaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_139_of_141_rows_are_comparable_the_rest_are_teacher_supplied(): void
    {
        $this->assertSame(141, DepedSubjectCatalog::count());
        $this->assertSame(139, DepedSubjectCatalog::where('teacher_supplied', false)->count());
    }

    public function test_38_rows_agree_with_the_silent_core_academic_default_101_disagree(): void
    {
        $default = SubjectGroupWeight::resolve('do015_2026', 'core_academic');
        $defaultWw = (float) $default->ww_weight;
        $defaultPt = (float) $default->pt_weight;
        $defaultEx = $default->ex_weight !== null ? (float) $default->ex_weight : null;

        $rows = DepedSubjectCatalog::where('teacher_supplied', false)->get();

        $agreesWithDefault = function (DepedSubjectCatalog $row) use ($defaultWw, $defaultPt, $defaultEx) {
            $rowEx = $row->ex_weight !== null ? (float) $row->ex_weight : null;

            return (float) $row->ww_weight === $defaultWw
                && (float) $row->pt_weight === $defaultPt
                && $rowEx === $defaultEx;
        };

        $agree = $rows->filter($agreesWithDefault);
        $disagree = $rows->reject($agreesWithDefault);

        $this->assertCount(38, $agree);
        $this->assertCount(101, $disagree);
    }

    public function test_citizenship_and_civic_engagement_is_among_the_disagreeing_subjects(): void
    {
        $row = DepedSubjectCatalog::where('course_title', 'Citizenship and Civic Engagement')->first();
        $this->assertNotNull($row);

        // Catalog says 20/60/20 — the Arts, Social Sciences, and Humanities
        // pattern. The silent default says 20/50/30 (core_academic). These
        // must NOT match, or Part 2 would have nothing to fix for this subject.
        $this->assertEquals(20.0, (float) $row->ww_weight);
        $this->assertEquals(60.0, (float) $row->pt_weight);
        $this->assertEquals(20.0, (float) $row->ex_weight);

        $default = SubjectGroupWeight::resolve('do015_2026', 'core_academic');
        $this->assertNotEquals((float) $row->pt_weight, (float) $default->pt_weight);
    }
}
