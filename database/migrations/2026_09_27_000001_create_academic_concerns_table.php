<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Rule-based academic monitoring" Phase 2B — Academic Concern Foundation.
 *
 * One row per observable academic concern a school-approved MonitoringRule
 * found in the current evidence (App\Services\AcademicMonitoringService).
 * A concern is For Review evidence for the Principal — never a prediction,
 * never an intervention.
 *
 * History is kept, never rewritten away:
 *   open -> the evidence no longer meets the rule -> resolved (row kept,
 *   resolved_at + resolution_reason set, last evidence retained).
 * A condition that returns later opens a NEW row, so first_detected_at of
 * the earlier episode is never overwritten.
 *
 * Duplicate prevention: MySQL has no partial unique index, so `open_key`
 * (rule_key|student|context) is set only while a concern is open and
 * cleared on resolution. The unique index then allows at most ONE open
 * concern per logical rule/context and any number of resolved ones
 * (NULLs never collide).
 *
 * Context columns are stored on the row, never re-derived (CLAUDE.md
 * design decision 4). Foreign keys RESTRICT on delete: a concern is
 * academic history, protected the same way grades and interventions are.
 *
 * Additive only: creates one new table; touches no existing row.
 * down() drops only this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_concerns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            // Nullable: a future approved rule may concern a learner's term
            // as a whole rather than one subject.
            $table->foreignId('subject_id')->nullable()->constrained('subjects')->restrictOnDelete();
            $table->foreignId('section_id')->constrained('sections')->restrictOnDelete();
            $table->string('school_year');
            $table->unsignedTinyInteger('grading_period');

            $table->string('rule_key', 64);
            $table->unsignedSmallInteger('rule_version');
            // What, within the rule, this concern is about — e.g.
            // "assessment:123". Deliberately not a foreign key: the concern
            // must outlive the thing it described.
            $table->string('context_key', 100);
            $table->string('open_key', 191)->nullable()->unique('academic_concerns_open_key_unique');

            $table->json('evidence');
            $table->enum('status', ['open', 'resolved'])->default('open');

            // DATETIME, not TIMESTAMP, on purpose. The demo box runs MariaDB
            // 10.4 with explicit_defaults_for_timestamp = OFF, where a NOT
            // NULL TIMESTAMP never stays a plain column: the table's FIRST
            // one silently gains DEFAULT CURRENT_TIMESTAMP ON UPDATE
            // CURRENT_TIMESTAMP (it would rewrite first_detected_at on every
            // save), and every later one gains DEFAULT '0000-00-00 00:00:00',
            // which strict mode's NO_ZERO_DATE rejects (error 1067). DATETIME
            // has no implicit default or auto-update on any MySQL/MariaDB
            // version, so these stay exactly what the service writes:
            // AcademicMonitoringService sets both required ones on every
            // insert, and an insert without them is refused, never defaulted.
            $table->dateTime('first_detected_at');
            $table->dateTime('last_evaluated_at');
            $table->dateTime('resolved_at')->nullable();
            $table->string('resolution_reason')->nullable();

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('reviewed_at')->nullable();

            $table->timestamps();

            $table->index(['school_year', 'grading_period', 'status'], 'academic_concerns_year_term_status_index');
            $table->index(['section_id', 'grading_period', 'school_year'], 'academic_concerns_section_term_year_index');
            $table->index(['student_id', 'status'], 'academic_concerns_student_status_index');
            $table->index(['rule_key', 'context_key'], 'academic_concerns_rule_context_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_concerns');
    }
};
