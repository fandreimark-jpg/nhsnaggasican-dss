<?php

namespace App\Services;

use App\Models\TransmutationRange;
use Illuminate\Support\Facades\Log;

/**
 * The step between GradingEngine's raw weighted percentage
 * ("computed_grade" — what the component analysis and the risk
 * classifier need) and the number DepEd expects to see reported
 * ("transmuted_grade" — what goes on a report card). Never replaces the
 * raw value; GradingEngine carries both, always.
 *
 * A strict range lookup against transmutation_ranges — the table is the
 * single source of truth, never approximated by a formula here. See
 * TransmutationRangesSeeder for the seeded DO 8, s. 2015 table.
 */
class TransmutationService
{
    public const DEFAULT_SCHEME = 'do8_2015';

    /**
     * "Performance audit" pass — every scheme's bands are read ONCE per
     * instance (generation-checked against GradingEngine's evidence
     * counter, which TransmutationRange saves also bump) instead of one
     * range query plus one exists() query per transmuted grade. matchBand()
     * applies the identical predicate (min_initial <= g AND max_initial
     * >= g) to the same rows, in id order, so the band chosen is the one
     * the query returned.
     *
     * @var array<string, \Illuminate\Support\Collection<int, TransmutationRange>>
     */
    private array $bandsByScheme = [];
    private int $bandsGeneration = -1;

    /** @return \Illuminate\Support\Collection<int, TransmutationRange> */
    private function bandsFor(string $scheme): \Illuminate\Support\Collection
    {
        if ($this->bandsGeneration !== GradingEngine::evidenceGeneration()) {
            $this->bandsByScheme = [];
            $this->bandsGeneration = GradingEngine::evidenceGeneration();
        }

        return $this->bandsByScheme[$scheme] ??= TransmutationRange::where('scheme', $scheme)->orderBy('id')->get();
    }

    /** DO 015, s. 2026's adjusted table — see schemeFor() and TransmutationRangesSeeder's TODO. */
    public const SCHEME_DO015_2026 = 'do015_2026';

    /**
     * Which published grading scheme (weights AND transmutation table)
     * applies to a section.
     *
     * $curriculum decides when it is set ("ECR alignment" work order, PART
     * 3a): `sshs` -> do015_2026, `k12_2013` -> do8_2015, regardless of grade
     * level or year — that is the whole reason `sections.curriculum` exists.
     *
     * For a NULL/unknown curriculum the fallback is by SCHOOL YEAR ONLY
     * ("SSHS ECR grading correction", 2026-09-20): SY 2026-2027 onward ->
     * do015_2026 for BOTH grade levels; earlier years -> do8_2015. The
     * earlier fallback ("Grade 11 = DO 015, Grade 12 = DO 8 in the same
     * year") rested on a client communication CLAUDE.md records as "not
     * verified", and the repository's own SY 2026-2027 instruments
     * contradict it: the prescribed SSHS E-Class Record accepts Grade 11 AND
     * 12 (INPUT DATA!F24 validates "11,12") and weights every subject by its
     * catalog cluster with no grade-level rule at all (Term sheets' D12/Q12/
     * AD12 XLOOKUP HELPER by cluster + course title), and the school's own
     * Grade 12 class record (tests/Fixtures/GRADE-12-SANITIZED.xlsx,
     * 12-AGILA, SY 2026-2027) is term-based with SSHS component names and
     * a 20/60/20 split — a DO 015 cluster weight, not DO 8's 25/45/30. A
     * transition cohort that genuinely stays on the 2013 curriculum is
     * recorded by setting the section's curriculum to `k12_2013`
     * explicitly (Admin > Sections), never inferred from its grade level.
     */
    public function schemeFor(int $gradeLevel, string $schoolYear, ?string $curriculum = null): string
    {
        if ($curriculum === 'sshs') {
            return self::SCHEME_DO015_2026;
        }
        if ($curriculum === 'k12_2013') {
            return self::DEFAULT_SCHEME;
        }

        $startYear = (int) substr($schoolYear, 0, 4);

        return $startYear >= 2026 ? self::SCHEME_DO015_2026 : self::DEFAULT_SCHEME;
    }

