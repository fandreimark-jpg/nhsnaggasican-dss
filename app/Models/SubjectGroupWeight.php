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
     * subject_group is never read in the first place (see
     * GradingEngine::resolveDo8GroupKey()), so null there still resolves
     * cleanly via the 'all' bucket regardless of this change.
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
     * called from both Admin\SubjectController and SubjectsImport so the
     * manual form and the bulk importer can never disagree about what's
     * allowed. Returns a human-readable reason the combination is invalid,
     * or null if it's fine.
     *
     * Grade 12 stays on do8_2015, which weighs by a SECTION's track, not a
     * subject's subject_group at all (see GradingEngine::
     * resolveDo8GroupKey()) — the only consistent value there is null; any
     * other value would imply a do015_2026 grading rule that is never
     * actually applied. Grade 11 (do015_2026) requires an explicit,
     * type-consistent group: 'core_academic' is reserved for type=core,
     * every other seeded group is elective-only — never both, never
     * neither ("no unknown subject silently becomes Core").
     */
    public static function classificationError(string $type, int $gradeLevel, ?string $subjectGroup): ?string
    {
        $subjectGroup = $subjectGroup !== '' ? $subjectGroup : null;

        if ($gradeLevel === 12) {
            return $subjectGroup !== null
                ? 'Subject Group does not apply to Grade 12 — DO 8, s. 2015 weighs by section track, not subject group. Leave it blank.'
                : null;
        }

        if ($subjectGroup === null) {
            return 'Subject Group is required for a Grade 11 (Strengthened SHS) subject — the grading weights cannot be resolved without it.';
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
