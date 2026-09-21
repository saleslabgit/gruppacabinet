@props(['title' => 'Пока нет записей', 'text' => 'Здесь появятся данные, когда они будут доступны.'])
<div class="panel empty">
<x-icon name="inbox" class="empty-icon" />
<h2>{{ $title }}</h2>
<p class="small mb-3">{{ $text }}</p>
<div class="actions">{{ $slot }}</div>
</div>
