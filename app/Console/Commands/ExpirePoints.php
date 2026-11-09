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

    public function handle(PointLedger $ledger): int
    {
        $days = $this->resolveDays();
        $dryRun = (bool) $this->option('dry-run');

        // Явный UTC: created_at хранится в UTC, а now() без таймзоны
        // сериализуется в APP_TIMEZONE — при коротких TTL это даёт сдвиг.
        $now = Carbon::now('UTC');
        $threshold = $now->copy()->subDays($days);
        $today = $now->toDateString();

        $this->expireStaleAccounts($ledger, $threshold, $days, $today, $dryRun);
        $this->info($this->summary($dryRun));

        return self::SUCCESS;
    }

    private function resolveDays(): int
    {
        // ?? ловит только null; --days=0 и --days= проходят как '0' и '',
        // превращая threshold в "сейчас" и снося все балансы.
        // Поэтому валидируем явно, а не полагаемся на дефолт.
        $days = (int) ($this->option('days') ?? config('loyalty.points.ttl_days', 365));

        if ($days < 1) {
            throw new InvalidArgumentException("TTL должен быть >= 1 дня, получено: {$days}");
        }

        return $days;
    }

    private function expireStaleAccounts(
        PointLedger $ledger,
        Carbon $threshold,
        int $days,
        string $today,
        bool $dryRun,
    ): void {
        // cursor() + take() даёт корректный --limit и итерирует по одной
        // модели (в отличие от lazyById, где take() перезаписывается чанком).
        foreach ($this->staleAccounts($threshold, $this->limit())->cursor() as $account) {
            $this->expireAccount($ledger, $account, $days, $today, $dryRun);
        }
    }

    private function staleAccounts(Carbon $threshold, ?int $limit): Builder
    {
        // whereDoesntHave, а не whereNotExists: сохраняет global scopes
        // модели PointTransaction (SoftDeletes и т.п.).
        // whereNotExists с from() их теряет — регрессия из прошлой итерации.
        return LoyaltyAccount::query()
            ->where('balance', '>', 0)
            ->whereDoesntHave(
                'transactions',
                fn ($query) => $query->where('created_at', '>=', $threshold),
            )
            ->when($limit !== null, fn ($query) => $query->take($limit));
    }

    private function expireAccount(
        PointLedger $ledger,
        LoyaltyAccount $account,
        int $days,
        string $today,
        bool $dryRun,
    ): void {
        if ($dryRun) {
            $this->previewAccount($account);
        } else {
            $this->debitAccount($ledger, $account, $days, $today);
        }
    }

    private function previewAccount(LoyaltyAccount $account): void
    {
        $this->line("Счёт #{$account->getKey()}: сгорит {$account->balance} баллов");

        $this->expiredCount++;
        $this->expiredPoints += $account->balance;
    }

    private function debitAccount(
        PointLedger $ledger,
        LoyaltyAccount $account,
        int $days,
        string $today,
    ): void {
        try {
            // ВАЖНО: debit() должен внутри делать lockForUpdate() + проверку
            // balance >= points + уникальный индекс на idempotency_key.
            // Иначе — гонка со снапшотом $account->balance и двойное списание
            // при параллельных запусках.
            $ledger->debit(
                account: $account,
                points: $account->balance,
                reason: "Сгорание баллов (не использованы {$days} дн.)",
                idempotencyKey: "expire:{$account->getKey()}:{$today}",
                type: PointTransactionType::Expiry,
            );

            $this->expiredCount++;
            $this->expiredPoints += $account->balance;
        } catch (Throwable $e) {
            // Один сбой не должен ронять весь прогон — остальные счета важнее.
            // Идемпотентность защитит от повторной обработки при перезапуске.
            Log::error('ExpirePoints: не удалось списать', [
                'account_id' => $account->getKey(),
                'balance' => $account->balance,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function limit(): ?int
    {
        $limit = (int) $this->option('limit');

        return $limit > 0 ? $limit : null;
    }

    private function summary(bool $dryRun): string
    {
        return $dryRun
            ? "Режим проверки: затронуто {$this->expiredCount} счетов, сгорит {$this->expiredPoints} баллов."
            : "Списано счетов: {$this->expiredCount}, баллов: {$this->expiredPoints}.";
    }
}
