@props(['navigation' => [], 'logoutUrl' => null])
<nav class="sidebar" aria-label="Администрирование">
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
