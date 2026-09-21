@props(['navigation' => [], 'logoutUrl' => null])
<nav class="sidebar" aria-label="Администрирование">
@foreach($navigation as $item)
<x-navigation-link :item="$item" />
@endforeach
@if($logoutUrl)
<form method="POST" action="{{ $logoutUrl }}">
@csrf
<x-button icon="box-arrow-right" kind="ghost" type="submit">Выход</x-button>
</form>
@else
<x-button icon="box-arrow-right" kind="ghost" data-noop>Выход</x-button>
@endif
</nav>
