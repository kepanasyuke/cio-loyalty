<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\PointTransactionType;
use App\Enums\RedemptionStatus;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\PointTransaction;
use App\Models\Redemption;
use App\Models\Reward;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Дашборд админ-панели: агрегаты и последние активности.
 *
 * Все счётчики и списки ограничены брендом текущего оператора.
 * Оператор без brand_id (суперадмин) видит данные всех брендов.
 */
final class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $brandId = $this->currentUser($request)->brand_id;

        return view('admin.dashboard', [
            'customersCount' => $this->scopedByBrand(Customer::query(), $brandId)->count(),
            'activeRewardsCount' => $this->scopedByBrand(Reward::query(), $brandId)
                ->where('is_active', true)
                ->count(),
            'pendingRedemptions' => $this->scopedByBrand(Redemption::query(), $brandId)
                ->where('status', RedemptionStatus::Created->value)
                ->count(),
            'pointsIssuedToday' => $this->pointsIssuedToday($brandId),
            'latestRedemptions' => $this->scopedByBrand(Redemption::query(), $brandId)
                ->with(['customer', 'reward'])
                ->latest('id')
                ->limit(10)
                ->get(),
        ]);
    }

    /**
     * Начисленные баллы за сегодня (UTC).
     *
     * Фильтр по бренду — через связь account.customer, поскольку
     * у point_transactions нет колонки brand_id напрямую. Если в
     * схеме появится денормализованный brand_id — заменить whereHas
     * на прямой where.
     */
    private function pointsIssuedToday(?int $brandId): int
    {
        $todayStart = Carbon::now('UTC')->startOfDay();
        $tomorrowStart = $todayStart->copy()->addDay();

        return (int) PointTransaction::query()
            ->where('type', PointTransactionType::Accrual->value)
            ->where('created_at', '>=', $todayStart)
            ->where('created_at', '<', $tomorrowStart)
            ->when(
                $brandId !== null,
                fn (Builder $query) => $query->whereHas(
                    'account.customer',
                    fn (Builder $inner) => $inner->where('brand_id', $brandId),
                ),
            )
            ->sum('points');
    }

    /**
     * Ограничивает запрос брендом оператора.
     * brand_id = null (суперадмин) — без фильтра; brand_id = 0 — валидный бренд.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function scopedByBrand(Builder $query, ?int $brandId): Builder
    {
        return $query->when(
            $brandId !== null,
            fn (Builder $q) => $q->where('brand_id', $brandId),
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
