@props(['tone' => 'info', 'title' => null])
<div {{ $attributes->class(['notice', 'tone-'.$tone]) }} role="{{ $tone === 'danger' ? 'alert' : 'status' }}">
<x-icon :name="['success'=>'check-circle', 'warning'=>'exclamation-triangle', 'danger'=>'exclamation-circle', 'info'=>'info-circle'][$tone] ?? 'info-circle'" />
<div class="notice-content">
@if($title)<strong class="notice-title">{{ $title }}</strong>@endif
{{ $slot }}
</div>
</div>
