<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Tier;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoyaltyAccount>
 */
class LoyaltyAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'balance' => 0,
            'lifetime_points' => 0,
            'tier' => Tier::Bronze,
        ];
    }

    public function withBalance(int $balance, ?int $lifetime = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'balance' => $balance,
            'lifetime_points' => $lifetime ?? max($balance, 0),
        ]);
    }
}
