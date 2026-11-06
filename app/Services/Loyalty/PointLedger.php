<?php

declare(strict_types=1);

namespace App\Services\Loyalty;

use App\Enums\PointTransactionType;
use App\Exceptions\DomainException;
use App\Exceptions\InsufficientPointsException;
use App\Models\LoyaltyAccount;
use App\Models\PointTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class PointLedger
{
    public function __construct(private readonly TierCalculator $tiers) {}

    public function credit(
        LoyaltyAccount $account,
        int $points,
        string $reason,
        ?Model $source = null,
        ?string $idempotencyKey = null,
        PointTransactionType $type = PointTransactionType::Accrual,
    ): PointTransaction {
        if ($points <= 0) {
            throw new DomainException('Сумма начисления должна быть положительной.', 422);
        }

        return $this->apply($account, $type, $points, $reason, $source, $idempotencyKey);
    }

    public function debit(
        LoyaltyAccount $account,
        int $points,
        string $reason,
        ?Model $source = null,
        ?string $idempotencyKey = null,
        PointTransactionType $type = PointTransactionType::Redemption,
    ): PointTransaction {
        if ($points <= 0) {
            throw new DomainException('Сумма списания должна быть положительной.', 422);
        }

        return $this->apply($account, $type, -$points, $reason, $source, $idempotencyKey);
    }

    private function apply(
        LoyaltyAccount $account,
        PointTransactionType $type,
        int $signedPoints,
        string $reason,
        ?Model $source,
        ?string $idempotencyKey,
    ): PointTransaction {
        $transaction = DB::transaction(function () use ($account, $type, $signedPoints, $reason, $source, $idempotencyKey): PointTransaction {
            if ($idempotencyKey !== null) {
                $existing = PointTransaction::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing instanceof PointTransaction) {
                    return $existing;
                }
            }

            /** @var LoyaltyAccount $locked */
            $locked = LoyaltyAccount::query()
                ->whereKey($account->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $balanceAfter = $locked->balance + $signedPoints;

            if ($balanceAfter < 0) {
                throw new InsufficientPointsException($locked->balance, abs($signedPoints));
            }

            $lifetime = $locked->lifetime_points + max($signedPoints, 0);

            $transaction = $locked->transactions()->create([
                'type' => $type,
                'points' => $signedPoints,
                'balance_after' => $balanceAfter,
                'reason' => $reason,
                'idempotency_key' => $idempotencyKey,
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
            ]);

            $locked->forceFill([
                'balance' => $balanceAfter,
                'lifetime_points' => $lifetime,
                'tier' => $this->tiers->for($lifetime),
            ])->save();

            return $transaction;
        });

        $account->refresh();

        return $transaction;
    }
}
