<?php

namespace App\Support;

class PsychologistCabinetPages
{
    public static function layout(string $title): array
    {
        return [
            'title' => $title, 'prototype' => false, 'variant' => 'normal',
            'navigation' => [
                ['label' => 'Мои группы', 'url' => route('psychologist.home'), 'current' => request()->routeIs('psychologist.home', 'psychologist.groups.*')],
                ['label' => 'Мои данные', 'url' => route('psychologist.profile'), 'current' => request()->routeIs('psychologist.profile')],
            ],
            'logoutUrl' => route('logout'), 'homeUrl' => route('psychologist.home'),
        ];
    }
}
