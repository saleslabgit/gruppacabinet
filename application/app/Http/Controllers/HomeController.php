<?php

namespace App\Http\Controllers;

use App\Support\PsychologistCabinetPages;
use App\Support\PsychologistPages;
use Illuminate\Contracts\View\View;

class HomeController extends Controller
{
    public function psychologist(): View
    {
        return view('psychologist.groups.index', array_merge(PsychologistCabinetPages::layout('Мои группы'), [
            'empty' => true,
            'canCreateGroup' => false,
        ]));
    }

    public function admin(): View
    {
        return view('admin.home', array_merge(PsychologistPages::layout('Главная'), [
            'workQueueAvailable' => false,
        ]));
    }
}
