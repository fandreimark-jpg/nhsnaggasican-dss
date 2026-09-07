<?php

namespace App\Console\Commands;

use App\Models\TransmutationRange;
use App\Services\TransmutationService;
use Illuminate\Console\Command;

/**
 * TASK 2 of "Naggasican DSS: unblock verification" — the cheapest way to
 * catch a mistyped transmutation table before it reaches a report card.
 * Reports, per scheme: how many bands are seeded, whether they cover
 * 0-100 without gaps or overlaps (an overlap is exactly "one initial
 * grade maps to more than one transmuted grade" — two bands both
 * claiming it), and what 70.00 and 100.00 resolve to. See
 * TransmutationService::analyzeCoverage(), which does the actual work —
 * this command only formats and reports it.
 *
 * Exits non-zero on any gap, any overlap, or a scheme's own confirmed
 * passing anchor no longer resolving to 75 — that last check is the one
 * that matters most. NOTE: the anchor initial grade is NOT the same
 * number for every scheme (do8_2015's is 60, do015_2026's is 70 — see
 * TransmutationService::PASSING_ANCHOR_INITIAL); it is each scheme's own
 * confirmed, already-tested cut-off, not a single universal number.
 */
class VerifyTransmutationCommand extends Command
{
    protected $signature = 'dss:verify-transmutation {scheme? : Check only this scheme; omit to check every known scheme}';

    protected $description = 'Report transmutation_ranges coverage per scheme and fail loudly on gaps, overlaps, or a wrong 70->75 anchor.';

    public function handle(TransmutationService $transmutation): int
    {
        $requestedScheme = $this->argument('scheme');

        $schemes = $requestedScheme
            ? collect([$requestedScheme])
            : collect([TransmutationService::DEFAULT_SCHEME, TransmutationService::SCHEME_DO015_2026])
                ->merge(TransmutationRange::query()->distinct()->pluck('scheme'))
                ->unique()
                ->values();

        $rows = [];
        $failed = false;

        foreach ($schemes as $scheme) {
            $coverage = $transmutation->analyzeCoverage($scheme);

            $ok = $coverage['band_count'] > 0
                && empty($coverage['gaps'])
                && empty($coverage['overlaps'])
                && $coverage['anchor_is_75'];

            if (!$ok) {
                $failed = true;
            }

            $rows[] = [
                $scheme,
                $coverage['band_count'],
                empty($coverage['gaps']) ? 'none' : $this->formatRanges($coverage['gaps']),
                empty($coverage['overlaps']) ? 'none' : count($coverage['overlaps']) . ' pair(s)',
                $coverage['covers_0_to_100'] ? 'yes' : 'no',
                number_format($coverage['anchor_initial'], 2) . ' ->',
                $coverage['resolved_anchor'] !== null ? number_format($coverage['resolved_anchor'], 2) : '—',
                $coverage['resolved_100'] !== null ? number_format($coverage['resolved_100'], 2) : '—',
                $coverage['anchor_is_75'] ? 'OK' : 'FAIL',
            ];
        }

        $this->table(
            ['Scheme', 'Bands', 'Gaps', 'Overlaps', 'Covers 0-100', 'Anchor', 'Anchor ->', '100.00 ->', 'Anchor = 75?'],
            $rows
        );

        if ($failed) {
            $this->error('One or more schemes have gaps, overlaps, or a wrong passing anchor. See CLAUDE.md\'s "Known limitations".');
            return self::FAILURE;
        }

        $this->info('Every checked scheme covers 0-100 without gaps or overlaps, and its confirmed passing anchor resolves to 75.');
        return self::SUCCESS;
    }

    private function formatRanges(array $ranges): string
    {
        return collect($ranges)
            ->map(fn($range) => number_format($range[0], 2) . '-' . number_format($range[1], 2))
            ->implode(', ');
    }
}
