@props(['tone' => 'info', 'title' => null])
<div {{ $attributes->class(['notice', 'tone-'.$tone]) }} role="{{ $tone === 'danger' ? 'alert' : 'status' }}">
@if($title)<strong class="notice-title">{{ $title }}</strong>@endif
{{ $slot }}
</div>
