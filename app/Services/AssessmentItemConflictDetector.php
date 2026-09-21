<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\Section;
use App\Models\Subject;

/**
 * Pre-demo hardening, Phase 2 (2026-09-21) — the one place a repeat
 * upload's column mapping is compared against assessment items that
 * already exist under the same identity.
 *
 * WHY. AssessmentUploadService::import() matches an item by (subject,
 * section, grading period, school year, name) and used to
 * updateOrCreate() it with the confirmed mapping's component, exam role,
 * additional-support flag and max score. Re-uploading a corrected
 * E-Class Record is the intended use of that match — scores are replaced
 * in place, no duplicate items. But the same match let a second upload
 * that classified "Summative Test 1" as ST2 instead of ST1, or moved an
 * item from Written Work to Performance Task, rewrite the stored item
 * SILENTLY: every recorded score for it would be re-weighted under a
 * different rule with nothing on screen saying so. The Preview named
 * which items existed; it never compared what they were.
 *
 * WHAT COUNTS AS A CONFLICT. An existing item, matched the way import()
 * matches (name, compared case-insensitively — the live MySQL collation
 * is case-insensitive, so that IS the existing identity rule), whose
 * stored value differs from the incoming value on any PROTECTED field:
 * component, exam_role, is_additional_support, max_score. Identical
 * metadata is never a conflict; a new item is never a conflict; an item
 * under another subject/section/term/year is out of scope and never
 * compared. Values are normalised first (trimmed lower-case strings, a
 * real boolean, a max score rounded to the two decimals the column
 * stores) so "20" and 20.00 do not read as different.
 *
 * WHO DECIDES. Nobody, automatically. A conflict is reported in words
 * and the upload is refused at Verify/Preview AND again inside
 * import()'s transaction; neither the stored item nor the file's
 * classification is preferred. The adviser corrects the classification
 * on the Verify screen, or edits the existing item, and retries.
 */
class AssessmentItemConflictDetector
{
    public const COMPONENT_LABELS = [
        'written_work'     => 'Written Work',
        'performance_task' => 'Performance Task',
        'examination'      => 'Examination',
    ];

    public const EXAM_ROLE_LABELS = [
        'st1'       => 'Summative Test 1',
        'st2'       => 'Summative Test 2',
        'term_exam' => 'Term Exam',
    ];

    public const FIELD_LABELS = [
        'component'             => 'Component',
        'exam_role'             => 'Exam role',
        'is_additional_support' => 'Additional support',
        'max_score'             => 'Max score',
    ];

    /** The protected fields, in the order conflicts are reported. */
    public const PROTECTED_FIELDS = ['component', 'exam_role', 'is_additional_support', 'max_score'];

    /**
     * @param array<string, array{component: string, max_score: float|int|string, exam_role?: ?string, is_additional_support?: mixed}> $columnMapping keyed by column name, the shape preview()/import() build
     * @return list<array{item: string, field: string, field_label: string, stored: mixed, incoming: mixed, stored_label: string, incoming_label: string, message: string}>
     */
    public function detect(Section $section, Subject $subject, int $gradingPeriod, string $schoolYear, array $columnMapping): array
    {
        if (empty($columnMapping)) {
            return [];
        }

        // Every item under this exact identity scope, keyed by the same
        // name comparison import()'s match resolves to on the live
        // database. Another subject, section, term or year is a different
        // item by definition and is never loaded here.
        $existingByName = Assessment::query()
            ->where('subject_id', $subject->id)
            ->where('section_id', $section->id)
            ->where('grading_period', $gradingPeriod)
            ->where('school_year', $schoolYear)
            ->get()
            ->keyBy(fn(Assessment $a) => self::normalizeName($a->name));

        $conflicts = [];

        foreach ($columnMapping as $name => $col) {
            $existing = $existingByName->get(self::normalizeName((string) $name));
            if (!$existing) {
                continue; // a new item — nothing to protect yet
            }

            $stored   = self::normalize([
                'component'             => $existing->component,
                'exam_role'             => $existing->exam_role,
                'is_additional_support' => $existing->is_additional_support,
                'max_score'             => $existing->max_score,
            ]);
            $incoming = self::normalize($col);

            foreach (self::PROTECTED_FIELDS as $field) {
                // A component conflict already explains why the exam role
                // moved (a non-Examination column carries no role); do not
                // report the consequence as a second finding.
                if ($field === 'exam_role' && $stored['component'] !== $incoming['component']) {
                    continue;
                }
                if (self::same($field, $stored[$field], $incoming[$field])) {
                    continue;
                }

                $storedLabel   = self::label($field, $stored[$field]);
                $incomingLabel = self::label($field, $incoming[$field]);

                $conflicts[] = [
                    'item'           => $existing->name,
                    'field'          => $field,
                    'field_label'    => self::FIELD_LABELS[$field],
                    'stored'         => $stored[$field],
                    'incoming'       => $incoming[$field],
                    'stored_label'   => $storedLabel,
                    'incoming_label' => $incomingLabel,
                    'message'        => self::message($field, $existing->name, $storedLabel, $incomingLabel),
                ];
            }
        }

        return $conflicts;
    }

