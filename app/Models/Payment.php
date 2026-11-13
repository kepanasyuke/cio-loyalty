<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentStatus;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Платёж клиента через внешний шлюз (YooKassa / fake).
 *
 * State machine:
 *   Pending → Succeeded (вебхук payment.succeeded, оплата прошла)
 *   Pending → Canceled  (вебхук payment.canceled, отмена/неудача)
 * Succeeded и Canceled — финальные. Обратных переходов нет.
 *
 * Контракт markSucceeded()/markCanceled():
 * - Возвращают false, если платёж уже финальный (идемпотентность).
 * - Возвращают true и меняют статус + payload, если Pending.
 * - НЕ логируют и НЕ открывают транзакцию. Вызывающий код отвечает за:
 *   * DB::transaction + lockForUpdate() перед вызовом (иначе гонка при
 *     параллельных вебхуках: два markSucceeded() оба пройдут isFinal()
 *     и оба вернут true);
 *   * логирование события (см. PaymentService::confirm()).
 *
 * Поля:
 * - amount — cast decimal:2 возвращает СТРОКУ ('100.00'), не float.
 *   Это защита от потери точности на больших суммах. Не сравнивать
 *   с float через ===.
 * - payload — снимок данных от шлюза для аудита. markSucceeded/MarkCanceled
 *   сливают через array_merge (верхнеуровнево, не рекурсивно). Если
 *   нужна полная история вебхуков — отдельная append-only таблица
 *   payment_webhooks (не в этой модели).
 * - external_id — ID платежа в шлюзе. Уникален, ставится через
 *   forceFill в PaymentService::start() после ответа шлюза. Должен
 *   иметь UNIQUE INDEX (для вебхук-запросов where('external_id', ...)).
 *
 * $fillable:
 * - status, external_id, paid_at НЕ в $fillable: финансовые поля
 *   меняются только через markSucceeded()/markCanceled() и forceFill
 *   в PaymentService::start(). Защита от mass-assignment.
 */
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'customer_id',
        'gateway',
        'amount',
        'currency',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'status' => PaymentStatus::class,
            'payload' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @param  Builder<Payment>  $query
     * @return Builder<Payment>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', PaymentStatus::Pending);
    }

    /**
     * Помечает платёж как успешный (идемпотентно).
     *
     * @param  array<string, mixed>  $payload  Данные вебхука для audit trail.
     *                                         Сливаются с существующим payload
     *                                         через array_merge (верхнеуровнево).
     * @return bool true — статус изменён, false — платёж уже финальный.
     *
     * @see PaymentService::confirm() Вызывается в DB::transaction с
     *                                 lockForUpdate. Без блокировки — гонка.
     */
    public function markSucceeded(array $payload = []): bool
    {
        return $this->status->isFinal()
            ? false
            : $this->persistSucceeded($payload);
    }

    /**
     * Помечает платёж как отменённый (идемпотентно).
     *
     * @param  array<string, mixed>  $payload
     * @return bool true — статус изменён, false — платёж уже финальный.
     */
    public function markCanceled(array $payload = []): bool
    {
        return $this->status->isFinal()
            ? false
            : $this->persistCanceled($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function persistSucceeded(array $payload): bool
    {
        $this->forceFill([
            'status' => PaymentStatus::Succeeded,
            'paid_at' => now(),
            'payload' => $this->mergePayload($payload),
        ])->save();

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function persistCanceled(array $payload): bool
    {
        $this->forceFill([
            'status' => PaymentStatus::Canceled,
            'payload' => $this->mergePayload($payload),
        ])->save();

        return true;
    }

    /**
     * Верхнеуровневый merge с существующим payload. array_merge, а не
     * array_replace_recursive: «последний снимок побеждает», вложенные
     * ключи перезаписываются целиком.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function mergePayload(array $payload): array
    {
        return array_merge($this->payload ?? [], $payload);
    }
}
