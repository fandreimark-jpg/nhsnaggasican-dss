<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Principal decisions on DSS recommendations (P-9). No equivalent
 * structure exists anywhere in the app — confirmed by searching for
 * "intervention" across app/routes/resources/database before writing
 * this, per "create intervention records only if no equivalent existing
 * structure exists."
 *
 * The DSS RECOMMENDS (recommended_type + recommendation_reason, set by
 * App\Services\InterventionRecommender); the PRINCIPAL DECIDES
 * (status, principal_notes, decided_by/decided_at) — a recommendation
 * is never auto-approved, it starts at 'recommended' and only a
 * Principal action moves it forward.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interventions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->onDelete('cascade');
            $table->foreignId('subject_id')->nullable()->constrained('subjects')->onDelete('set null');
            $table->foreignId('risk_result_id')->nullable()->constrained('risk_results')->onDelete('set null');

            $table->string('recommended_type');       // remediation | additional_learning_activity | additional_performance_task | teacher_monitoring | attendance_monitoring | parent_conference | other
            $table->text('recommendation_reason')->nullable(); // transparent "why" — e.g. weakest component + gap

            $table->string('status')->default('recommended'); // recommended | in_review | approved | in_progress | completed | monitoring
            $table->text('principal_notes')->nullable();

            $table->foreignId('created_by')->constrained('users')->onDelete('restrict');
            $table->foreignId('decided_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('decided_at')->nullable();

            $table->timestamps();

            $table->index(['student_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interventions');
    }
};
