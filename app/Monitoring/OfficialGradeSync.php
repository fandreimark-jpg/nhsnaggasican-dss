<?php

namespace App\Monitoring;

use App\Models\Grade;
use App\Services\AcademicMonitoringService;
use Illuminate\Support\Facades\DB;

/**
 * Keeps Subject Performance Concerns in step with OFFICIAL grades, whatever
 * wrote them (Encode Grades, Verify, Verify All, grade import,
 * dss:recompute-grades): a Grade save or delete queues its (section, school
 * year, term) once, and the Subject Performance rule runs for that scope
 * after the write COMMITS — never inside it, so a monitoring failure can
 * never undo a saved grade. A failure is report()ed; dss:check-integrity
 * (4c) and dss:evaluate-concerns show and repair the gap.
 */
final class OfficialGradeSync
{
    /** @var array<string, true> scopes already queued in this request */
    private static array $pending = [];

    public static function queue(Grade $grade): void
    {
        $key = $grade->section_id . '|' . $grade->school_year . '|' . $grade->grading_period;
        if (isset(self::$pending[$key])) {
            return;
        }
        self::$pending[$key] = true;

        [$sectionId, $schoolYear, $term] = [(int) $grade->section_id, (string) $grade->school_year, (int) $grade->grading_period];
        DB::afterCommit(function () use ($key, $sectionId, $schoolYear, $term) {
            unset(self::$pending[$key]);
            try {
                app(AcademicMonitoringService::class)->synchronizeOfficialGrades($sectionId, $schoolYear, $term);
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }
}
