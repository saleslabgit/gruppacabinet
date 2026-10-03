{{ $groupTitle }}
{{ match($result) { 'approved' => 'Группа прошла модерацию и ожидает первой публикации. Срок размещения ещё не начался.', 'revision' => 'Группе требуется доработка.', default => 'Группа отклонена.' } }}
@if($result !== 'approved'){{ $comment }}@endif

{{ $groupUrl }}
