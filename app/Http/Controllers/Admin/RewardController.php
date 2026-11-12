<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRewardRequest;
use App\Http\Requests\Admin\UpdateRewardRequest;
use App\Models\Brand;
use App\Models\Reward;
use App\Models\User;
use App\Services\Catalog\RewardCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\View\View;

/**
 * CRUD наград в админ-панели.
 *
 * Область видимости — по бренду оператора:
 * - Manager (brand_id != null) видит, создаёт и редактирует только награды
 *   своего бренда. brand_id принудительно выставляется в его бренд.
 * - Admin (brand_id == null) видит все бренды и указывает brand_id в форме.
 *
 * Награда привязана к бренду с момента создания и не меняет бренд через update().
 */
final class RewardController extends Controller
{
    public function index(Request $request): View
    {
        $brandId = $this->currentUser($request)->brand_id;

        $rewards = Reward::query()
            ->with('brand')
            ->when($brandId !== null, fn (Builder $query) => $query->where('brand_id', (int) $brandId))
            ->latest('id')
            ->paginate(15);

        return view('admin.rewards.index', ['rewards' => $rewards]);
    }

    public function create(Request $request): View
    {
        return view('admin.rewards.form', [
            'reward' => new Reward(['is_active' => true, 'cost_points' => 500]),
            'brands' => $this->availableBrands($this->currentUser($request)),
        ]);
    }

    public function store(StoreRewardRequest $request, RewardCatalog $catalog): RedirectResponse
    {
        $reward = Reward::query()->create(
            array_merge(
                $request->payload(),
                ['brand_id' => $this->resolveBrandId($request)],
            ),
        );

        $catalog->forget($reward->brand);

        return redirect()
            ->route('admin.rewards.index')
            ->with('status', 'Награда создана.');
    }

    public function edit(Request $request, Reward $reward): View
    {
        $this->ensureAccessible($request, $reward);

        return view('admin.rewards.form', [
            'reward' => $reward,
            'brands' => $this->availableBrands($this->currentUser($request)),
        ]);
    }

    public function update(UpdateRewardRequest $request, Reward $reward, RewardCatalog $catalog): RedirectResponse
    {
        $this->ensureAccessible($request, $reward);

        // brand_id намеренно не меняется через update: награда привязана
        // к бренду с момента создания. Защита от потенциального изменения
        // brand_id через payload() FormRequest.
        $reward->update(Arr::except($request->payload(), ['brand_id']));

        $catalog->forget($reward->brand);

        return redirect()
            ->route('admin.rewards.index')
            ->with('status', 'Награда обновлена.');
    }

    public function destroy(Request $request, Reward $reward, RewardCatalog $catalog): RedirectResponse
    {
        $this->ensureAccessible($request, $reward);

        $reward->delete();
        $catalog->forget($reward->brand);

        return redirect()
            ->route('admin.rewards.index')
            ->with('status', 'Награда удалена.');
    }

    /**
     * Список брендов для select в форме:
     * - Manager → только свой бренд (чтобы не путать UX и не раскрывать чужие);
     * - Admin → все бренды.
     *
     * @return Collection<int, Brand>
     */
    private function availableBrands(User $user): Collection
    {
        return Brand::query()
            ->when(
                $user->brand_id !== null,
                fn (Builder $query) => $query->whereKey((int) $user->brand_id),
            )
            ->orderBy('name')
            ->get();
    }

    /**
     * Определяет brand_id для создаваемой награды:
     * - Manager → всегда свой brand_id (значение из формы игнорируется);
     * - Admin → brand_id из формы (валидируется по brands.id).
     *
     * Примечание: долгосрочно brand_id стоит перенести в
     * StoreRewardRequest::rules() с Rule::requiredIf(...), чтобы
     * не было двойной валидации. Пока — минимизируем изменения.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    private function resolveBrandId(Request $request): int
    {
        $user = $this->currentUser($request);

        if ($user->brand_id !== null) {
            return (int) $user->brand_id;
        }

        /** @var array{brand_id: int|string} $validated */
        $validated = $request->validate([
            'brand_id' => ['required', 'integer', 'exists:brands,id'],
        ]);

        return (int) $validated['brand_id'];
    }

    private function ensureAccessible(Request $request, Reward $reward): void
    {
        $user = $this->currentUser($request);

        abort_if(
            $user->brand_id !== null && (int) $reward->brand_id !== (int) $user->brand_id,
            403,
            'Нет доступа к награде другого бренда.',
        );
    }

    /**
     * Сужает $request->user() до App\Models\User. Authenticatable
     * не имеет brand_id; abort() возвращает never — сужение типов
     * для PHPStan level 8.
     */
    private function currentUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403, 'Требуется аутентификация оператора.');
        }

        return $user;
    }
}
