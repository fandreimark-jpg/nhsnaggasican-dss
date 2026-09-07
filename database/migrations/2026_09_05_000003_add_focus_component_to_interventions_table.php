<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK 2 of "status clarity and progress consistency" — the component an
 * intervention's recorded reason was actually about, captured once at
 * creation so ProgressMonitoringService::compareWithinTerm() can always
 * anchor to THAT component rather than recomputing "whichever is weakest
 * right now" (which drifts from the recorded reason the moment new
 * evidence lands in a different component — the bug this task fixes).
 *
 * Nullable, and backfilled below from each existing row's
 * recommendation_reason text using the SAME strict, no-guessing rule
 * Intervention::extractNamedComponent() uses going forward (duplicated
 * here rather than calling the model, so this migration keeps working
 * even if that method's shape later changes) — ONLY the three component
 * labels ("Written Work"/"Performance Task"/"Examination") appearing
 * literally in the reason text count. There is deliberately NO
 * recommended_type fallback here: a fallback guess is exactly what would
 * silently substitute a different component than what the reason
 * actually said — see the ground rule "show 'focus component not
 * recorded' rather than silently substituting a different one." A row
 * whose reason names no component is left null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interventions', function (Blueprint $table) {
            $table->string('focus_component')->nullable()->after('recommendation_reason');
        });

        $labels = ['Written Work' => 'written_work', 'Performance Task' => 'performance_task', 'Examination' => 'examination'];

        foreach (DB::table('interventions')->select('id', 'recommendation_reason')->get() as $row) {
            $reason = (string) $row->recommendation_reason;
            $component = null;

            foreach ($labels as $label => $key) {
                if (str_contains($reason, $label)) {
                    $component = $key;
                    break;
                }
            }

            if ($component !== null) {
                DB::table('interventions')->where('id', $row->id)->update(['focus_component' => $component]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('interventions', function (Blueprint $table) {
            $table->dropColumn('focus_component');
        });
    }
};
