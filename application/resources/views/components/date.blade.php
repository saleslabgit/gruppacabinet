@props(['value' => null])
@if($value)
<time datetime="{{ $value->format(DATE_ATOM) }}">{{ \App\Support\DateTimeFormatter::format($value) }}</time>
@else
<span class="meta">Не установлена</span>
@endif
