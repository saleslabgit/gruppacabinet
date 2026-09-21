@props(['value', 'domain' => 'group'])
@php($presentation = \App\Support\UiStatus::get($domain, $value))
<span class="status tone-{{ $presentation[0] }}" data-status="{{ $domain }}:{{ $value }}"><x-icon :name="($domain === 'group' && $value === 'draft') ? 'pencil-square' : (['success'=>'check-circle', 'warning'=>'clock', 'danger'=>'exclamation-circle', 'info'=>'info-circle', 'neutral'=>'circle'][$presentation[0]] ?? 'circle')" /><span class="control-label">{{ $presentation[1] }}</span></span>
