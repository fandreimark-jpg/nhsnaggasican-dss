<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\AssessmentLearnerStatus;
use App\Models\AssessmentScore;
use App\Models\Grade;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Class Record — learners as rows, assessment items as columns,
 * grouped by component — built ONCE here for the page, the batch save
 * response, the Excel export and the copy-as-TSV text, so the four can
 * never show different numbers ("Subject Teacher and Class Record" pass).
 *
 * Nothing is calculated here that GradingEngine does not already
 * calculate. Per learner the record carries GradingEngine::computeGrade()
 * (component percentages, weighted contributions, initial grade and the
 * transmuted grade it produced) and the raw earned/possible points from
 * GradingEngine::scoredItems() — the same scored items the engine summed.
 * A computed figure is PROVISIONAL; the official grade is a verified
 * `grades` row, labelled as such, never inferred from the computation.
 *
 * Evidence states per cell (AssessmentStatusService): a score (0 included)
 * = Completed; an explicit marker = Missing; neither = No Evidence Yet.
 */
class ClassRecordService
{
    public const COMPONENTS = [
        'written_work'     => 'Written Work',
        'performance_task' => 'Performance Task',
        'examination'      => 'Examination',
    ];

    /** The one textual token for an explicit Missing marker in copied/exported cells. */
    public const MISSING_TOKEN = 'MISSING';

    public function __construct(private ?PerformanceAnalysisService $analysis = null)
    {
        $this->analysis ??= new PerformanceAnalysisService();
    }

    /**
     * @return array{
     *   section: Section, subject: Subject, term: int, school_year: string,
     *   profile: array, groups: array<string, Collection<int, Assessment>>, items: Collection<int, Assessment>,
     *   students: Collection<int, Student>, scores: array, missing: array, computed: array<int, array>,
     *   official: array<int, ?Grade>
     * }
     */
    public function build(Section $section, Subject $subject, int $term, array $overlay = []): array
    {
        $schoolYear = $section->school_year;
        $engine = $this->analysis->engine();
        $profile = $engine->resolveWeightProfile($section, $subject);
        if ($overlay !== []) {
            $engine->overlayScores($section, $term, $schoolYear, $overlay);
        }

        $items = Assessment::where('section_id', $section->id)
            ->where('subject_id', $subject->id)
            ->where('grading_period', $term)
            ->where('school_year', $schoolYear)
            ->get()
            ->sortBy(fn(Assessment $a) => sprintf('%d|%s|%010d',
                array_search($a->component, array_keys(self::COMPONENTS), true),
                $a->assessment_date?->format('Y-m-d') ?? '9999-12-31',
                $a->id))
            ->values();

        $groups = [];
        foreach (array_keys(self::COMPONENTS) as $component) {
            $weight = $profile[match ($component) { 'written_work' => 'ww_weight', 'performance_task' => 'pt_weight', default => 'ex_weight' }];
            $groupItems = $items->where('component', $component)->values();
            // A component with no weight in the profile is shown only if an
            // item was recorded under it anyway (so nothing is hidden).
            if ($weight !== null || $groupItems->isNotEmpty()) {
                $groups[$component] = $groupItems;
            }
        }

        // The workbook lists learners in a MALE block then a FEMALE block
        // (Term 1!B16 / B67); the recorded gender groups them, never a guess.
        $students = $this->roster($section)
            ->sortBy(fn(Student $s, int $i) => sprintf('%d|%06d', self::GENDER_ORDER[$s->gender] ?? 2, $i))
            ->values();

        $scores = [];
        foreach (AssessmentScore::whereIn('assessment_id', $items->pluck('id'))->get(['assessment_id', 'student_id', 'score']) as $row) {
            $scores[(int) $row->assessment_id][(int) $row->student_id] = (float) $row->score;
        }
        $missing = [];
        foreach (AssessmentLearnerStatus::whereIn('assessment_id', $items->pluck('id'))
                     ->where('status', AssessmentLearnerStatus::STATUS_MISSING)->get(['assessment_id', 'student_id']) as $row) {
            $missing[(int) $row->assessment_id][(int) $row->student_id] = true;
        }
        // Preview: the draft values as they would stand after Save — a
        // score replaces Missing; a blank becomes Missing.
        foreach ($overlay as $aid => $byStudent) {
            foreach ($byStudent as $sid => $value) {
                if ($value === null) {
                    unset($scores[$aid][$sid]);
                    $missing[$aid][$sid] = true;
                } else {
                    $scores[$aid][$sid] = (float) $value;
                    unset($missing[$aid][$sid]);
                }
            }
        }

        $official = Grade::where('section_id', $section->id)
            ->where('subject_id', $subject->id)
            ->where('grading_period', $term)
            ->where('school_year', $schoolYear)
            ->get()
            ->keyBy('student_id');

        $computed = [];
        foreach ($students as $student) {
            $computed[$student->id] = $this->computedFor($student, $subject, $section, $term, $profile);
        }

        $officialByStudent = $students->mapWithKeys(fn($s) => [$s->id => $official->get($s->id)])->all();
        $display = [];
        foreach ($students as $student) {
            $display[$student->id] = self::displayRow($computed[$student->id], $officialByStudent[$student->id], $items, $scores, $missing, $student->id);
        }

        return [
            'section'     => $section,
            'subject'     => $subject,
            'term'        => $term,
            'school_year' => $schoolYear,
            'profile'     => $profile,
            'groups'      => $groups,
            'items'       => $items,
            'students'    => $students,
            'scores'      => $scores,
            'missing'     => $missing,
            'computed'    => $computed,
            'official'    => $officialByStudent,
            'display'     => $display,
            'teacher'     => (new TeachingAccess())->teacherFor($section, $subject->id, $term),
            'columns'     => self::templateColumns($items),
            'exam_shares' => $engine->examRoleShares($section, $subject),
        ];
    }