    /**
     * One shape for both sides of the comparison, so a stored decimal
     * string and a typed "20", or a checkbox "1" and a cast boolean, are
     * compared as values rather than as representations.
     *
     * @return array{component: string, exam_role: ?string, is_additional_support: bool, max_score: float}
     */
    public static function normalize(array $col): array
    {
        $component = strtolower(trim((string) ($col['component'] ?? '')));

        $examRole = $col['exam_role'] ?? null;
        $examRole = $examRole === null ? null : strtolower(trim((string) $examRole));
        // Only an Examination item carries a role — the controller already
        // nulls the role for any other component, and a stored
        // non-Examination item has none. Normalise both sides the same way.
        if ($examRole === '' || $component !== 'examination') {
            $examRole = null;
        }

        return [
            'component'             => $component,
            'exam_role'             => $examRole,
            'is_additional_support' => filter_var($col['is_additional_support'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'max_score'             => round((float) ($col['max_score'] ?? 0), 2),
        ];
    }

    public static function normalizeName(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    private static function same(string $field, mixed $stored, mixed $incoming): bool
    {
        if ($field === 'max_score') {
            return abs((float) $stored - (float) $incoming) < 0.005;
        }

        return $stored === $incoming;
    }

    /** Human-readable value for the Verify screen and the refusal message — never a raw enum. */
    public static function label(string $field, mixed $value): string
    {
        return match ($field) {
            'component'             => self::COMPONENT_LABELS[$value] ?? (string) $value,
            'exam_role'             => $value === null ? 'no exam role' : (self::EXAM_ROLE_LABELS[$value] ?? (string) $value),
            'is_additional_support' => $value ? 'Yes' : 'No',
            'max_score'             => number_format((float) $value, 2),
            default                 => (string) $value,
        };
    }

    private static function message(string $field, string $item, string $storedLabel, string $incomingLabel): string
    {
        return match ($field) {
            'component'             => "Existing assessment item '{$item}' is classified as {$storedLabel}, but this upload classifies it as {$incomingLabel}.",
            'exam_role'             => "Existing assessment item '{$item}' has "
                . ($storedLabel === 'no exam role' ? $storedLabel : "exam role {$storedLabel}")
                . ", but this upload assigns {$incomingLabel}.",
            'is_additional_support' => $storedLabel === 'Yes'
                ? "Existing assessment item '{$item}' is recorded as additional support, but this upload marks it as a regular item."
                : "Existing assessment item '{$item}' is recorded as a regular item, but this upload marks it as additional support.",
            'max_score'             => "Existing assessment item '{$item}' has a maximum score of {$storedLabel}, but this upload sets {$incomingLabel}.",
            default                 => "Existing assessment item '{$item}' has {$storedLabel} recorded, but this upload sets {$incomingLabel}.",
        };
    }
}
