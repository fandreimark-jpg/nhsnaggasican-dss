@extends('layouts.app')

@section('title', 'My Students')
@section('subtitle', $section ? 'Section ' . $section->name . ' — Grade ' . $section->grade_level : 'No section assigned')

@section('content')

@include('partials.section-school-year-context')

{{-- My Students is a view-only roster (the adviser edit action was removed
     2026-09-27); the partial stays for any flashed validation message. --}}
@include('partials.validation-errors')

<div class="card overflow-x-auto">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-3 px-5 py-4 border-b border-line">
        <div>
            <h2 class="card-title">Students</h2>
            <p class="text-xs text-muted"><x-count-label :count="$students->total()" noun="student" /> in your section</p>
            @if($section)
            <p class="text-xs mt-1" data-for-review-count>
                <span class="font-medium text-amber-700">{{ $forReviewCount }} of {{ $students->total() }} For Review</span> in Term {{ $period }}
                — from open Academic Concerns, evaluated subject by subject.
            </p>
            @endif
        </div>
        @if($section)
        <form method="GET" action="{{ route('adviser.students') }}" class="flex items-center gap-2" data-no-loading>
            <label for="myStudentsTerm" class="text-xs text-muted">Term</label>
            <select id="myStudentsTerm" name="period" class="form-select-sm" onchange="this.form.submit()">
                @foreach($terms as $t)
                    <option value="{{ $t }}" @selected($t === $period)>Term {{ $t }}</option>
                @endforeach
            </select>
        </form>
        @endif
        <div class="flex items-center gap-3 flex-wrap">
            {{-- Search box--}}
            <div>
                <div class="relative">
                    <input type="text" id="adviserStudentSearch"
                        placeholder="Search this page..."
                        class="form-input !w-56 pl-9">
                    <i class="bi bi-search absolute left-3 top-2.5 text-gray-400 text-sm"></i>
                </div>
                {{-- TASK 5d of "clarity, progress, and visual design pass"
                     — honest about scope now that the roster is paginated:
                     this box filters only the {{ $students->count() }} rows
                     on the current page, not the full section. --}}
                @if($students->hasPages())
                <p class="text-[11px] text-gray-400 mt-1">Searches this page only ({{ $students->firstItem() }}–{{ $students->lastItem() }} of {{ $students->total() }}).</p>
                @endif
            </div>
            <p class="text-xs text-muted">
                <i class="bi bi-info-circle"></i> Learner records are managed by the Admin.
            </p>
        </div>
    </div>
    <table class="tbl">
        <thead>
            <tr>
                <th scope="col">LRN</th>
                <th scope="col">Last Name</th>
                <th scope="col">First Name</th>
                <th scope="col">M.I.</th>
                <th scope="col">Sex</th>
                <th scope="col" title="Open Academic Concerns this term (Subject Performance Concern, Missing Requirement). The evidence is under View details.">
                    Monitoring
                    <span class="block normal-case font-normal text-gray-400 text-[10px] tracking-normal">Term {{ $period }}</span>
                </th>
                <th scope="col" class="text-right">Action</th>
            </tr>
        </thead>

        <tbody id="adviserStudentTableBody">
            @forelse($students as $student)

            <tr class="student-row" data-student-row="{{ $student->id }}">
                <td>{{ $student->lrn }}</td>
                <td class="font-medium text-ink">{{ $student->last_name }}</td>
                <td>{{ $student->first_name }}</td>
                <td>{{ $student->middle_name ? mb_substr($student->middle_name, 0, 1) . '.' : '—' }}</td>
                <td class="capitalize">{{ $student->gender }}</td>
                <td data-monitoring-cell>
                    <x-ui.monitoring-status :status="$monitoring->get($student->id)" compact />
                </td>
                <td class="text-right whitespace-nowrap">
                    {{-- Official Term results and the full concern evidence
                         live on Submit Report (read-only here). --}}
                    <a href="{{ route('adviser.submit.report', ['period' => $period]) }}#learner-{{ $student->id }}"
                       class="text-brand-700 hover:underline text-sm" data-view-details>
                        <i class="bi bi-eye"></i> View details
                    </a>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="7">
                    <x-empty-state message="No students in your section yet." hint="An Admin adds students and assigns them to your section." />
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>

    @if($students->hasPages())
    <div class="px-5 py-4 border-t border-line flex flex-col items-center gap-2 text-sm text-muted">
        <div class="flex items-center gap-1">
            @if($students->onFirstPage())
                <span class="px-3 py-1 rounded-md border border-line text-gray-300 cursor-not-allowed">← Prev</span>
            @else
                <a href="{{ $students->previousPageUrl() }}" class="px-3 py-1 rounded-md border border-line hover:bg-surface text-gray-600">← Prev</a>
            @endif
            <span class="px-3 py-1 rounded-md border border-brand-800 bg-brand-800 text-white font-medium">{{ $students->currentPage() }}</span>
            @if($students->hasMorePages())
                <a href="{{ $students->nextPageUrl() }}" class="px-3 py-1 rounded-md border border-line hover:bg-surface text-gray-600">Next →</a>
            @else
                <span class="px-3 py-1 rounded-md border border-line text-gray-300 cursor-not-allowed">Next →</span>
            @endif
        </div>
        <span class="text-xs">Showing {{ $students->firstItem() }}–{{ $students->lastItem() }} of <x-count-label :count="$students->total()" noun="student" /></span>
    </div>
    @endif
</div>



@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        initTableSearch('adviserStudentSearch', '#adviserStudentTableBody .student-row');
    });
</script>
@endpush

@endsection