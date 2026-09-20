@props(['navigation' => []])
<header class="topbar">
<div class="container topbar-inner">
<a class="wordmark" href="{{ url('/') }}">gruppa<span>.</span> <span class="meta">Кабинет психолога</span>
</a>
@if($navigation)
<nav class="nav-links" aria-label="Кабинет">
@foreach($navigation as $item)
<a href="{{ $item['url'] }}" @if($item['current']) aria-current="page" @endif>{{ $item['label'] }}</a>
@endforeach
<x-button kind="ghost" data-noop>Выход</x-button>
</nav>
@endif
</div>
</header>
