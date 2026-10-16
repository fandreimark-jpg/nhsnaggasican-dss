<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Intervention scheduling, deadlines, completion tracking and expiry
 * (2026-10-06). ADDITIVE only: every new column is nullable (or has a
 * default), no existing value is rewritten, and an existing row keeps its
 * status — with no deadline it reads "Schedule not set" and never expires.
 *
 * Times are stored in the application's timezone (config app.timezone =
 * Asia/Manila), the convention every other timestamp here already follows.
 * DATETIME (not TIMESTAMP) so the stored wall-clock value is never shifted
 * by the database session's time zone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interventions', function (Blueprint $table) {
            $table->string('title')->nullable()->after('recommended_type');
            $table->text('instructions')->nullable()->after('title');
            $table->text('completion_criteria')->nullable()->after('instructions');
            $table->foreignId('responsible_user_id')->nullable()->after('completion_criteria')->constrained('users')->restrictOnDelete();
            $table->dateTime('starts_at')->nullable()->after('responsible_user_id');
            $table->dateTime('deadline_at')->nullable()->after('starts_at');
            // Actual time the activity was done (entered by the responsible
            // staff); the RECORDING time/actor/notes are delivered_at /
            // delivered_by / delivery_notes, which already mean "it happened".
            $table->dateTime('completed_at')->nullable()->after('delivery_notes');
            $table->dateTime('expired_at')->nullable()->after('completed_at');           // the effective deadline that lapsed
            $table->dateTime('expiry_processed_at')->nullable()->after('expired_at');    // when the system recorded it
            $table->string('status_before_expiry')->nullable()->after('expiry_processed_at');
            $table->dateTime('cancelled_at')->nullable()->after('status_before_expiry');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->restrictOnDelete();
            $table->text('cancellation_reason')->nullable()->after('cancelled_by');
            // Optimistic version of the approved schedule: an approval or edit
            // based on an older version is refused, never applied over a newer one.
            $table->unsignedInteger('schedule_version')->default(0)->after('cancellation_reason');
            $table->index(['status', 'deadline_at']);
        });

        Schema::create('intervention_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intervention_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);                     // schedule | extension | late_completion
            $table->string('status', 20)->default('pending'); // pending | approved | rejected | superseded
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('proposed_starts_at')->nullable();
            $table->dateTime('proposed_deadline_at')->nullable();
            $table->dateTime('actual_completed_at')->nullable(); // late_completion only
            $table->text('reason');
            $table->text('evidence_notes')->nullable();
            $table->unsignedInteger('based_on_version');
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->text('decision_notes')->nullable();
            $table->timestamps();
            $table->index(['intervention_id', 'status']);
        });

        Schema::create('intervention_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intervention_id')->constrained()->restrictOnDelete();
            $table->string('event', 40);
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete(); // null = the system (scheduled expiry)
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->text('reason')->nullable();
            $table->dateTime('effective_at');   // when it took effect (e.g. the deadline that lapsed)
            $table->dateTime('recorded_at');    // when the system recorded it
            // Idempotency: one event per (intervention, event, key) — a rerun
            // or retry can never write the same transition twice.
            $table->string('dedupe_key', 120);
            $table->unique(['intervention_id', 'event', 'dedupe_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intervention_events');
        Schema::dropIfExists('intervention_requests');
        Schema::table('interventions', function (Blueprint $table) {
            $table->dropIndex(['status', 'deadline_at']);
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropConstrainedForeignId('responsible_user_id');
            $table->dropColumn(['title', 'instructions', 'completion_criteria', 'starts_at', 'deadline_at', 'completed_at', 'expired_at',
                'expiry_processed_at', 'status_before_expiry', 'cancelled_at', 'cancellation_reason', 'schedule_version']);
        });
    }
};
