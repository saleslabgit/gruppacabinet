<?php

namespace Tests\Unit\Enums;

use App\Enums\GroupStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserStatus;
use BackedEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StatusTransitionMatrixTest extends TestCase
{
    #[DataProvider('matrices')]
    public function test_every_status_pair_matches_the_defined_matrix(
        BackedEnum $from,
        BackedEnum $to,
        bool $allowed,
    ): void {
        /** @var callable(BackedEnum): bool $transitionCheck */
        $transitionCheck = [$from, 'canTransitionTo'];

        $this->assertSame($allowed, $transitionCheck($to));
    }

    /**
     * @return array<string, array{BackedEnum, BackedEnum, bool}>
     */
    public static function matrices(): array
    {
        return [
            ...self::pairs(UserStatus::cases(), [
                'pending:approved',
                'pending:rejected',
                'rejected:pending',
            ]),
            ...self::pairs(GroupStatus::cases(), [
                'awaiting_payment:draft',
                'draft:moderation',
                'moderation:approved',
                'moderation:revision',
                'moderation:rejected',
                'revision:moderation',
                'approved:active',
                'active:expired',
                'expired:approved',
            ]),
            ...self::pairs(PaymentStatus::cases(), [
                'created:pending',
                'pending:succeeded',
                'pending:failed',
                'pending:cancelled',
                'succeeded:refunded',
            ]),
        ];
    }

    /**
     * @param  list<BackedEnum>  $statuses
     * @param  list<string>  $allowed
     * @return array<string, array{BackedEnum, BackedEnum, bool}>
     */
    private static function pairs(array $statuses, array $allowed): array
    {
        $pairs = [];

        foreach ($statuses as $from) {
            foreach ($statuses as $to) {
                $key = "{$from->value}:{$to->value}";
                $pairs[$from::class.' '.$key] = [$from, $to, in_array($key, $allowed, true)];
            }
        }

        return $pairs;
    }
}
