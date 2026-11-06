<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Enums\PaymentStatus;
use App\Exceptions\DomainException;
use App\Models\Customer;
use App\Models\Payment;
use App\Services\Loyalty\PointLedger;
use Illuminate\Support\Facades\DB;

final class PaymentService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly PointLedger $ledger,
    ) {}

    public function start(Customer $customer, string $packageCode): Payment
    {
        /** @var array{points: int, amount: string, currency: string}|null $package */
        $package = config("loyalty.packages.{$packageCode}");

        if (! is_array($package)) {
            throw new DomainException('Пакет баллов не найден.', 404);
        }

        $payment = Payment::query()->create([
            'customer_id' => $customer->getKey(),
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
     * @param  array<string, mixed>  $payload
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

            $points = (int) ($locked->payload['points'] ?? 0);

            if ($points > 0) {
                $this->ledger->credit(
                    account: $locked->customer->account()->firstOrFail(),
                    points: $points,
                    reason: 'Покупка пакета баллов',
                    source: $locked,
                    idempotencyKey: 'payment:'.$locked->getKey(),
                );
            }

            return true;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function cancel(Payment $payment, array $payload = []): bool
    {
        return $payment->markCanceled($payload);
    }
}
