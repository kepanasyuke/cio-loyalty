<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CreatePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Customer;
use App\Models\Payment;
use App\Services\Payments\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Платежи клиента мобильного приложения.
 *
 * store() — создание платёжного интента во внешнем шлюзе (YooKassa
 * или аналог). Клиент получает confirmation_url, платит на стороне
 * шлюза, подтверждение приходит вебхуком (см. YooKassaWebhookController).
 *
 * fakeConfirm() — только для local/testing или fake-шлюза. Имитирует
 * успешное подтверждение без внешнего шлюза (E2E-тесты, локальная
 * разработка). В проде должен быть недоступен — тройная защита:
 * 1. Роут не регистрируется (см. routes/api.php).
 * 2. Проверка окружения / config(payments.default).
 * 3. Проверка gateway === 'fake' на самом платеже.
 *
 * Безопасность fakeConfirm:
 * - Проверяется, что платёж принадлежит текущему клиенту (IDOR).
 * - Без проверки клиент мог бы подтвердить чужой fake-платёж.
 */
final class PaymentController extends Controller
{
    /**
     * Создаёт платёжный интент и возвращает PaymentResource с
     * confirmation_url для редиректа на страницу оплаты шлюза.
     *
     * @return JsonResponse 201 Created
     */
    public function store(CreatePaymentRequest $request, PaymentService $service): JsonResponse
    {
        $customer = $this->currentCustomer($request);
        $package = (string) $request->validated('package');

        $payment = $service->start($customer, $package);

        return (new PaymentResource($payment))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Подтверждает fake-платёж (только local/testing/fake-gateway).
     *
     * @return JsonResponse 200 OK: {status: string}
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException
     *         404 если не fake, 403 если платёж чужой
     */
    public function fakeConfirm(
        Request $request,
        Payment $payment,
        PaymentService $service,
    ): JsonResponse {
        abort_unless($this->fakeGatewayEnabled(), 404);
        abort_unless($payment->gateway === 'fake', 404);

        $customer = $this->currentCustomer($request);
        abort_unless(
            (int) $payment->customer_id === (int) $customer->getKey(),
            403,
            'Платёж принадлежит другому клиенту.',
        );

        $service->confirm($payment, ['source' => 'fake-confirm']);

        // confirm() работает с lockForUpdate()-копией, не с $payment —
        // перечитываем статус из БД.
        return response()->json([
            'status' => $payment->fresh()->status->value,
        ]);
    }

    /**
     * Разрешён ли fake-режим: local/testing ИЛИ fake-шлюз в конфиге.
     *
     * Двойная защита поверх abort_unless($payment->gateway === 'fake'):
     * даже если в БД случайно окажется платёж с gateway='fake'
     * (утечка из dev-БД, миграция, ручная правка), в проде без
     * явного config('payments.default')='fake' роут не сработает.
     */
    private function fakeGatewayEnabled(): bool
    {
        return app()->environment(['local', 'testing'])
            || config('payments.default') === 'fake';
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
