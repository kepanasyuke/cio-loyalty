<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PointTransactionType;
use Database\Factories\PointTransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * Журнал движений баллов — append-only.
 *
 * Инварианты:
 * - Запись НЕ МОЖЕТ быть изменена или удалена. События updating/deleting
 *   бросают LogicException. Любая попытка — баг в коде, а не операция.
 * - Источник истины баланса: LoyaltyAccount.balance — материализованный
 *   кеш, восстанавливаемый как SUM(points) по этому журналу. Если
 *   balance расходится с SUM(points) — баг в PointLedger, а не в модели.
 * - points — знаковое число: > 0 начисление, < 0 списание. type — метка
 *   для аудита, направление определяется знаком, не типом. Adjustment
 *   может быть и положительным, и отрицательным.
 * - balance_after — снимок баланса ПОСЛЕ операции. Позволяет
 *   восстановить баланс на любую дату без пересчёта журнала.
 * - idempotency_key — ключ дедупликации. UNIQUE INDEX в БД обязателен
 *   (partial, WHERE idempotency_key IS NOT NULL): без него PointLedger
 *   не может гарантировать идемпотентность при параллельных запросах.
 *
 * Writer:
 * - Только PointLedger::apply() (DB::transaction + lockForUpdate на
 *   LoyaltyAccount, вычисление balance_after, INSERT). Прямое создание
 *   через PointTransaction::create() в обход PointLedger — нарушение
 *   инварианта (balance_after может не совпасть с balance + points).
 *
 * source_type/source_id:
 * - Могут быть null (ручной Adjustment без привязки к бизнес-объекту).
 *   В этом случае $tx->source() вернёт null.
 */
class PointTransaction extends Model
{
    /** @use HasFactory<PointTransactionFactory> */
    use HasFactory;

    /**
     * Поля для create() — оба writer'а (PointLedger) передают их одним
     * массивом. update()/delete() запрещены append-only событиями ниже,
     * поэтому mass-assignment на этой модели не является риском.
     *
     * @var list<string>
     */
    protected $fillable = [
        'loyalty_account_id',
        'type',
        'points',
        'balance_after',
        'reason',
        'source_type',
        'source_id',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'type' => PointTransactionType::class,
            'points' => 'integer',
            'balance_after' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (PointTransaction $tx): void {
            throw new LogicException(
                "PointTransaction#{$tx->getKey()} is append-only: updates are forbidden.",
            );
        });

        static::deleting(function (PointTransaction $tx): void {
            throw new LogicException(
                "PointTransaction#{$tx->getKey()} is append-only: deletes are forbidden.",
            );
        });
    }

    /**
     * @return BelongsTo<LoyaltyAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(LoyaltyAccount::class, 'loyalty_account_id');
    }

    /**
     * Полиморфный источник транзакции: Payment, Redemption или null
     * (ручная корректировка без привязки к бизнес-объекту).
     *
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
