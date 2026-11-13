<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Customer;
use App\Models\PushToken;
use App\Services\Notifications\PushGateway;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Отправка push-уведомления клиенту на все его устройства.
 *
 * Передаётся только \$customerId (не модель): на момент выполнения
 * клиент может быть удалён — тогда просто логируем и выходим, без
 * падения ModelNotFoundException.
 *
 * Идемпотентность на уровне job:
 * - Повторный запуск после сбоя отправит push повторно (FCM не
 *   поддерживает idempotency key). Если нужна дедупликация —
 *   передавать uniqueId + ShouldBeUnique в вызывающем коде, а не
 *   в этом job (push разным клиентам независим).
 *
 * Обработка ошибок per-token:
 * - Один плохой токен (Unregistered, 429) не должен ронять отправку
 *   на остальные устройства. try/catch вокруг каждого send(),
 *   успешные токены попадают в \$succeededIds для батчевого UPDATE
 *   last_used_at.
 * - Job падает (→ retry) только если \$customer->pushTokens не
 *   загрузился (ошибка БД) — это транзиентно, повтор оправдан.
 *
 * Мёртвые токены:
 * - PushGateway::send() возвращает void — интерфейс не сообщает,
 *   какой именно токен невалиден. Реальная очистка мёртвых токенов —
 *   ответственность драйвера (FakeGateway/LogPushGateway/RealGateway):
 *   FCM возвращает 'error: Unregistered' в ответе, драйвер должен
 *   удалить токен или пометить revoked_at. Этот job только
 *   обновляет last_used_at у успешно отправленных.
 *
 * Стиль: один return на функцию, без break/continue, без ранних return.
 */
final class SendPushNotification implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 60, 180];

    /**
     * @param  array<string, mixed>  $data  Должен быть JSON-сериализуемым
     *                                       (скаляры, массивы). Closure/resource
     *                                       упадёт при serialize() ещё на dispatch.
     */
    public function __construct(
        public readonly int $customerId,
        public readonly string $title,
        public readonly string $body,
        public readonly array $data = [],
    ) {}

    public function handle(PushGateway $push): void
    {
        $customer = Customer::query()->with('pushTokens')->find($this->customerId);

        if ($customer instanceof Customer && ! $customer->is_blocked) {
            $this->deliverToAllTokens($push, $customer);
        } else {
            $this->logSkipped($customer);
        }
    }

    /**
     * Проходит по всем токенам, отправляет push, собирает ID успешных
     * для одного батчевого UPDATE. Один плохой токен не прерывает цикл.
     */
    private function deliverToAllTokens(PushGateway $push, Customer $customer): void
    {
        $succeededIds = [];

        foreach ($customer->pushTokens as $token) {
            if ($this->deliverToToken($push, $token)) {
                $succeededIds[] = (int) $token->getKey();
            }
        }

        $this->touchSucceeded($succeededIds);
        $this->logDelivered($customer, count($succeededIds));
    }

    /**
     * Возвращает true, если push ушёл, false — если отправка упала.
     * Тернарник вместо try/catch с двумя return — один return на функцию.
     */
    private function deliverToToken(PushGateway $push, PushToken $token): bool
    {
        return $this->attemptSend($push, $token)
            ? true
            : $this->handleSendFailure($token);
    }

    private function attemptSend(PushGateway $push, PushToken $token): bool
    {
        try {
            $push->send($token->token, $this->title, $this->body, $this->data);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function handleSendFailure(PushToken $token): bool
    {
        Log::warning('push.send.failed', [
            'customer_id' => $this->customerId,
            'token_id' => $token->getKey(),
        ]);

        return false;
    }

    /**
     * Один UPDATE ... WHERE id IN (...) вместо N отдельных save().
     * last_used_at = now() (APP_TIMEZONE) — это аналитическое поле,
     * не критичное к таймзоне, UTC-конверсия не нужна.
     *
     * @param  list<int>  $ids
     */
    private function touchSucceeded(array $ids): void
    {
        if ($ids !== []) {
            PushToken::query()->whereKey($ids)->update(['last_used_at' => now()]);
        }
    }

    private function logSkipped(?Customer $customer): void
    {
        Log::info('push.skipped', [
            'customer_id' => $this->customerId,
            'reason' => $customer === null ? 'customer_not_found' : 'customer_blocked',
        ]);
    }

    private function logDelivered(Customer $customer, int $sent): void
    {
        Log::info('push.delivered', [
            'customer_id' => $customer->getKey(),
            'sent' => $sent,
            'total' => $customer->pushTokens->count(),
        ]);
    }
}
