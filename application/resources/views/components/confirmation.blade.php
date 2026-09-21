@props(['id', 'title', 'action' => 'Подтвердить', 'kind' => 'danger', 'open' => false, 'url' => null, 'method' => 'POST', 'fields' => []])
<div class="modal fade" id="{{ $id }}" tabindex="-1" aria-labelledby="{{ $id }}-title" aria-hidden="true" @if($open) data-prototype-open @endif>
<div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
<div class="modal-content">
<div class="modal-header">
<h2 class="modal-title" id="{{ $id }}-title">{{ $title }}</h2>
<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть">
</button>
</div>
<div class="modal-body">{{ $slot }}</div>
<div class="modal-footer">
<x-button kind="secondary" data-bs-dismiss="modal">Отмена</x-button>
@if($url)
<form id="{{ $id }}-form" method="POST" action="{{ $url }}">
@csrf
@if($method !== 'POST') @method($method) @endif
<input type="hidden" name="confirmed" value="1">
@foreach($fields as $name => $value)
<input type="hidden" name="{{ $name }}" value="{{ $value }}">
@endforeach
<x-button type="submit" :kind="$kind">{{ $action }}</x-button>
</form>
@else
<x-button :kind="$kind" data-noop>{{ $action }}</x-button>
@endif
</div>
</div>
</div>
</div>
