@props(['items' => []])
@if(count($items) > 1)
<nav class="breadcrumbs" aria-label="Хлебные крошки">
<ol>
@foreach($items as $item)
<li>
@if(!$loop->first)<x-icon name="chevron-right" />@endif
@if(!$loop->last)
<a href="{{ $item['url'] }}">{{ $item['label'] }}</a>
@else
<span aria-current="page">{{ $item['label'] }}</span>
@endif
</li>
@endforeach
</ol>
</nav>
@endif
