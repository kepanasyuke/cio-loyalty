<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\BrandFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Бренд — тенант мультитенантной системы.
 *
 * Все сущности (клиенты, награды, операторы, обмены) принадлежат
 * ровно одному бренду. Изоляция обеспечивается middleware ResolveBrand
 * (X-Brand header) + явными фильтрами where('brand_id', $id) в запросах.
 *
 * SoftDeletes: при "удалении" бренд остаётся в БД (deleted_at), чтобы
 * сохранить историю платежей, транзакций и обменов. Клиенты soft-deleted
 * бренда не могут аутентифицироваться — ResolveBrand не находит бренд
 * по slug (firstOrFail → 404).
 *
 * Route key: slug (не id) — SEO-дружественные URL в админке:
 * /admin/brands/acme вместо /admin/brands/1. Slug должен иметь
 * UNIQUE INDEX в БД.
 */
class Brand extends Model
{
    /** @use HasFactory<BrandFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return HasMany<Customer, $this>
     */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    /**
     * @return HasMany<Reward, $this>
     */
    public function rewards(): HasMany
    {
        return $this->hasMany(Reward::class);
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<Redemption, $this>
     */
    public function redemptions(): HasMany
    {
        return $this->hasMany(Redemption::class);
    }
}
