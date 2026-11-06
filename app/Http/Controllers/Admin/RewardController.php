<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRewardRequest;
use App\Http\Requests\Admin\UpdateRewardRequest;
use App\Models\Brand;
use App\Models\Reward;
use App\Services\Catalog\RewardCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class RewardController extends Controller
{
    public function index(Request $request): View
    {
        $rewards = Reward::query()
            ->with('brand')
            ->when($request->user()?->brand_id, fn (Builder $query, int $brandId) => $query->where('brand_id', $brandId))
            ->latest('id')
            ->paginate(15);

        return view('admin.rewards.index', ['rewards' => $rewards]);
    }

    public function create(): View
    {
        return view('admin.rewards.form', [
            'reward' => new Reward(['is_active' => true, 'cost_points' => 500]),
            'brands' => Brand::query()->orderBy('name')->get(),
        ]);
    }

    public function store(StoreRewardRequest $request, RewardCatalog $catalog): RedirectResponse
    {
        $reward = Reward::query()->create(
            $request->payload() + ['brand_id' => $this->resolveBrandId($request)]
        );

        $catalog->forget($reward->brand);

        return redirect()
            ->route('admin.rewards.index')
            ->with('status', 'Награда создана.');
    }

    public function edit(Reward $reward): View
    {
        $this->ensureAccessible($reward);

        return view('admin.rewards.form', [
            'reward' => $reward,
            'brands' => Brand::query()->orderBy('name')->get(),
        ]);
    }

    public function update(UpdateRewardRequest $request, Reward $reward, RewardCatalog $catalog): RedirectResponse
    {
        $this->ensureAccessible($reward);

        $reward->update($request->payload());

        $catalog->forget($reward->brand);

        return redirect()
            ->route('admin.rewards.index')
            ->with('status', 'Награда обновлена.');
    }

    public function destroy(Reward $reward, RewardCatalog $catalog): RedirectResponse
    {
        $this->ensureAccessible($reward);

        $reward->delete();

        $catalog->forget($reward->brand);

        return redirect()
            ->route('admin.rewards.index')
            ->with('status', 'Награда удалена.');
    }

    private function resolveBrandId(Request $request): int
    {
        $brandId = $request->user()?->brand_id;

        if ($brandId !== null) {
            return (int) $brandId;
        }

        /** @var array{brand_id: int|string} $validated */
        $validated = $request->validate([
            'brand_id' => ['required', 'integer', 'exists:brands,id'],
        ]);

        return (int) $validated['brand_id'];
    }

    private function ensureAccessible(Reward $reward): void
    {
        $brandId = request()->user()?->brand_id;

        abort_if($brandId !== null && (int) $reward->brand_id !== (int) $brandId, 403);
    }
}
