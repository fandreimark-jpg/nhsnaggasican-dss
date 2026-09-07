{{-- TASK 7 of "terminology, transmutation, and interface cleanup" — the
     lighter alternative to real-time: every number on this page is
     computed fresh on THIS request, so "Last updated" is simply when this
     page was rendered, and Refresh is a plain reload of the current
     (filtered) URL. No websocket/broadcast driver/queue worker — see
     CLAUDE.md and this task's own note on why that's not built here. --}}
<div class="flex items-center gap-3 text-xs text-gray-400 mt-3 pt-3 border-t">
    <span><i class="bi bi-clock-history"></i> Last updated {{ now()->format('h:i A') }}</span>
    <a href="{{ request()->fullUrl() }}" class="inline-flex items-center gap-1 text-brand-700 hover:underline">
        <i class="bi bi-arrow-clockwise"></i> Refresh
    </a>
</div>
