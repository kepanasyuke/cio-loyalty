<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'gateway' => 'fake',
            'external_id' => (string) Str::uuid(),
            'amount' => '500.00',
            'currency' => 'RUB',
            'status' => PaymentStatus::Pending,
            'payload' => [],
            'paid_at' => null,
        ];
    }

    public function succeeded(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PaymentStatus::Succeeded,
            'paid_at' => now(),
        ]);
    }
}
