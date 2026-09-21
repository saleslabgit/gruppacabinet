@props(['value', 'domain' => 'group'])
@php($presentation = \App\Support\UiStatus::get($domain, $value))
<span class="status tone-{{ $presentation[0] }}" data-status="{{ $domain }}:{{ $value }}"><x-icon :name="['success'=>'check-circle', 'warning'=>'clock', 'danger'=>'exclamation-circle', 'info'=>'info-circle', 'neutral'=>'circle'][$presentation[0]] ?? 'circle'" />{{ $presentation[1] }}</span>
