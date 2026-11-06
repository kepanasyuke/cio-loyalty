<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PointTransactionType;
use App\Models\LoyaltyAccount;
use App\Models\PointTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PointTransaction>
 */
class PointTransactionFactory extends Factory
{
    public function definition(): array
    {
        $points = fake()->numberBetween(50, 1_000);

        return [
            'loyalty_account_id' => LoyaltyAccount::factory(),
            'type' => PointTransactionType::Accrual,
            'points' => $points,
            'balance_after' => $points,
            'reason' => 'Начисление за покупку',
            'source_type' => null,
            'source_id' => null,
            'idempotency_key' => null,
        ];
    }
}
