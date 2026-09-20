<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', config('app.name'))</title>
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap/5.3.8/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('app.css') }}">
</head>
<body>
    <main class="container py-5">
        @yield('content')
    </main>

    <script src="{{ asset('vendor/bootstrap/5.3.8/js/bootstrap.bundle.min.js') }}"></script>
    <script src="{{ asset('app.js') }}"></script>
</body>
</html>
