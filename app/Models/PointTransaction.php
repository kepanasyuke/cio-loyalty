<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PointTransactionType;
use Database\Factories\PointTransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PointTransaction extends Model
{
    /** @use HasFactory<PointTransactionFactory> */
    use HasFactory;

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

    /**
     * @return BelongsTo<LoyaltyAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(LoyaltyAccount::class, 'loyalty_account_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
