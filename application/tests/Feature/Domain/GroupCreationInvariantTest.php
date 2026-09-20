<?php

namespace Tests\Feature\Domain;

use App\Models\Group;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GroupCreationInvariantTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('tariffs')]
    public function test_group_snapshots_the_persisted_owner_tariff(
        bool $ownerIsFree,
        bool $callerValue,
    ): void {
        $owner = $this->user("tariff-{$this->booleanName($ownerIsFree)}@example.test", $ownerIsFree);

        $group = Group::query()->create([
            'owner_id' => $owner->getKey(),
            'title' => 'Tariff snapshot',
            'free' => $callerValue,
        ]);

        $this->assertSame($ownerIsFree, $group->fresh()->free);
    }

    public function test_existing_snapshot_is_unchanged_and_later_group_uses_current_tariff(): void
    {
        $owner = $this->user('tariff-change@example.test', false);
        $existingGroup = $this->group($owner);

        $owner->free = true;
        $owner->save();

        $laterGroup = $this->group($owner);

        $this->assertFalse($existingGroup->fresh()->free);
        $this->assertTrue($laterGroup->fresh()->free);
    }

    #[DataProvider('callerUuids')]
    public function test_caller_supplied_uuid_cannot_control_group_creation(string $callerUuid): void
    {
        $owner = $this->user('uuid-'.md5($callerUuid).'@example.test', false);

        $group = Group::query()->create([
            'owner_id' => $owner->getKey(),
            'title' => 'Application-owned UUID',
            'public_uuid' => $callerUuid,
        ]);
        $group->refresh();

        $this->assertNotSame($callerUuid, $group->public_uuid);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $group->public_uuid,
        );
    }

    public function test_each_group_receives_a_distinct_application_generated_uuid(): void
    {
        $owner = $this->user('distinct-uuid@example.test', false);

        $first = $this->group($owner);
        $second = $this->group($owner);
        $first->refresh();
        $second->refresh();

        $this->assertNotSame($first->public_uuid, $second->public_uuid);
    }

    public function test_post_creation_uuid_mutation_fails_and_persisted_value_is_unchanged(): void
    {
        $owner = $this->user('immutable-uuid@example.test', false);
        $group = $this->group($owner);
        $persistedUuid = $group->public_uuid;

        $group->public_uuid = '9cda8bd4-d81f-4d5a-9a8d-a315743c2969';

        try {
            $group->save();
            $this->fail('The group UUID mutation unexpectedly succeeded.');
        } catch (DomainException) {
            $this->assertSame($persistedUuid, $group->fresh()->public_uuid);
        }
    }

    public function test_group_creation_fails_clearly_when_owner_cannot_be_resolved(): void
    {
        $this->expectException(ModelNotFoundException::class);

        Group::query()->create([
            'owner_id' => PHP_INT_MAX,
            'title' => 'Missing owner',
        ]);
    }

    /**
     * @return array<string, array{bool, bool}>
     */
    public static function tariffs(): array
    {
        return [
            'free owner overrides paid caller value' => [true, false],
            'paid owner overrides free caller value' => [false, true],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function callerUuids(): array
    {
        return [
            'valid UUID v4' => ['19cfdd9c-f064-40ca-b0d5-1eaa5518ef13'],
            'invalid UUID text' => ['caller-controlled-value'],
        ];
    }

    private function user(string $email, bool $free): User
    {
        return User::query()->create([
            'email' => $email,
            'free' => $free,
        ]);
    }

    private function group(User $owner): Group
    {
        return Group::query()->create([
            'owner_id' => $owner->getKey(),
            'title' => 'Test group',
        ]);
    }

    private function booleanName(bool $value): string
    {
        return $value ? 'free' : 'paid';
    }
}
