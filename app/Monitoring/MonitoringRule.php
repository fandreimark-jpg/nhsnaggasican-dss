<?php

namespace App\Monitoring;

use App\Models\AcademicConcern;
use Illuminate\Database\Eloquent\Builder;

/**
 * A deterministic, school-approved academic monitoring rule.
 *
 * A rule reads CURRENT recorded evidence and reports what it observes. It
 * never predicts an outcome, never assigns a severity, and never creates
 * an intervention — App\Services\AcademicMonitoringService turns its
 * findings into For Review concerns and the Principal decides what to do.
 *
 * A rule runs only when it is listed in config('dss.monitoring_rules').
 * A rule that needs a numeric academic threshold (a component percentage,
 * a count of Missing items, a decline) must not be listed there until the
 * school has approved that threshold.
 */
interface MonitoringRule
{
    /** Stable identifier stored on every concern (academic_concerns.rule_key). */
    public function key(): string;

    /** Bumped whenever the rule's condition or evidence shape changes. */
    public function version(): int;

    /** Principal-facing concern type, e.g. "Missing Requirement". */
    public function label(): string;

    /**
     * Every condition this rule observes in the scope, right now.
     *
     * @return iterable<ConcernFinding>
     */
    public function findings(EvaluationScope $scope): iterable;

    /**
     * Narrows the open concerns this rule reconciles against to exactly
     * the scope findings() looked at. The service has already applied the
     * rule key, school year, term and sections; a rule adds whatever else
     * its scope narrowing means (e.g. only the changed assessment items).
     * Without this, a narrow evaluation would resolve concerns it never
     * looked at.
     */
    public function constrainOpenConcerns(Builder $query, EvaluationScope $scope): void;

    /**
     * Why an open concern of this rule no longer applies, stated from the
     * current evidence — recorded on the concern when it is resolved.
     */
    public function resolutionReason(AcademicConcern $concern): string;
}
