<div style="white-space: pre-wrap">
{{ $groupTitle }}
{{ match($result) { 'approved' => 'Группа прошла модерацию и ожидает первой публикации. Срок размещения ещё не начался.', 'revision' => 'Группе требуется доработка.', default => 'Группа отклонена.' } }}
@if($result !== 'approved'){{ $comment }}@endif
</div>
<p><a href="{{ $groupUrl }}">Открыть группу в кабинете</a></p>
