{{-- One learner cell under a template column. A column with no assessment
     is an inactive placeholder: no input, no score, never Missing. --}}
@if($item)
    @php
        $score = $record['scores'][$item->id][$student->id] ?? null;
        $isMissing = $score === null && isset($record['missing'][$item->id][$student->id]);
        $display = $score !== null ? \App\Services\ClassRecordService::fmt($score) : ($isMissing ? 'M' : '');
        $hps = \App\Services\ClassRecordService::fmt((float) $item->max_score);
    @endphp
    <td class="cr-cell-td {{ $first ? 'cr-gs' : '' }}" data-col="{{ $item->id }}">
        <input type="text" inputmode="decimal" autocomplete="off" spellcheck="false"
               class="cr-cell {{ $isMissing ? 'cr-missing' : '' }}"
               value="{{ $display }}"
               data-a="{{ $item->id }}" data-s="{{ $student->id }}"
               data-max="{{ (float) $item->max_score }}"
               data-learner="{{ $learnerName }}" data-item="{{ $item->name }}"
               data-base-score="{{ $score !== null ? \App\Services\ClassRecordService::fmt($score) : '' }}"
               data-base-missing="{{ $isMissing ? '1' : '0' }}"
               data-support="{{ $item->is_additional_support ? '1' : '0' }}"
               aria-label="{{ $item->name }} (HPS {{ $hps }}) for {{ $learnerName }}"
               @unless($canEdit) readonly @endunless>
    </td>
@else
    <td class="cr-cell-td cr-slot-empty {{ $first ? 'cr-gs' : '' }}" aria-hidden="true"></td>
@endif
