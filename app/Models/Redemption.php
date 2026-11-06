<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RedemptionStatus;
use Database\Factories\RedemptionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Redemption extends Model
{
    /** @use HasFactory<RedemptionFactory> */
    use HasFactory;

    protected $fillable = [
        'brand_id',
        'customer_id',
        'reward_id',
        'points_spent',
        'status',
        'code',
        'fulfilled_at',
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
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', RedemptionStatus::Created);
    }

    public function fulfill(): void
    {
        $this->forceFill([
            'status' => RedemptionStatus::Fulfilled,
            'fulfilled_at' => now(),
        ])->save();
    }
}
