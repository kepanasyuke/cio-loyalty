<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RedemptionStatus;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\Redemption;
use App\Models\Reward;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Redemption>
 */
class RedemptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'brand_id' => Brand::factory(),
            'customer_id' => Customer::factory(),
            'reward_id' => Reward::factory(),
            'points_spent' => 500,
            'status' => RedemptionStatus::Created,
            'code' => Str::upper(Str::random(10)),
            'fulfilled_at' => null,
        ];
    }

    public function fulfilled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => RedemptionStatus::Fulfilled,
            'fulfilled_at' => now(),
        ]);
    }
}
