{{--
    Shared by every screen that displays a classifier Risk Level (adviser
    dashboard, admin/principal reports, principal dashboard, principal
    students) — see AcademicTerm::staleRiskTerms() and the "live in-term
    risk + stale data guard" prompt. $staleRiskTerms is an array of term
    numbers (1-3), passed in by the calling controller/view.

    This is deliberately loud (red, not amber) and never dismissible —
    a risk level with no surviving evidence behind it is not a minor
    notice, and hiding or auto-deleting the stale rows is explicitly out
    of scope: this only ever surfaces the problem and lets a human decide.
--}}
@if(!empty($staleRiskTerms ?? []))
<div class="bg-red-50 border border-red-200 text-red-800 text-sm p-4 rounded-lg mb-4">
    <p class="font-medium">
        <i class="bi bi-exclamation-triangle-fill"></i>
        Risk level{{ count($staleRiskTerms) === 1 ? '' : 's' }} may be out of date — Term{{ count($staleRiskTerms) === 1 ? '' : 's' }} {{ implode(', ', $staleRiskTerms) }}
    </p>
    <p class="mt-1 text-red-700">
        Risk levels are on record for Term{{ count($staleRiskTerms) === 1 ? '' : 's' }} {{ implode(', ', $staleRiskTerms) }}, but the grades they were computed from no longer exist.
        These numbers are stale — re-enter the grades and re-submit the term report to regenerate them. Nothing has been deleted automatically.
    </p>
</div>
@endif
