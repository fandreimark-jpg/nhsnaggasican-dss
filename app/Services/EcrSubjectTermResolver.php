<?php

namespace App\Services;

use App\Models\DepedSubjectCatalog;
use App\Models\Section;
use App\Models\Subject;

/**
 * THE ONE PLACE a prescribed E-Class Record is checked against the DSS
 * selection, and the one place "which academic terms does this subject
 * actually run for" is answered.
 *
 * Before this class existed, the workbook's cover cells were compared to
 * the on-screen selection in a single controller method that produced a
 * DISMISSIBLE WARNING — an adviser could read "this file says section
 * Curie but you selected Shakespeare", click through, and import one
 * class's scores onto another class's learners. Identity metadata is not
 * advisory. A file that names a different section is the wrong file, and
 * the only safe response is to refuse it.
 *
 * WHAT BLOCKS AND WHAT WARNS — the distinction is deliberate
 * ----------------------------------------------------------
 * BLOCKING (`errors`): the workbook states a value and it CONTRADICTS the
 * selection — a different section, subject, grade level or school year, or
 * a term the subject does not run in. Conflicting evidence about which
 * class a file belongs to can only be resolved by a person looking at it.
 *
 * NON-BLOCKING (`warnings`): the workbook does not state the value at all
 * (the teacher left the cover cell blank). That cannot confirm the file
 * belongs here, but neither does it prove it does not, and the real
 * instrument ships blank — refusing every workbook with an unfilled cover
 * cell would block legitimate uploads to protect against a conflict that
 * has not been demonstrated. It is surfaced in words rather than passed
 * over silently.
 *
 * This is NOT a change to the standing rule on conflicting WEIGHTS
 * (CLAUDE.md, "The rule on conflicting evidence"): a teacher-typed weight
 * cell that disagrees with the catalog is still a warning and still never
 * overwrites a grading profile. That rule is about how a grade is
 * COMPUTED. This class is about which class the file BELONGS to. A wrong
 * weight computes a grade differently; a wrong section files it under a
 * different learner.
 *
 * HOW MANY TERMS DOES A SUBJECT RUN FOR
 * -------------------------------------
 * From the workbook's own TERMS AND UNITS block, read at its source:
 *
 *  - A CATALOGUED subject: INPUT DATA!F33 is an array formula doing an
 *    XLOOKUP into HELPER. This codebase already holds that entire HELPER
 *    catalog in `deped_subject_catalog` (seeded from HELPER!J7:AC161) with
 *    `g11_terms`/`g12_terms` per grade level, so the count is read from
 *    there — the same number the formula would produce, without evaluating
 *    a formula the spreadsheet library may not support.
 *  - An OTHER ELECTIVE / SPECIAL CURRICULAR PROGRAM: the teacher types the
 *    count directly (INPUT DATA!F51), so it is read from the file.
 *
 * The starting term is INPUT DATA!H33 (or H51), a teacher-typed dropdown
 * whose allowed values are literally "FIRST TERM, SECOND TERM, THIRD TERM".
 *
 * The applicability rule is the workbook's own, quoted from HELPER!G24:G26
 * and G34:
 *
 *   1 term  -> "Accomplish only ONE (1) term sheet. Select the applicable
 *               TERM BLOCK."            => exactly the block term
 *   2 terms -> "Accomplish TWO (2) term sheets. 2-term electives cannot
 *               begin in Term 3."       => block and block+1; block 3 invalid
 *   3 terms -> "Accomplish all Term sheets."  => 1, 2 and 3
 *
 * This term check is a check of the FILE against the SELECTION. Whether
 * the subject applies to the section in the selected term at all is the
 * Admin's configuration (Terms Taught on Admin > Subjects, resolved by
 * SubjectApplicabilityService) and is enforced by the controller before
 * the file is opened. This class never writes that configuration — see
 * the note at the end of the file.
 */
class EcrSubjectTermResolver
{
    /**
     * Per-instance cache of `describe()` output, keyed by the file's path,
     * size and mtime together — a temp filename is a UUID and is never
     * reused, but keying on content identity rather than name alone means
     * a rewritten file is re-read rather than served stale.
     *
     * validate() is called on detect(), again on preview() and again on
     * import(); within one request the same file is often described more
     * than once. Parsing a 434KB seven-sheet workbook repeatedly is the
     * kind of cost that only shows up under load, so it is avoided here
     * rather than left to be discovered later.
     *
     * @var array<string, array>
     */
    private array $describeCache = [];

    public function __construct(
        private EcrReaderService $reader = new EcrReaderService(),
        private EcrProfileDetector $detector = new EcrProfileDetector(),
    ) {
    }

