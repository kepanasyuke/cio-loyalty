<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Payment;
use Illuminate\Support\Str;

final class FakeGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'fake';
    }

    public function createIntent(Payment $payment, array $metadata = []): PaymentIntent
    {
        return new PaymentIntent(
            externalId: 'fake_'.Str::uuid(),
            confirmationUrl: route('payments.fake_confirm', ['payment' => $payment->getKey()]),
        );
    }
}
