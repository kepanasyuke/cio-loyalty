<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\RewardFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Награда в каталоге бренда.
 *
 * Доступность (isAvailable / scopeAvailable):
 * Награда доступна для обмена, если выполнены ВСЕ условия:
 * - is_active = true;
 * - stock = NULL (неограниченно) ИЛИ stock > 0 (есть на складе);
 * - не soft-deleted (deleted_at IS NULL) — автоматически в scopeAvailable.
 *
 * isAvailable() (PHP) и scopeAvailable() (SQL) — параллельные реализации.
 * При добавлении нового условия — добавить в ОБА метода. Тест
 * tests/Unit/Models/RewardTest.php проверяет эквивалентность.
 *
 * stock:
 * - NULL = неограниченно (перк, не товар);
 * - 0 = распродано (временно);
 * - > 0 = конкретное количество.
 *
 * НЕ декрементировать через $reward->stock - 1: PHP конвертирует
 * null → 0, -1 = баг (неограниченная станет -1 и исчезнет из каталога).
 * Использовать decrementStock() — атомарный UPDATE с защитой от гонки.
 *
 * Гонка при обмене (критично):
 * isAvailable() / scopeAvailable() читают stock из БД/кэша. Между
 * проверкой и списанием сток может измениться параллельным запросом.
 * Единственный надёжный паттерн в RedemptionService::redeem():
 * 1. DB::transaction;
 * 2. $reward->decrementStock() ПЕРВЫМ (атомарный check+decrement);
 *    при false — OutOfStockException, транзакция откатится;
 * 3. создание Redemption, ledger->debit.
 * Альтернатива: lockForUpdate + isAvailable() + decrementStock() —
 * тоже работает, но медленнее (лишняя блокировка).
 *
 * $fillable:
 * Поля — для admin/manager CRUD через StoreRewardRequest /
 * UpdateRewardRequest. Безопасность — на уровне EnsureUserHasRole
 * (route middleware) + FormRequest валидации, а не mass-assignment.
 * Клиентское API (Api\V1\RewardController) только читает каталог через
 * RewardCatalog::active() — не пишет. Поэтому cost_points/is_active
 * в $fillable — корректно (в отличие от LoyaltyAccount.balance или
 * Payment.status, которые меняются через forceFill).
 *
 * brand_id защищён в RewardController::store() через array_merge
 * ($payload, ['brand_id' => resolveBrandId()]) и в update() через
 * Arr::except($payload, ['brand_id']).
 *
 * SoftDeletes:
 * Удаление — soft (deleted_at), чтобы сохранить историю обменов
 * (redemptions.reward_id FK). В каталоге (scopeAvailable) soft-deleted
 * не видна. В RedemptionResource для истории — может понадобиться
 * withTrashed() (проверить).
 */
class Reward extends Model
{
    /** @use HasFactory<RewardFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'brand_id',
        'title',
        'description',
        'cost_points',
        'stock',
        'is_active',
        'image_url',
    ];

    protected function casts(): array
    {
        return [
            'cost_points' => 'integer',
            'stock' => 'integer',
            'is_active' => 'boolean',
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
     * @return HasMany<Redemption, $this>
     */
    public function redemptions(): HasMany
    {
        return $this->hasMany(Redemption::class);
    }

    /**
     * SQL-фильтр доступных наград. Параллельная isAvailable() —
     * при добавлении условий менять оба метода.
     *
     * @param  Builder<Reward>  $query
     * @return Builder<Reward>
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(function (Builder $query): void {
                $query->whereNull('stock')->orWhere('stock', '>', 0);
            });
    }

    /**
     * PHP-проверка для одного экземпляра. Использует загруженные
     * атрибуты (не делает запрос). Если Reward мог измениться в БД —
     * refresh() перед вызовом или свежий запрос в RedemptionService
     * с lockForUpdate.
     *
     * Параллельная scopeAvailable() — при добавлении условий менять оба.
     */
    public function isAvailable(): bool
    {
        return $this->is_active && ($this->stock === null || $this->stock > 0);
    }

    /**
     * Атомарный декремент stock с защитой от гонки и от ухода в 0.
     *
     * - stock = null (неограниченно) → true, no-op.
     * - stock = 0 или race проигран → false.
     * - stock > 0 → UPDATE stock = stock - 1 WHERE id = ? AND stock > 0.
     *
     * Должен вызываться внутри DB::transaction (вместе с созданием
     * Redemption и ledger->debit). Иначе при последующем откате
     * (например, InsufficientPointsException) decrement не откатится.
     *
     * Не обновляет $this->stock в памяти — используйте fresh()/refresh()
     * для актуального значения.
     */
    public function decrementStock(): bool
    {
        if ($this->stock === null) {
            return true;
        }

        $affected = static::query()
            ->whereKey($this->getKey())
            ->where('stock', '>', 0)
            ->decrement('stock');

        return $affected > 0;
    }
}
