<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Subject Model
 *
 * Represents a SHS subject at Naggasican NHS.
 *
 * Two types:
 * - 'core'     → applies to ALL sections of the same grade level
 *                (e.g. Effective Communication, General Mathematics)
 * - 'elective' → specific to a track and optionally a specialization
 *                (e.g. Programming — ICT only)
 *
 * Grade levels: 11 or 12 only (Senior High School).
 */
class Subject extends Model
{
    use \App\Models\Concerns\ProtectsAcademicHistory;

    use HasFactory;

    // Fields that can be mass-assigned
    protected $fillable = [
        'name',               // e.g. 'General Mathematics'
        'type',               // 'core' or 'elective'
        'grade_level',        // 11 or 12
        'subject_group',      // which subject_group_weights row applies — see that table's migration
        'catalog_id',         // linked deped_subject_catalog row, if any — see that model's docblock; wins over subject_group in GradingEngine
        'track_id',           // null for core, required for elective
        'specialization_id',  // null if applies to whole track, specific if specialization-only
    ];

    // =============================================
    // RELATIONSHIPS
    // =============================================

    /**
     * Subject belongs to a track (only for elective subjects)
     * Core subjects have track_id = null
     */
    public function track()
    {
        return $this->belongsTo(Track::class);
    }

    /** Linked DepEd Strengthened SHS catalog row, if this subject is actually in that curriculum — see DepedSubjectCatalog. */
    public function catalog()
    {
        return $this->belongsTo(DepedSubjectCatalog::class, 'catalog_id');
    }

    /**
     * Subject belongs to a specialization (optional, even for electives)
     * If specialization_id is null — applies to all specializations in the track
     */
    public function specialization()
    {
        return $this->belongsTo(Specialization::class);
    }

    /** Subject has many grade records (one per student per term) */
    public function grades()
    {
        return $this->hasMany(Grade::class);
    }

    /** Subject has many individual assessment items (evidence, not the official grade) */
    public function assessments()
    {
        return $this->hasMany(Assessment::class);
    }

    /**
     * TERMS TAUGHT ("Subject applicability" refactor, 2026-09-20) — one
     * SubjectTerm row per academic term this subject is taught in, set on
     * Admin > Subjects. The third leg of SubjectApplicabilityService's
     * rule: a subject reaches a section only in the terms listed here.
     */
    public function terms()
    {
        return $this->hasMany(SubjectTerm::class)->orderBy('term');
    }

    /** @return array<int, int> ascending term numbers this subject is taught in */
    public function termNumbers(): array
    {
        return $this->terms->pluck('term')->map(fn($t) => (int) $t)->sort()->values()->all();
    }

    public function isTaughtIn(int $term): bool
    {
        return in_array($term, $this->termNumbers(), true);
    }

    /** "T1, T2, T3" — the short label the Admin lists use. */
    public function termsLabel(): string
    {
        $numbers = $this->termNumbers();

        return $numbers === [] ? 'No term set' : implode(', ', array_map(fn($t) => 'T' . $t, $numbers));
    }

    /**
     * Replaces this subject's Terms Taught with exactly $terms. Rows are
     * added and removed individually so an unchanged term keeps its row
     * (and its created_at). History protection is the CALLER's job —
     * Admin\SubjectController checks SubjectApplicabilityService::
     * historyConflicts() before ever reaching this.
     *
     * @param array<int, int> $terms
     */
    public function syncTerms(array $terms): void
    {
        $wanted = collect($terms)->map(fn($t) => (int) $t)->unique()->sort()->values();
        $current = $this->terms()->pluck('term')->map(fn($t) => (int) $t);

        foreach ($current->diff($wanted) as $term) {
            $this->terms()->where('term', $term)->delete();
        }
        foreach ($wanted->diff($current) as $term) {
            $this->terms()->create(['term' => $term]);
        }

        $this->unsetRelation('terms');
    }

