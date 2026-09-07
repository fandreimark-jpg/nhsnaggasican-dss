{{--
    TASK 1 of "Naggasican DSS: unblock verification" — shared by the
    Adviser and Principal dashboards. $transmutationBanner is either null
    (no fallback configured, or none is actually in use for what this
    viewer can see) or ['fallback_scheme' => string, 'grade_levels' =>
    array<int>], passed in by the calling controller — see
    TransmutationService::fallbackActiveFor().

    Deliberately loud (amber) and NEVER dismissible, same convention as
    partials.stale-risk-warning — a fallback that can be hidden will be
    forgotten, and these grades stop being provisional only once the real
    table is entered and a recompute is run.
--}}
@if(!empty($transmutationBanner))
<div class="bg-amber-50 border border-amber-200 text-amber-800 text-sm p-4 rounded-lg mb-4">
    <p class="font-medium">
        <i class="bi bi-exclamation-triangle-fill"></i>
        Provisional grading fallback active — Grade {{ implode(', ', $transmutationBanner['grade_levels']) }}
    </p>
    <p class="mt-1 text-amber-700">
        The real transmutation table for this grade level isn't fully seeded yet,
        so affected grades are being computed using the {{ \App\Services\TransmutationService::schemeLabel($transmutationBanner['fallback_scheme']) }}
        table instead and marked <strong>Provisional</strong> wherever they appear. These grades must be recomputed
        (<code>php artisan dss:recompute-grades</code>) once the real table is entered.
    </p>
</div>
@endif
