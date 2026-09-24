@if($realGroups ?? false)
@if($canDelete ?? false)
<x-confirmation id="delete-group" title="Удалить группу?" action="Удалить группу" :url="route($admin ? 'admin.groups.destroy' : 'psychologist.groups.destroy', $group['id'])" method="DELETE">
<p class="confirmation-object">{{ $group['title'] }}</p>
<p>Группа будет скрыта из списка. Связанная история сохранится.</p>
@if($admin && $group['status'] === 'active')
<x-alert tone="warning">Перед удалением или при удалении вручную снимите публикацию группы на gruppa.info.</x-alert>
@elseif($admin && $group['status'] === 'approved')
<x-alert tone="warning">Проверьте, опубликована ли группа в каталоге gruppa.info. Если опубликована — снимите публикацию вручную.</x-alert>
@elseif(!$admin && $group['status'] === 'rejected')
<p>Группа исчезнет из вашего кабинета и останется доступна администратору как отклонённая.</p>
@endif
</x-confirmation>
@endif
@else
<x-confirmation id="delete-group" title="Удалить группу?" action="Удалить группу" :open="$variant === 'confirmation'">
<p class="confirmation-object">{{ $group['title'] }}</p>
<p>Группа будет скрыта из списка. Связанная история сохранится.</p>
@if($admin && $group['status'] === 'active')
<x-alert tone="warning">Перед удалением или при удалении вручную снимите публикацию группы на gruppa.info.</x-alert>
@elseif($admin && $group['status'] === 'approved')
<x-alert tone="warning">Проверьте, опубликована ли группа в каталоге gruppa.info. Если опубликована — снимите публикацию вручную.</x-alert>
@elseif(!$admin && $group['status'] === 'rejected')
<p>Группа исчезнет из вашего кабинета и останется доступна администратору как отклонённая.</p>
@endif
</x-confirmation>
@endif
