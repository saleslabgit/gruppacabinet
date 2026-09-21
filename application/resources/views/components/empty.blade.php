@props(['title' => 'Пока нет записей', 'text' => 'Здесь появятся данные, когда они будут доступны.'])
<div class="panel empty">
<h2>{{ $title }}</h2>
<p class="small mb-3">{{ $text }}</p>
<div class="actions">{{ $slot }}</div>
</div>
