@props(['navigation' => []])
<nav class="sidebar" aria-label="Администрирование">
@foreach($navigation as $item)
<a href="{{ $item['url'] }}" @if($item['current']) aria-current="page" @endif>{{ $item['label'] }}</a>
@endforeach
<x-button kind="ghost" data-noop>Выход</x-button>
</nav>