    /**
     * A missing table (no rows at all for this scheme) or an initial
     * grade that falls outside every seeded band must degrade visibly,
     * never silently — the initial grade is returned unchanged and a
     * warning is logged, rather than fabricating a transmuted value.
     *
     * Kept as the original float-returning contract for existing callers/
     * tests — see transmuteWithAvailability() for the version that
     * distinguishes "unchanged because it's a real match" from "unchanged
     * because nothing matched," which GradingEngine needs so it can show
     * an explicit "not available" note instead of a number.
     */
    public function transmute(float $initialGrade, string $scheme = self::DEFAULT_SCHEME): float
    {
        return $this->resolve($initialGrade, $scheme)['value'];
    }

    /**
     * @return array{value: float, available: bool, provisional: bool, scheme_used: string}
     */
    public function transmuteWithAvailability(float $initialGrade, string $scheme = self::DEFAULT_SCHEME): array
    {
        return $this->resolve($initialGrade, $scheme);
    }

    /**
     * Human label for a scheme code, e.g. for a "computed using the X table
     * because Y bands are not yet entered" provisional message. Falls back
     * to the raw code for a scheme this hasn't been taught to name — never
     * throws, since this is display text, not a lookup that must succeed.
     */
    public static function schemeLabel(string $scheme): string
    {
        return match ($scheme) {
            self::DEFAULT_SCHEME => 'DO 8, s. 2015',
            self::SCHEME_DO015_2026 => 'DO 015, s. 2026',
            default => $scheme,
        };
    }

    /**
     * TASK 1 of "unblock verification" — a scheme with no band for this
     * initial grade normally makes the grade unavailable (see resolve()'s
     * fallback-less behavior below). When config('dss.transmutation_
     * fallback_scheme') names a DIFFERENT scheme that DOES have a matching
     * band, use it instead and mark the result provisional — never silent:
     * every caller gets 'provisional' => true and 'scheme_used' naming
     * which scheme's bands actually produced the number, so it can be
     * shown and later found again (see Grade::is_provisional /
     * provisional_scheme and dss:recompute-grades).
     *
     * @return array{value: float, available: bool, provisional: bool, scheme_used: string}
     */
    private function resolve(float $initialGrade, string $scheme): array
    {
        $match = $this->matchBand($scheme, $initialGrade);

        if ($match !== null) {
            return ['value' => $match, 'available' => true, 'provisional' => false, 'scheme_used' => $scheme];
        }

        $fallbackScheme = config('dss.transmutation_fallback_scheme');

        if ($fallbackScheme && $fallbackScheme !== $scheme) {
            $fallbackMatch = $this->matchBand($fallbackScheme, $initialGrade);

            if ($fallbackMatch !== null) {
                Log::warning("TransmutationService: scheme '{$scheme}' has no band for initial grade {$initialGrade} — using PROVISIONAL fallback scheme '{$fallbackScheme}' per dss.transmutation_fallback_scheme.");

                return ['value' => $fallbackMatch, 'available' => true, 'provisional' => true, 'scheme_used' => $fallbackScheme];
            }
        }

        if ($this->bandsFor($scheme)->isEmpty()) {
            Log::warning("TransmutationService: no transmutation_ranges rows exist for scheme '{$scheme}' — returning the initial grade ({$initialGrade}) unchanged.");
        } else {
            Log::warning("TransmutationService: no band in scheme '{$scheme}' matched initial grade {$initialGrade} — returning it unchanged.");
        }

        return ['value' => $initialGrade, 'available' => false, 'provisional' => false, 'scheme_used' => $scheme];
    }

    /** A strict range lookup against transmutation_ranges for ONE scheme — no fallback, no logging. */
    private function matchBand(string $scheme, float $initialGrade): ?float
    {
        $range = $this->bandsFor($scheme)->first(
            fn($r) => (float) $r->min_initial <= $initialGrade && (float) $r->max_initial >= $initialGrade
        );

        return $range ? (float) $range->transmuted : null;
    }

    /**
     * Each scheme's own CONFIRMED "passing" anchor (Initial Grade ->
     * Transmuted 75), used by dss:verify-transmutation as the cheapest
     * mistyped-table check. These are NOT the same number for every
     * scheme: do8_2015's real passing raw score is 60% (see
     * TransmutationServiceTest::test_exactly_60_transmutes_to_the_75_passing_cutoff
     * — long established and already covered by that test); do015_2026's
     * is 70% (see TransmutationRangesSeeder's TODO comment, where that
     * anchor was first entered). A scheme not listed here defaults to
     * do8_2015's 60, the longer-standing DepEd convention.
     */
    private const PASSING_ANCHOR_INITIAL = [
        self::DEFAULT_SCHEME => 60.00,
        self::SCHEME_DO015_2026 => 70.00,
    ];

