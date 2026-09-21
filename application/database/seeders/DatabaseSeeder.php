<?php

namespace Database\Seeders;

use App\Enums\UserStatus;
use App\Models\Dictionary;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedDictionaries();
        $this->seedSettings();
        $this->seedDevelopmentUsers();
    }

    private function seedDictionaries(): void
    {
        foreach ([
            'education_type' => 'Education type',
            'group_format' => 'Group format',
            'gender' => 'Gender',
        ] as $code => $name) {
            Dictionary::query()->updateOrCreate(['code' => $code], ['name' => $name]);
        }
    }

    private function seedSettings(): void
    {
        foreach ([
            SettingService::PLACEMENT_PRICE_MINOR_UNITS => null,
            SettingService::EXTENSION_PRICE_MINOR_UNITS => null,
            SettingService::PLACEMENT_DURATION_DAYS => '30',
            SettingService::EXPIRY_WARNING_DAYS => '3',
            SettingService::EXPIRED_EXTENSION_WINDOW_DAYS => '30',
            SettingService::PARTICIPANT_APPLICATION_RETENTION_MONTHS => '12',
            SettingService::PASSWORD_SETUP_LINK_TTL_HOURS => '72',
        ] as $key => $value) {
            Setting::query()->firstOrCreate(
                ['key' => $key],
                ['type' => 'integer', 'value' => $value],
            );
        }
    }

    private function seedDevelopmentUsers(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        User::query()->firstOrCreate(
            ['email' => 'psychologist@gruppa.test'],
            [
                'password' => Hash::make('password'),
                'status' => UserStatus::Approved,
                'admin' => false,
                'disabled' => false,
            ],
        );

        User::query()->firstOrCreate(
            ['email' => 'admin@gruppa.test'],
            [
                'password' => Hash::make('password'),
                'status' => UserStatus::Approved,
                'admin' => true,
                'disabled' => false,
            ],
        );
    }
}
