<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Customer;
use App\Models\Reward;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class RedeemRewardTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->brand = Brand::factory()->create();
        $this->customer = Customer::factory()
            ->for($this->brand)
            ->withPoints(1_000)
            ->create();

        Sanctum::actingAs($this->customer);
    }

    public function test_customer_can_redeem_reward(): void
    {
        $reward = Reward::factory()->for($this->brand)->costing(400)->create(['stock' => 3]);

        $this->withHeader('X-Brand', $this->brand->slug)
            ->postJson("/api/v1/rewards/{$reward->getKey()}/redeem")
            ->assertCreated()
            ->assertJsonPath('data.points_spent', 400);

        $this->assertSame(600, $this->customer->account()->first()?->balance);
        $this->assertSame(2, $reward->fresh()?->stock);
        $this->assertDatabaseCount('redemptions', 1);
        $this->assertDatabaseCount('point_transactions', 1);
    }

    public function test_customer_cannot_redeem_without_enough_points(): void
    {
        $reward = Reward::factory()->for($this->brand)->costing(5_000)->create();

        $this->withHeader('X-Brand', $this->brand->slug)
            ->postJson("/api/v1/rewards/{$reward->getKey()}/redeem")
            ->assertStatus(422)
            ->assertJsonPath('code', 'InsufficientPointsException');

        $this->assertSame(1_000, $this->customer->account()->first()?->balance);
        $this->assertDatabaseCount('redemptions', 0);
        $this->assertDatabaseCount('point_transactions', 0);
    }

    public function test_out_of_stock_reward_is_rejected(): void
    {
        $reward = Reward::factory()->for($this->brand)->costing(100)->outOfStock()->create();

        $this->withHeader('X-Brand', $this->brand->slug)
            ->postJson("/api/v1/rewards/{$reward->getKey()}/redeem")
            ->assertStatus(409)
            ->assertJsonPath('code', 'OutOfStockException');

        $this->assertSame(1_000, $this->customer->account()->first()?->balance);
    }

    public function test_reward_from_another_brand_is_not_found(): void
    {
        $foreignReward = Reward::factory()->costing(100)->create();

        $this->withHeader('X-Brand', $this->brand->slug)
            ->postJson("/api/v1/rewards/{$foreignReward->getKey()}/redeem")
            ->assertStatus(404);
    }

    public function test_repeated_request_with_same_idempotency_key_is_charged_once(): void
    {
        $reward = Reward::factory()->for($this->brand)->costing(400)->create();

        $first = $this->withHeader('X-Brand', $this->brand->slug)
            ->withHeader('Idempotency-Key', 'redeem-demo-1')
            ->postJson("/api/v1/rewards/{$reward->getKey()}/redeem");
        $first->assertCreated();

        $second = $this->withHeader('X-Brand', $this->brand->slug)
            ->withHeader('Idempotency-Key', 'redeem-demo-1')
            ->postJson("/api/v1/rewards/{$reward->getKey()}/redeem");
        $second->assertCreated();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(600, $this->customer->account()->first()?->balance);
        $this->assertDatabaseCount('redemptions', 1);
        $this->assertDatabaseCount('point_transactions', 1);
    }
}
