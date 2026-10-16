<?php

namespace App\Services;

/**
 * A refused intervention transition, with the message to show. $committed
 * marks the one case whose bookkeeping must survive the refusal (a request
 * marked superseded) — see InterventionScheduleService::decideRequest().
 */
class InterventionTransitionException extends \DomainException
{
    public function __construct(string $message, public readonly int $status = 422, public readonly bool $committed = false)
    {
        parent::__construct($message);
    }
}
