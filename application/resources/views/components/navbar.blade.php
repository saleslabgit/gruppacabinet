@props(['navigation' => [], 'logoutUrl' => null, 'homeUrl' => null])
<header class="topbar">
<div class="container topbar-inner">
<a class="wordmark" href="{{ $homeUrl ?? url('/') }}">gruppa<span>.</span> <span class="meta">Кабинет психолога</span>
</a>
@if($navigation)
<nav class="nav-links" aria-label="Кабинет">
@foreach($navigation as $item)
<a href="{{ $item['url'] }}" @if($item['current']) aria-current="page" @endif>{{ $item['label'] }}</a>
@endforeach
@if($logoutUrl)
<form method="POST" action="{{ $logoutUrl }}">
@csrf
<x-button kind="ghost" type="submit">Выход</x-button>
</form>
@else
<x-button kind="ghost" data-noop>Выход</x-button>
@endif
</nav>
@endif
</div>
</header>
