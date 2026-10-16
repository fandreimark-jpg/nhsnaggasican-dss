<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Class Record refinements" pass (2026-09-28) — the append-only history of
 * teaching responsibility: every assignment, teacher replacement, scope
 * correction and removal, with the Admin who made it, when, and why.
 *
 * `teaching_assignments` keeps holding the ONE current teacher per
 * section/subject/term (its unique index is unchanged); a removed or
 * replaced responsibility survives here, never by deleting evidence.
 *
 * No foreign keys: a history row must outlive the assignment row it
 * describes. Backfill: one 'assigned' row per EXISTING assignment, copied
 * from that row's own assigned_by and created_at — nothing is inferred.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teaching_assignment_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('teaching_assignment_id')->nullable();
            // assigned | reassigned | scope_corrected | revoked
            $table->string('action', 20);
            $table->unsignedBigInteger('section_id');
            $table->unsignedBigInteger('subject_id');
            $table->string('school_year', 9);
            $table->unsignedTinyInteger('grading_period');
            $table->unsignedBigInteger('old_user_id')->nullable();
            $table->unsignedBigInteger('new_user_id')->nullable();
            // For a scope correction: where the assignment pointed before.
            $table->json('previous_scope')->nullable();
            $table->string('reason', 500)->nullable();
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->dateTime('changed_at');

            $table->index(['section_id', 'subject_id', 'school_year', 'grading_period'], 'ta_history_scope_index');
            $table->index('teaching_assignment_id', 'ta_history_assignment_index');
        });

        foreach (DB::table('teaching_assignments')->orderBy('id')->get() as $row) {
            DB::table('teaching_assignment_history')->insert([
                'teaching_assignment_id' => $row->id,
                'action'         => 'assigned',
                'section_id'     => $row->section_id,
                'subject_id'     => $row->subject_id,
                'school_year'    => $row->school_year,
                'grading_period' => $row->grading_period,
                'old_user_id'    => null,
                'new_user_id'    => $row->user_id,
                'previous_scope' => null,
                'reason'         => null,
                'changed_by'     => $row->assigned_by,
                'changed_at'     => $row->created_at ?? now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('teaching_assignment_history');
    }
};
