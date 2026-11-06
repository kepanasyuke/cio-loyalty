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
use Laravel\Sanctum\HasApiTokens;

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
        'is_blocked',
    ];

    protected $hidden = [
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
            $customer->account()->firstOrCreate([], [
                'balance' => 0,
                'lifetime_points' => 0,
                'tier' => Tier::Bronze,
            ]);
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
     * @param  Builder<Customer>  $query
     */
    public function scopeForBrand(Builder $query, Brand|int $brand): void
    {
        $query->where('brand_id', $brand instanceof Brand ? $brand->getKey() : $brand);
    }
}
