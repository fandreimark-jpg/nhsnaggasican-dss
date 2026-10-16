<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

trait ProtectsAcademicHistory
{
    public static function bootProtectsAcademicHistory(): void
    {
        static::deleting(function ($model) {
            if ($model->hasAcademicReferences()) {
                throw ValidationException::withMessages(['deletion' => match ($model->getTable()) {
                    'students' => 'Cannot delete this learner because academic records already exist.',
                    'users' => 'Cannot delete this account because assignments or audit history exist. Disable the account instead.',
                    default => 'Cannot delete this '.str($model->getTable())->singular().' because academic records or mappings already exist.',
                }]);
            }
        });
    }

    /** @var array<string, bool> per-process memo — a table does not appear or vanish mid-request */
    private static array $academicTableExists = [];

    private static function academicTableExists(string $table): bool
    {
        return self::$academicTableExists[$table] ??= Schema::hasTable($table);
    }

    public function hasAcademicReferences(): bool
    {
        $references = match ($this->getTable()) {
            'students' => ['grades' => ['student_id'], 'assessment_scores' => ['student_id'], 'assessment_learner_statuses' => ['student_id'], 'risk_results' => ['student_id'], 'interventions' => ['student_id'], 'academic_concerns' => ['student_id'], 'attendance_records' => ['student_id']],
            'subjects' => ['grades' => ['subject_id'], 'assessments' => ['subject_id'], 'assessment_uploads' => ['subject_id'], 'section_subjects' => ['subject_id'], 'interventions' => ['subject_id'], 'risk_results' => ['weakest_subject_id'], 'academic_concerns' => ['subject_id']],
            'sections' => ['students' => ['section_id'], 'student_enrollments' => ['section_id'], 'grades' => ['section_id'], 'assessments' => ['section_id'], 'assessment_uploads' => ['section_id'], 'report_submissions' => ['section_id'], 'section_subjects' => ['section_id'], 'risk_results' => ['section_id'], 'interventions' => ['section_id'], 'academic_concerns' => ['section_id'], 'attendance_records' => ['section_id']],
            'tracks' => ['sections' => ['track_id'], 'subjects' => ['track_id'], 'specializations' => ['track_id']],
            'specializations' => ['sections' => ['specialization_id'], 'subjects' => ['specialization_id']],
            'users' => ['sections' => ['adviser_id'], 'grades' => ['encoded_by'], 'assessments' => ['uploaded_by'], 'assessment_learner_statuses' => ['recorded_by'], 'assessment_uploads' => ['uploaded_by'], 'report_submissions' => ['submitted_by'], 'report_submission_versions' => ['submitted_by'], 'activity_logs' => ['user_id'], 'interventions' => ['created_by', 'decided_by', 'acknowledged_by', 'delivered_by'], 'academic_concerns' => ['reviewed_by'], 'attendance_records' => ['recorded_by']],
            default => [],
        };
        if ($this->getTable() === 'sections' && $this->adviser_id !== null) return true;
        foreach ($references as $table => $columns) {
            // A table whose migration has not been applied yet (a newly
            // added academic table, before the operator runs it) holds no
            // references — never a "table not found" error on delete.
            if (!self::academicTableExists($table)) continue;
            foreach ($columns as $column) {
                if (DB::table($table)->where($column, $this->getKey())->exists()) return true;
            }
        }

        return false;
    }
}
