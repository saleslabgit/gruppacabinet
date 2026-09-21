@props(['value', 'domain' => 'group'])
@php
$presentation = \App\Support\UiStatus::get($domain, $value);
$icon = match ($domain.':'.$value) {
    'group:draft' => 'pencil-square',
    'group:expired' => 'calendar-check',
    'access:enabled' => 'unlock',
    'access:disabled' => 'lock',
    default => ['success'=>'check-circle', 'warning'=>'clock', 'danger'=>'exclamation-circle', 'info'=>'info-circle', 'neutral'=>'circle'][$presentation[0]] ?? 'circle',
};
@endphp
<span class="status tone-{{ $presentation[0] }}" data-status="{{ $domain }}:{{ $value }}"><x-icon :name="$icon" /><span class="control-label">{{ $presentation[1] }}</span></span>
