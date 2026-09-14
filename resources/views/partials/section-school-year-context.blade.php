{{-- "Multi-school-year academic history" work order, PART 19 — the
     academic context an Adviser page is showing: which school year the
     section belongs to, and whether it is the active year (writable while
     a term is open) or a historical one (read-only, server-enforced by
     AcademicTerm::acceptsWrites()). Expects $section (may be null). --}}
@if($section)
<div class="mb-4 flex flex-wrap items-center gap-2 text-sm text-gray-700">
    <span><span class="text-xs text-gray-500">School Year:</span> <span class="font-semibold">{{ $section->school_year }}</span></span>
    <span class="text-gray-300">|</span>
    <span><span class="text-xs text-gray-500">Section:</span> <span class="font-semibold">{{ $section->name }}</span> <span class="text-xs text-gray-500">Grade {{ $section->grade_level }}</span></span>
    @if($section->isInActiveSchoolYear())
        <span class="badge badge-success"><i class="bi bi-check-circle-fill"></i> Active school year</span>
    @else
        <span class="badge badge-gray"><i class="bi bi-archive"></i> Historical Record</span>
        <span class="text-xs text-gray-500">This section belongs to a completed school year. Its grades, assessments, and reports are read-only. You have no section assigned for the active school year ({{ \App\Models\Section::activeSchoolYear() }}) yet.</span>
    @endif
</div>
@endif