    private static function passingAnchorInitial(string $scheme): float
    {
        return self::PASSING_ANCHOR_INITIAL[$scheme] ?? self::PASSING_ANCHOR_INITIAL[self::DEFAULT_SCHEME];
    }

    /**
     * TASK 2 of "unblock verification" — used by `dss:verify-transmutation`
     * and by the dashboard fallback banner (see fallbackActiveFor()) to
     * judge whether a scheme's bands are trustworthy: do they cover the
     * full 0-100 range with no gaps and no two bands claiming the same
     * initial grade (an overlap — which would let one initial grade map to
     * more than one transmuted grade, depending on lookup order), and does
     * this scheme's own confirmed passing anchor still resolve to 75.
     *
     * @return array{
     *     band_count: int,
     *     gaps: array<int, array{0: float, 1: float}>,
     *     overlaps: array<int, array{0: array{0: float, 1: float}, 1: array{0: float, 1: float}}>,
     *     covers_0_to_100: bool,
     *     anchor_initial: float,
     *     resolved_anchor: ?float,
     *     resolved_100: ?float,
     *     anchor_is_75: bool,
     * }
     */
    public function analyzeCoverage(string $scheme): array
    {
        $ranges = TransmutationRange::where('scheme', $scheme)
            ->orderBy('min_initial')
            ->get(['min_initial', 'max_initial', 'transmuted']);

        $gaps = [];
        $overlaps = [];

        if ($ranges->isEmpty()) {
            $gaps[] = [0.00, 100.00];
        } else {
            $first = $ranges->first();
            if ((float) $first->min_initial > 0.00) {
                $gaps[] = [0.00, round((float) $first->min_initial - 0.01, 2)];
            }

            for ($i = 1; $i < $ranges->count(); $i++) {
                $prev = $ranges[$i - 1];
                $curr = $ranges[$i];

                if ((float) $curr->min_initial > round((float) $prev->max_initial + 0.01, 2)) {
                    $gaps[] = [round((float) $prev->max_initial + 0.01, 2), round((float) $curr->min_initial - 0.01, 2)];
                } elseif ((float) $curr->min_initial <= (float) $prev->max_initial) {
                    $overlaps[] = [
                        [(float) $prev->min_initial, (float) $prev->max_initial],
                        [(float) $curr->min_initial, (float) $curr->max_initial],
                    ];
                }
            }

            $last = $ranges->last();
            if ((float) $last->max_initial < 100.00) {
                $gaps[] = [round((float) $last->max_initial + 0.01, 2), 100.00];
            }
        }

        $anchorInitial = self::passingAnchorInitial($scheme);
        $resolvedAnchor = $this->matchBand($scheme, $anchorInitial);

        return [
            'band_count' => $ranges->count(),
            'gaps' => $gaps,
            'overlaps' => $overlaps,
            'covers_0_to_100' => $ranges->isNotEmpty()
                && (float) $ranges->first()->min_initial <= 0.00
                && (float) $ranges->last()->max_initial >= 100.00
                && empty($gaps),
            'anchor_initial' => $anchorInitial,
            'resolved_anchor' => $resolvedAnchor,
            'resolved_100' => $this->matchBand($scheme, 100.00),
            'anchor_is_75' => $resolvedAnchor !== null && abs($resolvedAnchor - 75.0) < 0.005,
        ];
    }

    /**
     * Whether the dashboard fallback banner should call out THIS grade
     * level/school year as currently running on a provisional scheme —
     * true only when a fallback is configured, this grade level's real
     * scheme is a DIFFERENT scheme than the fallback, and that real scheme
     * does not yet cover 0-100 cleanly (so at least some grades in it will
     * actually need the fallback).
     */
    public function fallbackActiveFor(int $gradeLevel, string $schoolYear, ?string $curriculum = null): bool
    {
        $fallbackScheme = config('dss.transmutation_fallback_scheme');

        if (!$fallbackScheme) {
            return false;
        }

        // Same resolution as grading: an explicit section curriculum wins
        // over the school-year inference.
        $scheme = $this->schemeFor($gradeLevel, $schoolYear, $curriculum);

        if ($scheme === $fallbackScheme) {
            return false;
        }

        return !$this->analyzeCoverage($scheme)['covers_0_to_100'];
    }
}
