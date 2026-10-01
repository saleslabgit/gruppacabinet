<x-panel :title="$group['title']">
@include('shared.group-summary')
<hr class="my-4">
<h3>Краткое описание</h3>
<p class="mb-4 readable-text">{{ $group['description'] }}</p>
@if(!empty($group['full_description_html']))
<h3>Полное описание</h3>
<div class="rich-description">{!! $group['full_description_html'] !!}</div>
@endif
@if($coverUrl ?? null)<img class="group-cover" src="{{ $coverUrl }}" alt="Обложка группы" loading="lazy">@endif
<dl class="detail-grid">
<div>
<dt>Расписание</dt>
<dd>
@if(!empty($group['meeting_days']) || !empty($group['start_time']))
{{ implode(', ', array_intersect_key(\App\Services\GroupContent::DAYS, array_flip($group['meeting_days'] ?? []))) }} {{ $group['start_time'] ?? '' }} (Минск)
@else
{{ $group['schedule'] ?: 'Не указано' }} @if($group['schedule'])<span class="meta">· прежнее расписание</span>@endif
@endif
</dd>
</div>
<div>
<dt>Периодичность</dt><dd>{{ $group['frequency'] ?? 'Не указана' }}</dd>
</div>
<div><dt>Город</dt><dd>{{ $group['city'] ?? 'Не указан' }}</dd></div>
<div><dt>Тип группы</dt><dd>{{ $group['group_type'] ?? 'Не указан' }}</dd></div>
<div><dt>Подходы</dt><dd>{{ implode(', ', $group['approaches'] ?? []) ?: 'Не указаны' }}</dd></div>
<div><dt>Теги</dt><dd>{{ implode(', ', $group['tags'] ?? []) ?: 'Не указаны' }}</dd></div>
<div>
<dt>Длительность встречи</dt>
<dd>{{ $group['meeting_duration_minutes'] }} минут</dd>
</div>
<div>
<dt>Участников</dt>
<dd>{{ $group['participant_capacity'] }}</dd>
</div>
<div>
<dt>Пол участников</dt>
<dd>{{ $group['gender'] }}</dd>
</div>
<div>
<dt>Стоимость встречи</dt>
<dd>
<x-money :value="$group['meeting_price']" />
</dd>
</div>
@if(trim($group['meeting_price_currency'] ?? '') !== '')
<div><dt>Стоимость встречи (В валюте)</dt><dd>{{ $group['meeting_price_currency'] }}</dd></div>
@endif
</dl>
</x-panel>