    /**
     * The subjects a section takes — optionally in ONE academic term.
     * Shared by grade encoding, assessment upload, the term-completion
     * check, and everywhere else that needs "what does this section
     * take," so they all count the same set. Returns a query builder,
     * not a collection — every caller appends ->get()/->pluck()/->count()
     * itself.
     *
     * "Subject applicability" refactor (2026-09-20) — the rule lives in
     * ONE place, SubjectApplicabilityService::query(): core subjects of the
     * grade level, plus electives reached by the section's track /
     * specialization (k12_2013 strands) or chosen for the section
     * (section_subjects), each only in the terms the subject is TAUGHT
     * (Terms Taught on Admin > Subjects). The former "term-managed"
     * path, where section_subjects rows replaced the curriculum outright
     * and had to be assigned per section per term, is gone; the table now
     * records section elective choices only. See that service's docblock.
     */
    public static function forSection(Section $section, ?int $term = null)
    {
        return (new \App\Services\SubjectApplicabilityService())->query($section, $term);
    }

    /**
     * The single yes/no every Adviser write path asks before touching a
     * subject: is this subject offered to this section in this term?
     * A crafted request naming a subject the section takes in Term 1
     * only, posted against Term 2, gets false here and is refused with
     * SubjectOfferingService::notOfferedMessage().
     */
    public static function isOfferedTo(Section $section, int $term, int $subjectId): bool
    {
        return static::forSection($section, $term)->where('id', $subjectId)->exists();
    }

    /**
     * "ECR alignment" work order, PART 4b — every subject whose stored
     * `subject_group` looks suspect, by one of two independent signals:
     *
     *  1. An elective still sitting on `core_academic` — the silent
     *     import/column default. `core_academic` is meant for core subjects
     *     (and, per the catalog, a handful of academic electives that
     *     genuinely are 20/50/30) — an elective landing there almost always
     *     means nobody ever set it.
     *  2. A subject linked to a DepEd catalog row (`catalog_id` set) whose
     *     own weights disagree with what `SubjectGroupWeight::resolve()`
     *     gives for the subject's STORED `subject_group`. The catalog
     *     already wins in `GradingEngine` when linked (see that class), so
     *     this isn't a grading error — it's a stale/misleading `subject_group`
     *     value that no longer describes what's actually driving the grade.
     *
     * The comparison is weights-to-weights, not slug-to-slug:
     * `DepedSubjectCatalog` has no `subject_group`-equivalent field, so
     * there is no "catalog implies this slug" mapping to check against —
     * only whether the two sources of weight actually agree. Shared by
     * `DashboardAnalyticsService::getDataHealthChecks()` (the count) and
     * `Admin\SubjectController::index()`'s `?subject_group_check=1` filter
     * (the list) — one method, one place, so they can't drift apart the
     * way the "Awaiting Your Decision" duplicate once did.
     *
     * See CLAUDE.md for the real scale of this: 101 of 139 comparable
     * subjects in the full DepEd catalog disagree with the silent
     * `core_academic` default.
     */
    public static function withSuspectSubjectGroup(): \Illuminate\Support\Collection
    {
        // "Subject Group for both grade levels" pass — a subject with NO
        // group is a configuration gap at either grade level (the form now
        // requires one for Grade 11 and Grade 12 alike; only data created
        // before that rule can still be null). Listed, never backfilled.
        $unclassified = static::whereNull('subject_group')->get();

        $electivesOnDefault = static::where('type', 'elective')->where('subject_group', 'core_academic')->get();

        $catalogLinked = static::whereNotNull('catalog_id')->whereNotNull('subject_group')->with('catalog')->get();
        $mismatched = $catalogLinked->filter(function (self $subject) {
            $catalog = $subject->catalog;
            if (!$catalog || $catalog->teacher_supplied) {
                return false;
            }

            $stored = SubjectGroupWeight::resolve('do015_2026', $subject->subject_group);
            $catalogEx = $catalog->ex_weight !== null ? (float) $catalog->ex_weight : null;
            $storedEx = $stored->ex_weight !== null ? (float) $stored->ex_weight : null;

            return (float) $catalog->ww_weight !== (float) $stored->ww_weight
                || (float) $catalog->pt_weight !== (float) $stored->pt_weight
                || $catalogEx !== $storedEx;
        });

        return $unclassified->merge($electivesOnDefault)->merge($mismatched)->unique('id')->values();
    }
}