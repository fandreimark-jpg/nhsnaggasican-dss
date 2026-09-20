<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The Written Work / Performance Task / Examination weight for one
 * (scheme, subject_group) pair — see that table's migration for why this
 * replaced `assessment_components.weight` as a single system-wide flat
 * default. `ex_weight` null means this subject group has no Examination
 * component at all (never zero — zero would mean "has one, worth
 * nothing").
 */
class SubjectGroupWeight extends Model
{
    protected $fillable = ['scheme', 'subject_group', 'ww_weight', 'pt_weight', 'ex_weight'];

    /**
     * DO 015, s. 2026's subject group names — purely a display label for
     * the slug stored here and on subjects.subject_group, never a weight
     * or share itself. One map, read by Admin > Subjects and Admin >
     * Sections > Subjects alike, so the two pages cannot label the same
     * slug differently.
     */
    public const LABELS = [
        'core_academic'        => 'Core Academic',
        'academic_other'       => 'Academic Elective',
        'field_exposure'       => 'Field Exposure',
        'arts_sports_wellness' => 'Arts, Sports & Wellness',
        'research_innovation'  => 'Research/Innovation',
        'techpro'              => 'Tech-Pro',
        'work_immersion'       => 'Work Immersion',
        // DO 8, s. 2015 (Grade 12) weighting buckets — COMPUTED by
        // GradingEngine::resolveDo8GroupKey() from a section's track and a
        // subject's type/name, never selected by a human (allGroups() is
        // scoped to do015_2026, so none of these reaches a dropdown). Named
        // here so a resolved DO 8 profile can be labelled in words.
        'do8_core'                           => 'Core Subjects',
        'do8_academic_other'                 => 'Academic Track — all other subjects',
        'do8_academic_work_immersion'        => 'Academic Track — Work Immersion / Research / Business Enterprise Simulation',
        'do8_tvl_sports_arts_other'          => 'TVL / Sports / Arts and Design — all other subjects',
        'do8_tvl_sports_arts_work_immersion' => 'TVL / Sports / Arts and Design — Work Immersion / Research / Exhibit / Performance',
        'all'                                => 'Universal fallback (section has no track)',
    ];

    /** A group not in LABELS (a future scheme's addition) still renders a readable fallback. */
    public static function labelFor(?string $group): string
    {
        if ($group === null || $group === '') {
            return '—';
        }

        return self::LABELS[$group] ?? ucwords(str_replace('_', ' ', $group));
    }

    protected $casts = [
        'ww_weight' => 'decimal:2',
        'pt_weight' => 'decimal:2',
        'ex_weight' => 'decimal:2',
    ];

    /**
     * The weights row for a subject in this scheme. Tries the subject's
     * own group first, then falls back to a scheme-wide 'all' bucket
     * (what DO 8, s. 2015 uses — one flat split for every subject,
     * unlike DO 015, s. 2026's six distinct groups) — a generic fallback,
     * not a scheme-specific special case, so a future scheme can choose
     * either shape without a code change here.
     *
     * A null/blank $subjectGroup is NEVER silently treated as
     * 'core_academic' — "subject classification and grading weights
     * cleanup" pass removed that fallback deliberately. An unclassified
     * do015_2026 subject has no group-specific row to find (there is no
     * 'all' bucket for do015_2026 — every subject group there is a real,
     * distinct split), so it falls straight through to the exception
     * below, exactly as an unseeded scheme would. A do8_2015 subject's
     * subject_group is never read by GradingEngine in the first place
     * (see resolveDo8GroupKey()); a caller that does pass one here (the
     * Principal dashboard's evidence trend) finds no do8_2015 row for a
     * DO 015 group name and falls to the 'all' bucket — the same row a
     * null reaches — so storing a group on a Grade 12 subject changes no
     * figure.
     *
     * @throws \RuntimeException if neither is seeded — a data gap to fix
     * by seeding a row (or classifying the subject), never silently
     * guessed at.
     */
    public static function resolve(string $scheme, ?string $subjectGroup): self
    {
        $weights = $subjectGroup !== null && $subjectGroup !== ''
            ? static::where('scheme', $scheme)->where('subject_group', $subjectGroup)->first()
            : null;

        $weights ??= static::where('scheme', $scheme)->where('subject_group', 'all')->first();

        if (!$weights) {
            $group = $subjectGroup ?: '(none)';
            throw new \RuntimeException(
                "No subject_group_weights row for scheme '{$scheme}' (subject group '{$group}' or 'all') — classify the subject or seed one before computing grades."
            );
        }

        return $weights;
    }

    /**
     * Every do015_2026 group a subject may actually be assigned, excluding
     * the scheme-wide 'all' fallback (that's do8_2015's bucket — a do015
     * subject always has a real, specific group, never 'all').
     */
    public static function allGroups(): array
    {
        return static::where('scheme', 'do015_2026')
            ->where('subject_group', '!=', 'all')
            ->orderBy('subject_group')
            ->pluck('subject_group')
            ->all();
    }

    /**
     * do015_2026 groups an ELECTIVE may be assigned — every group except
     * 'core_academic', which "subject classification and grading weights
     * cleanup" reserves for type=core subjects only.
     */
    public static function electiveGroups(): array
    {
        return array_values(array_diff(static::allGroups(), ['core_academic']));
    }

    /**
     * The single authoritative check for whether ($type, $gradeLevel,
     * $subjectGroup) is an internally-consistent subject classification —
     * called from Admin\SubjectController::store()/update() (the bulk
     * SubjectsImport that also called it was removed on 2026-09-20 — no
     * official file format exists for subject master data). Returns a
     * human-readable reason the combination is invalid, or null if it's
     * fine.
     *
     * "Subject Group for both grade levels" pass (2026-09-20): the rule is
     * the same for Grade 11 and Grade 12. Every subject requires an
     * explicit, type-consistent group: 'core_academic' is reserved for
     * type=core, every other seeded group is elective-only — never both,
     * never neither ("no unknown subject silently becomes Core"). Grade
     * level is NOT read here on purpose — Subject Group is the subject's
     * classification, and grade level must not decide whether it applies.
     *
     * What this does NOT change: Grade 12 GRADING. do8_2015 still weighs
     * by a SECTION's track (GradingEngine::resolveDo8GroupKey()) and never
     * reads subject_group; a Grade 12 subject's group is stored, shown
     * and edited like Grade 11's, but its WW/PT/Exam split is unchanged.
     * Deriving DO 8 weights from the group would need a DO 8 mapping that
     * no order or instrument publishes — a decision for the school, not
     * a default (see CLAUDE.md).
     */
    public static function classificationError(string $type, int $gradeLevel, ?string $subjectGroup): ?string
    {
        $subjectGroup = $subjectGroup !== '' ? $subjectGroup : null;

        if ($subjectGroup === null) {
            return "Subject Group is required for a Grade {$gradeLevel} subject — choose the group it belongs to.";
        }

        if (!in_array($subjectGroup, static::allGroups(), true)) {
            return "'{$subjectGroup}' is not a recognised subject group.";
        }

        if ($type === 'core' && $subjectGroup !== 'core_academic') {
            return "A Core subject must use the 'core_academic' subject group, not '{$subjectGroup}'.";
        }

        if ($type === 'elective' && $subjectGroup === 'core_academic') {
            return "An Elective subject cannot use 'core_academic' — choose the specific elective group it belongs to (Academic Elective, Arts/Sports/Wellness, Research/Innovation, Tech-Pro, Work Immersion, or Field Experience).";
        }

        return null;
    }
}
