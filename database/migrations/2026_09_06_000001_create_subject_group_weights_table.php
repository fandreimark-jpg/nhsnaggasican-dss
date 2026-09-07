<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The grading weights `assessment_components` used to hold as a single
 * system-wide flat 25/50/25 default (see that table's migration) turned
 * out not to be system-wide at all: DO 015, s. 2026 assigns different
 * Written Work / Performance Task / Examination weights to six different
 * SHS subject groups, and DO 8, s. 2015 (which Grade 12 stays on — see
 * TransmutationService::schemeFor()) keeps its own flat 25/50/25 for
 * every subject. `assessment_components` itself is untouched — it still
 * names the 3 fixed components and their keys — only the WEIGHT number
 * moves here, keyed by (scheme, subject_group), so a future order's
 * different split is a seeded row, never a code change.
 *
 * `ex_weight` is nullable, not zero: DO 015's research_innovation and
 * work_immersion subject groups have NO Examination component at all —
 * null means "not applicable," zero would wrongly mean "applicable, but
 * worth nothing." GradingEngine relies on this distinction to compute a
 * complete grade from 2 components without treating the subject as
 * missing evidence for a component it was never supposed to have.
 *
 * Seeded directly in this migration's up() — same precedent as
 * assessment_components' own migration — rather than left to a
 * separately-run seeder: every one of these 7 rows is CONFIRMED,
 * published data (DO 015, s. 2026 Table 10, and DO 8, s. 2015's
 * long-standing flat split), not provisional like exam_role_shares'
 * 30/30/40 split — so every environment (a fresh clone, CI, a
 * RefreshDatabase test) has it the moment this migration runs, with no
 * extra seeder step required before a grade can be computed.
 * SubjectGroupWeightsSeeder (still called from DatabaseSeeder) is a
 * harmless idempotent no-op against what's already here.
 */
return new class extends Migration
{
    private const DO015_2026 = 'do015_2026';
    private const DO8_2015 = 'do8_2015';

    /** @var array<int, array{0: string, 1: float, 2: float, 3: ?float}> [subject_group, ww, pt, ex] */
    private const DO015_2026_GROUPS = [
        ['core_academic',        20.00, 50.00, 30.00],
        ['field_exposure',       15.00, 70.00, 15.00],
        ['arts_sports_wellness', 20.00, 60.00, 20.00],
        ['research_innovation',  40.00, 60.00, null],
        ['techpro',              15.00, 65.00, 20.00],
        ['work_immersion',       20.00, 80.00, null],
    ];

    public function up(): void
    {
        Schema::create('subject_group_weights', function (Blueprint $table) {
            $table->id();
            $table->string('scheme');          // 'do015_2026' | 'do8_2015'
            $table->string('subject_group');    // e.g. 'core_academic', or 'all' for a scheme with no per-group split
            $table->decimal('ww_weight', 5, 2);
            $table->decimal('pt_weight', 5, 2);
            $table->decimal('ex_weight', 5, 2)->nullable(); // null = this subject group has no Examination component
            $table->timestamps();

            $table->unique(['scheme', 'subject_group']);
        });

        $now = now();

        foreach (self::DO015_2026_GROUPS as [$group, $ww, $pt, $ex]) {
            DB::table('subject_group_weights')->insert([
                'scheme' => self::DO015_2026, 'subject_group' => $group,
                'ww_weight' => $ww, 'pt_weight' => $pt, 'ex_weight' => $ex,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        DB::table('subject_group_weights')->insert([
            'scheme' => self::DO8_2015, 'subject_group' => 'all',
            'ww_weight' => 25.00, 'pt_weight' => 50.00, 'ex_weight' => 25.00,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('subject_group_weights');
    }
};
