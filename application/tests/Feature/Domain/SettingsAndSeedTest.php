<?php

namespace Tests\Feature\Domain;

use App\Enums\UserStatus;
use App\Models\Dictionary;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SettingsAndSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_is_idempotent_and_creates_only_approved_defaults(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(3, Dictionary::query()->count());
        $this->assertSame(7, Setting::query()->count());
        $this->assertSame(1, User::query()->where('email', 'admin@gruppa.test')->count());

        $admin = User::query()->where('email', 'admin@gruppa.test')->firstOrFail();
        $this->assertSame(UserStatus::Approved, $admin->status);
        $this->assertTrue($admin->admin);
        $this->assertTrue($admin->accept);
        $this->assertFalse($admin->disabled);
        $this->assertTrue(Hash::check('password', (string) $admin->password));
    }

    public function test_setting_service_returns_typed_values_and_keeps_prices_unconfigured(): void
    {
        $this->seed(DatabaseSeeder::class);
        $settings = app(SettingService::class);

        $this->assertNull($settings->placementPriceMinorUnits());
        $this->assertNull($settings->extensionPriceMinorUnits());
        $this->assertSame(30, $settings->placementDurationDays());
        $this->assertSame(3, $settings->expiryWarningDays());
        $this->assertSame(30, $settings->expiredExtensionWindowDays());
        $this->assertSame(12, $settings->participantApplicationRetentionMonths());
        $this->assertSame(72, $settings->passwordSetupLinkTtlHours());
    }

    public function test_setting_reads_are_cached_and_can_be_invalidated_explicitly(): void
    {
        $this->seed(DatabaseSeeder::class);
        Cache::flush();
        $settings = app(SettingService::class);

        $this->assertSame(30, $settings->placementDurationDays());

        Setting::query()
            ->where('key', SettingService::PLACEMENT_DURATION_DAYS)
            ->update(['value' => '45']);

        $this->assertSame(30, $settings->placementDurationDays());

        $settings->invalidate(SettingService::PLACEMENT_DURATION_DAYS);
        $this->assertSame(45, $settings->placementDurationDays());
    }
}
