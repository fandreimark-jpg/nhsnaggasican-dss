<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Under DO 015, s. 2026 the Examination component itself is split
 * between two Summative Tests and a Term Examination, and they do not
 * carry equal weight within the component — see GradingEngine's
 * examinationPercentage(). Keyed by (scheme, exam_role) rather than
 * hardcoded, for the same reason subject_group_weights exists: a
 * correction to this split later is an UPDATE to a row, not a
 * deployment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_role_shares', function (Blueprint $table) {
            $table->id();
            $table->string('scheme');
            $table->string('exam_role'); // 'st1' | 'st2' | 'term_exam'
            $table->decimal('share', 5, 2);
            $table->timestamps();

            $table->unique(['scheme', 'exam_role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_role_shares');
    }
};
