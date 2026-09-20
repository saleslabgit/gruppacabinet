@props(['pages' => [], 'current' => 1])
<nav aria-label="Страницы списка">
<ul class="pagination">
@foreach($pages as $number => $href)
<li class="page-item {{ $current === $number ? 'active' : '' }}">
<a class="page-link" href="{{ $href }}" @if($current === $number) aria-current="page" @endif>{{ $number }}</a>
</li>
@endforeach
</ul>
</nav>
