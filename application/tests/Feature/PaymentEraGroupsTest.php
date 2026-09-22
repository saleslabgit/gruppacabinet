<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Payment;
use App\Models\PaymentNotification;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class PaymentEraGroupsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        URL::forceRootUrl('http://localhost');
        $this->travelTo(now()->startOfSecond());
        $this->owner = User::where('email', 'psychologist@gruppa.test')->sole();
        $this->admin = User::where('email', 'admin@gruppa.test')->sole();
    }

    private function group(string $status = 'awaiting_payment', int $days = 31): Group
    {
        return Group::create(['owner_id' => $this->owner->id, 'title' => 'Synthetic payment group', 'status' => $status, 'created_at' => now()->subDays($days)]);
    }

    private function payment(Group $group, string $status = 'pending', bool $refunded = false): Payment
    {
        return Payment::create(['owner_id' => $this->owner->id, 'group_id' => $group->id, 'type' => 'placement', 'order_number' => 'synthetic-'.bin2hex(random_bytes(8)), 'amount' => 5000, 'status' => $status, 'refunded_at' => $refunded ? now() : null]);
    }

    public function test_abandoned_boundaries_pagination_and_soft_delete_preserves_history(): void
    {
        $old = [];
        foreach (['awaiting_payment', 'draft'] as $status) {
            $old[] = $this->group($status, config('groups.abandoned_draft_days'));
            $young = $this->group($status, config('groups.abandoned_draft_days'));
            $young->update(['created_at' => $young->created_at->addSecond()]);
            $this->actingAs($this->admin)->delete('/admin/groups/'.$young->id, ['confirmed' => 1])->assertForbidden();
        }
        foreach (['active', 'approved', 'rejected', 'expired', 'revision', 'moderation'] as $status) {
            $other = $this->group($status, 60);
            $this->delete('/admin/groups/'.$other->id, ['confirmed' => 1])->assertForbidden();
        }
        $this->get('/admin/groups?quick=abandoned')->assertOk()->assertViewHas('groups', fn ($rows) => $rows->pluck('id')->all() === [$old[1]->id, $old[0]->id]);
        $payment = $this->payment($old[0]);
        $notification = PaymentNotification::create(['payment_id' => $payment->id, 'order_number' => $payment->order_number, 'payload' => [], 'signature_valid' => false, 'processed' => false, 'result' => 'synthetic']);
        foreach ($old as $group) {
            $this->delete('/admin/groups/'.$group->id, ['confirmed' => 1])->assertRedirect();
            $this->assertSoftDeleted($group);
        }
        $this->assertDatabaseHas('gp_payments', ['id' => $payment->id]);
        $this->assertDatabaseHas('gp_payment_notifications', ['id' => $notification->id]);
        for ($i = 0; $i < 21; $i++) {
            $this->group();
        }
        $this->get('/admin/groups?quick=abandoned')->assertSee('quick=abandoned&amp;page=2', false);
        $this->get('/admin/groups?quick=abandoned&page=2')->assertViewHas('groups', fn ($rows) => $rows->count() === 1);
    }

    public function test_payment_safety_and_owner_deletion_rules_remain_authoritative(): void
    {
        $group = $this->group();
        $payment = $this->payment($group, 'succeeded');
        $payment->delete();
        $this->actingAs($this->admin)->delete('/admin/groups/'.$group->id, ['confirmed' => 1])->assertForbidden();
        $payment->update(['status' => 'refunded', 'refunded_at' => now()]);
        $this->delete('/admin/groups/'.$group->id, ['confirmed' => 1])->assertRedirect();
        $young = $this->group(days: 0);
        $this->payment($young, 'refunded', true);
        $this->delete('/admin/groups/'.$young->id, ['confirmed' => 1])->assertForbidden();
        $this->actingAs($this->owner)->delete('/groups/'.$young->id, ['confirmed' => 1])->assertForbidden();
        foreach (['draft', 'rejected'] as $status) {
            $owned = $this->group($status, 0);
            $this->delete('/groups/'.$owned->id, ['confirmed' => 1])->assertRedirect();
        }
    }

    public function test_successful_filter_composes_and_paginates_without_n_plus_one(): void
    {
        $yes = $this->group('draft');
        $this->payment($yes, 'succeeded')->delete();
        $no = [$this->group('draft')->id];
        foreach (['pending', 'created', 'failed', 'cancelled', 'refunded'] as $status) {
            $group = $this->group('draft');
            $this->payment($group, $status, $status === 'refunded');
            $no[] = $group->id;
        }
        $refunded = $this->group('draft');
        $this->payment($refunded, 'succeeded', true);
        $no[] = $refunded->id;
        $this->actingAs($this->admin)->get('/admin/groups?successful_payment=yes')->assertOk()->assertSee('name="successful_payment"', false)
            ->assertViewHas('groups', fn ($rows) => $rows->pluck('id')->all() === [$yes->id]);
        $this->get('/admin/groups?successful_payment=no')->assertOk()->assertViewHas('groups', fn ($rows) => $rows->pluck('id')->all() === array_reverse($no));
        $this->get('/admin/groups?successful_payment=invalid')->assertSessionHasErrors('successful_payment');
        $counts = [];
        foreach ([1, 24] as $size) {
            if ($size > 1) {
                for ($i = 1; $i < $size; $i++) {
                    $this->payment($this->group('draft'), 'succeeded');
                }
            }
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->get('/admin/groups?successful_payment=yes&status=draft&free=paid&search=Synthetic&quick=abandoned&sort=created_at')->assertOk()
                ->assertViewHas('groups', fn ($rows) => $rows->count() === min($size, 20));
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $counts[] = count($queries);
            $this->assertCount(2, array_filter($queries, fn ($query) => str_contains($query['query'], 'gp_payments')));
        }
        $this->assertSame($counts[0], $counts[1]);
        $this->get('/admin/groups?successful_payment=yes&status=draft&page=2')->assertOk()
            ->assertViewHas('groups', fn ($rows) => $rows->count() === 4)->assertSee('successful_payment=yes&amp;status=draft', false);
    }

    public function test_real_copy_latest_payment_link_disabled_and_owner_boundaries(): void
    {
        $group = $this->group();
        $this->payment($group, 'failed');
        $latest = $this->payment($group, 'created');
        $this->actingAs($this->owner)->get('/')->assertOk()->assertDontSee('Историческая запись')->assertSee('Ожидается оплата размещения')->assertSee('Оплатить размещение');
        $this->get('/groups/'.$group->id)->assertOk()->assertDontSee('Историческая запись')->assertSee('доверенного подтверждения WEBPAY')
            ->assertSee(route('psychologist.payments.show', $latest), false);
        $group->update(['disabled' => true]);
        $this->get('/groups/'.$group->id)->assertOk()->assertSee('Группа отключена')->assertDontSee('Оплатить размещение');
        $other = User::create(['email' => 'foreign-payment@example.test', 'status' => 'approved']);
        $this->actingAs($other)->get('/groups/'.$group->id)->assertNotFound();
        $this->get('/payments/'.$latest->id)->assertNotFound();
    }
}
