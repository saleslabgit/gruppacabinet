<?php

namespace Tests\Feature\Domain;

use App\Enums\UserStatus;
use App\Models\Dictionary;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SettingsAndSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_is_idempotent_and_creates_only_approved_defaults(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(6, Dictionary::query()->count());
        $this->assertNull(Dictionary::where('code', 'education_type')->firstOrFail()->modx_tv_name);
        $this->assertSame(5, Dictionary::whereNotNull('modx_tv_name')->count());
        $this->assertSame(7, Setting::query()->count());
        $this->assertSame(1, User::query()->where('email', 'admin@gruppa.test')->count());

        $this->assertSame(1, User::query()->where('email', 'psychologist@gruppa.test')->count());
        $psychologist = User::query()->where('email', 'psychologist@gruppa.test')->firstOrFail();
        $this->assertSame(UserStatus::Approved, $psychologist->status);
        $this->assertFalse($psychologist->admin);
        $this->assertFalse($psychologist->disabled);
        $this->assertTrue($psychologist->accept);
        $this->assertTrue(Hash::check('password', (string) $psychologist->password));

        $admin = User::query()->where('email', 'admin@gruppa.test')->firstOrFail();
        $this->assertSame(UserStatus::Approved, $admin->status);
        $this->assertTrue($admin->admin);
        $this->assertTrue($admin->accept);
        $this->assertFalse($admin->disabled);
        $this->assertTrue(Hash::check('password', (string) $admin->password));
    }

    public function test_seed_preserves_existing_dictionary_names_and_legacy_items(): void
    {
        $dictionary = Dictionary::where('code', 'group_format')->firstOrFail();
        $dictionary->update(['name' => 'Existing format name']);
        $item = $dictionary->items()->create(['code' => 'legacy', 'name' => 'Historical value']);
        $this->seed(DatabaseSeeder::class);
        $this->assertSame('Existing format name', $dictionary->fresh()->name);
        $this->assertSame('legacy', $item->fresh()->code);
        $this->assertNull($item->fresh()->modx_value);
        $this->assertTrue($item->fresh()->active);
    }

    public function test_production_seed_does_not_create_known_password_accounts(): void
    {
        $this->app->instance('env', 'production');
        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertExitCode(0);
        $this->assertSame(0, User::query()->count());
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
}
