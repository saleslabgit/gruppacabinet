@props(['navigation' => [], 'logoutUrl' => null])
<div class="admin-navigation">
<button class="btn btn-ghost menu-toggle" type="button" aria-expanded="false" aria-controls="admin-navigation"><x-icon name="list" /><span class="control-label">Меню</span></button>
<nav class="sidebar" id="admin-navigation" aria-label="Администрирование">
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
</div>
