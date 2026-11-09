<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PointTransactionType;
use App\Models\LoyaltyAccount;
use App\Services\Loyalty\PointLedger;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ExpirePoints extends Command
{
    protected $signature = 'loyalty:expire-points {--days= : Срок жизни баллов в днях} {--dry-run : Только показать, что сгорит}';

    protected $description = 'Списывает баллы, не использованные дольше настроенного срока';

    public function handle(PointLedger $ledger): int
    {
        $days = $this->resolveDays();
        $today = now()->toDateString();
        $threshold = now()->subDays($days);
        $dryRun = (bool) $this->option('dry-run');

        $processed = $this->expireStaleAccounts($ledger, $threshold, $days, $today, $dryRun);

        $this->info($this->summary($dryRun, $processed));

        return self::SUCCESS;
    }

    private function resolveDays(): int
    {
        return (int) ($this->option('days') ?? config('loyalty.points.ttl_days', 365));
    }

    private function expireStaleAccounts(PointLedger $ledger, Carbon $threshold, int $days, string $today, bool $dryRun): int
    {
        $processed = 0;

        foreach ($this->staleAccounts($threshold)->lazyById(500) as $account) {
            $processed += $this->expireAccount($ledger, $account, $days, $today, $dryRun);
        }

        return $processed;
    }

    private function staleAccounts(Carbon $threshold): Builder
    {
        return LoyaltyAccount::query()
            ->where('balance', '>', 0)
            ->whereNotExists($this->recentTransactionsSubquery($threshold));
    }

    private function recentTransactionsSubquery(Carbon $threshold): \Closure
    {
        return function ($query) use ($threshold): void {
            $query->select(DB::raw(1))
                ->from('point_transactions')
                ->whereColumn('point_transactions.loyalty_account_id', 'loyalty_accounts.id')
                ->where('point_transactions.created_at', '>=', $threshold);
        };
    }

    private function expireAccount(PointLedger $ledger, LoyaltyAccount $account, int $days, string $today, bool $dryRun): int
    {
        return $dryRun
            ? $this->previewAccount($account)
            : $this->debitAccount($ledger, $account, $days, $today);
    }

    private function previewAccount(LoyaltyAccount $account): int
    {
        $this->line("Счёт #{$account->getKey()}: сгорит {$account->balance} баллов");

        return 0;
    }

    private function debitAccount(PointLedger $ledger, LoyaltyAccount $account, int $days, string $today): int
    {
        $ledger->debit(
            account: $account,
            points: $account->balance,
            reason: "Сгорание баллов (не использованы {$days} дн.)",
            idempotencyKey: "expire:{$account->getKey()}:{$today}",
            type: PointTransactionType::Expiry,
        );

        return 1;
    }

    private function summary(bool $dryRun, int $processed): string
    {
        return $dryRun ? 'Режим проверки завершён.' : "Списано счетов: {$processed}";
    }
}
