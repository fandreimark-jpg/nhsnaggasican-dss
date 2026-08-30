<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a computed-grade layer on top of the existing official grade,
 * without touching it. `grade` remains the adviser-controlled official
 * final grade exactly as before — nothing in the grading engine (P-6)
 * ever writes to it. `computed_grade` is what App\Services\GradingEngine
 * derives from assessment evidence (Written Work 25% / Performance Task
 * 50% / Examination 25%); `is_verified`/`verified_at` record whether an
 * adviser has reviewed a computed value (a later phase's UI action) —
 * both nullable/default-false so every existing row is valid as-is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grades', function (Blueprint $table) {
            $table->decimal('computed_grade', 5, 2)->nullable()->after('grade');
            $table->boolean('is_verified')->default(false)->after('computed_grade');
            $table->timestamp('verified_at')->nullable()->after('is_verified');
        });
    }

    public function down(): void
    {
        Schema::table('grades', function (Blueprint $table) {
            $table->dropColumn(['computed_grade', 'is_verified', 'verified_at']);
        });
    }
};
