<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Tier;
use Database\Factories\LoyaltyAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoyaltyAccount extends Model
{
    /** @use HasFactory<LoyaltyAccountFactory> */
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'balance',
        'lifetime_points',
        'tier',
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

    public function hasEnoughPoints(int $points): bool
    {
        return $this->balance >= $points;
    }
}
