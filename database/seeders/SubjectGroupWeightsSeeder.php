<?php

namespace Database\Seeders;

use App\Models\SubjectGroupWeight;
use Illuminate\Database\Seeder;

/**
 * Weights by (scheme, subject_group) — see subject_group_weights'
 * migration. DO 015, s. 2026 Table 10 for the six SHS subject groups;
 * DO 8, s. 2015's long-standing flat 25/50/25 under the single 'all'
 * bucket (see SubjectGroupWeight::resolve()'s fallback). Idempotent via
 * firstOrCreate(), matching this codebase's other seeders.
 */
class SubjectGroupWeightsSeeder extends Seeder
{
    private const SCHEME_DO015_2026 = 'do015_2026';
    private const SCHEME_DO8_2015 = 'do8_2015';

    /** @var array<int, array{0: string, 1: float, 2: float, 3: ?float}> [subject_group, ww, pt, ex] */
    private const DO015_2026_GROUPS = [
        ['core_academic',        20.00, 50.00, 30.00],
        // "Subject classification and grading weights cleanup" pass —
        // same 20/50/30 numbers as core_academic, but a genuinely
        // different DepEd profile (STEM / Business & Entrepreneurship
        // cluster ELECTIVES, never a Core subject) — see
        // 2026_09_12_000001_add_academic_other_subject_group_to_weights_table.php
        // and SubjectGroupWeight::classificationError().
        ['academic_other',       20.00, 50.00, 30.00],
        ['field_exposure',       15.00, 70.00, 15.00],
        ['arts_sports_wellness', 20.00, 60.00, 20.00],
        ['research_innovation',  40.00, 60.00, null],
        ['techpro',              15.00, 65.00, 20.00],
        ['work_immersion',       20.00, 80.00, null],
    ];

    /**
     * "ECR alignment" work order, PART 2d -- DO 8, s. 2015 weights by TRACK,
     * not DO 015's subject-group axis, so these five are new subject_group
     * slugs under the SAME do8_2015 scheme rather than a new table. See the
     * matching migration (2026_09_10_000003) for the SOURCE NOTE and
     * GradingEngine::resolveDo8GroupKey() for how a subject resolves to one
     * of these. The existing 'all' row (25/50/25) stays as the safety-net
     * fallback -- never deleted.
     *
     * @var array<int, array{0: string, 1: float, 2: float, 3: float}> [subject_group slug, ww, pt, qa]
     */
    private const DO8_2015_GROUPS = [
        ['do8_core',                           25.00, 50.00, 25.00],
        ['do8_academic_other',                 25.00, 45.00, 30.00],
        ['do8_academic_work_immersion',        35.00, 40.00, 25.00],
        ['do8_tvl_sports_arts_other',          20.00, 60.00, 20.00],
        ['do8_tvl_sports_arts_work_immersion', 20.00, 60.00, 20.00],
    ];

    public function run(): void
    {
        foreach (self::DO015_2026_GROUPS as [$group, $ww, $pt, $ex]) {
            SubjectGroupWeight::firstOrCreate(
                ['scheme' => self::SCHEME_DO015_2026, 'subject_group' => $group],
                ['ww_weight' => $ww, 'pt_weight' => $pt, 'ex_weight' => $ex]
            );
        }

        SubjectGroupWeight::firstOrCreate(
            ['scheme' => self::SCHEME_DO8_2015, 'subject_group' => 'all'],
            ['ww_weight' => 25.00, 'pt_weight' => 50.00, 'ex_weight' => 25.00]
        );

        foreach (self::DO8_2015_GROUPS as [$group, $ww, $pt, $ex]) {
            SubjectGroupWeight::firstOrCreate(
                ['scheme' => self::SCHEME_DO8_2015, 'subject_group' => $group],
                ['ww_weight' => $ww, 'pt_weight' => $pt, 'ex_weight' => $ex]
            );
        }
    }
}
