<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Customer;
use App\Models\PushToken;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PushToken>
 */
class PushTokenFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'token' => Str::random(64),
            'platform' => 'ios',
            'last_used_at' => now(),
        ];
    }
}
