<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $databaseConnected = (int) DB::selectOne('SELECT 1 AS connected')?->connected === 1;

    return view('foundation', compact('databaseConnected'));
})->name('foundation');

Route::get('/redirect-check', fn () => redirect()->route('foundation'))
    ->name('foundation.redirect');
