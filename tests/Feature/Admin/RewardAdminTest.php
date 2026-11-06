<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Brand;
use App\Models\Reward;
use App\Models\User;
use App\Services\Catalog\RewardCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RewardAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_creates_reward_and_catalog_cache_is_flushed(): void
    {
        $brand = Brand::factory()->create();
        $admin = User::factory()->admin()->create();

        $catalog = app(RewardCatalog::class);

        $this->assertCount(0, $catalog->active($brand));

        $this->actingAs($admin)
            ->post(route('admin.rewards.store'), [
                'brand_id' => $brand->getKey(),
                'title' => 'Кофе в подарок',
                'description' => 'Любой напиток до 400 мл',
                'cost_points' => 500,
                'is_active' => '1',
            ])
            ->assertRedirect(route('admin.rewards.index'));

        $this->assertDatabaseHas('rewards', [
            'brand_id' => $brand->getKey(),
            'title' => 'Кофе в подарок',
            'cost_points' => 500,
        ]);

        $this->assertCount(1, $catalog->active($brand));
    }

    public function test_manager_cannot_edit_reward_of_another_brand(): void
    {
        $ownBrand = Brand::factory()->create();
        $foreignReward = Reward::factory()->create();

        $manager = User::factory()->create([
            'brand_id' => $ownBrand->getKey(),
        ]);

        $this->actingAs($manager)
            ->get(route('admin.rewards.edit', $foreignReward))
            ->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
    }

    public function test_guest_cannot_open_customers_section(): void
    {
        $this->get(route('admin.customers.index'))->assertRedirect(route('admin.login'));
    }
}
