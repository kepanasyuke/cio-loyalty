<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\LoyaltyAccount;
use App\Services\Loyalty\TierCalculator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LoyaltyAccount
 */
final class LoyaltyAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $calculator = app(TierCalculator::class);
        $nextThreshold = $calculator->nextThreshold($this->tier);

        return [
            'balance' => $this->balance,
            'lifetime_points' => $this->lifetime_points,
            'tier' => [
                'code' => $this->tier->value,
                'label' => $this->tier->label(),
                'multiplier' => $this->tier->multiplier(),
            ],
            'next_tier_at' => $nextThreshold,
            'points_to_next_tier' => $nextThreshold === null ? 0 : max($nextThreshold - $this->lifetime_points, 0),
        ];
    }
}