    /** Numbered WW / PT slots in the client workbook (Term 1!D13:M13, Q13:Z13). */
    public const TEMPLATE_SLOTS = 10;

    /** Workbook Examination columns ST 1 / ST 2 / TE (Term 1!AD13:AF13) => Examination Type. */
    public const EXAM_COLUMNS = ['st1' => 'ST 1', 'st2' => 'ST 2', 'term_exam' => 'TE'];

    private const GENDER_ORDER = ['male' => 0, 'female' => 1];

    /** Evidence-history source of a Missing marker set because a saved cell was blank. */
    public const AUTO_MISSING_SOURCE = 'class_record_auto_missing';

    /**
     * Where each real assessment sits in the client's template grid.
     * WW / PT: items in record order fill numbered slots 1..10; a slot with
     * no item is an inactive placeholder (null), and items beyond ten get
     * slots 11, 12, … — never hidden or dropped. Examination: the first
     * regular item of each Examination Type fills ST 1 / ST 2 / TE; every
     * other Examination item (a second item of a type, an additional-support
     * item, an item with no type) is listed after TE under its own name.
     *
     * @return array{written_work: list<?Assessment>, performance_task: list<?Assessment>, exam_roles: array<string, ?Assessment>, exam_other: list<Assessment>}
     */
    public static function templateColumns(Collection $items): array
    {
        $slots = function (string $component) use ($items) {
            $list = $items->where('component', $component)->values()->all();

            return array_pad($list, max(self::TEMPLATE_SLOTS, count($list)), null);
        };

        // The same mapping GradingEngine puts into the workbook formula.
        ['roles' => $roles, 'other' => $other] = ExaminationRoles::workbookColumns($items->where('component', 'examination'));

        return [
            'written_work'     => $slots('written_work'),
            'performance_task' => $slots('performance_task'),
            'exam_roles'       => $roles,
            'exam_other'       => $other,
        ];
    }

