<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrollment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * StudentEnrollmentService
 * ------------------------
 * "Multi-school-year academic history" work order, PARTS 5 and 15 — the
 * ONE place an enrollment row is written. Two entry points:
 *
 *  - syncCurrentEnrollment(): fired from Student::saved whenever
 *    section_id is set/changed, and called explicitly after bulk imports
 *    (Maatwebsite batch inserts bypass model events). Creates or
 *    corrects the enrollment row for the CURRENT section's school year
 *    only. Never touches a row for another year.
 *
 *  - enroll(): the explicit Admin "enroll / promote into a school year"
 *    action. Creates a NEW row for the target year (refusing to overwrite
 *    an existing one — a learner is in one section per year), and moves
 *    students.section_id to the new section ONLY when the target year is
 *    the active one, so the current-section pointer always names the
 *    active year's placement and a historical enrollment never becomes
 *    "current" by accident.
 */
class StudentEnrollmentService
{
    public function syncCurrentEnrollment(Student $student): ?StudentEnrollment
    {
        $section = $student->section_id ? Section::find($student->section_id) : null;

        if (!$section) {
            return null;
        }

        return $this->writeEnrollment($student, $section, 'enrolled', correctExisting: true);
    }

    /**
     * Creates enrollment rows for every learner currently pointed at
     * this section who has none for the section's school year — the
     * post-import catch-up for paths that insert students in bulk.
     */
    public function ensureForSection(Section $section): int
    {
        $missing = Student::where('section_id', $section->id)
            ->whereDoesntHave('enrollments', fn($q) => $q->where('school_year', $section->school_year))
            ->get();

        foreach ($missing as $student) {
            $this->writeEnrollment($student, $section, 'enrolled', correctExisting: false);
        }

        return $missing->count();
    }

    /**
     * Enrolls (or promotes) a learner into a section for ITS school year.
     * The old year's row is left exactly as it is; a second row is
     * created for the new year. Throws a ValidationException (rendered as
     * a normal form error) rather than silently overwriting when the
     * learner already has a row for the target year, or when the target
     * year is not a configured academic year.
     */
    public function enroll(Student $student, Section $section, ?string $status = 'enrolled'): StudentEnrollment
    {
        $schoolYear = $section->school_year;

        if (!AcademicYear::where('school_year', $schoolYear)->exists()) {
            throw ValidationException::withMessages([
                'section_id' => "School Year {$schoolYear} is not a configured academic year. Create it under Academic Terms first.",
            ]);
        }

        if ($student->enrollments()->where('school_year', $schoolYear)->exists()) {
            $existing = $student->enrollmentFor($schoolYear);
            throw ValidationException::withMessages([
                'section_id' => "{$student->last_name}, {$student->first_name} is already enrolled for School Year {$schoolYear}"
                    . ($existing?->section ? " (Section {$existing->section->name}, Grade {$existing->grade_level})" : '')
                    . '. A learner has one enrollment per school year; edit that enrollment instead.',
            ]);
        }

        return DB::transaction(function () use ($student, $section, $status) {
            $enrollment = $this->writeEnrollment($student, $section, $status ?? 'enrolled', correctExisting: false);

            if ($section->isInActiveSchoolYear() && (int) $student->section_id !== (int) $section->id) {
                // Student::saved will call syncCurrentEnrollment(), which
                // finds the row just written and leaves it as is.
                $student->forceFill(['section_id' => $section->id])->save();
            }

            return $enrollment;
        });
    }

    private function writeEnrollment(Student $student, Section $section, string $status, bool $correctExisting): StudentEnrollment
    {
        $year = AcademicYear::ensureFor($section->school_year);

        $attributes = [
            'academic_year_id' => $year->id,
            'section_id'       => $section->id,
            'grade_level'      => (int) $section->grade_level,
            'curriculum'       => $section->curriculum,
        ];

        $existing = StudentEnrollment::where('student_id', $student->id)
            ->where('school_year', $section->school_year)
            ->first();

        if ($existing) {
            if ($correctExisting) {
                $existing->fill($attributes)->save();
            }

            return $existing;
        }

        return StudentEnrollment::create(array_merge($attributes, [
            'student_id'  => $student->id,
            'school_year' => $section->school_year,
            'status'      => $status,
            'enrolled_at' => now()->toDateString(),
        ]));
    }
}
