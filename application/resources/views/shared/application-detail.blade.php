<x-panel :title="$application['name']">
<div class="mb-4">
<x-status domain="application" :value="$application['processed_at'] ? 'processed' : 'new'" />
</div>
<dl class="detail-grid">
<div>
<dt>Телефон</dt>
<dd>{{ $application['phone'] }}</dd>
</div>
<div>
<dt>Группа</dt>
<dd>
<a href="{{ $links[$admin ? 'admin-group' : 'group'] }}">{{ $group['title'] }}</a>
</dd>
</div>
@if($admin)
<div>
<dt>Психолог</dt>
<dd>
<a href="{{ $links['admin-user'] }}">{{ $user['name'] }}</a>
</dd>
</div>
@endif
<div>
<dt>Получена</dt>
<dd>
<x-date :value="$application['created_at']" />
</dd>
</div>
<div>
<dt>Обновлена</dt>
<dd>
<x-date :value="$application['updated_at']" />
</dd>
</div>
<div>
<dt>Обработана</dt>
<dd>
<x-date :value="$application['processed_at']" />
</dd>
</div>
</dl>
<div class="actions mt-4">
@unless($admin)
<x-button data-noop>{{ $application['processed_at'] ? 'Вернуть в необработанные' : 'Отметить обработанной' }}</x-button>
@endunless
<x-button kind="ghost" :href="$links[$admin ? 'admin-applications' : 'applications']">Назад к заявкам</x-button>
</div>
</x-panel>
