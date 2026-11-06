<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PointTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PointTransaction
 */
final class PointTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => [
                'code' => $this->type->value,
                'label' => $this->type->label(),
            ],
            'points' => $this->points,
            'balance_after' => $this->balance_after,
            'reason' => $this->reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
