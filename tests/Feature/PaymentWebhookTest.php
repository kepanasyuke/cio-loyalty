<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PaymentWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_payment_webhook_credits_points_once(): void
    {
        $brand = Brand::factory()->create();
        $customer = Customer::factory()->for($brand)->create();

        $payment = Payment::factory()->for($customer)->create([
            'gateway' => 'fake',
            'external_id' => 'yk-demo-1',
            'payload' => ['package' => 'p1000', 'points' => 1_000],
        ]);

        $payload = [
            'event' => 'payment.succeeded',
            'object' => ['id' => 'yk-demo-1', 'status' => 'succeeded'],
        ];

        $this->postJson('/api/v1/webhooks/payments/yookassa', $payload)->assertOk();
        $this->postJson('/api/v1/webhooks/payments/yookassa', $payload)->assertOk();

        $this->assertSame(1_000, $customer->account()->first()?->balance);
        $this->assertSame(PaymentStatus::Succeeded, $payment->fresh()?->status);
        $this->assertDatabaseCount('point_transactions', 1);
    }

    public function test_canceled_payment_does_not_credit_points(): void
    {
        $brand = Brand::factory()->create();
        $customer = Customer::factory()->for($brand)->create();

        $payment = Payment::factory()->for($customer)->create([
            'gateway' => 'fake',
            'external_id' => 'yk-demo-2',
            'payload' => ['package' => 'p1000', 'points' => 1_000],
        ]);

        $this->postJson('/api/v1/webhooks/payments/yookassa', [
            'event' => 'payment.canceled',
            'object' => ['id' => 'yk-demo-2', 'status' => 'canceled'],
        ])->assertOk();

        $this->assertSame(PaymentStatus::Canceled, $payment->fresh()?->status);
        $this->assertSame(0, $customer->account()->first()?->balance);
        $this->assertDatabaseCount('point_transactions', 0);
    }

    public function test_unknown_payment_is_ignored_without_error(): void
    {
        $this->postJson('/api/v1/webhooks/payments/yookassa', [
            'event' => 'payment.succeeded',
            'object' => ['id' => 'missing-external-id'],
        ])->assertStatus(202);
    }
}
