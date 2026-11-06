<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PointTransactionType;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\PointTransaction;
use App\Models\Reward;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $brand = Brand::query()->firstOrCreate(
            ['slug' => 'coffee-lab'],
            ['name' => 'Coffee Lab', 'is_active' => true]
        );

        User::query()->firstOrCreate(
            ['email' => 'admin@cio.test'],
            [
                'name' => 'CIO Admin',
                'password' => 'password',
                'role' => 'admin',
                'brand_id' => null,
            ]
        );

        User::query()->firstOrCreate(
            ['email' => 'manager@cio.test'],
            [
                'name' => 'Бренд-менеджер',
                'password' => 'password',
                'role' => 'manager',
                'brand_id' => $brand->getKey(),
            ]
        );

        $catalog = [
            ['title' => 'Кофе в подарок', 'cost_points' => 500, 'stock' => null],
            ['title' => 'Круассан за баллы', 'cost_points' => 800, 'stock' => 50],
            ['title' => 'Десерт недели', 'cost_points' => 1_200, 'stock' => 20],
            ['title' => 'Скидка 20% на заказ', 'cost_points' => 2_000, 'stock' => null],
            ['title' => 'Мерч бренда', 'cost_points' => 5_000, 'stock' => 10],
        ];

        foreach ($catalog as $item) {
            Reward::query()->firstOrCreate(
                ['brand_id' => $brand->getKey(), 'title' => $item['title']],
                [
                    'description' => 'Награда из демо-каталога.',
                    'cost_points' => $item['cost_points'],
                    'stock' => $item['stock'],
                    'is_active' => true,
                ]
            );
        }

        if ($brand->customers()->exists()) {
            return;
        }

        $balances = [0, 250, 1_400, 6_500, 22_000];

        foreach (range(1, 25) as $index) {
            $balance = $balances[$index % count($balances)];

            $customer = Customer::factory()
                ->for($brand)
                ->withPoints($balance)
                ->create([
                    'phone' => '+7900'.str_pad((string) $index, 7, '0', STR_PAD_LEFT),
                    'name' => 'Клиент '.$index,
                ]);

            if ($balance === 0) {
                continue;
            }

            PointTransaction::query()->create([
                'loyalty_account_id' => $customer->account->getKey(),
                'type' => PointTransactionType::Accrual,
                'points' => $balance,
                'balance_after' => $balance,
                'reason' => 'Приветственные баллы демо-стенда',
            ]);
        }
    }
}
