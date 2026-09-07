<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * How much of the Examination component one role (st1/st2/term_exam)
 * is worth, under a given scheme — see GradingEngine's
 * examinationPercentage() for how this combines with items that have no
 * role set, and how it renormalises when a role has no item yet.
 */
class ExamRoleShare extends Model
{
    protected $fillable = ['scheme', 'exam_role', 'share'];

    protected $casts = [
        'share' => 'decimal:2',
    ];
}
