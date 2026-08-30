<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lookup table for the 3 assessment components (Written Work, Performance
 * Task, Examination) and their weight in the final computed grade.
 *
 * Weights are stored here as a single system-wide default rather than a
 * separate subject_component_weights override table — there is no current
 * requirement for per-subject weight customization (the approved grading
 * requirement is a flat 25/50/25 for every subject), and CLAUDE.md itself
 * warns against over-engineering the weight design ahead of an actual need.
 * If per-subject overrides become a real requirement later, that's a small
 * additive migration on top of this one — nothing here needs to change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_components', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();   // 'written_work' | 'performance_task' | 'examination'
            $table->string('name');            // display name, e.g. 'Written Work'
            $table->decimal('weight', 5, 2);   // e.g. 25.00, 50.00, 25.00 — must sum to 100 across all rows
            $table->timestamps();
        });

        DB::table('assessment_components')->insert([
            ['key' => 'written_work',     'name' => 'Written Work',     'weight' => 25.00, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'performance_task', 'name' => 'Performance Task', 'weight' => 50.00, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'examination',      'name' => 'Examination',      'weight' => 25.00, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_components');
    }
};
