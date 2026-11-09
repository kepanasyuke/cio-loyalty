<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PointTransactionType;
use App\Models\LoyaltyAccount;
use App\Services\Loyalty\PointLedger;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

final class ExpirePoints extends Command
{
    protected $signature = 'loyalty:expire-points
                            {--days= : Срок жизни баллов в днях}
                            {--dry-run : Только показать, что сгорит}
                            {--limit= : Ограничить количество счетов (для тестов)}';

    protected $description = 'Списывает баллы, не использованные дольше настроенного срока';

    private int $expiredCount = 0;

    private int $expiredPoints = 0;

    private Carbon $now;

    private int $days = 0;

    private string $today = '';

    private bool $dryRun = false;

    public function handle(PointLedger $ledger): int
    {
        $this->setup();
        $this->runExpiry($ledger);

        return self::SUCCESS;
    }

    private function setup(): void
    {
        $this->setupTime();
        $this->setupOptions();
    }

    private function setupTime(): void
    {
        $this->now = Carbon::now('UTC');
        $this->today = $this->now->toDateString();
    }

    private function setupOptions(): void
    {
        $this->days = $this->resolveDays();
        $this->dryRun = (bool) $this->option('dry-run');
    }

    private function resolveDays(): int
    {
        return $this->validateDays((int) ($this->option('days') ?? config('loyalty.points.ttl_days', 365)));
    }

    private function validateDays(int $days): int
    {
        return $days >= 1
            ? $days
            : throw new InvalidArgumentException("TTL должен быть >= 1 дня, получено: {$days}");
    }

    private function runExpiry(PointLedger $ledger): void
    {
        $this->expireStale($ledger);
        $this->info($this->summary());
    }

    private function expireStale(PointLedger $ledger): void
    {
        foreach ($this->staleQuery()->cursor() as $account) {
            $this->expireAccount($ledger, $account);
        }
    }

    private function staleQuery(): Builder
    {
        return LoyaltyAccount::query()
            ->where('balance', '>', 0)
            ->whereDoesntHave(
                'transactions',
                fn ($query) => $query->where('created_at', '>=', $this->threshold()),
            )
            ->when($this->limit() !== null, fn ($query) => $query->take($this->limit()));
    }

    private function threshold(): Carbon
    {
        return $this->now->copy()->subDays($this->days);
    }

    private function limit(): ?int
    {
        return $this->parseLimit((int) $this->option('limit'));
    }

    private function parseLimit(int $value): ?int
    {
        return $value > 0 ? $value : null;
    }

    private function expireAccount(PointLedger $ledger, LoyaltyAccount $account): void
    {
        if ($this->dryRun) {
            $this->preview($account);
        } else {
            $this->debit($ledger, $account);
        }
    }

    private function preview(LoyaltyAccount $account): void
    {
        $this->line("Счёт #{$account->getKey()}: сгорит {$account->balance} баллов");
        $this->trackExpiry($account);
    }

    private function debit(PointLedger $ledger, LoyaltyAccount $account): void
    {
        try {
            $this->tryDebit($ledger, $account);
        } catch (Throwable $error) {
            $this->logFailure($account, $error);
        }
    }

    private function tryDebit(PointLedger $ledger, LoyaltyAccount $account): void
    {
        $this->performDebit($ledger, $account);
        $this->trackExpiry($account);
    }

    private function performDebit(PointLedger $ledger, LoyaltyAccount $account): void
    {
        $ledger->debit(
            account: $account,
            points: $account->balance,
            reason: "Сгорание баллов (не использованы {$this->days} дн.)",
            idempotencyKey: "expire:{$account->getKey()}:{$this->today}",
            type: PointTransactionType::Expiry,
        );
    }

    private function trackExpiry(LoyaltyAccount $account): void
    {
        $this->expiredCount++;
        $this->expiredPoints += $account->balance;
    }

    private function logFailure(LoyaltyAccount $account, Throwable $error): void
    {
        Log::error('ExpirePoints: не удалось списать', [
            'account_id' => $account->getKey(),
            'balance' => $account->balance,
            'error' => $error->getMessage(),
        ]);
    }

    private function summary(): string
    {
        return $this->dryRun ? $this->dryRunSummary() : $this->debitSummary();
    }

    private function dryRunSummary(): string
    {
        return "Режим проверки: затронуто {$this->expiredCount} счетов, сгорит {$this->expiredPoints} баллов.";
    }

    private function debitSummary(): string
    {
        return "Списано счетов: {$this->expiredCount}, баллов: {$this->expiredPoints}.";
    }
}