    /**
     * The read-only computed cells of one learner's row, as TEXT — the one
     * formatting used by the page and by the save response, so the browser
     * never calculates anything. Keys: "{component}:total|ps|ws" for each
     * component, plus "grade:initial|transmuted|official|evidence" and a
     * "...:note" line under the grade cells.
     *
     * @return array<string, string>
     */
    public static function displayRow(array $computed, ?Grade $official, Collection $items, array $scores, array $missing, int $studentId): array
    {
        $num = fn($v) => $v === null ? '—' : number_format((float) $v, 2);
        $out = [];
        $labelOf = fn(int $id) => $items->firstWhere('id', $id)?->name ?? ('#' . $id);
        foreach (array_keys(self::COMPONENTS) as $component) {
            $c = $computed['components'][$component] ?? null;
            $scored = $c && $c['earned'] !== null;
            $out[$component . ':total'] = $scored ? self::fmt($c['earned']) . ' / ' . self::fmt($c['possible']) : '—';
            $out[$component . ':ps'] = $num($c['percentage'] ?? null);
            $out[$component . ':ws'] = $num($c['contribution'] ?? null);

            // "…:why" — a short, factual reason read from the engine's own
            // output, used as the cell's hover/assistive text. Never a guess.
            $out[$component . ':total:why'] = $scored
                ? 'Points earned / total HPS of the assessments in this component (workbook TOTAL). A blank or Missing entry earns 0 but its HPS still counts; a recorded 0 is a score. An additional-support item counts (points and HPS) only for the learners with a score on it.'
                : 'No numeric score in this component yet — blank and Missing entries alone give no TOTAL (as in the workbook).';
            $isExam = $component === 'examination';
            $workbookExam = $isExam && in_array($c['method'] ?? null, ['workbook', 'equal_split'], true);
            $unscored = array_map(fn($r) => ExaminationRoles::LABELS[$r] ?? $r, $c['unscored_roles'] ?? []);
            $unmapped = array_map($labelOf, $c['unmapped'] ?? []);
            $ps = $c['percentage'] ?? null;
            $out[$component . ':ps:why'] = match (true) {
                ($c['blockers'] ?? []) !== []  => 'No Examination figure: ' . implode(' ', $c['blockers']),
                $ps !== null && $workbookExam => 'Examination PS = (WS ST1 + WS ST2 + WS TE) ÷ the sum of the configured shares of this subject × 100 — a type not given or not scored adds nothing; nothing is rescaled.'
                    . (($c['method'] ?? null) === 'equal_split' ? ' No shares are configured for this profile, so the types given share equally.' : '')
                    . ($unscored !== [] ? ' Not yet scored: ' . implode(', ', $unscored) . ' — the Term Grade waits for it.' : ''),
                $ps !== null && $isExam        => 'Examination PS over the Examination items (no Examination Type shares apply to this subject).',
                $ps !== null                   => 'Percentage Score = TOTAL ÷ total HPS × 100.',
                $items->where('component', $component)->contains(fn($i) => (float) $i->max_score <= 0) && ($scored || $workbookExam)
                    => 'An assessment in this component has a Highest Possible Score of 0, so no percentage can be computed. Correct its HPS (Edit Assessment).',
                $workbookExam                  => 'No Examination Type has a numeric score yet.',
                default                        => 'No numeric score in this component yet.',
            } . ($unmapped !== [] ? ' Not in the workbook formula: ' . implode(', ', $unmapped) . '.' : '');
            $out[$component . ':ws:why'] = match (true) {
                ($c['contribution'] ?? null) !== null => 'Weighted Score = Percentage Score × the component weight, rounded to 2 decimals.',
                ($c['percentage'] ?? null) === null   => 'No Percentage Score yet.',
                default                               => 'This component is not weighted in the grading profile of this subject.',
            };
        }
        // WS ST1 / WS ST2 / WS TE: ROUND(score ÷ HPS × share, 2) (workbook AG17:AI17).
        foreach (array_keys(self::EXAM_COLUMNS) as $role) {
            $out['examination:ws_' . $role] = $num($computed['exam_roles'][$role]['weighted'] ?? null);
        }

        $running = $computed['running_grade'] ?? null;
        $out['grade:initial'] = $computed['computed_grade'] !== null ? $num($computed['computed_grade']) : $num($running);
        $blockers = $computed['blockers'] ?? [];
        $out['grade:initial:note'] = match (true) {
            $computed['computed_grade'] !== null => 'provisional',
            $blockers !== [] && $running === null => 'cannot be computed',
            $running !== null                    => 'running · partial',
            $computed['item_count'] === 0        => 'no evidence',
            default                              => 'incomplete',
        };
        $out['grade:initial:why'] = (match (true) {
            $computed['computed_grade'] !== null => 'Initial grade from the recorded evidence. Provisional until an official grade is verified.',
            $running !== null && ($computed['workbook_complete'] ?? false)
                => 'Running Initial Grade: every component has a Weighted Score, but an Examination Type that was given has no score yet. This equals the Initial Grade of the workbook with that exam counted as nothing; the Term Grade waits for the score. Never official.',
            $running !== null                    => 'Running Initial Grade: the Weighted Scores of the components that have scores so far, added (weights are not rescaled). Not every required component has a score yet, so this is partial — it is not a Term Grade and is never official.',
            $computed['item_count'] === 0        => 'No scored item yet.',
            default                              => 'No component has a Percentage Score yet.',
        }) . ($blockers !== [] ? ' ' . implode(' ', $blockers) : '');
        $out['grade:transmuted'] = $num($computed['transmuted_grade']);
        $out['grade:transmuted:note'] = $computed['transmuted_grade'] === null ? '' : ($computed['transmutation_provisional'] ? 'provisional table' : 'provisional');
        $out['grade:transmuted:why'] = match (true) {
            $computed['transmuted_grade'] !== null => 'Report-card equivalent of the initial grade. Provisional until verified.',
            $computed['computed_grade'] === null   => 'A Term Grade is shown once every required component — and every Examination Type given — has a score.',
            default                                => 'No transmutation band is available for this grade.',
        };
        if (self::isOfficial($official)) {
            $out['grade:official'] = $num($official->grade);
            // Verified against other evidence than today's (scores changed,
            // evidence no longer complete, or a grading-rule change): say so —
            // a stale verification must never read as current.
            $out['grade:official:note'] = 'Official' . (InTermStatusService::isFailing($official) ? ' · Failed' : '') . (self::evidenceChanged($official, $computed) ? ' · evidence changed — re-verify' : '');
        } elseif ($official !== null && $official->grade !== null) {
            $out['grade:official'] = $num($official->grade);
            $out['grade:official:note'] = 'Not yet official';
        } else {
            $out['grade:official'] = '—';
            $out['grade:official:note'] = '';
        }

        // Evidence status: counted from the cells themselves, never a score.
        $blank = 0;
        $missingCount = 0;
        foreach ($items as $item) {
            if (isset($scores[$item->id][$studentId])) {
                continue;
            }
            isset($missing[$item->id][$studentId]) ? $missingCount++ : $blank++;
        }
        $out['grade:evidence'] = match (true) {
            $items->isEmpty()              => 'No assessments yet',
            $computed['item_count'] === 0  => 'No Evidence Yet',
            !$computed['complete']         => 'Partial Evidence',
            $blank > 0 || $missingCount > 0 => 'Partial Evidence',
            default                        => 'All assessments scored',
        };
        $out['grade:evidence:note'] = trim(($missingCount ? $missingCount . ' Missing' : '') . ($missingCount && $blank ? ' · ' : '') . ($blank ? $blank . ' blank' : ''));

        return $out;
    }

