<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Exceptions\DomainException;
use App\Models\Payment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class YooKassaGateway implements PaymentGateway
{
    public function __construct(
        private readonly string $shopId,
        private readonly string $secretKey,
        private readonly string $baseUrl,
        private readonly string $returnUrl,
    ) {}

    public function name(): string
    {
        return 'yookassa';
    }

    public function createIntent(Payment $payment, array $metadata = []): PaymentIntent
    {
        try {
            $response = Http::withBasicAuth($this->shopId, $this->secretKey)
                ->acceptJson()
                ->asJson()
                ->withHeaders(['Idempotence-Key' => (string) Str::uuid()])
                ->timeout(10)
                ->retry(2, 200)
                ->post($this->baseUrl.'/payments', [
                    'amount' => [
                        'value' => (string) $payment->amount,
                        'currency' => $payment->currency,
                    ],
                    'capture' => true,
                    'confirmation' => [
                        'type' => 'redirect',
                        'return_url' => $this->returnUrl,
                    ],
                    'description' => 'Покупка пакета баллов',
                    'metadata' => ['payment_id' => (string) $payment->getKey()] + $metadata,
                ]);
        } catch (ConnectionException) {
            throw new DomainException('Платёжный шлюз не отвечает, попробуйте позже.', 502);
        }

        if ($response->failed()) {
            throw new DomainException('Платёжный шлюз вернул ошибку.', 502, [
                'status' => $response->status(),
            ]);
        }

        $id = (string) $response->json('id', '');
        $confirmationUrl = (string) $response->json('confirmation.confirmation_url', '');

        if ($id === '' || $confirmationUrl === '') {
            throw new DomainException('Некорректный ответ платёжного шлюза.', 502);
        }

        return new PaymentIntent(externalId: $id, confirmationUrl: $confirmationUrl);
    }
}
