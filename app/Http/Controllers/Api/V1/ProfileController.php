<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\PointTransactionResource;
use App\Http\Resources\RedemptionResource;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ProfileController extends Controller
{
    public function show(Request $request): CustomerResource
    {
        /** @var Customer $customer */
        $customer = $request->user();

        return new CustomerResource($customer->load('account'));
    }

    public function transactions(Request $request): AnonymousResourceCollection
    {
        /** @var Customer $customer */
        $customer = $request->user();

        $transactions = $customer->account()
            ->firstOrFail()
            ->transactions()
            ->latest('id')
            ->paginate(20);

        return PointTransactionResource::collection($transactions);
    }

    public function redemptions(Request $request): AnonymousResourceCollection
    {
        /** @var Customer $customer */
        $customer = $request->user();

        $redemptions = $customer->redemptions()
            ->with('reward')
            ->latest('id')
            ->paginate(20);

        return RedemptionResource::collection($redemptions);
    }
}
