<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * AcademicYear
 * ------------
 * The explicit, admin-configurable "which school year is active"
 * record — SYSTEM_FIXES_AND_ML_AUDIT.md, "Remove Hardcoded Academic
 * Year." Only one row is ever active at a time — enforced in
 * activate() inside a transaction with the rows locked.
 *
 * Section::activeSchoolYear() reads this FIRST, falling back to its own
 * insertion-order inference only when no row here is marked active —
 * every existing caller of that method (assessments, grades, reports,
 * risk, interventions, imports, dashboards — see its own docblock)
 * therefore becomes admin-configurable automatically, with no changes
 * needed at any of those call sites.
 *
 * "Multi-school-year academic history" work order — a school year is a
 * permanent historical entity, never renamed once referenced (see
 * hasDependentRecords()) and never deleted (there is no destroy route,
 * by design). `school_year` (the 'YYYY-YYYY' string) is the join key
 * every academic table carries; the relationships below are keyed on it
 * so a year can enumerate its own terms, sections, grades, reports, risk
 * results, interventions, and enrollments without any of those tables
 * needing a second column. academic_terms and student_enrollments also
 * carry an `academic_year_id` foreign key.
 */
class AcademicYear extends Model
{
    protected $fillable = ['school_year', 'start_date', 'end_date', 'is_active'];

    protected $casts = [
        'start_date' => 'date',
        'end_date'   => 'date',
        'is_active'  => 'boolean',
    ];

    // =============================================
    // RELATIONSHIPS
    // =============================================

    public function terms()
    {
        return $this->hasMany(AcademicTerm::class)->orderBy('term');
    }

    public function enrollments()
    {
        return $this->hasMany(StudentEnrollment::class);
    }

    public function sections()
    {
        return $this->hasMany(Section::class, 'school_year', 'school_year');
    }

    public function grades()
    {
        return $this->hasMany(Grade::class, 'school_year', 'school_year');
    }

    public function reportSubmissions()
    {
        return $this->hasMany(ReportSubmission::class, 'school_year', 'school_year');
    }

    public function riskResults()
    {
        return $this->hasMany(RiskResult::class, 'school_year', 'school_year');
    }

    public function interventions()
    {
        return $this->hasMany(Intervention::class, 'school_year', 'school_year');
    }

    public function assessments()
    {
        return $this->hasMany(Assessment::class, 'school_year', 'school_year');
    }

    // =============================================
    // LIFECYCLE
    // =============================================

    public static function active(): ?self
    {
        return static::where('is_active', true)->first();
    }

    /**
     * Find-or-create the row for a school-year label. Used wherever the
     * system learns about a year from data (a term row, an enrollment)
     * before an Admin has explicitly configured it. Never activates.
     */
    public static function ensureFor(string $schoolYear): self
    {
        return static::firstOrCreate(['school_year' => $schoolYear], ['is_active' => false]);
    }

    /**
     * Makes THIS year the one active year. Runs in a transaction with
     * every academic_years row locked, so two admins clicking Activate
     * at the same moment cannot leave two rows active. Nothing about
     * the outgoing year's grades, reports, assessments, risk results,
     * interventions, or enrollments is touched — the only change to the
     * outgoing year is that any term still open there is closed, so
     * that Advisers cannot keep writing into a year that is no longer
     * the school's current one (AcademicTerm::acceptsWrites() also
     * requires the active year, as defense in depth).
     *
     * @return array{closed_terms: array<int, array{school_year: string, term: int}>}
     */
    public function activate(): array
    {
        return DB::transaction(function () {
            static::query()->lockForUpdate()->get();

            $closedTerms = [];

            foreach (static::where('is_active', true)->where('id', '!=', $this->id)->get() as $outgoing) {
                foreach (AcademicTerm::where('school_year', $outgoing->school_year)->where('is_open', true)->get() as $term) {
                    $term->update(['is_open' => false, 'closed_at' => now()]);
                    $closedTerms[] = ['school_year' => $outgoing->school_year, 'term' => (int) $term->term];
                }
            }

            static::where('is_active', true)->where('id', '!=', $this->id)->update(['is_active' => false]);
            $this->forceFill(['is_active' => true])->save();

            AcademicTerm::ensureExistFor($this->school_year);

            return ['closed_terms' => $closedTerms];
        });
    }

    /**
     * Human-readable lifecycle state for the Admin page — 'Active',
     * 'Completed' (an inactive year that precedes the active one), or
     * 'Upcoming' (an inactive year that follows it, or any inactive
     * year when nothing is active yet).
     */
    public function lifecycleStatus(): string
    {
        if ($this->is_active) {
            return 'Active';
        }

        $active = static::active();

        if ($active && strcmp($this->school_year, $active->school_year) < 0) {
            return 'Completed';
        }

        return 'Upcoming';
    }

    /**
     * True when this year is a historical (non-active) one — every
     * record under it is read-only for Advisers.
     */
    public function isHistorical(): bool
    {
        return !$this->is_active;
    }

    // =============================================
    // SELECTION (historical pages)
    // =============================================

    /**
     * Every school year a user may select on a historical page — the
     * union of configured academic_years rows and any school_year a
     * section actually carries (a database that predates academic_years
     * may have sections in a year no row was ever created for). Newest
     * first.
     *
     * @return Collection<int, string>
     */
    public static function selectableSchoolYears(): Collection
    {
        return static::query()->pluck('school_year')
            ->merge(Section::query()->distinct()->pluck('school_year'))
            ->unique()
            ->sortDesc()
            ->values();
    }

    /**
     * Resolves the school year a historical page should show: the
     * requested one if it is a real, known year, otherwise the ACTIVE
     * one. This is the ONLY place a "?school_year=" request parameter
     * is turned into a year, so an unknown or tampered value can never
     * select a year that does not exist — it silently falls back to the
     * active year.
     */
    public static function resolveSelected(?string $requested): string
    {
        $requested = is_string($requested) ? trim($requested) : null;

        if ($requested !== null && $requested !== '' && static::selectableSchoolYears()->contains($requested)) {
            return $requested;
        }

        return Section::activeSchoolYear();
    }

    /**
     * School-year strings are join keys. Any reference prevents renaming;
     * date corrections remain safe. Students and scores are protected
     * through their sections and assessments respectively.
     */
    public static function hasDependentRecords(string $schoolYear): bool
    {
        foreach ([AcademicTerm::class, Section::class, SectionSubject::class,
            Assessment::class, Grade::class, RiskResult::class,
            ReportSubmission::class, AssessmentUpload::class,
            StudentEnrollment::class, Intervention::class] as $model) {
            if ($model::where('school_year', $schoolYear)->exists()) {
                return true;
            }
        }

        return false;
    }
}
