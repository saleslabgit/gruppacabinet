<div class="actions">
<x-button icon="arrow-left" :href="request()->user()?->admin ? route('admin.home') : url('/')">Вернуться в кабинет</x-button>
@if(!request()->user())
<x-button icon="grid" kind="secondary" :href="route('admin.home')">Кабинет администратора</x-button>
@endif
</div>
