<?php

namespace Tests\Unit;

use App\Models\Section;
use App\Models\Specialization;
use App\Models\Subject;
use App\Models\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "ECR alignment" work order, PART 6 — Subject::forSection() branches on
 * curriculum now; this pins that the k12_2013 branch is byte-identical to
 * before the branch existed. k12_2013's specialization-based elective
 * match is not broken (see CLAUDE.md, "Elective selection is per-cluster,
 * not per-learner") and must never be touched by this part.
 */
class ForSectionK122013UnchangedTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_k12_2013_section_gets_core_plus_every_specialization_matching_elective_unchanged(): void
    {
        $track = Track::factory()->create(['code' => 'ACAD']);
        $specialization = Specialization::factory()->create(['track_id' => $track->id, 'code' => 'HUMSS']);
        $section = Section::factory()->create([
            'curriculum' => 'k12_2013', 'grade_level' => 12,
            'track_id' => $track->id, 'specialization_id' => $specialization->id,
            'school_year' => '2026-2027',
        ]);

        $core = Subject::factory()->create(['type' => 'core', 'grade_level' => 12]);
        $matchingElective = Subject::factory()->create([
            'type' => 'elective', 'grade_level' => 12, 'track_id' => $track->id, 'specialization_id' => $specialization->id,
        ]);
        $wholeTrackElective = Subject::factory()->create([
            'type' => 'elective', 'grade_level' => 12, 'track_id' => $track->id, 'specialization_id' => null,
        ]);
        $otherSpecializationElective = Subject::factory()->create([
            'type' => 'elective', 'grade_level' => 12, 'track_id' => $track->id,
            'specialization_id' => Specialization::factory()->create(['track_id' => $track->id, 'code' => 'STEM'])->id,
        ]);

        $ids = Subject::forSection($section)->pluck('id')->sort()->values();

        // Same rule as always: core + (this specialization OR whole-track
        // null-specialization elective), never electives from a DIFFERENT
        // specialization. No section_subject row exists anywhere in this
        // test -- k12_2013 never needed one.
        $expected = collect([$core->id, $matchingElective->id, $wholeTrackElective->id])->sort()->values();
        $this->assertEquals($expected, $ids);
        $this->assertFalse($ids->contains($otherSpecializationElective->id));
    }

    public function test_a_null_curriculum_section_behaves_the_same_as_k12_2013_the_existing_fallback(): void
    {
        $track = Track::factory()->create(['code' => 'ACAD']);
        $section = Section::factory()->create([
            'curriculum' => null, 'grade_level' => 12, 'track_id' => $track->id,
            'specialization_id' => null, 'school_year' => '2026-2027',
        ]);

        $core = Subject::factory()->create(['type' => 'core', 'grade_level' => 12]);
        $elective = Subject::factory()->create([
            'type' => 'elective', 'grade_level' => 12, 'track_id' => $track->id, 'specialization_id' => null,
        ]);

        $ids = Subject::forSection($section)->pluck('id')->sort()->values();
        $this->assertEquals(collect([$core->id, $elective->id])->sort()->values(), $ids);
    }
}
