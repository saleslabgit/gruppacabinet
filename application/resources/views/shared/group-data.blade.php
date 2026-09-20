<x-panel :title="$group['title']">
@include('shared.group-summary')
<hr class="my-4">
<h3>О группе</h3>
<p class="mb-4">{{ $group['description'] }}</p>
<dl class="detail-grid">
<div>
<dt>Расписание</dt>
<dd>{{ $group['schedule'] }}</dd>
</div>
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
</dl>
</x-panel>