    /** The section's learners for its school year, in the one order every view and export uses. */
    public function roster(Section $section): Collection
    {
        return Student::enrolledIn($section)
            ->orderBy('last_name')->orderBy('first_name')->orderBy('id')
            ->get();
    }

    /**
     * GradingEngine's figures for one learner, plus earned/possible points
     * per component from the engine's own scored items.
     */
    public function computedFor(Student $student, Subject $subject, Section $section, int $term, ?array $profile = null): array
    {
        $engine = $this->analysis->engine();
        $grade = $engine->computeGrade($student, $subject, $section, $term, $section->school_year);

        // TOTAL / PS / WS straight from the engine (workbook N17/N14, O17, P17).
        $components = [];
        foreach (array_keys(self::COMPONENTS) as $component) {
            $d = $grade['component_detail'][$component];
            $components[$component] = [
                'earned'       => $d['earned'],
                'possible'     => $d['possible'],
                'percentage'   => $d['percentage'],
                // The WS of a component with evidence, shown while the term
                // is still partial (the complete grade sums these same values).
                'contribution' => $grade['partial_contributions'][$component] ?? null,
                'method'       => $d['method'] ?? 'workbook',
                'unscored_roles' => $d['unscored_roles'] ?? [],
                'unmapped'     => $d['unmapped'] ?? [],
                'blockers'     => $d['blockers'] ?? [],
            ];
        }

        return [
            'complete'                  => (bool) $grade['complete'],
            'workbook_complete'         => (bool) $grade['workbook_complete'],
            'blockers'                  => $grade['blockers'] ?? [],
            'components'                => $components,
            'exam_roles'                => $grade['exam_roles'] ?? [],
            'running_grade'             => $grade['running_grade'] ?? null,
            'computed_grade'            => $grade['computed_grade'],
            'transmuted_grade'          => $grade['transmuted_grade'],
            'transmutation_provisional' => (bool) ($grade['transmutation_provisional'] ?? false),
            'item_count'                => $engine->scoredItemCount($student, $subject, $section, $term, $section->school_year),
        ];
    }

