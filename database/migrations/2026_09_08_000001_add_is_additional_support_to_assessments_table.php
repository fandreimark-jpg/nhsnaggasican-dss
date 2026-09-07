<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Workflow completion pass" TASK 3b — whether this assessment item is
 * within-term additional support (a re-teach quiz, an extra activity)
 * rather than a regular planned item, as DATA instead of a hardcoded
 * name match on "%remedial%". Nullable-by-default false: every existing
 * row is unaffected, and the Adviser sets this explicitly on the Verify
 * screen at upload time (see AssessmentUploadService) or when adding an
 * item by hand — never inferred from the item's name.
 *
 * Display-only. Nothing here changes GradingEngine's arithmetic: an
 * additional-support item still contributes its full points to the
 * term total exactly as before this column existed — see CLAUDE.md's
 * "Known limitations" open policy question this column exists to make
 * visible, not resolve.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->boolean('is_additional_support')->default(false)->after('exam_role');
        });
    }

    public function down(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->dropColumn('is_additional_support');
        });
    }
};
