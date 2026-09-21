<?php

namespace App\Support;

use App\Models\User;

class PsychologistPages
{
    public static function layout(string $title): array
    {
        return [
            'title' => $title, 'prototype' => false, 'variant' => 'normal', 'empty' => false,
            'navigation' => [
                ['label' => 'Главная', 'url' => route('admin.home'), 'current' => request()->routeIs('admin.home')],
                ['label' => 'Психологи', 'url' => route('admin.psychologists.index'), 'current' => request()->routeIs('admin.psychologists.*')],
                ['label' => 'Группы', 'url' => route('admin.groups.index'), 'current' => request()->routeIs('admin.groups.*')],
                ['label' => 'Платежи', 'url' => route('admin.payments.index'), 'current' => request()->routeIs('admin.payments.*')],
                ['label' => 'Справочники', 'url' => route('admin.dictionaries.index'), 'current' => request()->routeIs('admin.dictionaries.*')],
                ['label' => 'Настройки', 'url' => route('admin.settings.index'), 'current' => request()->routeIs('admin.settings.*')],
            ],
            'logoutUrl' => route('logout'), 'homeUrl' => route('admin.home'),
            'links' => ['admin-users' => route('admin.psychologists.index'), 'admin-user-form' => route('admin.psychologists.create')],
            'notice' => session()->has('success') ? ['tone' => 'success', 'text' => session('success')] : null,
            'errors' => session('errors')?->getBag('default')->messages() ? array_map(fn ($messages) => $messages[0], session('errors')->getBag('default')->messages()) : [],
        ];
    }

    public static function profile(User $user): array
    {
        $data = $user->only([
            'id', 'last_name', 'first_name', 'middle_name', 'phone', 'email', 'education_type_id',
            'other_education', 'modality_program', 'training_center', 'graduation_year', 'training_hours',
            'license_number', 'group_leading_experience', 'groups_conducted_count',
            'documents_confirmed', 'education_confirmed', 'live_session_ready', 'personal_data_consent_version',
            'free', 'disabled',
        ]);
        $data['name'] = trim(implode(' ', array_filter([$user->last_name, $user->first_name, $user->middle_name]))) ?: $user->email;
        $data['status'] = $user->status->value;
        $data['created_at'] = $user->created_at;
        $data['license_expires_at'] = $user->license_expires_at?->format('Y-m-d');
        $data['personal_data_consent_at'] = $user->personal_data_consent_at;
        $data['education_type'] = $user->relationLoaded('educationType') ? $user->educationType?->name : null;

        return $data;
    }
}