    private function describe(string $filePath): array
    {
        $key = $filePath . '|' . @filesize($filePath) . '|' . @filemtime($filePath);

        return $this->describeCache[$key] ??= $this->reader->describe($filePath);
    }

    /**
     * Validate a prescribed ECR against the selection the adviser made.
     *
     * Returns null when the file is not a prescribed Strengthened SHS ECR
     * at all — the flat CSV/XLSX path and the Grade 12 workbook have their
     * own readers and are not this class's business (see
     * Grade12EcrProfileDetector; CLAUDE.md, "Grade 11 vs Grade 12 format").
     *
     * @return null|array{
     *     errors: array<int, string>, warnings: array<int, string>,
     *     applicable_terms: ?array<int, int>, terms_taught: ?int,
     *     term_block: ?int, terms_source: string, metadata: array
     * }
     */
    public function validate(string $filePath, Section $section, Subject $subject, int $gradingPeriod): ?array
    {
        if ($this->detector->detect($filePath) === null) {
            return null;
        }

        $meta = $this->describe($filePath);
        $errors = [];
        $warnings = [];

        $this->checkIdentity($meta, $section, $subject, $errors, $warnings);

        $terms = $this->resolveApplicableTerms($meta, $section, $subject);

        if ($terms['applicable'] === null) {
            $warnings[] = $terms['reason'];
        } elseif (!in_array($gradingPeriod, $terms['applicable'], true)) {
            $errors[] = sprintf(
                'the workbook says %s runs for %d term%s starting in Term %d, so it does not run in Term %d (applicable: %s)',
                $subject->name,
                $terms['terms_taught'],
                $terms['terms_taught'] === 1 ? '' : 's',
                $terms['term_block'],
                $gradingPeriod,
                'Term ' . implode(', Term ', $terms['applicable'])
            );
        }

        return [
            'errors'           => $errors,
            'warnings'         => $warnings,
            'applicable_terms' => $terms['applicable'],
            'terms_taught'     => $terms['terms_taught'],
            'term_block'       => $terms['term_block'],
            'terms_source'     => $terms['source'],
            'metadata'         => $meta,
        ];
    }

    /**
     * The one sentence an adviser sees when a prescribed ECR does not
     * belong to what they selected. Returns null when nothing blocks.
     */
    public function blockingMessage(?array $result): ?string
    {
        if ($result === null || $result['errors'] === []) {
            return null;
        }

        return 'This E-Class Record does not match your selection, so nothing was imported: '
            . implode('; ', $result['errors'])
            . '. Re-select the correct section, subject and term, or upload the correct file.';
    }

    /** The non-blocking note shown on the Verify screen. Null when there is nothing to say. */
    public function advisoryMessage(?array $result): ?string
    {
        if ($result === null || $result['warnings'] === []) {
            return null;
        }

        return 'This E-Class Record could not be fully verified against your selection: '
            . implode('; ', $result['warnings'])
            . '. Check that it is the right file before you import.';
    }

    /**
     * @param array<int, string> $errors
     * @param array<int, string> $warnings
     */
    private function checkIdentity(array $meta, Section $section, Subject $subject, array &$errors, array &$warnings): void
    {
        $fileSection = trim((string) ($meta['section_name'] ?? ''));
        if ($fileSection === '') {
            $warnings[] = 'its INPUT DATA sheet does not name a section';
        } elseif (strcasecmp($fileSection, $section->name) !== 0) {
            $errors[] = "its INPUT DATA sheet names section \"{$fileSection}\" but you selected {$section->name}";
        }

        $fileSubject = trim((string) ($meta['course_title'] ?? ''));
        if ($fileSubject === '') {
            $warnings[] = 'its INPUT DATA sheet does not name a subject';
        } elseif (strcasecmp($fileSubject, $subject->name) !== 0) {
            $errors[] = "its INPUT DATA sheet names subject \"{$fileSubject}\" but you selected {$subject->name}";
        }

        $fileGrade = $meta['grade_level'] ?? null;
        if ($fileGrade === null) {
            $warnings[] = 'its INPUT DATA sheet does not state a grade level';
        } elseif ((int) $fileGrade !== (int) $section->grade_level) {
            $errors[] = "its INPUT DATA sheet says Grade {$fileGrade} but {$section->name} is Grade {$section->grade_level}";
        }

        $fileYear = $meta['school_year'] ?? null;
        if ($fileYear === null) {
            $warnings[] = 'its INPUT DATA sheet does not state a school year';
        } elseif ($fileYear !== $section->school_year) {
            $errors[] = "its INPUT DATA sheet says school year {$fileYear} but {$section->name} is a {$section->school_year} section";
        }
    }

