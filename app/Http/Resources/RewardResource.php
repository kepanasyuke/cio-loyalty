<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Reward;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Reward
 */
final class RewardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'cost_points' => $this->cost_points,
            'stock' => $this->stock,
            'is_active' => $this->is_active,
            'image_url' => $this->image_url,
        ];
    }
}
