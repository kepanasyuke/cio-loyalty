<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\PointTransaction;
use App\Models\Redemption;
use App\Models\Reward;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $brandId = $request->user()?->brand_id;

        $customers = Customer::query()->when($brandId, fn (Builder $query) => $query->where('brand_id', $brandId));
        $rewards = Reward::query()->when($brandId, fn (Builder $query) => $query->where('brand_id', $brandId));
        $redemptions = Redemption::query()->when($brandId, fn (Builder $query) => $query->where('brand_id', $brandId));

        return view('admin.dashboard', [
            'customersCount' => $customers->count(),
            'activeRewardsCount' => $rewards->where('is_active', true)->count(),
            'pendingRedemptions' => $redemptions->where('status', 'created')->count(),
            'pointsIssuedToday' => (int) PointTransaction::query()
                ->where('type', 'accrual')
                ->whereDate('created_at', today())
                ->sum('points'),
            'latestRedemptions' => Redemption::query()
                ->when($brandId, fn (Builder $query) => $query->where('brand_id', $brandId))
                ->with(['customer', 'reward'])
                ->latest('id')
                ->limit(10)
                ->get(),
        ]);
    }
}
