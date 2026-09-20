@props(['title' => null])
<section {{ $attributes->class(['panel']) }}>
@if($title)
<h2>{{ $title }}</h2>
@endif{{ $slot }}</section>
