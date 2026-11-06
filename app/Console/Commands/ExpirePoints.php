<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PointTransactionType;
use App\Models\LoyaltyAccount;
use App\Services\Loyalty\PointLedger;
use Illuminate\Console\Command;

final class ExpirePoints extends Command
{
    protected $signature = 'loyalty:expire-points {--days= : Срок жизни баллов в днях} {--dry-run : Только показать, что сгорит}';

    protected $description = 'Списывает баллы, не использованные дольше настроенного срока';

    public function handle(PointLedger $ledger): int
    {
        $days = (int) ($this->option('days') ?? config('loyalty.points.ttl_days', 365));
        $threshold = now()->subDays($days);
        $dryRun = (bool) $this->option('dry-run');
        $processed = 0;

        LoyaltyAccount::query()
            ->where('balance', '>', 0)
            ->whereDoesntHave('transactions', function ($query) use ($threshold): void {
                $query->where('created_at', '>=', $threshold);
            })
            ->chunkById(200, function ($accounts) use ($ledger, $days, $dryRun, &$processed): void {
                foreach ($accounts as $account) {
                    if ($dryRun) {
                        $this->line("Счёт #{$account->getKey()}: сгорит {$account->balance} баллов");

                        continue;
                    }

                    $ledger->debit(
                        account: $account,
                        points: $account->balance,
                        reason: "Сгорание баллов (не использованы {$days} дн.)",
                        idempotencyKey: 'expire:'.$account->getKey().':'.now()->toDateString(),
                        type: PointTransactionType::Expiry,
                    );

                    $processed++;
                }
            });

        $this->info($dryRun ? 'Режим проверки завершён.' : "Списано счетов: {$processed}");

        return self::SUCCESS;
    }
}
