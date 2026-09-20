@extends('layouts.app')

@section('title', 'Gruppa Cabinet — Stage 1')

@section('content')
    <div class="card shadow-sm foundation-card">
        <div class="card-body p-4">
            <span class="badge text-bg-primary mb-3">Stage 1</span>
            <h1 class="h3">Gruppa Cabinet</h1>
            <p class="text-secondary mb-4">Laravel runtime foundation is available under the configured base path.</p>

            <dl class="row mb-0">
                <dt class="col-sm-5">Blade rendering</dt>
                <dd class="col-sm-7 text-success">OK</dd>
                <dt class="col-sm-5">MySQL connection</dt>
                <dd class="col-sm-7 {{ $databaseConnected ? 'text-success' : 'text-danger' }}">
                    {{ $databaseConnected ? 'OK' : 'Unavailable' }}
                </dd>
                <dt class="col-sm-5">Named route</dt>
                <dd class="col-sm-7"><a href="{{ route('foundation.redirect') }}">{{ route('foundation.redirect') }}</a></dd>
                <dt class="col-sm-5">Application asset</dt>
                <dd class="col-sm-7"><code>{{ asset('app.css') }}</code></dd>
            </dl>
        </div>
    </div>
@endsection
