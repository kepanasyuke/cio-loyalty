<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\InsufficientPointsException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RedeemRewardRequest;
use App\Http\Resources\RedemptionResource;
use App\Models\Customer;
use App\Models\Reward;
use App\Services\Loyalty\RedemptionService;
use Illuminate\Http\JsonResponse;

/**
 * Обмен наград на баллы.
 *
 * Поток:
 * 1. Клиент выбирает награду и нажимает «Обменять».
 * 2. Сервер проверяет баланс, наличие на складе, активность награды,
 *    бренд-скоуп (см. ниже) и идемпотентность.
 * 3. Создаётся Redemption со статусом Created, баллы списываются
 *    через PointLedger. Выдача награды — отдельным шагом в админке
 *    (см. RedemptionService::fulfill()).
 *
 * Бренд-скоуп:
 * - Награда должна принадлежать бренду клиента. Проверка выполняется
 *   в RedemptionService::redeem(), а также защитно в контроллере
 *   (abort_unless 404 — не раскрываем существование чужих наград).
 *
 * Идемпотентность:
 * - idempotencyKey из формы (required, uuid) передаётся в сервис.
 *   Повторный submit с тем же ключом возвращает существующий обмен,
 *   а не списывает баллы дважды. Клиент должен генерировать ключ
 *   при открытии формы и переиспользовать до успеха.
 */
final class RedemptionController extends Controller
{
    /**
     * Обмен награды на баллы.
     *
     * @return JsonResponse 201 Created: RedemptionResource
     *
     * @throws InsufficientPointsException Если баланса не хватает
     * @throws \App\Exceptions\OutOfStockException Если награда закончилась
     */
    public function store(
        RedeemRewardRequest $request,
        Reward $reward,
        RedemptionService $service,
    ): JsonResponse {
        $customer = $this->currentCustomer($request);

        $this->ensureRewardBelongsToCustomerBrand($customer, $reward);

        $redemption = $service->redeem(
            customer: $customer,
            reward: $reward,
            idempotencyKey: $request->idempotencyKey(),
        );

        return (new RedemptionResource($redemption))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Защитная проверка бренд-скоупа. Основная — внутри
     * RedemptionService::redeem(); здесь fail-closed на случай,
     * если сервис когда-нибудь перестанет проверять.
     *
     * 404, а не 403: не раскрываем клиенту существование наград
     * других брендов (информационная безопасность).
     */
    private function ensureRewardBelongsToCustomerBrand(Customer $customer, Reward $reward): void
    {
        abort_unless(
            (int) $reward->brand_id === (int) $customer->brand_id,
            404,
            'Награда не найдена.',
        );
    }

    /**
     * Сужает $request->user() до App\Models\Customer.
     * abort() возвращает never — PHPStan level 8 понимает сужение.
     */
    private function currentCustomer(RedeemRewardRequest $request): Customer
    {
        $user = $request->user();

        if (! $user instanceof Customer) {
            abort(401, 'Требуется аутентификация клиента.');
        }

        return $user;
    }
}