    /**
     * Saves a batch of cell changes — ALL OR NOTHING.
     *
     * Each cell names its item, its learner, the new value, and the value
     * the browser LOADED ("base"). Inside one transaction, with the items
     * locked, every cell is validated (item in this record, learner
     * enrolled, score a number from 0 to the item's CURRENT highest
     * possible score, never a score and Missing together) and compared
     * with the value stored now. Any invalid cell or any cell whose stored
     * value is no longer its base (another tab or teacher saved first)
     * rolls the whole batch back; nothing is partly saved and nothing is
     * silently overwritten.
     *
     * @param list<array{assessment_id: int, student_id: int, score: ?string, missing: bool, base_score: ?string, base_missing: bool}> $cells
     * @return array{status: 'saved'|'invalid'|'conflict', errors?: array<string,string>, conflicts?: array<string, array>, changed?: int, assessment_ids?: list<int>}
     */
    public function saveBatch(Section $section, Subject $subject, int $term, array $cells, int $userId, ?string $reason = null): array
    {
        return DB::transaction(function () use ($section, $subject, $term, $cells, $userId, $reason) {
            $itemIds = array_values(array_unique(array_map(fn($c) => (int) $c['assessment_id'], $cells)));
            $items = Assessment::whereIn('id', $itemIds)
                ->where('section_id', $section->id)
                ->where('subject_id', $subject->id)
                ->where('grading_period', $term)
                ->where('school_year', $section->school_year)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $enrolled = $this->roster($section)->keyBy('id');

            $errors = [];
            $plan = [];
            foreach ($cells as $cell) {
                $aid = (int) $cell['assessment_id'];
                $sid = (int) $cell['student_id'];
                $key = $aid . ':' . $sid;
                $item = $items->get($aid);
                if (!$item) {
                    $errors[$key] = 'This assessment is not part of this class record.';
                    continue;
                }
                if (!$enrolled->has($sid)) {
                    $errors[$key] = 'This learner is not enrolled in ' . $section->name . ' for ' . $section->school_year . '.';
                    continue;
                }
                $parsed = self::parseScore($cell['score'] ?? null, (float) $item->max_score);
                if ($parsed['error']) {
                    $errors[$key] = $parsed['error'];
                    continue;
                }
                if ((bool) ($cell['missing'] ?? false) && $parsed['value'] !== null) {
                    $errors[$key] = 'A learner can have a score or be marked Missing, not both.';
                    continue;
                }
                // AUTOMATIC MISSING (2026-10-04): a cell SUBMITTED without a
                // score is Missing. Only submitted cells are touched — a cell
                // the page did not send (another assessment, a learner not
                // loaded) is never read as blank. An ADDITIONAL-SUPPORT item
                // applies only to the learners given it (scored on it), so its
                // blank means "not given": never Missing, never a concern.
                $missing = $parsed['value'] === null && !$item->is_additional_support;
                $plan[$key] = ['item' => $item, 'sid' => $sid, 'score' => $parsed['value'], 'missing' => $missing,
                    'base_score' => self::parseScore($cell['base_score'] ?? null, PHP_FLOAT_MAX)['value'],
                    'base_missing' => (bool) ($cell['base_missing'] ?? false)];
            }

            if ($errors) {
                return ['status' => 'invalid', 'errors' => $errors];
            }

            // Stale-edit detection against what is stored NOW (items locked).
            $current = $this->currentState($itemIds, array_values(array_unique(array_column($plan, 'sid'))));
            $conflicts = [];
            foreach ($plan as $key => $p) {
                $now = $current[$key] ?? ['score' => null, 'missing' => false];
                $changedSinceLoad = !self::sameScore($now['score'], $p['base_score']) || $now['missing'] !== $p['base_missing'];
                // A cell that already holds exactly what is being saved is a
                // retried request whose first response was lost (the server
                // committed, the browser never heard back) — not a stale
                // edit. Nothing would be overwritten, so it is not a conflict.
                $alreadyApplied = self::sameScore($now['score'], $p['score']) && $now['missing'] === $p['missing'];
                if ($changedSinceLoad && !$alreadyApplied) {
                    $conflicts[$key] = ['current_score' => $now['score'], 'current_missing' => $now['missing']];
                }
            }
            if ($conflicts) {
                return ['status' => 'conflict', 'conflicts' => $conflicts];
            }

            $statuses = new AssessmentStatusService();
            $changed = 0;
            EvidenceAudit::withContext('class_record', $reason, function () use ($plan, $current, $statuses, $userId, $reason, &$changed) {
                foreach ($plan as $key => $p) {
                    $now = $current[$key] ?? ['score' => null, 'missing' => false];
                    if (self::sameScore($now['score'], $p['score']) && $now['missing'] === $p['missing']) {
                        continue;
                    }
                    $changed++;
                    if ($p['score'] !== null) {
                        $statuses->recordScore($p['item'], $p['sid'], $p['score']);
                        continue;
                    }
                    // Cleared: the score (if any) goes, never replaced by a 0,
                    // and the blank becomes Missing — audited as automatic.
                    AssessmentScore::where('assessment_id', $p['item']->id)->where('student_id', $p['sid'])->get()->each->delete();
                    if (!$p['missing']) {
                        continue; // additional support: cleared = not given
                    }
                    EvidenceAudit::withContext(self::AUTO_MISSING_SOURCE, $reason,
                        fn() => $statuses->markMissing($p['item'], $p['sid'], $userId));
                }
            });

            return ['status' => 'saved', 'changed' => $changed, 'assessment_ids' => $itemIds];
        });
    }

