<?php

namespace App\Services;

/**
 * "ML architecture preparation" pass, Phase 6 — format detection for the
 * FUTURE historical-dataset import (building `training_data_contract`
 * rows for ML training — see TRAINING_DATA_CONTRACT.md), distinct from
 * `AssessmentUploadService`'s current-term grading upload path, which
 * this class does not touch and is never called from.
 *
 * Detection is by workbook STRUCTURE (sheet names, marker cells), never by
 * filename — delegates to the same two structural detectors the live
 * grading path already uses and trusts (`EcrProfileDetector`,
 * `Grade12EcrProfileDetector`), so "is this a Strengthened SHS ECR" is
 * answered identically everywhere in the codebase, not by a second,
 * possibly-drifting copy of the same sheet-name/marker-cell check.
 *
 * `quarterly_ecr` and `three_term_ecr`-from-a-different-school-year are
 * listed as KNOWN, FUTURE format identifiers — not because a detector for
 * either exists (no real Q1-Q4 workbook has been provided to build a
 * structural signature against, and "guess the shape" is exactly what this
 * project's own rules forbid), but so that adding one later is a new
 * `detect*()` method plus one line in `detect()`, not a redesign of the
 * return contract every caller already codes against.
 *
 * An unrecognised workbook — including a genuine Q1-Q4 file, until a real
 * sample exists to build a detector from — returns
 * `self::UNSUPPORTED`, never a guess and never a partial read. Nothing
 * in this class extracts or imports rows; it only classifies a file, so
 * "must not partially import corrupt/incorrect data" is satisfied by
 * construction — there is no import path here to partially run.
 */
class HistoricalEcrFormatDetector
{
    public const STRENGTHENED_SHS_ECR = 'strengthened_shs_ecr';
    public const GRADE12_CLASS_RECORD = 'grade12_class_record';

    /**
     * Documented, future format identifiers with NO detector implemented
     * yet — deliberately kept separate from UNSUPPORTED so a reader of
     * this list knows these are anticipated, not merely unheard-of.
     */
    public const QUARTERLY_ECR = 'quarterly_ecr';
    public const THREE_TERM_ECR = 'three_term_ecr';

    public const UNSUPPORTED = 'unsupported_historical_ecr_format';

    /** Every identifier this detector can actually recognise today. */
    public const IMPLEMENTED_FORMATS = [
        self::STRENGTHENED_SHS_ECR,
        self::GRADE12_CLASS_RECORD,
    ];

    /** Named, anticipated, but not yet detectable — see class docblock. */
    public const PLANNED_FORMATS = [
        self::QUARTERLY_ECR,
        self::THREE_TERM_ECR,
    ];

    public function __construct(
        private EcrProfileDetector $sshsDetector = new EcrProfileDetector(),
        private Grade12EcrProfileDetector $grade12Detector = new Grade12EcrProfileDetector(),
    ) {
    }

    /**
     * @return string one of self::STRENGTHENED_SHS_ECR,
     *   self::GRADE12_CLASS_RECORD, or self::UNSUPPORTED. Never throws for
     *   an unrecognised or unreadable file — both detectors already treat
     *   that as "not this format," and this class does the same.
     */
    public function detect(string $filePath): string
    {
        if ($this->sshsDetector->detect($filePath) !== null) {
            return self::STRENGTHENED_SHS_ECR;
        }

        if ($this->grade12Detector->detect($filePath)) {
            return self::GRADE12_CLASS_RECORD;
        }

        return self::UNSUPPORTED;
    }

    /** Human-readable reason, for a Preview screen or an error message. */
    public function describe(string $format): string
    {
        return match ($format) {
            self::STRENGTHENED_SHS_ECR => 'Strengthened SHS E-Class Record (DO 015, s. 2026, Term 1-3)',
            self::GRADE12_CLASS_RECORD => 'Grade 12 Class Record (DO 8, s. 2015, Term 1-3)',
            self::QUARTERLY_ECR => 'Quarterly (Q1-Q4) E-Class Record — format recognised by name only; no structural detector built yet',
            self::THREE_TERM_ECR => 'Three-Term E-Class Record from an earlier school year — format recognised by name only; no structural detector built yet',
            default => 'Unsupported historical ECR format',
        };
    }
}
