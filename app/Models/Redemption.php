<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RedemptionStatus;
use Database\Factories\RedemptionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Обмен награды на баллы — state machine.
 *
 * State machine:
 *   Created → Fulfilled (награда выдана, fulfilled_at = now())
 *   Created → Canceled  (отмена до выдачи; баллы возвращаются в сервисе)
 * Fulfilled и Canceled — финальные. Обратных переходов нет.
 *
 * Контракт fulfill()/cancel():
 * - Возвращают true и меняют статус, если Redemption в статусе Created.
 * - Возвращают false и НЕ меняют ничего, если статус финальный
 *   (идемпотентность, защита от двойной выдачи).
 * - НЕ возвращают баллы при cancel() — это делает RedemptionService::cancel()
 *   через PointLedger::credit + Adjustment (модель не знает про ledger).
 *
 * Defense in depth:
 * - RedemptionService::fulfill()/cancel() проверяют isFinal() и бросают
 *   DomainException 409 — для UX (клиент видит ошибку).
 * - Модель тоже возвращает false для финального — если кто-то вызовет
 *   $redemption->fulfill() в обход сервиса, данные не испортятся.
 *
 * Поля:
 * - points_spent — снимок цены награды на момент обмена. Не менять при
 *   изменении reward.cost_points — история должна остаться.
 * - code — уникальный код выдачи (показывается клиентом в магазине).
 *   UNIQUE INDEX в БД обязателен, генерируется сервером (forceFill),
 *   не в $fillable (клиент не должен передавать свой).
 * - brand_id — денормализация для запросов (Redemption::where('brand_id', ...)
 *   в DashboardController). Не выводить из customer.brand_id в рантайме.
 *
 * $fillable:
 * - status, code, fulfilled_at НЕ в $fillable: финансовые/state поля
 *   меняются только через fulfill()/cancel() и forceFill в сервисе.
 * - points_spent, brand_id, customer_id, reward_id — для create() в
 *   RedemptionService::redeem().
 */
class Redemption extends Model
{
    /** @use HasFactory<RedemptionFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'brand_id',
        'customer_id',
        'reward_id',
        'points_spent',
    ];

    protected function casts(): array
    {
        return [
            'points_spent' => 'integer',
            'status' => RedemptionStatus::class,
            'fulfilled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Reward, $this>
     */
    public function reward(): BelongsTo
    {
        return $this->belongsTo(Reward::class);
    }

    /**
     * @param  Builder<Redemption>  $query
     * @return Builder<Redemption>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', RedemptionStatus::Created);
    }

    /**
     * Помечает обмен как выданный (идемпотентно).
     *
     * @return bool true — статус изменён; false — обмен уже финальный
     *              (Fulfilled или Canceled), ничего не изменено.
     */
    public function fulfill(): bool
    {
        return $this->status->isFinal()
            ? false
            : $this->persistFulfilled();
    }

    /**
     * Помечает обмен как отменённый (идемпотентно).
     *
     * ВАЖНО: не возвращает баллы. Возврат — ответственность
     * RedemptionService::cancel() (PointLedger::credit + Adjustment).
     * Модель не должна знать про ledger и транзакции.
     *
     * @return bool true — статус изменён; false — обмен уже финальный.
     */
    public function cancel(): bool
    {
        return $this->status->isFinal()
            ? false
            : $this->persistCanceled();
    }

    private function persistFulfilled(): bool
    {
        $this->forceFill([
            'status' => RedemptionStatus::Fulfilled,
            'fulfilled_at' => now(),
        ])->save();

        return true;
    }

    private function persistCanceled(): bool
    {
        $this->forceFill([
            'status' => RedemptionStatus::Canceled,
        ])->save();

        return true;
    }
}
