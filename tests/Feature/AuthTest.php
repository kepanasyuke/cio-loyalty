<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Brand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AuthTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '+79001234567';

    public function test_customer_receives_token_after_valid_otp(): void
    {
        $brand = Brand::factory()->create();

        $otp = $this->withHeader('X-Brand', $brand->slug)
            ->postJson('/api/v1/auth/otp', ['phone' => self::PHONE]);

        $otp->assertStatus(202)
            ->assertJsonStructure(['message', 'ttl', 'debug_code']);

        $token = $this->withHeader('X-Brand', $brand->slug)
            ->postJson('/api/v1/auth/token', [
                'phone' => self::PHONE,
                'code' => (string) $otp->json('debug_code'),
            ]);

        $token->assertCreated()
            ->assertJsonStructure([
                'token',
                'token_type',
                'customer' => ['id', 'phone', 'account' => ['balance', 'tier']],
            ]);

        $this->assertDatabaseHas('customers', [
            'brand_id' => $brand->getKey(),
            'phone' => self::PHONE,
        ]);

        $this->assertDatabaseHas('loyalty_accounts', [
            'balance' => 0,
            'lifetime_points' => 0,
        ]);
    }

    public function test_invalid_code_is_rejected(): void
    {
        $brand = Brand::factory()->create();

        $this->withHeader('X-Brand', $brand->slug)
            ->postJson('/api/v1/auth/otp', ['phone' => self::PHONE]);

        $this->withHeader('X-Brand', $brand->slug)
            ->postJson('/api/v1/auth/token', [
                'phone' => self::PHONE,
                'code' => '111111',
            ])
            ->assertStatus(401)
            ->assertJsonPath('code', 'DomainException');
    }

    public function test_requests_without_brand_header_are_rejected(): void
    {
        $this->postJson('/api/v1/auth/otp', ['phone' => self::PHONE])
            ->assertStatus(400);
    }
}
