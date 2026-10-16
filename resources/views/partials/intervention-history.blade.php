{{-- Schedule/completion history (intervention_events) and requests. --}}
@php
    $fmt = fn($t) => \App\Services\InterventionScheduleService::fmt($t);
    $names = \App\Models\User::whereIn('id', $intervention->events->flatMap(fn($e) => [$e->old_values['responsible_user_id'] ?? null, $e->new_values['responsible_user_id'] ?? null, $e->new_values['approved_by'] ?? null])->filter()->unique())->pluck('name', 'id');
    $value = fn($x, $k) => in_array($k, ['responsible_user_id', 'approved_by'], true) && $x ? ($names[$x] ?? '#' . $x) : (is_bool($x) ? ($x ? 'yes' : 'no') : ($x ?? '—'));
    $show = fn($v) => is_array($v) ? collect($v)->except(['instructions', 'completion_criteria'])->map(fn($x, $k) => str_replace(['_user_id', '_'], ['', ' '], $k) . ': ' . $value($x, $k))->implode('; ') : '';
@endphp
<section class="card mt-4" aria-labelledby="ivRequestsTitle">
    <div class="card-header"><h2 id="ivRequestsTitle" class="card-title">Requests</h2></div>
    <div class="tbl-scroll">
        <table class="tbl">
            <thead><tr><th>Type</th><th>Requested</th><th>Proposed / actual</th><th>Reason</th><th>Status</th>@isset($decideRoute)<th class="text-right">Decision</th>@endisset</tr></thead>
            <tbody>
            @forelse($intervention->scheduleRequests as $r)
                <tr data-request-row="{{ $r->id }}">
                    <td>{{ $r->label() }}@if($r->isLegacy() && $r->status === 'pending')<span class="block text-xs text-muted">Does not block the responsible staff; replaced by their next schedule change.</span>@endif</td>
                    <td>{{ $r->requestedBy?->name }}<span class="block text-xs text-muted">{{ $fmt($r->created_at) }}</span></td>
                    <td class="text-xs">
                        @if($r->type === 'late_completion') completed {{ $fmt($r->actual_completed_at) }}
                        @else {{ $fmt($r->proposed_starts_at) }} → {{ $fmt($r->proposed_deadline_at) }} @endif
                    </td>
                    <td class="text-xs whitespace-pre-line">{{ $r->reason }}@if($r->evidence_notes)<span class="block text-muted">Evidence: {{ $r->evidence_notes }}</span>@endif</td>
                    <td><span class="badge {{ ['pending' => 'badge-warning', 'approved' => 'badge-success', 'rejected' => 'badge-gray', 'superseded' => 'badge-outline'][$r->status] ?? 'badge-gray' }}">{{ ucfirst($r->status) }}</span>
                        @if($r->decided_at)<span class="block text-xs text-muted">{{ $r->decidedBy?->name ?? 'system' }}, {{ $fmt($r->decided_at) }}</span>@endif
                        @if($r->decision_notes)<span class="block text-xs text-muted">{{ $r->decision_notes }}</span>@endif</td>
                    @isset($decideRoute)
                        <td class="text-right">
                            @if($r->status === 'pending')
                                <form method="POST" action="{{ route($decideRoute, $r) }}" class="flex flex-col gap-1 items-end">
                                    @csrf
                                    <label class="sr-only" for="dn{{ $r->id }}">Decision notes</label>
                                    <input id="dn{{ $r->id }}" name="decision_notes" class="form-input text-xs" placeholder="Notes (optional)" maxlength="2000">
                                    <span class="flex gap-1">
                                        @if($r->isLegacy())
                                            {{-- Previous approval workflow: never executed now, only dismissed. --}}
                                            <button class="btn btn-outline btn-xs" name="decision" value="reject">Dismiss (Reject)</button>
                                        @else
                                            <button class="btn btn-primary btn-xs" name="decision" value="approve">Approve</button>
                                            <button class="btn btn-outline btn-xs" name="decision" value="reject">Reject</button>
                                        @endif
                                    </span>
                                </form>
                            @endif
                        </td>
                    @endisset
                </tr>
            @empty
                <tr><td colspan="6" class="text-muted">No requests.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</section>

<section class="card mt-4" aria-labelledby="ivHistoryTitle">
    <div class="card-header"><h2 id="ivHistoryTitle" class="card-title">History</h2>
        <p class="card-subtitle">"Effective" is when it took effect (an expiry: the deadline that passed); "recorded" is when the system wrote it.</p></div>
    <div class="tbl-scroll">
        <table class="tbl">
            <thead><tr><th>Event</th><th>By</th><th>Effective</th><th>Recorded</th><th>Details</th></tr></thead>
            <tbody>
            @forelse($intervention->events as $e)
                <tr data-event="{{ $e->event }}">
                    <td class="font-medium text-ink">{{ $e->label() }}</td>
                    <td>{{ $e->actor?->name ?? 'System' }}</td>
                    <td class="text-xs whitespace-nowrap">{{ $fmt($e->effective_at) }}</td>
                    <td class="text-xs whitespace-nowrap">{{ $fmt($e->recorded_at) }}</td>
                    <td class="text-xs">@if($e->old_values)<span class="block text-muted">Before: {{ $show($e->old_values) }}</span>@endif
                        @if($e->new_values)<span class="block">After: {{ $show($e->new_values) }}</span>@endif
                        @if($e->reason)<span class="block">Reason: {{ $e->reason }}</span>@endif</td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-muted">No schedule history yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</section>
