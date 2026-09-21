<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

class HomeController extends Controller
{
    public function psychologist(): View
    {
        return view('psychologist.groups.index', [
            'title' => 'Мои группы',
            'prototype' => false,
            'empty' => true,
            'canCreateGroup' => false,
            'navigation' => [['label' => 'Мои группы', 'url' => route('psychologist.home'), 'current' => true]],
            'logoutUrl' => route('logout'),
            'homeUrl' => route('psychologist.home'),
        ]);
    }

    public function admin(): View
    {
        return view('admin.home', [
            'title' => 'Главная',
            'prototype' => false,
            'workQueueAvailable' => false,
            'navigation' => [['label' => 'Главная', 'url' => route('admin.home'), 'current' => true]],
            'logoutUrl' => route('logout'),
            'homeUrl' => route('admin.home'),
        ]);
    }
}
