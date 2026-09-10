<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "ECR alignment" work order, PART 2d — DO 8, s. 2015 weights five
 * subjects differently by TRACK (Core / Academic / TVL-Sports-Arts), not by
 * DO 015's subject-group axis, so these are new rows in the EXISTING
 * subject_group_weights table (not a new table), keyed by new subject_group
 * slugs prefixed do8_ -- see GradingEngine::resolveDo8GroupKey() for how a
 * (Section, Subject) pair resolves to one of these five slugs.
 *
 * SOURCE NOTE -- read before defending this in writing. These five rows are
 * read from secondary reproductions of the DO 8, s. 2015 table, not the
 * signed PDF of the order itself. Correcting one later is an UPDATE to this
 * table, never a deployment -- the same discipline as Do015TransmutationSeeder.
 * Before citing this table in the thesis, confirm it against the signed
 * order and delete this note.
 *
 * The existing do8_2015/'all' row (25/50/25) is left in place untouched, as
 * the safety-net fallback SubjectGroupWeight::resolve() already falls to if
 * a group key it doesn't recognise is ever passed.
 */
return new class extends Migration
{
    /** @var array<int, array{0: string, 1: float, 2: float, 3: float}> [subject_group slug, ww, pt, qa] */
    private const DO8_GROUPS = [
        ['do8_core',                          25.00, 50.00, 25.00], // Core subjects
        ['do8_academic_other',                25.00, 45.00, 30.00], // Academic -- all other subjects
        ['do8_academic_work_immersion',       35.00, 40.00, 25.00], // Academic -- Work Immersion / Research / Business Enterprise Simulation
        ['do8_tvl_sports_arts_other',         20.00, 60.00, 20.00], // TVL, Sports, Arts and Design -- all other subjects
        ['do8_tvl_sports_arts_work_immersion',20.00, 60.00, 20.00], // TVL, Sports, Arts and Design -- Work Immersion / Research / Exhibit / Performance
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::DO8_GROUPS as [$group, $ww, $pt, $ex]) {
            DB::table('subject_group_weights')->insert([
                'scheme' => 'do8_2015', 'subject_group' => $group,
                'ww_weight' => $ww, 'pt_weight' => $pt, 'ex_weight' => $ex,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('subject_group_weights')
            ->where('scheme', 'do8_2015')
            ->whereIn('subject_group', array_column(self::DO8_GROUPS, 0))
            ->delete();
    }
};
