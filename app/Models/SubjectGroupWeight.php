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
     * @throws \RuntimeException if neither is seeded — a data gap to fix
     * by seeding a row, never silently guessed at.
     */
    public static function resolve(string $scheme, ?string $subjectGroup): self
    {
        $group = $subjectGroup ?: 'core_academic';

        $weights = static::where('scheme', $scheme)->where('subject_group', $group)->first()
            ?? static::where('scheme', $scheme)->where('subject_group', 'all')->first();

        if (!$weights) {
            throw new \RuntimeException(
                "No subject_group_weights row for scheme '{$scheme}' (subject group '{$group}' or 'all') — seed one before computing grades."
            );
        }

        return $weights;
    }
}
