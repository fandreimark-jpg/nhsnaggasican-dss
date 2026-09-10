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
     * Get the subjects applicable to a given section — core subjects for
     * that grade level, plus elective subjects matching the section's
     * track/specialization. Shared logic used by both grade encoding
     * AND the term-completion check, so both always count the same set.
     */
    public static function forSection(Section $section)
    {
        return static::where('grade_level', $section->grade_level)
            ->where(function ($query) use ($section) {
                $query->where('type', 'core')
                    ->orWhere(function ($q) use ($section) {
                        $q->where('type', 'elective')
                          ->where('track_id', $section->track_id)
                          ->where(function ($q2) use ($section) {
                              $q2->whereNull('specialization_id')
                                 ->orWhere('specialization_id', $section->specialization_id);
                          });
                    });
            });
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
        $electivesOnDefault = static::where('type', 'elective')->where('subject_group', 'core_academic')->get();

        $catalogLinked = static::whereNotNull('catalog_id')->with('catalog')->get();
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

        return $electivesOnDefault->merge($mismatched)->unique('id')->values();
    }
}