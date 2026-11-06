<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RedeemRewardRequest;
use App\Http\Resources\RedemptionResource;
use App\Models\Customer;
use App\Models\Reward;
use App\Services\Loyalty\RedemptionService;
use Illuminate\Http\JsonResponse;

final class RedemptionController extends Controller
{
    public function store(
        RedeemRewardRequest $request,
        Reward $reward,
        RedemptionService $service,
    ): JsonResponse {
        /** @var Customer $customer */
        $customer = $request->user();

        $redemption = $service->redeem($customer, $reward, $request->idempotencyKey());

        return (new RedemptionResource($redemption))
            ->response()
            ->setStatusCode(201);
    }
}
