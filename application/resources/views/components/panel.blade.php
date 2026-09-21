@props(['title' => null, 'compact' => false])
<section {{ $attributes->class(['panel']) }}>
@if($title)
<h2 @class(['panel-title-compact' => $compact])>{{ $title }}</h2>
@endif{{ $slot }}</section>
