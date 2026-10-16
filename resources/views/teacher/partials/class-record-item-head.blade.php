{{-- One template column head: its workbook label (1–10…, ST 1 / ST 2 / TE)
     and, when an assessment fills it, that assessment's name and details.
     An empty numbered column is an inactive placeholder — no assessment. --}}
@if($item)
    <th scope="col" class="cr-item-head {{ $first ? 'cr-gs' : '' }} {{ $newAssessmentId == $item->id ? 'cr-new' : '' }}" data-item-head="{{ $item->id }}">
        <span class="cr-slot-label">{{ $label }}</span>
        <span class="cr-item-name" title="{{ $item->name }}">{{ $item->name }}</span>
        @if($item->exam_role && $label === 'Other')<span class="cr-item-meta"><abbr title="Examination Type: {{ $examRoleLabels[$item->exam_role] ?? $item->exam_role }}">{{ \App\Services\ClassRecordService::EXAM_COLUMNS[$item->exam_role] ?? $item->exam_role }}</abbr></span>@endif
        @if($item->is_additional_support)<span class="cr-item-meta text-amber-800">Additional support</span>@endif
        @if($item->assessment_date)<span class="cr-item-meta">{{ $item->assessment_date->format('M j') }}</span>@endif
        @if($canEdit)
            <a href="{{ route('teacher.assessments.edit', $item->id) }}" class="cr-item-edit" data-cr-leave aria-label="Edit {{ $item->name }}">Edit</a>
        @endif
    </th>
@else
    <th scope="col" class="cr-item-head cr-slot-empty {{ $first ? 'cr-gs' : '' }}" title="No assessment in this column yet">
        <span class="cr-slot-label">{{ $label }}</span>
    </th>
@endif