    /**
     * LIVE PREVIEW — READ ONLY. The computed cells each learner would show
     * if the given draft cells were saved now: the same build() and the same
     * GradingEngine as the saved page, over the stored evidence with the
     * draft values overlaid in memory (a blank draft = Missing after save,
     * which the engine excludes exactly as it excludes a saved Missing).
     * Validated like a save (item in this record, learner enrolled, score
     * 0..HPS); nothing is written — no score, marker, grade, concern or log.
     *
     * @param list<array{assessment_id: int, student_id: int, score: ?string}> $cells
     * @param list<int> $studentIds learners whose rows to return (besides those in $cells)
     * @return array{status: 'ok'|'invalid', errors?: array<string,string>, rows?: array<int, array<string,string>>}
     */
    public function preview(Section $section, Subject $subject, int $term, array $cells, array $studentIds = []): array
    {
        $items = Assessment::where('section_id', $section->id)->where('subject_id', $subject->id)
            ->where('grading_period', $term)->where('school_year', $section->school_year)
            ->get()->keyBy('id');
        $enrolled = $this->roster($section)->keyBy('id');

        $errors = [];
        $overlay = [];
        foreach ($cells as $cell) {
            $aid = (int) $cell['assessment_id'];
            $sid = (int) $cell['student_id'];
            $key = $aid . ':' . $sid;
            $item = $items->get($aid);
            $parsed = $item ? self::parseScore($cell['score'] ?? null, (float) $item->max_score) : null;
            $error = match (true) {
                !$item                   => 'This assessment is not part of this class record.',
                !$enrolled->has($sid)    => 'This learner is not enrolled in ' . $section->name . ' for ' . $section->school_year . '.',
                default                  => $parsed['error'],
            };
            if ($error) {
                $errors[$key] = $error;
                continue;
            }
            $overlay[$aid][$sid] = $parsed['value'];
        }
        if ($errors) {
            return ['status' => 'invalid', 'errors' => $errors];
        }

        // A fresh engine: the overlay lives on that instance only.
        $record = (new self(new PerformanceAnalysisService(new GradingEngine())))->build($section, $subject, $term, $overlay);
        $wanted = array_unique(array_merge(array_map('intval', $studentIds), array_map(fn($c) => (int) $c['student_id'], $cells)));

        return ['status' => 'ok', 'rows' => collect($wanted)
            ->filter(fn($sid) => isset($record['display'][$sid]))
            ->mapWithKeys(fn($sid) => [$sid => $record['display'][$sid]])->all()];
    }

