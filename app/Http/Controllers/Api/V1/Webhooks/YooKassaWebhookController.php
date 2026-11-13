<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Payments\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Обработчик вебхуков YooKassa.
 *
 * YooKassa не подписывает вебхуки — единственная защита от подделки
 * это IP-whitelist (middleware EnsureWebhookFromTrustedIp на маршруте).
 * Без этого middleware эндпоинт открыт: любой может «подтвердить»
 * платёж по известному external_id.
 *
 * Идемпотентность — в PaymentService::confirm()/cancel()
 * (DB::transaction + lockForUpdate + isFinal() check + idempotencyKey
 * на ledger->credit). Повторный вебхук не задвоит баллы.
 *
 * Коды ответов YooKassa (2xx = доставлено, повторов нет):
 * - succeeded / canceled → 200: обработано.
 * - unknown external_id → 202: платёж удалён или из другого окружения;
 *   повтор бессмысленен.
 * - unsupported event → 200: платёж известен, событие валидно по
 *   формату, просто не обрабатывается; логируем в info.
 *
 * Стиль: один return на функцию, без break/continue, без ранних return.
 */
final class YooKassaWebhookController extends Controller
{
    private const EVENT_CONFIRM = 'payment.succeeded';
    private const EVENT_CANCEL = 'payment.canceled';

    public function __invoke(Request $request, PaymentService $payments): JsonResponse
    {
        $event = $request->string('event')->toString();
        $externalId = $request->string('object.id')->toString();
        $payment = $this->findPayment($externalId);

        return $payment instanceof Payment
            ? $this->handleEvent($request, $payments, $payment, $event)
            : $this->ignoreUnknown($request, $event, $externalId);
    }

    /**
     * Один индексный SELECT по external_id. Пустой external_id
     * (malformed payload) сразу даёт null — без запроса в БД.
     */
    private function findPayment(string $externalId): ?Payment
    {
        return $externalId === ''
            ? null
            : Payment::query()->where('external_id', $externalId)->first();
    }

    private function handleEvent(
        Request $request,
        PaymentService $payments,
        Payment $payment,
        string $event,
    ): JsonResponse {
        $this->dispatch($payments, $payment, $event, $request->all());
        $this->logHandled($request, $payment, $event);

        return response()->json(['result' => 'ok']);
    }

    /**
     * Ветвление по событию. Void-функция, без return:
     * if/elseif/else исчерпывает все случаи, одна точка выхода — конец.
     *
     * @param  array<string, mixed>  $payload
     */
    private function dispatch(
        PaymentService $payments,
        Payment $payment,
        string $event,
        array $payload,
    ): void {
        if ($event === self::EVENT_CONFIRM) {
            $payments->confirm($payment, $payload);
        } elseif ($event === self::EVENT_CANCEL) {
            $payments->cancel($payment, $payload);
        } else {
            $this->logUnsupported($payment, $event);
        }
    }

    private function logHandled(Request $request, Payment $payment, string $event): void
    {
        Log::info('webhook.payment.handled', [
            'event' => $event,
            'payment_id' => $payment->getKey(),
            'external_id' => $payment->external_id,
            'ip' => $request->ip(),
        ]);
    }

    private function logUnsupported(Payment $payment, string $event): void
    {
        Log::info('webhook.payment.unsupported_event', [
            'event' => $event,
            'payment_id' => $payment->getKey(),
            'external_id' => $payment->external_id,
        ]);
    }

    private function ignoreUnknown(Request $request, string $event, string $externalId): JsonResponse
    {
        Log::warning('webhook.payment.unknown', [
            'event' => $event,
            'external_id' => $externalId,
            'ip' => $request->ip(),
        ]);

        return response()->json(['result' => 'ignored'], 202);
    }
}
