<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * AcademicTerm
 * -------------
 * Controls which grading period (1, 2, or 3) is currently open for
 * grade encoding, system-wide, for a given school year. Only ONE
 * term can be open at a time — enforced in AcademicTermController.
 */
class AcademicTerm extends Model
{
    protected $fillable = ['school_year', 'term', 'is_open', 'opened_at', 'closed_at'];

    protected $casts = [
        'is_open'   => 'boolean',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    /**
     * Make sure rows for Term 1, 2, 3 exist for this school year.
     * A brand-new school year starts with Term 1 already open.
     */
    public static function ensureExistFor(string $schoolYear): void
    {
        foreach ([1, 2, 3] as $term) {
            static::firstOrCreate(
                ['school_year' => $schoolYear, 'term' => $term],
                ['is_open' => $term === 1]
            );
        }
    }

    /** Which term number is open right now? Null if none. */
    public static function currentOpenTerm(string $schoolYear): ?int
    {
        return static::where('school_year', $schoolYear)
            ->where('is_open', true)
            ->value('term');
    }

    public static function isOpen(string $schoolYear, int $term): bool
    {
        return static::where('school_year', $schoolYear)
            ->where('term', $term)
            ->where('is_open', true)
            ->exists();
    }

    /**
     * Is this term FULLY encoded across every section for this school year?
     * "Fully encoded" = every student in every section has a grade for
     * every subject applicable to their section, in this term.
     *
     * Returns ['complete' => bool, 'incomplete_sections' => [...]]
     */
    public static function completionStatus(string $schoolYear, int $term): array
    {
        $sections = Section::where('school_year', $schoolYear)->get();
        $incomplete = [];

        foreach ($sections as $section) {
            $studentCount = Student::where('section_id', $section->id)->count();
            $subjectCount = Subject::forSection($section)->count();
            $expected     = $studentCount * $subjectCount;

            // Nothing to require yet (no students or no subjects assigned) — skip.
            if ($expected === 0) continue;

            $actual = Grade::where('section_id', $section->id)
                ->where('grading_period', $term)
                ->where('school_year', $schoolYear)
                ->count();

            if ($actual < $expected) {
                $incomplete[] = [
                    'section'  => $section->name . ' — Grade ' . $section->grade_level,
                    'encoded'  => $actual,
                    'expected' => $expected,
                ];
            }
        }

        return [
            'complete'            => empty($incomplete),
            'incomplete_sections' => $incomplete,
        ];
    }
}