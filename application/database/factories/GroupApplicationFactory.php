<?php

namespace Database\Factories;

use App\Models\GroupApplication;
use App\Support\PhoneNormalizer;
use Illuminate\Database\Eloquent\Factories\Factory;
use LogicException;

/** @extends Factory<GroupApplication> */
class GroupApplicationFactory extends Factory
{
    protected $model = GroupApplication::class;

    public function definition(): array
    {
        // Reserved fictional NANP range; never generate real participant data.
        $phone = '+1 (202) 555-01'.str_pad((string) random_int(0, 99), 2, '0', STR_PAD_LEFT);

        return [
            'group_id' => fn () => throw new LogicException('Associate an existing synthetic group using for($group).'),
            'last_name' => 'Тестовый участник', 'first_name' => 'Синтетический',
            'phone' => $phone,
            'phone_normalized' => fn (array $attributes) => app(PhoneNormalizer::class)->digitsForSearch($attributes['phone']),
            'processed_at' => null,
        ];
    }

    public function processed(): static
    {
        return $this->state(fn () => ['processed_at' => now('UTC')]);
    }

    public function unprocessed(): static
    {
        return $this->state(fn () => ['processed_at' => null]);
    }
}
