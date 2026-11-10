<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Enums\PaymentStatus;
use App\Enums\Tier;
use App\Exceptions\DomainException;
use App\Models\Payment;
use App\Services\Loyalty\PointLedger;
use Illuminate\Support\Facades\DB;

final class PaymentService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly PointLedger $ledger,
    ) {
    }

    /**
     * @param array<string, mixed> $package
     */
    public function create(int $customerId, string $packageCode, array $package): Payment
    {
        $payment = Payment::query()->create([
            'customer_id' => $customerId,
            'gateway' => $this->gateway->name(),
            'amount' => $package['amount'],
            'currency' => $package['currency'],
            'status' => PaymentStatus::Pending,
            'payload' => [
                'package' => $packageCode,
                'points' => (int) $package['points'],
            ],
        ]);

        $intent = $this->gateway->createIntent($payment, ['package' => $packageCode]);

        $payment->forceFill([
            'external_id' => $intent->externalId,
            'payload' => array_merge($payment->payload ?? [], [
                'confirmation_url' => $intent->confirmationUrl,
            ]),
        ])->save();

        return $payment;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function confirm(Payment $payment, array $payload = []): bool
    {
        return DB::transaction(function () use ($payment, $payload): bool {
            /** @var Payment $locked */
            $locked = Payment::query()
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->markSucceeded($payload)) {
                return false;
            }

            $basePoints = (int) ($locked->payload['points'] ?? 0);

            if ($basePoints > 0) {
                $account = $locked->customer->account()->firstOrFail();
                $earned = $account->tier->applyTo($basePoints);

                $this->ledger->credit(
                    account: $account,
                    points: $earned,
                    reason: $this->accrualReason($account->tier, $basePoints, $earned),
                    source: $locked,
                    idempotencyKey: 'payment:'.$locked->getKey(),
                );
            }

            return true;
        });
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function cancel(Payment $payment, array $payload = []): bool
    {
        return $payment->markCanceled($payload);
    }

    private function accrualReason(Tier $tier, int $base, int $earned): string
    {
        return $earned === $base
            ? 'Покупка пакета баллов'
            : sprintf('Покупка пакета баллов (%s ×%s)', $tier->label(), $tier->multiplier());
    }
}