    /**
     * One cell's text -> a score. Blank is "no score" (never 0). Returns an
     * error for anything that is not a number from 0 to the maximum.
     *
     * @return array{value: ?float, error: ?string}
     */
    public static function parseScore(mixed $raw, float $max): array
    {
        if ($raw === null) {
            return ['value' => null, 'error' => null];
        }
        $text = trim((string) $raw);
        if ($text === '') {
            return ['value' => null, 'error' => null];
        }
        if (!is_numeric($text)) {
            return ['value' => null, 'error' => '"' . mb_substr($text, 0, 20) . '" is not a number.'];
        }
        $value = round((float) $text, 2);
        if ($value < 0) {
            return ['value' => null, 'error' => 'A score cannot be negative.'];
        }
        if ($value > $max + 0.000001) {
            return ['value' => null, 'error' => 'A score cannot be above the highest possible score (' . self::fmt($max) . ').'];
        }

        return ['value' => $value, 'error' => null];
    }

    /** @return array<string, array{score: ?float, missing: bool}> keyed "assessmentId:studentId" */
    public function currentState(array $itemIds, array $studentIds): array
    {
        $state = [];
        foreach (AssessmentScore::whereIn('assessment_id', $itemIds)->whereIn('student_id', $studentIds)->get() as $row) {
            $state[$row->assessment_id . ':' . $row->student_id] = ['score' => (float) $row->score, 'missing' => false];
        }
        foreach (AssessmentLearnerStatus::whereIn('assessment_id', $itemIds)->whereIn('student_id', $studentIds)
                     ->where('status', AssessmentLearnerStatus::STATUS_MISSING)->get() as $row) {
            $key = $row->assessment_id . ':' . $row->student_id;
            $state[$key] = ['score' => $state[$key]['score'] ?? null, 'missing' => true];
        }

        return $state;
    }

