<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\LoyaltyAccount;
use App\Services\Loyalty\TierCalculator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

final class RecalculateTier implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 120;

    public function handle(TierCalculator $tiers): void
    {
        LoyaltyAccount::query()->chunkById(500, function ($accounts) use ($tiers): void {
            foreach ($accounts as $account) {
                $tier = $tiers->for($account->lifetime_points);

                if ($account->tier !== $tier) {
                    $account->forceFill(['tier' => $tier])->save();
                }
            }
        });
    }
}
