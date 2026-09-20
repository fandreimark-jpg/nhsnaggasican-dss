<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * A SECTION ELECTIVE CHOICE — one row says "this section chose this
 * elective, and the subject is taught in this academic term". The table
 * keeps its original name (section_subjects, ECR alignment PART 6) and
 * its schema (one row per section/subject/academic_terms row, unique,
 * FKs restrict; school_year carried as the join key every other academic
 * table uses — CLAUDE.md, design decision 4).
 *
 * "Subject applicability" refactor (2026-09-20) — a row no longer
 * replaces the curriculum for its section. SubjectApplicabilityService
 * resolves core subjects and track-matched electives from the subject
 * configuration for every section automatically; a row here is the one
 * per-section decision the curriculum cannot make — which elective an
 * SSHS section (no strand to match on) actually takes. Rows are written
 * by SubjectOfferingService::chooseElective() for every term the subject
 * is taught; the resolver reads "this section chose this subject" and the
 * subject's own Terms Taught decide the terms. A row for a CORE subject
 * changes nothing (core applies regardless).
 *
 * Deleting a row that already has academic records behind it is refused
 * (ProtectsAcademicHistory, with hasAcademicReferences() overridden
 * below because the records are keyed by the (section, subject, term,
 * year) tuple rather than by this row's id).
 */
class SectionSubject extends Model
{
    use \App\Models\Concerns\ProtectsAcademicHistory;

    protected $fillable = ['section_id', 'subject_id', 'school_year', 'academic_term_id'];

    public function section()
    {
        return $this->belongsTo(Section::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function academicTerm()
    {
        return $this->belongsTo(AcademicTerm::class);
    }

    /** The term NUMBER (1-3) this offering belongs to. */
    public function termNumber(): int
    {
        return (int) ($this->academicTerm?->term ?? AcademicTerm::whereKey($this->academic_term_id)->value('term'));
    }

    /**
     * One (section, subject, term) row, idempotent — the bare write
     * SubjectOfferingService::chooseElective() performs per taught term;
     * the rule-checked path an Admin goes through is that service.
     */
    public static function offer(Section $section, Subject $subject, int $term): self
    {
        $termId = (new \App\Services\SubjectOfferingService())->academicTermId($section->school_year, $term);

        return static::firstOrCreate([
            'section_id'       => $section->id,
            'subject_id'       => $subject->id,
            'academic_term_id' => $termId,
        ], [
            'school_year'      => $section->school_year,
        ]);
    }

    // =============================================
    // SCOPES
    // =============================================

    /** Every offering of a section in ITS school year. */
    public function scopeForSection(Builder $query, Section $section): Builder
    {
        return $query->where('section_id', $section->id)
            ->where('school_year', $section->school_year);
    }

    /** Restrict to one term number of a school year (joins through academic_terms). */
    public function scopeForTerm(Builder $query, string $schoolYear, int $term): Builder
    {
        return $query->whereIn('academic_term_id', function ($sub) use ($schoolYear, $term) {
            $sub->select('id')->from('academic_terms')
                ->where('school_year', $schoolYear)
                ->where('term', $term);
        });
    }

    // =============================================
    // HISTORY PROTECTION
    // =============================================

    /**
     * Overrides the trait's id-keyed lookup: the records that hang off an
     * offering are keyed by (section, subject, grading_period, school_year).
     * Any one of these existing means the offering is history and must
     * not be deleted — grades, assessment items (and through them
     * scores), assessment uploads, interventions scoped to the subject,
     * and risk results naming the subject as a learner's weakest.
     */
    public function hasAcademicReferences(): bool
    {
        return $this->academicReferenceCounts()->sum() > 0;
    }

    /**
     * @return \Illuminate\Support\Collection<string, int> record counts keyed by table
     */
    public function academicReferenceCounts(): \Illuminate\Support\Collection
    {
        $term = $this->termNumber();
        $common = fn(string $table) => DB::table($table)
            ->where('section_id', $this->section_id)
            ->where('subject_id', $this->subject_id)
            ->where('grading_period', $term)
            ->where('school_year', $this->school_year);

        return collect([
            'grades'             => $common('grades')->count(),
            'assessments'        => $common('assessments')->count(),
            'assessment_uploads' => $common('assessment_uploads')->count(),
            'interventions'      => $common('interventions')->count(),
            'risk_results'       => DB::table('risk_results')
                ->where('section_id', $this->section_id)
                ->where('weakest_subject_id', $this->subject_id)
                ->where('grading_period', $term)
                ->where('school_year', $this->school_year)
                ->count(),
        ]);
    }
}
