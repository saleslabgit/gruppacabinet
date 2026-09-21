@props(['navigation' => [], 'logoutUrl' => null, 'homeUrl' => null])
<header class="topbar">
<div class="container topbar-inner">
<a class="wordmark" href="{{ $homeUrl ?? (request()->user()?->admin ? route('admin.home') : url('/')) }}">gruppa<span>.</span> <span class="meta">Кабинет психолога</span>
</a>
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
