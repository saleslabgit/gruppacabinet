@props(['id', 'label' => 'Другие действия'])
<div class="dropdown">
<x-button kind="ghost" :id="$id" data-bs-toggle="dropdown" aria-expanded="false">{{ $label }}</x-button>
<ul class="dropdown-menu" aria-labelledby="{{ $id }}">{{ $slot }}</ul>
</div>
