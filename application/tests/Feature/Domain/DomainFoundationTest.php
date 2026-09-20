<?php

namespace Tests\Feature\Domain;

use App\Enums\GroupStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Group;
use App\Models\GroupApplication;
use App\Models\GroupStatusHistory;
use App\Models\Payment;
use App\Models\PaymentNotification;
use App\Models\User;
use App\Models\UserDocument;
use App\Services\AuditService;
use App\Services\GroupStatusTransitionService;
use App\Services\PaymentStatusTransitionService;
use App\Services\UserStatusTransitionService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class DomainFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_email_is_unique_and_can_be_reused_after_soft_delete(): void
    {
        $first = $this->user('same@example.test');

        try {
            $this->user('same@example.test');
            $this->fail('MySQL accepted two active users with the same email.');
        } catch (QueryException) {
            $this->assertDatabaseCount('gp_users', 1);
        }

        $first->delete();
        $replacement = $this->user('same@example.test');

        $this->assertNotSame($first->getKey(), $replacement->getKey());
        $this->assertDatabaseCount('gp_users', 2);
    }

    public function test_status_services_enforce_transitions_and_derive_accept(): void
    {
        $user = $this->user('status@example.test');
        $user = app(UserStatusTransitionService::class)->transition($user, UserStatus::Approved);
        $this->assertTrue($user->accept);

        $group = $this->group($user, GroupStatus::Draft);
        $group = app(GroupStatusTransitionService::class)->transition(
            $group,
            GroupStatus::Moderation,
            $user,
            'user',
            'Submitted for moderation',
        );

        $this->assertSame(GroupStatus::Moderation, $group->status);
        $this->assertFalse($group->accept);
        $this->assertDatabaseHas('gp_group_status_history', [
            'group_id' => $group->getKey(),
            'from_status' => 'draft',
            'to_status' => 'moderation',
            'actor_id' => $user->getKey(),
        ]);

        $payment = $this->payment($user, $group, 'ORDER-STATUS');
        $payment = app(PaymentStatusTransitionService::class)->transition($payment, PaymentStatus::Pending);
        $this->assertSame(PaymentStatus::Pending, $payment->status);

        $this->expectException(InvalidStatusTransition::class);
        app(PaymentStatusTransitionService::class)->transition($payment, PaymentStatus::Refunded);
    }

    public function test_compatibility_accept_mapping_is_derived_only_from_status(): void
    {
        foreach (UserStatus::cases() as $status) {
            $user = $this->user("accept-{$status->value}@example.test", $status);
            $this->assertSame($status === UserStatus::Approved, $user->accept);
        }

        $owner = $this->user('accept-owner@example.test');

        foreach (GroupStatus::cases() as $status) {
            $group = $this->group($owner, $status);
            $expected = in_array($status, [
                GroupStatus::Approved,
                GroupStatus::Active,
                GroupStatus::Expired,
            ], true);

            $this->assertSame($expected, $group->accept, $status->value);
        }
    }

    public function test_group_transition_and_history_write_are_atomic(): void
    {
        $user = $this->user('atomic@example.test');
        $group = $this->group($user, GroupStatus::Draft);

        GroupStatusHistory::creating(function (): void {
            throw new RuntimeException('History write failed.');
        });

        try {
            app(GroupStatusTransitionService::class)->transition($group, GroupStatus::Moderation);
            $this->fail('The transition unexpectedly succeeded.');
        } catch (RuntimeException $exception) {
            $this->assertSame('History write failed.', $exception->getMessage());
        }

        $this->assertSame(GroupStatus::Draft, $group->fresh()->status);
        $this->assertDatabaseCount('gp_group_status_history', 0);
    }

    public function test_group_uuid_is_generated_unique_and_immutable(): void
    {
        $user = $this->user('uuid@example.test');
        $first = $this->group($user);
        $second = $this->group($user);

        $this->assertNotSame($first->public_uuid, $second->public_uuid);
        $this->assertTrue((bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $first->public_uuid,
        ));

        $first->public_uuid = $second->public_uuid;
        $this->expectException(DomainException::class);
        $first->save();
    }

    public function test_payment_nullable_transaction_id_and_money_cast_follow_mysql_semantics(): void
    {
        $user = $this->user('payment@example.test');
        $group = $this->group($user);
        $first = $this->payment($user, $group, 'ORDER-1');
        $second = $this->payment($user, $group, 'ORDER-2');

        $this->assertNull($first->transaction_id);
        $this->assertNull($second->transaction_id);
        $this->assertSame(12345, $first->amount);

        $first->transaction_id = 'transaction-1';
        $first->save();
        $second->transaction_id = 'transaction-1';

        $this->expectException(QueryException::class);
        $second->save();
    }

    public function test_audit_service_records_non_sensitive_metadata(): void
    {
        $admin = $this->user('audit@example.test', UserStatus::Approved, true);
        $subject = $this->user('subject@example.test');

        $entry = app(AuditService::class)->record(
            'user',
            $subject->getKey(),
            'tariff_changed',
            ['old' => 'paid', 'new' => 'free'],
            $admin,
        );

        $this->assertEquals(['old' => 'paid', 'new' => 'free'], $entry->fresh()->metadata);
        $this->assertTrue($entry->actor->is($admin));
    }

    public function test_required_relationship_chain_is_available(): void
    {
        $user = $this->user('relations@example.test');
        $group = $this->group($user);
        $application = GroupApplication::query()->create([
            'group_id' => $group->getKey(),
            'last_name' => 'Doe',
            'first_name' => 'Jane',
            'phone' => '+375 29 000-00-00',
            'phone_normalized' => '+375290000000',
        ]);
        $payment = $this->payment($user, $group, 'ORDER-REL');
        app(GroupStatusTransitionService::class)->transition($group, GroupStatus::Moderation);
        $notification = PaymentNotification::query()->create([
            'payment_id' => $payment->getKey(),
            'payload' => ['order_number' => 'ORDER-REL'],
        ]);
        $document = UserDocument::query()->create([
            'user_id' => $user->getKey(),
            'type' => 'diploma',
            'path' => 'private/document.pdf',
            'original_name' => 'document.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
        ]);

        $this->assertTrue($user->groups->first()->is($group));
        $this->assertTrue($user->documents->first()->is($document));
        $this->assertTrue($group->owner->is($user));
        $this->assertTrue($group->applications->first()->is($application));
        $this->assertTrue($group->payments->first()->is($payment));
        $this->assertSame('draft', $group->statusHistory->first()->from_status->value);
        $this->assertTrue($payment->notifications->first()->is($notification));
    }

    private function user(
        string $email,
        UserStatus $status = UserStatus::Pending,
        bool $admin = false,
    ): User {
        return User::query()->create([
            'email' => $email,
            'status' => $status,
            'admin' => $admin,
        ]);
    }

    private function group(User $owner, GroupStatus $status = GroupStatus::Draft): Group
    {
        return Group::query()->create([
            'owner_id' => $owner->getKey(),
            'status' => $status,
            'title' => 'Test group',
        ]);
    }

    private function payment(User $owner, Group $group, string $orderNumber): Payment
    {
        return Payment::query()->create([
            'owner_id' => $owner->getKey(),
            'group_id' => $group->getKey(),
            'type' => 'placement',
            'order_number' => $orderNumber,
            'amount' => 12345,
            'status' => PaymentStatus::Created,
        ]);
    }
}
