{{--
    Shared import-result panel: one summary line, up to 5 skipped-row
    messages inline, a "Show all N" toggle for the rest, and an optional
    header-mismatch hint — used by every bulk-upload screen (5 Admin
    importers + 2 Adviser importers) so they render identically instead
    of each carrying its own copy of this markup.

    Expected variables (all read from the session so a redirect can carry
    them):
    - warning (string)              summary line, e.g. "12 section(s) imported. 3 row(s) were skipped:"
    - import_errors (array<string>) one message per skipped row/entry
    - import_header_hint (?string)  optional "check your header row" hint
    - import_warnings (array<string>) optional, non-error notices (kept in its own, separate box)
--}}
@if(session('import_errors') && count(session('import_errors')) > 0)
    @php
        $importErrorRows = session('import_errors');
        $importVisibleRows = array_slice($importErrorRows, 0, 5);
        $importRemainingCount = count($importErrorRows) - count($importVisibleRows);
    @endphp
    <div data-dismissible class="alert alert-warning mb-4 relative">
        <button type="button" data-dismiss aria-label="Dismiss" class="absolute top-2 right-2 text-yellow-800 hover:text-yellow-900">
            <i class="bi bi-x-lg"></i>
        </button>
        <p class="font-medium mb-1 pr-6">{{ session('warning') }}</p>

        @if(session('import_header_hint'))
            <p class="mb-2">{{ session('import_header_hint') }}</p>
        @endif

        <ul class="list-disc list-inside max-h-64 overflow-y-auto">
            @foreach($importVisibleRows as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>

        @if($importRemainingCount > 0)
            <details class="mt-2">
                <summary class="cursor-pointer select-none">Show all {{ count($importErrorRows) }} skipped rows</summary>
                <ul class="list-disc list-inside max-h-64 overflow-y-auto mt-1">
                    @foreach($importErrorRows as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </details>
        @endif
    </div>
@endif

@if(session('import_warnings') && count(session('import_warnings')) > 0)
<div data-dismissible class="bg-blue-50 text-blue-800 text-sm p-4 rounded-lg mb-4 border border-blue-100 relative">
    <button type="button" data-dismiss aria-label="Dismiss" class="absolute top-2 right-2 text-blue-800 hover:text-blue-900">
        <i class="bi bi-x-lg"></i>
    </button>
    <p class="font-medium mb-1 pr-6"><i class="bi bi-info-circle"></i> Note — not an error:</p>
    <ul class="list-disc list-inside">
        @foreach(session('import_warnings') as $warning)
            <li>{{ $warning }}</li>
        @endforeach
    </ul>
</div>
@endif
