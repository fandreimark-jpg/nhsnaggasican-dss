<?php

namespace Tests\Feature;

use App\Models\Intervention;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Master pass" PART 1.2c — every intervention created through the
 * interface today is created BY a Principal, so both the single-record
 * and bulk-record routes must stamp origin => 'principal' regardless of
 * which decision path (approved-on-creation or recommendation-only) is
 * taken.
 */
class InterventionOriginDefaultsToPrincipalTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_record_sets_origin_to_principal(): void
    {
        $principal = User::factory()->principal()->create();
        $student = Student::factory()->create();
        $subject = Subject::factory()->create();

        $this->actingAs($principal)->post('/principal/interventions', [
            'student_id'       => $student->id,
            'subject_id'       => $subject->id,
            'grading_period'   => 1,
            'recommended_type' => 'teacher_monitoring',
        ])->assertSessionHas('success');

        $this->assertSame('principal', Intervention::first()->origin);
    }

    public function test_single_record_sets_origin_to_principal_even_when_recommendation_only(): void
    {
        $principal = User::factory()->principal()->create();
        $student = Student::factory()->create();
        $subject = Subject::factory()->create();

        $this->actingAs($principal)->post('/principal/interventions', [
            'student_id'           => $student->id,
            'subject_id'           => $subject->id,
            'grading_period'       => 1,
            'recommended_type'     => 'teacher_monitoring',
            'recommendation_only'  => '1',
        ]);

        // Deferring the DECISION never changes WHO created the record.
        $this->assertSame('principal', Intervention::first()->origin);
        $this->assertSame('recommended', Intervention::first()->status);
    }

    public function test_bulk_record_sets_origin_to_principal(): void
    {
        $principal = User::factory()->principal()->create();
        $student = Student::factory()->create();
        $subject = Subject::factory()->create();

        $this->actingAs($principal)->post('/principal/interventions/bulk', [
            'subject_id'     => $subject->id,
            'grading_period' => 1,
            'included'       => [$student->id],
            'types'          => [$student->id => 'teacher_monitoring'],
            'statuses'       => [$student->id => 'At Risk'],
        ])->assertRedirect();

        $intervention = Intervention::where('student_id', $student->id)->firstOrFail();
        $this->assertSame('principal', $intervention->origin);
    }

    public function test_intervention_factory_default_is_also_principal(): void
    {
        $intervention = Intervention::factory()->create();

        $this->assertSame('principal', $intervention->origin);
        $this->assertFalse($intervention->isSystemGenerated());
    }
}
