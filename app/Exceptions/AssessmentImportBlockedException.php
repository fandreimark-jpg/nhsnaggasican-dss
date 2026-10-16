<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An assessment upload holds a value that must not be imported as it stands
 * — a score that is not a number, is negative or exceeds the item's maximum;
 * a learner listed twice; or scores for an LRN that is not enrolled in the
 * section. Importing the rest and skipping these would leave a learner's
 * real result silently absent, so the whole file is refused before anything
 * is written (AssessmentUploadService::blockingProblems()).
 */
class AssessmentImportBlockedException extends RuntimeException
{
    /** @param list<string> $problems */
    public function __construct(private readonly array $problems)
    {
        parent::__construct('Nothing was imported: this file has ' . count($problems)
            . ' problem(s) that must be corrected in the file first. Fix them and upload it again.');
    }

    /** @return list<string> */
    public function problems(): array
    {
        return $this->problems;
    }
}
