{{--
    TASK 1 of "status clarity and progress consistency" — every place an
    In-Term Status badge appears must show WHICH NUMBER produced it
    (visible text, not a tooltip), because a student can show Computed
    66.98 / Transmuted 79.00 / At Risk, and to anyone who hasn't read the
    code that looks like a contradiction. It isn't: transmutation can
    lift a failing raw score into a passing reported grade, and a status
    built on the transmuted number would only flag a student once their
    real mastery had already fallen below the passing anchor of their
    curriculum — far too late for this system's early-intervention
    purpose. In-Term Status is always
    computed from the Computed grade — see InTermStatusService and the
    ground rule in that task's prompt. This partial never changes that
    threshold or which grade drives it; it only makes the choice legible.

    The single most confusing case — At Risk/Needs Attention while the
    Transmuted grade is already 75+ — gets an explicit "passing on paper"
    marker rather than being left for the reader to notice.

    "Correctness and interface pass" TASK 5b — compressed to at most TWO
    lines regardless of case: the badge, then ONE supporting-detail line.
    When passing-on-paper applies, that line carries both the marker and
    the item count together rather than each getting its own line — a
    row must not grow taller than any other just because one learner
    happens to have an extra badge (see the table's fixed row height).

    @param array $its an InTermStatusService::statusFor()/fromAnalysis() result
    @param float|null $transmutedGrade this same subject's transmuted grade, when known (null if incomplete/unavailable)
--}}
@php
    $inTermStatusColors = ['On Track' => 'bg-status-ontrack/10 text-status-ontrack', 'Needs Attention' => 'bg-status-attention/10 text-status-attention', 'At Risk' => 'bg-status-risk/10 text-status-risk'];
    $passingOnPaper = in_array($its['status'], ['At Risk', 'Needs Attention'], true)
        && ($its['complete'] ?? false)
        && $transmutedGrade !== null
        && $transmutedGrade >= 75;
    $itemCountText = $its['item_count'] . ' item' . ($its['item_count'] === 1 ? '' : 's') . ' scored so far';
@endphp
<span class="badge whitespace-nowrap {{ $inTermStatusColors[$its['status']] ?? 'bg-gray-100 text-gray-600' }}">
    {{ $its['status'] }}@if($its['computed_grade'] !== null) &middot; computed {{ number_format($its['computed_grade'], 2) }}@endif
</span>
@if($passingOnPaper)
    <span class="block mt-0.5 text-[11px] font-semibold text-status-attention truncate" title="passing on paper (transmuted {{ number_format($transmutedGrade, 2) }}) &middot; {{ $itemCountText }}">
        <i class="bi bi-exclamation-triangle-fill"></i> passing on paper &middot; {{ $itemCountText }}
    </span>
@else
    <span class="block text-xs text-muted mt-0.5">
        {{ $itemCountText }}
    </span>
@endif
