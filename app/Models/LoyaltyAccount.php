<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Tier;
use Database\Factories\LoyaltyAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Счёт лояльности клиента — материализованный кеш журнала баллов.
 *
 * Инвариант «balance — это кеш»:
 * - Источник истины — таблица point_transactions. balance восстанавливается
 *   как SUM(points) по всем транзакциям счёта.
 * - Любое изменение balance/lifetime_points/tier идёт через сервисы:
 *   * PointLedger::apply() (DB::transaction + lockForUpdate + INSERT
 *     в point_transactions) — для balance/lifetime_points;
 *   * RecalculateTier job (forceFill tier) — для tier.
 * - Если balance разошёлся с суммой транзакций — это баг в PointLedger,
 *   а не в модели. Модель «тупая»: только хранит числа.
 *
 * Создание:
 * - Автоматически при регистрации Customer (см. Customer::booted()).
 * - Начальные значения (balance=0, lifetime_points=0, tier=Bronze)
 *   выставляются forceFill'ом в Customer::booted(), не через массовое
 *   присваивание.
 *
 * Мультитенантность:
 * - brand_id НЕ дублируется в этой таблице. Для фильтра по бренду —
 *   whereHas('customer', fn ($q) => $q->where('brand_id', $id)).
 *
 * Удаление:
 * - Без SoftDeletes: счёт живёт, пока жив клиент. При удалении клиента
 *   счёт удаляется каскадно (FK) или остаётся сиротой (нужен cleanup).
 */
class LoyaltyAccount extends Model
{
    /** @use HasFactory<LoyaltyAccountFactory> */
    use HasFactory;

    /**
     * Только customer_id. balance/lifetime_points/tier НЕ в $fillable:
     * финансовые данные меняются только через forceFill() в сервисах
     * (PointLedger, RecalculateTier). Защита от mass-assignment — если
     * кто-то напишет $account->update($request->validated()), поля
     * balance/tier молча проигнорируются, а не перезапишутся.
     *
     * customer_id в $fillable нужен для Customer::booted() (firstOrCreate
     * через relationship устанавливает FK). Начальные значения
     * (balance=0, lifetime_points=0, tier=Bronze) выставляются явным
     * forceFill в Customer::booted() — см. coordinated change ниже.
     *
     * @var list<string>
     */
    protected $fillable = [
        'customer_id',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'integer',
            'lifetime_points' => 'integer',
            'tier' => Tier::class,
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
     * @return HasMany<PointTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(PointTransaction::class);
    }
}
