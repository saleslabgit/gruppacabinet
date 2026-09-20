@props(['title', 'eyebrow' => ''])
<header class="page-header">
<div>
<p class="eyebrow">{{ $eyebrow }}</p>
<h1>{{ $title }}</h1>
</div>
<div class="actions">{{ $slot }}</div>
</header>