    /**
     * A fingerprint of one item's DEFINITION as stored now (title, scope,
     * component, Examination Type, HPS, date, support flag). The Edit
     * Assessment page carries the fingerprint it was opened with; an edit
     * whose fingerprint no longer matches is refused (another tab or
     * teacher changed the definition first). Scores are not part of it —
     * they are edited only in the Class Record, with their own stale check.
     */
    public static function definitionFingerprint(Assessment $assessment): string
    {
        $assessment = $assessment->fresh() ?? $assessment;

        return sha1(json_encode([
            $assessment->id, $assessment->name, $assessment->section_id, $assessment->subject_id, $assessment->school_year,
            (int) $assessment->grading_period, self::fmt((float) $assessment->max_score), $assessment->component, $assessment->exam_role,
            (bool) $assessment->is_additional_support, $assessment->assessment_date?->format('Y-m-d'),
        ]));
    }

    /**
     * THE stale-verification rule: an official grade whose stored computed
     * figure is not what today's evidence computes (scores changed, evidence
     * no longer complete, or a grading-rule change). It stays the official
     * record — nothing is rewritten — but must never read as current evidence.
     */
    public static function evidenceChanged(Grade $official, array $computed): bool
    {
        return $computed['computed_grade'] === null || $official->computed_grade === null
            || round((float) $official->computed_grade, 2) !== round((float) $computed['computed_grade'], 2);
    }

    /** Official = verified and not provisional (SubjectResultSummary's own rule). */
    public static function isOfficial(?Grade $grade): bool
    {
        return $grade !== null && $grade->is_verified && !$grade->is_provisional && $grade->grade !== null;
    }

    public static function sameScore(?float $a, ?float $b): bool
    {
        return ($a === null && $b === null) || ($a !== null && $b !== null && abs($a - $b) < 0.005);
    }

    public static function fmt(?float $value): string
    {
        if ($value === null) {
            return '';
        }

        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    /**
     * A text value that a spreadsheet could read as a formula (=, +, -, @,
     * tab, CR) is prefixed with an apostrophe in copied text. Numbers are
     * never passed through here.
     */
    public static function safeText(?string $text): string
    {
        $text = (string) $text;

        return $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $text : $text;
    }

    /**
     * The whole record as tab-separated text (Excel paste). mode "full": a
     * header row (LRN, Learner, item names), an HPS row, then one row per
     * learner in roster order. mode "scores": only the score block (one row
     * per learner, one column per item, same order) with no labels.
     * Scores keep their number (0 stays 0), a blank stays empty, and an
     * explicit Missing marker is the text MISSING (text is ignored by
     * Excel's SUM, so it cannot turn into a zero).
     */
    public function toTsv(array $record, string $mode = 'full'): string
    {
        $items = collect($record['groups'])->flatten(1);
        $withLabels = $mode !== 'scores';
        $lines = [];
        if ($withLabels) {
            $lines[] = implode("\t", array_merge(['LRN', 'Learner'], $items->map(fn($a) => self::safeText(self::clean($a->name)))->all()));
            $lines[] = implode("\t", array_merge(['', 'Highest Possible Score'], $items->map(fn($a) => self::fmt((float) $a->max_score))->all()));
        }
        foreach ($record['students'] as $student) {
            // The LRN is sent with a leading apostrophe so Excel keeps it as
            // TEXT (and keeps its leading zeros) when pasted.
            $row = $withLabels ? ["'" . self::clean((string) $student->lrn), self::safeText(self::clean($student->last_name . ', ' . $student->first_name))] : [];
            foreach ($items as $item) {
                if (isset($record['scores'][$item->id][$student->id])) {
                    $row[] = self::fmt($record['scores'][$item->id][$student->id]);
                } elseif (isset($record['missing'][$item->id][$student->id])) {
                    $row[] = self::MISSING_TOKEN;
                } else {
                    $row[] = '';
                }
            }
            $lines[] = implode("\t", $row);
        }

        return implode("\r\n", $lines) . "\r\n";
    }

    /** Tabs and line breaks inside a name would break the grid. */
    private static function clean(string $text): string
    {
        return trim(preg_replace('/[\t\r\n]+/', ' ', $text));
    }
}
