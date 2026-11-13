<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Tier;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

/**
 * Клиент программы лояльности, привязанный к одному Brand.
 *
 * Аутентификация — Sanctum Bearer-токен, выданный после OTP-подтверждения
 * (см. AuthController::issueToken). Пароль не используется.
 *
 * Инвариант account:
 * - При создании клиента booted() автоматически создаёт LoyaltyAccount
 *   (balance=0, lifetime_points=0, tier=Bronze). Все потребители
 *   (ProfileController, PointLedger, RedemptionService) рассчитывают,
 *   что счёт существует с момента регистрации.
 * - Создание счёта обёрнуто в DB::transaction — если firstOrCreate
 *   падает, транзакция откатывается. Если вызывающий код создаёт
 *   Customer вне транзакции и firstOrCreate упал — клиент остаётся
 *   без счёта. Защита от orphan — scheduled cleanup (см. docs).
 *
 * SoftDeletes:
 * - Удаление клиента — soft (deleted_at), история платежей и
 *   транзакций сохраняется. Токены Sanctum удаляются в deleted event —
 *   soft-deleted клиент не аутентифицируется (auth:sanctum не находит
 *   его через Customer::find()).
 *
 * Мультитенантность:
 * - brand_id обязателен, UNIQUE (brand_id, phone) гарантирует
 *   идемпотентность firstOrCreate в AuthController.
 * - scopeForBrand() — фильтр по бренду для админ-запросов и дашбордов.
 */
class Customer extends Authenticatable
{
    /** @use HasFactory<CustomerFactory> */
    use HasApiTokens;

    use HasFactory;
    use Notifiable;
    use SoftDeletes;

    protected $fillable = [
        'brand_id',
        'phone',
        'name',
        'email',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'is_blocked' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (Customer $customer): void {
            DB::transaction(function () use ($customer): void {
                $customer->account()->firstOrCreate([], [
                    'balance' => 0,
                    'lifetime_points' => 0,
                    'tier' => Tier::Bronze,
                ]);
            });
        });

        static::deleted(function (Customer $customer): void {
            $customer->tokens()->delete();
        });
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return HasOne<LoyaltyAccount, $this>
     */
    public function account(): HasOne
    {
        return $this->hasOne(LoyaltyAccount::class);
    }

    /**
     * @return HasMany<Redemption, $this>
     */
    public function redemptions(): HasMany
    {
        return $this->hasMany(Redemption::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return HasMany<PushToken, $this>
     */
    public function pushTokens(): HasMany
    {
        return $this->hasMany(PushToken::class);
    }

    /**
     * Фильтр по бренду — для мультитенантных запросов.
     *
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    public function scopeForBrand(Builder $query, Brand|int $brand): Builder
    {
        return $query->where(
            'brand_id',
            $brand instanceof Brand ? $brand->getKey() : $brand,
        );
    }
}
