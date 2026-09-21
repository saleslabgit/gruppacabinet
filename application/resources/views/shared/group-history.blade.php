<x-panel title="История статусов и замечаний" :muted="true">
<ol class="timeline">
@if($realGroups ?? false)
@foreach($history as $entry)
<li>
<strong>@if($entry->from_status)<x-status :value="$entry->from_status->value" /> → @endif<x-status :value="$entry->to_status->value" /></strong>
@if($entry->comment)<p>{{ $entry->comment }}</p>@endif
<p class="meta"><x-date :value="$entry->created_at" /> · {{ $entry->actor ? \App\Support\PsychologistPages::profile($entry->actor)['name'] : ($entry->actor_type === 'system' ? 'Система' : 'Пользователь') }}</p>
</li>
@endforeach
@else
<li>
<strong>Черновик создан</strong>
<p class="meta">
<x-date :value="$group['created_at']" /> · Психолог</p>
</li>
@if(!in_array($group['status'], ['draft','awaiting_payment']))
<li>
<strong>Отправлена на модерацию</strong>
<p class="meta">Психолог · <x-date :value="$group['created_at']->addDay()" />
</p>
</li>
@endif
@if($group['status'] === 'revision')
<li>
<strong>Ранее запрошена доработка</strong>
<p>Добавьте описание формата участия.</p>
<p class="meta">Администратор · <x-date :value="$group['created_at']->addHours(26)" />
</p>
</li>
<li>
<strong>Повторно отправлена на модерацию</strong>
<p class="meta">Психолог · <x-date :value="$group['created_at']->addHours(36)" />
</p>
</li>
@endif
@if(in_array($group['status'], ['revision','rejected']))
<li>
<strong>{{ $group['status'] === 'revision' ? 'Запрошена доработка' : 'Отклонена' }}</strong>
<p>{{ $group['status'] === 'revision' ? $group['moderator_comment'] : $group['rejection_reason'] }}</p>
<p class="meta">Администратор · <x-date :value="$group['created_at']->addDays(2)" />
</p>
</li>
@endif
@if(in_array($group['status'], ['approved','active','expired']))
<li>
<strong>Одобрена</strong>
<p class="meta">Администратор · <x-date :value="$group['created_at']->addDays(2)" />
</p>
</li>
@endif
@if($group['published_at'])
<li>
<strong>Опубликована вручную</strong>
<p class="meta">Администратор · <x-date :value="$group['published_at']" />
</p>
</li>
@endif
@if($group['status'] === 'expired')
<li>
<strong>Срок размещения закончился</strong>
<p class="meta">Система · <x-date :value="$group['expires_at']" />
</p>
</li>
@endif
@endif
</ol>
</x-panel>
