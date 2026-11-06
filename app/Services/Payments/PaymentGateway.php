<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Payment;

interface PaymentGateway
{
    public function name(): string;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function createIntent(Payment $payment, array $metadata = []): PaymentIntent;
}
