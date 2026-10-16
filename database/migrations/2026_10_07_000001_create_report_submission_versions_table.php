<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Report submission versions (2026-10-07) — ADDITIVE. One immutable row per
 * explicit "Submit Report": who submitted (server-side auth user), when
 * (server clock), and a JSON snapshot of the report contents at that moment.
 * Recipients read the latest version; earlier versions are kept, never
 * rewritten. report_submissions keeps its one row per section/term/year as
 * the "has been submitted" marker. Existing report_submissions rows get NO
 * version: their original contents were never stored and are not
 * reconstructed from today's grades.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_submission_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_submission_id')->constrained('report_submissions')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('submitted_at')->nullable(); // always set by the submit action; nullable for MySQL portability (MigrationTimestampPortabilityTest)
            $table->longText('snapshot');
            $table->timestamps();
            $table->unique(['report_submission_id', 'version'], 'report_version_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_submission_versions');
    }
};
