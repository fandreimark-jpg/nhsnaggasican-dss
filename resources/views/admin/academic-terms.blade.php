@extends('layouts.app')

@section('title', 'Academic Terms')
@section('subtitle', 'Term control — School Year ' . $schoolYear)

@section('content')

@if(session('error'))
<div class="bg-red-100 text-red-700 text-sm p-4 rounded-lg mb-4">
    {{ session('error') }}
</div>
@endif

@if(session('success'))
<div class="bg-green-100 text-green-700 text-sm p-4 rounded-lg mb-4">
    {{ session('success') }}
</div>
@endif

<div class="bg-white rounded-xl shadow-sm">
    <div class="px-6 py-4 border-b">
        <h2 class="text-sm font-semibold text-gray-800">Term Control</h2>
        <p class="text-xs text-gray-400">
            Only one term can be open for encoding at a time. A term cannot be opened until the previous term is 100% encoded across every section.
        </p>
    </div>

    <div class="divide-y divide-gray-100">
        @foreach($terms as $term)
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-3 px-6 py-5">
            <div>
                <div class="flex items-center gap-2">
                    <h3 class="font-semibold text-gray-800">Term {{ $term->term }}</h3>
                    @if($term->is_open)
                        <span class="bg-green-100 text-green-700 text-xs font-medium px-2 py-0.5 rounded-full">
                            <i class="bi bi-unlock"></i> Open
                        </span>
                    @else
                        <span class="bg-gray-100 text-gray-500 text-xs font-medium px-2 py-0.5 rounded-full">
                            <i class="bi bi-lock"></i> Closed
                        </span>
                    @endif
                </div>
                <p class="text-xs text-gray-400 mt-1">
                    @if($term->completion['complete'])
                        All sections fully encoded for this term.
                    @else
                        Not fully encoded:
                        @foreach($term->completion['incomplete_sections'] as $s)
                            {{ $s['section'] }} ({{ $s['encoded'] }}/{{ $s['expected'] }})@if(!$loop->last), @endif
                        @endforeach
                    @endif
                </p>

                {{-- TASK 4 of "dashboard structure and upload safeguards"
                     — the subjects x students arithmetic behind the line
                     above, per section, visible before anyone attempts to
                     open the NEXT term and gets refused. Open by default
                     only when this term isn't complete, so the gap that
                     matters surfaces without a click. --}}
                <details class="mt-2" {{ $term->completion['complete'] ? '' : 'open' }}>
                    <summary class="text-xs text-brand-700 cursor-pointer hover:underline">
                        Section breakdown ({{ count($term->capacity) }} section{{ count($term->capacity) === 1 ? '' : 's' }})
                    </summary>
                    <div class="mt-2 overflow-x-auto border rounded-lg">
                        <table class="w-full text-xs">
                            <thead class="bg-gray-50 text-gray-500 uppercase tracking-wide">
                                <tr>
                                    <th class="text-left px-3 py-2">Section</th>
                                    <th class="text-center px-3 py-2">Subjects</th>
                                    <th class="text-center px-3 py-2">Students</th>
                                    <th class="text-center px-3 py-2">Expected Grades</th>
                                    <th class="text-center px-3 py-2">Encoded</th>
                                    <th class="text-center px-3 py-2">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse($term->capacity as $c)
                                <tr class="{{ $c['expected'] > 0 && $c['encoded'] < $c['expected'] ? 'bg-red-50' : '' }}">
                                    <td class="px-3 py-2 text-gray-700 whitespace-nowrap">{{ $c['section']->name }} (Grade {{ $c['section']->grade_level }})</td>
                                    <td class="px-3 py-2 text-center text-gray-600">{{ $c['subject_count'] }}</td>
                                    <td class="px-3 py-2 text-center text-gray-600">{{ $c['student_count'] }}</td>
                                    <td class="px-3 py-2 text-center text-gray-600">{{ $c['expected'] }}</td>
                                    <td class="px-3 py-2 text-center text-gray-600">{{ $c['encoded'] }}</td>
                                    <td class="px-3 py-2 text-center">
                                        @if($c['expected'] === 0)
                                            <span class="text-gray-300">No students/subjects yet</span>
                                        @elseif($c['encoded'] >= $c['expected'])
                                            <span class="text-green-600"><i class="bi bi-check-circle"></i> Complete</span>
                                        @else
                                            <span class="text-red-600"><i class="bi bi-exclamation-circle"></i> {{ $c['expected'] - $c['encoded'] }} short</span>
                                        @endif
                                    </td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="6" class="px-3 py-3 text-center text-gray-400">No sections exist for this school year yet.</td>
                                </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </details>
            </div>

            <div class="flex gap-2">
                @if($term->is_open)
                    <form method="POST" action="{{ route('admin.academic-terms.close', $term->term) }}">
                        @csrf
                        <button type="submit"
                            class="bg-white border border-red-300 text-red-600 px-4 py-2 rounded-lg text-sm font-medium hover:bg-red-50">
                            Close Term {{ $term->term }}
                        </button>
                    </form>
                @else
                    <form method="POST" action="{{ route('admin.academic-terms.open', $term->term) }}">
                        @csrf
                        <button type="submit"
                            class="bg-brand-700 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-brand-800">
                            Open Term {{ $term->term }}
                        </button>
                    </form>
                @endif
            </div>
        </div>
        @endforeach
    </div>
</div>

@endsection