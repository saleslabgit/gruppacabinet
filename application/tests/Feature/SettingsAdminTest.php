<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Group;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Services\AuditService;
use App\Services\SettingService;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SettingsAdminTest extends TestCase
{
    // Real commits are necessary to verify shared cache and afterCommit behavior.
    use DatabaseMigrations;

    private User $admin;

    private SettingService $settings;

    protected function setUp(): void
    {
        parent::setUp();
        URL::forceRootUrl('http://localhost');
        Cache::flush();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::where('admin', true)->firstOrFail();
        $this->settings = app(SettingService::class);
        $this->actingAs($this->admin);
    }

    private function fields(array $changes = []): array
    {
        return array_replace(['placement_price' => '50,00', 'extension_price' => '25.5', 'placement_duration_days' => '45', 'expiry_warning_days' => '4', 'expired_extension_window_days' => '30', 'participant_application_retention_months' => '12', 'password_setup_link_ttl_hours' => '72', 'confirmed' => 1], $changes);
    }

    public function test_round_trip_typed_values_minimal_audit_cache_no_payments_and_unchanged_save(): void
    {
        $this->assertSame(30, $this->settings->placementDurationDays());
        $this->get('/admin/settings')->assertOk()->assertViewIs('admin.settings.index')->assertSee('settings-form');
        $this->put('/admin/settings', $this->fields(['confirmed' => 0]))->assertSessionHasErrors('confirmed');
        $this->put('/admin/settings', $this->fields())->assertSessionHasNoErrors();
        $this->assertSame(45, $this->settings->placementDurationDays());
        $this->assertSame(5000, $this->settings->placementPriceMinorUnits());
        $this->assertSame(2550, $this->settings->extensionPriceMinorUnits());
        $this->get('/admin/settings')->assertOk()->assertSee('value="50,00"', false)->assertSee('value="25,50"', false);
        $audit = AuditLog::where('action', 'setting.updated')->where('entity_id', Setting::where('key', 'placement_price_minor_units')->value('id'))->sole();
        $this->assertSame($this->admin->id, $audit->actor_id);
        $this->assertSame('setting', $audit->entity_type);
        $this->assertSame(['key' => 'placement_price_minor_units', 'new_value' => 5000, 'old_value' => null], $audit->metadata);
        $count = AuditLog::count();
        $this->put('/admin/settings', $this->fields())->assertSessionHasNoErrors();
        $this->assertSame($count, AuditLog::count());
        $this->put('/admin/settings', $this->fields(['placement_price' => '', 'extension_price' => '']))->assertSessionHasNoErrors();
        $this->assertNull($this->settings->placementPriceMinorUnits());
        $this->assertNull($this->settings->extensionPriceMinorUnits());
        $this->assertDatabaseCount('gp_payments', 0);
        $this->assertDatabaseCount('gp_payment_notifications', 0);
    }

    public static function invalidValues(): array
    {
        return array_map(fn ($value) => ['placement_price', $value], ['-1', '1e3', '1.001', '1,2.3', '92233720368547758.08', 'NaN', '.', []]);
    }

    #[DataProvider('invalidValues')]
    public function test_invalid_prices_are_rejected(string $key, mixed $value): void
    {
        $this->put('/admin/settings', $this->fields([$key => $value]))->assertSessionHasErrors($key);
        $this->assertNull($this->settings->placementPriceMinorUnits());
    }

    public function test_valid_money_boundaries_and_integer_cross_field_validation(): void
    {
        foreach (['50' => 5000, '50.0' => 5000, '50,00' => 5000, '0.01' => 1, '92233720368547758.07' => PHP_INT_MAX] as $input => $expected) {
            $this->put('/admin/settings', $this->fields(['placement_price' => (string) $input]))->assertSessionHasNoErrors();
            $this->assertSame($expected, $this->settings->placementPriceMinorUnits());
        }
        foreach (SettingService::INTEGER_KEYS as $key) {
            foreach (['0', '-1', '1.5', '9223372036854775808'] as $input) {
                $this->put('/admin/settings', $this->fields([$key => $input]))->assertSessionHasErrors($key);
            }
        }
        $this->put('/admin/settings', $this->fields(['expiry_warning_days' => 45]))->assertSessionHasErrors('expiry_warning_days');
        $this->put('/admin/settings', $this->fields(['placement_duration_days' => SettingService::maximumPlacementDays() + 1]))->assertSessionHasErrors('placement_duration_days');
    }

    public function test_unknown_keys_missing_rows_and_untyped_values_fail_without_partial_updates(): void
    {
        $original = $this->settings->values();
        foreach ([$original + ['unknown' => 1], array_replace($original, ['placement_duration_days' => '45']), array_replace($original, ['expiry_warning_days' => null])] as $values) {
            try {
                $this->settings->update($values, $this->admin);
                $this->fail('Expected rejection');
            } catch (DomainException) {
            }
        }
        Setting::where('key', 'extension_price_minor_units')->delete();
        try {
            $this->settings->update(array_replace($original, ['placement_duration_days' => 45]), $this->admin);
            $this->fail('Expected missing row rejection');
        } catch (DomainException) {
        }
        $this->assertSame('30', Setting::where('key', 'placement_duration_days')->value('value'));
        $this->assertDatabaseCount('gp_audit_log', 0);
    }

    public function test_failed_audit_rolls_back_database_and_cached_values(): void
    {
        $original = $this->settings->values();
        $this->mock(AuditService::class, function ($mock): void {
            $mock->shouldReceive('record')->once()->andReturnUsing(function (): never {
                // A read during the transaction must never populate shared cache.
                Cache::forget('settings.placement_price_minor_units');
                $this->assertSame(100, $this->settings->placementPriceMinorUnits());
                throw new RuntimeException('Synthetic audit failure');
            });
        });
        try {
            $this->settings->update(array_replace($original, ['placement_price_minor_units' => 100]), $this->admin);
            $this->fail('Expected audit failure');
        } catch (RuntimeException $e) {
            $this->assertSame('Synthetic audit failure', $e->getMessage());
        }
        $this->assertSame($original, $this->settings->values());
        $this->assertNull(Setting::where('key', 'placement_price_minor_units')->value('value'));
        $this->assertDatabaseCount('gp_audit_log', 0);
    }

    public function test_nested_rollback_keeps_cache_and_commit_refreshes_it(): void
    {
        $original = $this->settings->values();
        DB::beginTransaction();
        $this->settings->update(array_replace($original, ['placement_duration_days' => 45]), $this->admin);
        $this->assertSame('30', Cache::get('settings.placement_duration_days')['value']);
        $this->assertSame(45, $this->settings->placementDurationDays());
        DB::rollBack();
        $this->assertSame(30, $this->settings->placementDurationDays());
        DB::transaction(fn () => $this->settings->update(array_replace($original, ['placement_duration_days' => 50]), $this->admin));
        $this->assertSame(50, $this->settings->placementDurationDays());
    }

    public function test_existing_activation_snapshot_is_unchanged_and_later_activation_uses_new_duration(): void
    {
        $owner = User::where('admin', false)->firstOrFail();
        $old = Group::create(['owner_id' => $owner->id, 'status' => 'approved']);
        $this->post('/admin/groups/'.$old->id.'/activate', ['confirmed' => 1])->assertSessionHasNoErrors();
        $snapshot = $old->fresh()->only(['placement_days', 'published_at', 'expires_at']);
        $this->put('/admin/settings', $this->fields())->assertSessionHasNoErrors();
        $new = Group::create(['owner_id' => $owner->id, 'status' => 'approved']);
        $this->post('/admin/groups/'.$new->id.'/activate', ['confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertEquals($snapshot, $old->fresh()->only(['placement_days', 'published_at', 'expires_at']));
        $this->assertSame(45, $new->fresh()->placement_days);
        $this->assertTrue($new->fresh()->published_at->addDays(45)->eq($new->fresh()->expires_at));
        $this->assertDatabaseCount('gp_payments', 0);
    }

    public function test_payment_list_is_available_without_configuring_business_prices(): void
    {
        $this->get('/admin/payments')->assertOk()->assertViewIs('admin.payments.index')
            ->assertSee('Платежи не найдены')->assertSee('name="search"', false);
        $this->get('/admin/payments/1')->assertNotFound();
        $this->post('/admin/payments')->assertStatus(405);
        $this->assertDatabaseCount('gp_payments', 0);
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

    public function test_price_edits_preserve_existing_payment_and_group(): void
    {
        $owner = User::where('admin', false)->firstOrFail();
        $group = Group::create(['owner_id' => $owner->id]);
        $payment = Payment::create(['owner_id' => $owner->id, 'group_id' => $group->id, 'type' => 'placement', 'order_number' => 'historical-local-test', 'amount' => 1200, 'status' => 'created']);
        $before = [$group->fresh()->getAttributes(), $payment->fresh()->getAttributes()];
        $this->put('/admin/settings', $this->fields())->assertSessionHasNoErrors();
        $this->assertSame($before, [$group->fresh()->getAttributes(), $payment->fresh()->getAttributes()]);
        $this->assertDatabaseCount('gp_payments', 1);
    }
}
