@if(!($realGroups ?? false) || ($canDelete ?? false))
<x-confirmation id="delete-group" title="Удалить группу?" action="Удалить группу" :url="($realGroups ?? false) ? route($admin ? 'admin.groups.destroy' : 'psychologist.groups.destroy', $group['id']) : null" method="DELETE" :open="$variant === 'confirmation'">
<p class="confirmation-object">{{ $group['title'] }}</p>
<p>Группа будет скрыта из списка. Связанная история и платежи сохранятся.</p>
<p>{{ ($admin ?? false) ? 'Для синхронизированной группы будет автоматически запрошено снятие с публикации на gruppa.info.' : 'Если группа опубликована на gruppa.info, будет запрошено снятие с публикации.' }} Возврат оплаты автоматически не выполняется.</p>
@unless($admin)<p>Группа останется доступна администратору.</p>@endunless
</x-confirmation>
@endif
