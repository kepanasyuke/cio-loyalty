<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\PointTransactionResource;
use App\Http\Resources\RedemptionResource;
use App\Models\Customer;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Профиль клиента мобильного приложения.
 *
 * Все методы требуют аутентификации клиента (middleware auth:sanctum)
 * и работают в контексте текущего клиента.
 *
 * - show()         — профиль + счёт (баланс, тариф, множитель).
 * - transactions() — история начислений и списаний (пагинация).
 * - redemptions()  — история обменов наград (пагинация).
 *
 * Инвариант account:
 * - show() и redemptions() не требуют наличия loyalty_account.
 * - transactions() использует account()->firstOrFail() — 404 если счёта
 *   нет. Это осознанный fail-fast при нарушении инварианта «у клиента
 *   есть счёт с момента регистрации». Если бизнес-требование изменится
 *   (счёт опционален), заменить на ?-> и возвращать пустой список — но
 *   синхронно во всех трёх методах, иначе клиент увидит разное поведение
 *   в разных разделах приложения.
 */
final class ProfileController extends Controller
{
    private const PER_PAGE = 20;

    /**
     * Профиль клиента + счёт (баланс, тариф, множитель).
     */
    public function show(Request $request): CustomerResource
    {
        return new CustomerResource(
            $this->currentCustomer($request)->load('account'),
        );
    }

    /**
     * История транзакций по счёту клиента, пагинация.
     *
     * @throws ModelNotFoundException Если у клиента нет loyalty_account
     *                                 (нарушение инварианта).
     */
    public function transactions(Request $request): AnonymousResourceCollection
    {
        $transactions = $this->currentCustomer($request)
            ->account()
            ->firstOrFail()
            ->transactions()
            ->latest('id')
            ->paginate(self::PER_PAGE);

        return PointTransactionResource::collection($transactions);
    }

    /**
     * История обменов наград клиента, пагинация.
     */
    public function redemptions(Request $request): AnonymousResourceCollection
    {
        $redemptions = $this->currentCustomer($request)
            ->redemptions()
            ->with('reward')
            ->latest('id')
            ->paginate(self::PER_PAGE);

        return RedemptionResource::collection($redemptions);
    }

    /**
     * Сужает $request->user() до App\Models\Customer.
     * abort() возвращает never — PHPStan level 8 понимает сужение.
     */
    private function currentCustomer(Request $request): Customer
    {
        $user = $request->user();

        if (! $user instanceof Customer) {
            abort(401, 'Требуется аутентификация клиента.');
        }

        return $user;
    }
}
