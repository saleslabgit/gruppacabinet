@props(['navigation' => [], 'logoutUrl' => null, 'homeUrl' => null, 'adminMenu' => false])
<header class="topbar">
<div class="container topbar-inner">
<a class="wordmark" href="{{ $homeUrl ?? (request()->user()?->admin ? route('admin.home') : url('/')) }}">gruppa<span>.</span> <span class="meta">Кабинет психолога</span>
</a>
@if($adminMenu)
<button id="admin-menu-toggle" class="btn btn-ghost menu-toggle" type="button" aria-expanded="false" aria-controls="admin-navigation"><x-icon name="list" /><span class="control-label">Меню</span></button>
@endif
@if($navigation)
<nav class="nav-links" aria-label="Кабинет">
@foreach($navigation as $item)
<x-navigation-link :item="$item" />
@endforeach
</nav>
@if($logoutUrl)
<form class="nav-logout" method="POST" action="{{ $logoutUrl }}">
@csrf
<x-button icon="box-arrow-right" kind="ghost" type="submit">Выход</x-button>
</form>
@else
<x-button class="nav-logout" icon="box-arrow-right" kind="ghost" data-noop>Выход</x-button>
@endif
@endif
</div>
</header>
