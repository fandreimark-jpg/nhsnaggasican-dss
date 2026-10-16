<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\Section;
use App\Models\Subject;
use Illuminate\Support\Collection;

/**
 * Examination Type (assessments.exam_role) — the one place its values,
 * labels and per-subject applicability are defined for the adviser screens.
 *
 * Canonical stored values are the ones GradingEngine, the ECR readers and
 * the upload Verify screen already use: st1, st2, term_exam.
 *
 * WHICH roles a subject uses comes from its grading profile, read the same
 * way GradingEngine::examinationPercentage() weighs them — never assumed:
 *   - no Examination weight (ex_weight null)   -> no Examination items at all;
 *   - a linked catalog row with role shares    -> exactly those roles
 *                                                 (e.g. a TE-only subject);
 *   - otherwise DO 015, s. 2026                -> ST1, ST2 and Term Exam (the
 *                                                 shares come from
 *                                                 exam_role_shares; a missing
 *                                                 row falls back to an equal
 *                                                 split in the engine);
 *   - DO 8, s. 2015 (explicit k12_2013)        -> no roles: DO 8 has one
 *                                                 Examination, no ST/TE split.
 *
 * Identity: an Examination role is identified by exam_role, never by the
 * item's name. One REGULAR item holds each role per subject, section, term
 * and school year (the official ECR has one ST1, one ST2 and one Term Exam
 * column); an additional-support item may share a type and is added to it
 * for the learners scored on it (workbookColumns()).
 */
class ExaminationRoles
{
    public const ROLES = ['st1', 'st2', 'term_exam'];

    /** Examination Type choices, worded as the official SSHS E-Class Record names them. */
    public const LABELS = [
        'st1'       => 'ST1 — Summative Test 1',
        'st2'       => 'ST2 — Summative Test 2',
        'term_exam' => 'Term Exam',
    ];

    /** The SSHS ECR's own column names (EcrReaderService::EX_COLUMNS) — the default Item Name. */
    public const ITEM_NAMES = [
        'st1'       => 'Summative Test 1',
        'st2'       => 'Summative Test 2',
        'term_exam' => 'Term Exam',
    ];

    public function __construct(private GradingEngine $engine = new GradingEngine())
    {
    }

    /**
     * How the Examination items of one class/term map onto the workbook's
     * ST 1 / ST 2 / TE columns (Term 1!AD:AF — one cell per learner each).
     * The ONE mapping GradingEngine, the Class Record grid and the export use.
     *
     *  - roles:      the REGULAR item of each type (one per type is the
     *                supported model, enforced on Add/Edit Assessment);
     *  - duplicates: a second REGULAR item of a type (legacy data only). It
     *                is AMBIGUOUS: the engine computes no Examination figure
     *                for the class until it is resolved — never "first wins";
     *  - support:    additional-support items of a type: ADDITIVE within that
     *                type for the learners scored on them (HANDOFF "within-term
     *                additional support is additive"; docs 04 open policy);
     *  - untyped:    items with no type while typed items exist — not counted
     *                (documented edge case), warned on the Class Record;
     *  - other:      every item that is not a regular column item (grid display).
     *
     * @param iterable<Assessment> $items Examination items of one class/term
     * @return array{roles: array<string, ?Assessment>, duplicates: array<string, list<Assessment>>, support: array<string, list<Assessment>>, untyped: list<Assessment>, other: list<Assessment>}
     */
    public static function workbookColumns(iterable $items): array
    {
        $sorted = collect($items)->sortBy(fn(Assessment $a) => sprintf('%s|%010d', $a->assessment_date?->format('Y-m-d') ?? '9999-12-31', $a->id));
        $roles = array_fill_keys(self::ROLES, null);
        $duplicates = $support = [];
        $untyped = $other = [];
        foreach ($sorted as $item) {
            $role = $item->exam_role && array_key_exists($item->exam_role, $roles) ? $item->exam_role : null;
            if ($role === null) {
                $untyped[] = $other[] = $item;
            } elseif ($item->is_additional_support) {
                $support[$role][] = $other[] = $item;
            } elseif ($roles[$role] === null) {
                $roles[$role] = $item;
            } else {
                $duplicates[$role][] = $other[] = $item;
            }
        }

        return compact('roles', 'duplicates', 'support', 'untyped', 'other');
    }

    public static function label(?string $role): ?string
    {
        return $role === null ? null : (self::LABELS[$role] ?? $role);
    }

    /**
     * @return array{has_examination: bool, roles: list<string>}
     */
    public function forContext(Section $section, Subject $subject): array
    {
        try {
            $profile = $this->engine->resolveWeightProfile($section, $subject);
        } catch (\RuntimeException) {
            // An unclassified subject cannot be graded at all yet; do not
            // invent a narrower rule here — offer the ECR's three roles.
            return ['has_examination' => true, 'roles' => self::ROLES];
        }
        if (($profile['configuration_error'] ?? null) !== null) {
            // Unconfigured (Other elective): the weights are unknown, not
            // "no Examination" — evidence is recorded, the grade is withheld.
            return ['has_examination' => true, 'roles' => self::ROLES];
        }

        if ($profile['ex_weight'] === null) {
            return ['has_examination' => false, 'roles' => []];
        }

        $catalogShares = $profile['catalog']?->examRoleShares() ?? [];
        $roles = match (true) {
            !empty($catalogShares)            => array_keys($catalogShares),
            $profile['scheme'] === 'do8_2015' => [],
            default                           => self::ROLES,
        };

        return [
            'has_examination' => true,
            'roles'           => array_values(array_intersect(self::ROLES, $roles)),
        ];
    }

    /**
     * REGULAR (not additional-support) items holding a role in one scope,
     * keyed by role — each list normally has one item.
     *
     * @return Collection<string, Collection<int, Assessment>>
     */
    public static function regularHolders(int $sectionId, int $subjectId, int $gradingPeriod, string $schoolYear, ?int $exceptId = null): Collection
    {
        return Assessment::query()
            ->where('section_id', $sectionId)
            ->where('subject_id', $subjectId)
            ->where('grading_period', $gradingPeriod)
            ->where('school_year', $schoolYear)
            ->where('component', 'examination')
            ->whereNotNull('exam_role')
            ->where('is_additional_support', false)
            ->when($exceptId, fn($q) => $q->whereKeyNot($exceptId))
            ->orderBy('id')
            ->get()
            ->groupBy('exam_role');
    }
}
