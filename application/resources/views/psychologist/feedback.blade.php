@extends('layouts.psychologist')
@section('content')
<x-panel title="Сообщить об ошибке">
<p>Опишите проблему. Только текст, до 2800 символов, без вложений.</p>
<x-validation-summary :errors="$errors" />
@if(($prototype ?? false) && $variant === 'error')<x-alert tone="danger">Не удалось поставить сообщение в очередь. Попробуйте позже.</x-alert>@endif
<form @if($prototype ?? false) data-prototype-form @else method="POST" action="{{ route('psychologist.feedback.store') }}" @endif>
@unless($prototype ?? false)
@csrf
@endunless
<x-textarea name="message" label="Сообщение" :required="true" maxlength="2800" :value="($prototype ?? false) ? '' : old('message')" :error="$errors['message'] ?? null" />
<x-button type="submit">Отправить</x-button>
</form>
</x-panel>
@endsection
