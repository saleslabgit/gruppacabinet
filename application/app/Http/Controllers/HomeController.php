<?php

namespace App\Http\Controllers;

use App\Support\PsychologistPages;
use Illuminate\Contracts\View\View;

class HomeController extends Controller
{
    public function admin(): View
    {
        return view('admin.home', array_merge(PsychologistPages::layout('Главная'), [
            'workQueueAvailable' => false,
        ]));
    }
}
