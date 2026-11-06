<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Brand;
use App\Models\Reward;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reward>
 */
class RewardFactory extends Factory
{
    public function definition(): array
    {
        return [
            'brand_id' => Brand::factory(),
            'title' => 'Кофе в подарок',
            'description' => fake()->sentence(8),
            'cost_points' => fake()->numberBetween(300, 5_000),
            'stock' => null,
            'is_active' => true,
            'image_url' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }

    public function outOfStock(): static
    {
        return $this->state(fn (array $attributes): array => [
            'stock' => 0,
        ]);
    }

    public function costing(int $points): static
    {
        return $this->state(fn (array $attributes): array => [
            'cost_points' => $points,
        ]);
    }
}
