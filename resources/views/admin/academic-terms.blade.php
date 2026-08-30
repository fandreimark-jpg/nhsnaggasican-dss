@extends('layouts.app')

@section('title', 'Academic Terms')
@section('subtitle', 'Grading period control — School Year ' . $schoolYear)

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
        <h2 class="text-sm font-semibold text-gray-800">Grading Period Control</h2>
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