@props(['value', 'domain' => 'group'])
@php($presentation = \App\Support\UiStatus::get($domain, $value))
<span class="status tone-{{ $presentation[0] }}" data-status="{{ $domain }}:{{ $value }}">{{ $presentation[1] }}</span>
