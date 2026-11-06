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

class Reward extends Model
{
    /** @use HasFactory<RewardFactory> */
    use HasFactory;

    use SoftDeletes;

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
     * @param  Builder<Reward>  $query
     */
    public function scopeAvailable(Builder $query): void
    {
        $query->where('is_active', true)
            ->where(function (Builder $query): void {
                $query->whereNull('stock')->orWhere('stock', '>', 0);
            });
    }

    public function isAvailable(): bool
    {
        return $this->is_active && ($this->stock === null || $this->stock > 0);
    }
}