    /**
     * @return array{applicable: ?array<int, int>, terms_taught: ?int, term_block: ?int, source: string, reason: string}
     */
    private function resolveApplicableTerms(array $meta, Section $section, Subject $subject): array
    {
        $none = fn(string $reason, string $source = 'unknown') => [
            'applicable' => null, 'terms_taught' => null, 'term_block' => null,
            'source' => $source, 'reason' => $reason,
        ];

        [$termsTaught, $source] = $this->termsTaughtFor($meta, $section, $subject);

        if ($termsTaught === null) {
            return $none(
                "the number of terms {$subject->name} is taught for could not be determined from the workbook "
                . '(it is not linked to a DepEd catalog row, and the workbook does not state it directly), '
                . 'so the term could not be verified'
            );
        }

        // Three terms covers the whole year whatever the block says — this
        // is the workbook's own rule (HELPER!G26, "Accomplish all Term
        // sheets"), and its I33 formula short-circuits the block lookup
        // entirely when the count is 3.
        if ($termsTaught === 3) {
            return [
                'applicable' => [1, 2, 3], 'terms_taught' => 3, 'term_block' => 1,
                'source' => $source, 'reason' => '',
            ];
        }

        $block = $meta['term_block'] ?? null;
        if ($block === null) {
            $label = trim((string) ($meta['term_block_label'] ?? ''));

            return $none(
                $label === ''
                    ? "the workbook says {$subject->name} runs for {$termsTaught} term(s) but its Term Block cell is blank, "
                      . 'so which term(s) it covers could not be verified'
                    : "the workbook's Term Block reads \"{$label}\", which is not one of FIRST TERM, SECOND TERM or THIRD TERM",
                $source
            );
        }

        if ($termsTaught === 1) {
            $applicable = [$block];
        } else {
            // "2-term electives cannot begin in Term 3" — HELPER!G25/G34.
            // The workbook itself labels this combination invalid, so a
            // file carrying it is malformed rather than merely unmatched.
            if ($block + $termsTaught - 1 > 3) {
                return [
                    'applicable' => [], 'terms_taught' => $termsTaught, 'term_block' => $block,
                    'source' => $source,
                    'reason' => '',
                ];
            }
            $applicable = range($block, $block + $termsTaught - 1);
        }

        return [
            'applicable' => array_values($applicable), 'terms_taught' => $termsTaught,
            'term_block' => $block, 'source' => $source, 'reason' => '',
        ];
    }

    /**
     * @return array{0: ?int, 1: string} [terms taught, where it came from]
     */
    private function termsTaughtFor(array $meta, Section $section, Subject $subject): array
    {
        // An OTHER ELECTIVE has no catalog row by definition — the order
        // publishes no entry for it, which is exactly why the workbook has
        // the teacher type the count. Trust the file here, because the file
        // is the only source that exists.
        if (!empty($meta['is_other_elective'])) {
            $typed = $meta['terms_taught'] ?? null;

            return in_array($typed, [1, 2, 3], true)
                ? [$typed, 'workbook (OTHER ELECTIVE, teacher-supplied)']
                : [null, 'unknown'];
        }

        $catalog = $this->catalogRowFor($subject, $meta);
        if (!$catalog) {
            return [null, 'unknown'];
        }

        $terms = (int) $section->grade_level === 12
            ? $catalog->g12_terms
            : $catalog->g11_terms;

        return $terms === null
            ? [null, 'unknown']
            : [(int) $terms, 'DepEd subject catalog (HELPER), Grade ' . (int) $section->grade_level];
    }

    /**
     * The subject's own catalog link first — that is the link an Admin
     * confirmed. Only if it has none does this fall back to matching the
     * workbook's course title, and then only by exact, case-insensitive
     * name, never fuzzily.
     */
    private function catalogRowFor(Subject $subject, array $meta): ?DepedSubjectCatalog
    {
        if ($subject->catalog_id) {
            return $subject->catalog()->first();
        }

        $title = trim((string) ($meta['course_title'] ?? ''));
        if ($title === '') {
            return null;
        }

        return DepedSubjectCatalog::whereRaw('LOWER(course_title) = ?', [mb_strtolower($title)])->first();
    }

    // -----------------------------------------------------------------
    // "Subject applicability" refactor (2026-09-20): this class VALIDATES
    // and never writes. The former synchronize() — which let a validated
    // workbook add a (section, subject, term) row — is gone: Admin >
    // Subjects' Terms Taught is the one source of truth for which terms a
    // subject runs in, and an upload's metadata is checked against it
    // (Adviser\AssessmentController refuses a subject that is not
    // applicable to the section and term BEFORE the file is read; this
    // class then refuses a workbook whose own TERMS AND UNITS block
    // contradicts the selected term). An upload can never rewrite the
    // configuration it is validated against.
    // -----------------------------------------------------------------
}
