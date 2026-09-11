<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * "ECR alignment" work order, PART 6 — records which elective a section
 * actually takes (and for how many terms), replacing "every elective
 * matching the section's track" for curriculum = 'sshs' sections. See
 * the creating migration's docblock and CLAUDE.md, "Elective selection
 * is per-cluster, not per-learner," for the full reasoning.
 */
class SectionSubject extends Model
{
    protected $fillable = ['section_id', 'subject_id', 'school_year', 'starting_term', 'term_count'];

    public function section()
    {
        return $this->belongsTo(Section::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * A null starting_term/term_count pair means no term data has been
     * recorded for this assignment yet — treated as "expected every
     * term," the same assumption every consumer made before this table
     * existed, not a new default invented for this method.
     */
    public function coversTerm(int $term): bool
    {
        if ($this->starting_term === null || $this->term_count === null) {
            return true;
        }

        $endTerm = $this->starting_term + $this->term_count - 1;
        return $term >= $this->starting_term && $term <= $endTerm;
    }
}
