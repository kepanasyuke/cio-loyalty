<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\Brand;
use App\Models\Reward;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

final class RewardCatalog
{
    /**
     * @return Collection<int, Reward>
     */
    public function active(Brand $brand): Collection
    {
        return Cache::remember(
            key: $this->key($brand),
            ttl: (int) config('loyalty.catalog_cache_ttl', 120),
            callback: fn (): Collection => Reward::query()
                ->where('brand_id', $brand->getKey())
                ->available()
                ->orderBy('cost_points')
                ->get(),
        );
    }

    public function forget(Brand $brand): void
    {
        Cache::forget($this->key($brand));
    }

    private function key(Brand $brand): string
    {
        return "brand:{$brand->getKey()}:rewards:active";
    }
}
