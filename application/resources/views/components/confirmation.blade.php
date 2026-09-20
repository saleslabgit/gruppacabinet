@props(['id', 'title', 'action' => 'Подтвердить', 'kind' => 'danger', 'open' => false])
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
<x-button :kind="$kind" data-noop>{{ $action }}</x-button>
</div>
</div>
</div>
</div>
