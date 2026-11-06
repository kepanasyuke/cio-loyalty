<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentStatus;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'gateway',
        'external_id',
        'amount',
        'currency',
        'status',
        'payload',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'status' => PaymentStatus::class,
            'payload' => 'array',
            'paid_at' => 'datetime',
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
     * @param  Builder<Payment>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', PaymentStatus::Pending);
    }

    public function markSucceeded(array $payload = []): bool
    {
        if ($this->status->isFinal()) {
            return false;
        }

        $this->forceFill([
            'status' => PaymentStatus::Succeeded,
            'paid_at' => now(),
            'payload' => array_merge($this->payload ?? [], $payload),
        ])->save();

        return true;
    }

    public function markCanceled(array $payload = []): bool
    {
        if ($this->status->isFinal()) {
            return false;
        }

        $this->forceFill([
            'status' => PaymentStatus::Canceled,
            'payload' => array_merge($this->payload ?? [], $payload),
        ])->save();

        return true;
    }
}
