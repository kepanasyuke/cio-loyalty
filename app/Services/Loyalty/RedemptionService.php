<?php

declare(strict_types=1);

namespace App\Services\Loyalty;

use App\Enums\RedemptionStatus;
use App\Exceptions\DomainException;
use App\Exceptions\OutOfStockException;
use App\Jobs\SendPushNotification;
use App\Models\Customer;
use App\Models\PointTransaction;
use App\Models\Redemption;
use App\Models\Reward;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RedemptionService
{
    public function __construct(private readonly PointLedger $ledger) {}

    public function redeem(Customer $customer, Reward $reward, ?string $requestKey = null): Redemption
    {
        if ((int) $reward->brand_id !== (int) $customer->brand_id) {
            throw new DomainException('Награда недоступна.', 404);
        }

        $idempotencyKey = $requestKey !== null ? 'redemption:'.$requestKey : null;

        $redemption = DB::transaction(function () use ($customer, $reward, $idempotencyKey): Redemption {
            if ($idempotencyKey !== null) {
                $existing = PointTransaction::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing?->source instanceof Redemption) {
                    return $existing->source;
                }
            }

            /** @var Reward $lockedReward */
            $lockedReward = Reward::query()
                ->whereKey($reward->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedReward->is_active) {
                throw new DomainException('Награда недоступна.', 422);
            }

            if ($lockedReward->stock !== null && $lockedReward->stock < 1) {
                throw new OutOfStockException($lockedReward->title);
            }

            $account = $customer->account()->firstOrFail();

            $redemption = Redemption::query()->create([
                'brand_id' => $customer->brand_id,
                'customer_id' => $customer->getKey(),
                'reward_id' => $lockedReward->getKey(),
                'points_spent' => $lockedReward->cost_points,
                'status' => RedemptionStatus::Created,
                'code' => $this->generateCode(),
            ]);

            $this->ledger->debit(
                account: $account,
                points: $lockedReward->cost_points,
                reason: "Списание за награду «{$lockedReward->title}»",
                source: $redemption,
                idempotencyKey: $idempotencyKey,
            );

            if ($lockedReward->stock !== null) {
                $lockedReward->decrement('stock');
            }

            return $redemption;
        });

        if ($redemption->wasRecentlyCreated) {
            SendPushNotification::dispatch(
                customerId: (int) $customer->getKey(),
                title: 'Награда оформлена',
                body: "Покажите код {$redemption->code} на кассе.",
                data: ['redemption_id' => $redemption->getKey()],
            )->afterCommit();
        }

        return $redemption->load('reward');
    }

    public function fulfill(Redemption $redemption): Redemption
    {
        if ($redemption->status->isFinal()) {
            throw new DomainException('Выдача уже закрыта.', 409);
        }

        $redemption->fulfill();

        return $redemption;
    }

    private function generateCode(): string
    {
        do {
            $code = Str::upper(Str::random(10));
        } while (Redemption::query()->where('code', $code)->exists());

        return $code;
    }
}
