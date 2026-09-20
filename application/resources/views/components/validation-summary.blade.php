@props(['errors' => []])
@if($errors)
<x-alert tone="danger">
<strong>Проверьте заполнение формы</strong>
<ul class="mb-0">
@foreach($errors as $name => $error)
<li>
<a href="#{{ $name }}">{{ $error }}</a>
</li>
@endforeach
</ul>
</x-alert>
@endif
