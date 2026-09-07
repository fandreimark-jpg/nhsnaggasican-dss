<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * SpecializationController::store() never checked (track_id, code)
     * uniqueness, so clicking Add twice created duplicate rows that
     * SubjectsImport's ->value('id') lookup then resolved unpredictably.
     * Before adding the unique index, collapse any existing duplicates
     * (keeping the lowest id per group) and repoint any subjects/sections
     * that referenced a row about to be deleted.
     */
    public function up(): void
    {
        $duplicateGroups = DB::table('specializations')
            ->select('track_id', 'code')
            ->groupBy('track_id', 'code')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicateGroups as $group) {
            $ids = DB::table('specializations')
                ->where('track_id', $group->track_id)
                ->where('code', $group->code)
                ->orderBy('id')
                ->pluck('id');

            $survivorId = $ids->first();
            $duplicateIds = $ids->slice(1)->values();

            if ($duplicateIds->isEmpty()) {
                continue;
            }

            DB::table('subjects')
                ->whereIn('specialization_id', $duplicateIds)
                ->update(['specialization_id' => $survivorId]);

            DB::table('sections')
                ->whereIn('specialization_id', $duplicateIds)
                ->update(['specialization_id' => $survivorId]);

            DB::table('specializations')->whereIn('id', $duplicateIds)->delete();
        }

        Schema::table('specializations', function (Blueprint $table) {
            $table->unique(['track_id', 'code'], 'specializations_track_id_code_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('specializations', function (Blueprint $table) {
            $table->dropUnique('specializations_track_id_code_unique');
        });
    }
};
