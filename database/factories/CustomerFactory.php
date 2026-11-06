<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Brand;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'brand_id' => Brand::factory(),
            'phone' => '+79'.fake()->unique()->numerify('#########'),
            'name' => fake()->name(),
            'email' => null,
            'is_blocked' => false,
        ];
    }

    public function blocked(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_blocked' => true,
        ]);
    }

    public function withPoints(int $points): static
    {
        return $this->afterCreating(function (Customer $customer) use ($points): void {
            $customer->account()->update([
                'balance' => $points,
                'lifetime_points' => max($points, 0),
            ]);
        });
    }
}
