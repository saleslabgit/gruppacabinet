@extends('layouts.public')
@section('content')
<x-page-header title="Каталог прототипов" eyebrow="Этап 3 · 31 группа страниц" />
<p class="mb-4">Кабинет психолога и административная часть. Выберите страницу и состояние. Все примеры вымышлены; формы и бизнес-действия ничего не сохраняют.</p>
<div class="catalog-grid">
@foreach($pages as $slug => $page)
<x-panel :title="$loop->iteration.'. '.$page['title']">
<div class="catalog-links">
@foreach($page['variants'] as $variant)
<a data-prototype-link href="{{ route('prototype.'.$slug, ['variant' => $variant]) }}">{{ $variant }}</a>
@endforeach
</div>
</x-panel>
@endforeach
</div>
@endsection
