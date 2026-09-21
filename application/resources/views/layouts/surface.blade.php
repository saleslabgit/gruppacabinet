<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', $title ?? 'Кабинет психолога') · gruppa</title>
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap/5.3.8/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap-icons/1.13.1/bootstrap-icons.css') }}">
    <link rel="stylesheet" href="{{ asset('ui.css').'?v='.filemtime(public_path('ui.css')) }}">
</head>
<body class="surface-{{ $surface }}">
<a href="#main" class="skip-link">К содержимому</a>
<x-navbar :home-url="$homeUrl ?? null" :logout-url="($prototype ?? false) ? null : ($logoutUrl ?? null)" :navigation="$surface === 'psychologist' ? ($navigation ?? []) : []" />
@if($prototype ?? false)
<div class="prototype-note"><div class="container">Прототип · Все данные вымышлены. Действия не сохраняются. <a href="{{ $links['catalog'] }}">Все страницы и состояния</a></div></div>
@endif
<div class="container app-shell {{ $surface !== 'admin' ? 'no-sidebar' : '' }}">
    @if($surface === 'admin') <x-sidebar :logout-url="($prototype ?? false) ? null : ($logoutUrl ?? null)" :navigation="$navigation ?? []" /> @endif
    <main id="main" tabindex="-1">
        @yield('breadcrumbs')
        @if($surface !== 'public')
            <x-page-header :title="$title ?? ''" :eyebrow="$surface === 'admin' ? 'Администрирование' : 'Личный кабинет'">@yield('actions')</x-page-header>
        @endif
        @if(isset($notice)) <x-alert :tone="$notice['tone']">{{ $notice['text'] }}</x-alert> @endif
        @yield('content')
    </main>
</div>
<div class="container pb-4 meta" role="status" aria-live="polite" id="prototype-feedback"></div>
<script src="{{ asset('vendor/bootstrap/5.3.8/js/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('ui.js').'?v='.filemtime(public_path('ui.js')) }}"></script>
</body>
</html>
