@if($realGroups ?? false)
@if($canDelete ?? false)
<x-confirmation id="delete-group" title="Удалить группу?" action="Удалить группу" :url="route($admin ? 'admin.groups.destroy' : 'psychologist.groups.destroy', $group['id'])" method="DELETE">
<p class="confirmation-object">{{ $group['title'] }}</p>
<p>Группа будет скрыта из списка. Связанная история сохранится.</p>
</x-confirmation>
@endif
@else
<x-confirmation id="delete-group" title="Удалить группу?" action="Удалить группу" :open="$variant === 'confirmation'">
<p class="confirmation-object">{{ $group['title'] }}</p>
<p>Группа будет скрыта из списка. Связанная история сохранится.</p>
</x-confirmation>
@endif
