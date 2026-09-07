<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One band of a published DepEd transmutation table — see
 * App\Services\TransmutationService, which is the only place that
 * should ever query this model.
 */
class TransmutationRange extends Model
{
    protected $fillable = [
        'scheme',
        'min_initial',
        'max_initial',
        'transmuted',
    ];
}
