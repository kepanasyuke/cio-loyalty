<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Redemption;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Redemption
 */
final class RedemptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'status' => [
                'code' => $this->status->value,
                'label' => $this->status->label(),
            ],
            'points_spent' => $this->points_spent,
            'reward' => new RewardResource($this->whenLoaded('reward')),
            'created_at' => $this->created_at?->toIso8601String(),
            'fulfilled_at' => $this->fulfilled_at?->toIso8601String(),
        ];
    }
}
