@props(['pages' => [], 'current' => 1])
@if(count($pages) > 1)
<nav aria-label="Страницы списка">
<ul class="pagination">
<li class="page-item {{ isset($pages[$current - 1]) ? '' : 'disabled' }}">
@if(isset($pages[$current - 1]))
<a class="page-link" href="{{ $pages[$current - 1] }}" aria-label="Предыдущая страница"><x-icon name="chevron-left" /></a>
@else
<span class="page-link" aria-disabled="true" aria-label="Предыдущая страница"><x-icon name="chevron-left" /></span>
@endif
</li>
@foreach($pages as $number => $href)
<li class="page-item {{ $current === $number ? 'active' : '' }}">
@if($current === $number)
<span class="page-link" aria-current="page">{{ $number }}</span>
@else
<a class="page-link" href="{{ $href }}" aria-label="Страница {{ $number }}">{{ $number }}</a>
@endif
</li>
@endforeach
<li class="page-item {{ isset($pages[$current + 1]) ? '' : 'disabled' }}">
@if(isset($pages[$current + 1]))
<a class="page-link" href="{{ $pages[$current + 1] }}" aria-label="Следующая страница"><x-icon name="chevron-right" /></a>
@else
<span class="page-link" aria-disabled="true" aria-label="Следующая страница"><x-icon name="chevron-right" /></span>
@endif
</li>
</ul>
</nav>
@endif
