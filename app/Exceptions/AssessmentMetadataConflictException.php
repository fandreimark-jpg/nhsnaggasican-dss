<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Pre-demo hardening, Phase 2 (2026-09-21). Thrown by
 * AssessmentUploadService::import() — inside its transaction, before any
 * Assessment or AssessmentScore row is written — when an item the upload
 * would match by name already exists with DIFFERENT protected metadata
 * (component, exam role, additional-support flag, max score). Carries
 * the conflict list AssessmentItemConflictDetector produced so the caller
 * can show every conflict in words; the transaction rolls back to
 * nothing imported.
 */
class AssessmentMetadataConflictException extends RuntimeException
{
    /**
     * @param list<array{item: string, field: string, field_label: string, stored_label: string, incoming_label: string, message: string}> $conflicts
     */
    public function __construct(private readonly array $conflicts)
    {
        parent::__construct(
            count($conflicts) . ' assessment item(s) in this upload would change metadata already recorded: '
            . implode(' ', array_column($conflicts, 'message'))
        );
    }

    /** @return list<array{item: string, field: string, field_label: string, stored_label: string, incoming_label: string, message: string}> */
    public function conflicts(): array
    {
        return $this->conflicts;
    }
}
