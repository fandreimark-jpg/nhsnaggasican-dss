<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which role (st1/st2/term_exam — see exam_role_shares) this Examination
 * item plays, so GradingEngine can weight it accordingly instead of
 * treating every Examination item as equally weighted. Nullable and
 * optional by design: the adviser is asked on the Verify screen only
 * when a column is classified as Examination, and may leave it unset —
 * an item with no role falls back to equal weighting within the
 * component, so every existing assessments row stays valid as-is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->string('exam_role')->nullable()->after('component');
        });
    }

    public function down(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->dropColumn('exam_role');
        });
    }
};
