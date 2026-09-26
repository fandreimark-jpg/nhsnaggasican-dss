<?php

namespace App\Exceptions;

/**
 * Thrown when a write would leave one learner with both a recorded score
 * and a Missing marker on the same assessment item — see
 * App\Services\AssessmentStatusService.
 */
class AssessmentStatusConflictException extends \DomainException
{
}
