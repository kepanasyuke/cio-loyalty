<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\RewardResource;
use App\Services\Catalog\RewardCatalog;
use App\Support\BrandContext;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class RewardController extends Controller
{
    public function index(BrandContext $brand, RewardCatalog $catalog): AnonymousResourceCollection
    {
        return RewardResource::collection($catalog->active($brand->get()));
    }
}
