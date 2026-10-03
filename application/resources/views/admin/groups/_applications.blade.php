<x-panel title="Заявки участников">
@include('shared.application-counters')
@if($realGroups ?? false)
@forelse($recentApplications as $record)
<p><a href="{{ route('admin.applications.show', $record) }}">{{ $record->last_name }} {{ $record->first_name }}</a> · {{ $record->phone }} · <x-date :value="$record->created_at" /> · <x-status domain="application" :value="$record->processed_at ? 'processed' : 'new'" />
@if($record->psychologist_deleted_at)<span class="meta">Психолог удалил заявку</span>@endif</p>
@empty<p>Заявок пока нет.</p>@endforelse
<a href="{{ route('admin.applications.index', ['group_id' => $group['id']]) }}">Все заявки группы</a>
@else
<p>{{ $application['name'] }} · {{ $application['phone'] }} · <x-date :value="$application['created_at']" /> · <x-status domain="application" :value="$application['processed_at'] ? 'processed' : 'new'" /></p>
<a href="{{ $links['admin-application'] }}">Открыть заявку</a> · <a href="{{ $links['admin-applications'] }}">Все заявки группы</a>
@endif
</x-panel>
