<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Tier;
use App\Models\LoyaltyAccount;
use App\Services\Loyalty\TierCalculator;
use Closure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Пересчитывает тариф всех LoyaltyAccount по текущему lifetime_points.
 *
 * Запускается:
 * - по расписанию (например, ежечасно или после полуночи UTC);
 * - вручную после изменения порогов в config('loyalty.tiers').
 *
 * Идемпотентность:
 * - тариф вычисляется из lifetime_points, а не инкрементально.
 *   Повторный запуск не меняет результат (кроме случаев, когда
 *   пороги в конфиге изменились — тогда это и нужно).
 * - UPDATE только для аккаунтов, чей tier реально изменился —
 *   на «стабильной» базе 0 UPDATE-запросов.
 *
 * Производительность:
 * - chunkById(500): 500 моделей в памяти на чанк; SQL-запросы
 *   через WHERE id > last_id (без OFFSET), не деградируют на
 *   больших таблицах.
 * - select(['id', 'tier', 'lifetime_points']) — не тянем
 *   timestamps, json-настройки, связи. Экономия ~3× памяти на чанк.
 * - Log только финальный (сводка), per-account не пишем — объём.
 *
 * Надёжность:
 * - ShouldBeUnique: cron и ручной запуск не пересекаются. Замок
 *   в cache, TTL uniqueFor = 1 час (джоб с timeout 300 с до
 *   этого не доживёт).
 * - timeout = 300 с: chunked-обработка 100k+ аккаунтов укладывается
 *   за 1-2 минуты; 120 с — риск ложного таймаута.
 * - tries = 3: на случай транзиентных ошибок БД / сети.
 *
 * Стиль: один return на функцию, без break/continue, без ранних return.
 */
final class RecalculateTier implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    private const CHUNK_SIZE = 500;

    public int $timeout = 300;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    private int $updated = 0;

    public function handle(TierCalculator $tiers): void
    {
        LoyaltyAccount::query()
            ->select(['id', 'tier', 'lifetime_points'])
            ->chunkById(self::CHUNK_SIZE, $this->processChunk($tiers));

        $this->logSummary();
    }

    /**
     * Фабрика колбэка для chunkById. Захватывает $this и $tiers,
     * чтобы не передавать их через параметры колбэка (chunkById
     * передаёт только Collection $accounts).
     */
    private function processChunk(TierCalculator $tiers): Closure
    {
        return function (Collection $accounts) use ($tiers): void {
            foreach ($accounts as $account) {
                $this->updated += $this->recalculate($account, $tiers);
            }
        };
    }

    /**
     * Возвращает 1, если тариф изменился и запись сохранена, 0 — если нет.
     * Тернарник вместо ранних return — один return на функцию.
     */
    private function recalculate(LoyaltyAccount $account, TierCalculator $tiers): int
    {
        $tier = $tiers->for($account->lifetime_points);

        return $account->tier !== $tier
            ? $this->persist($account, $tier)
            : 0;
    }

    /**
     * forceFill обходит fillable — тариф выставляется внутренней
     * логикой, не пользовательским вводом. save() триггерит события
     * (saved/updated) — если есть observer инвалидации кеша, он сработает.
     * Если нужна «тихая» запись без событий — заменить на saveQuietly().
     */
    private function persist(LoyaltyAccount $account, Tier $tier): int
    {
        $account->forceFill(['tier' => $tier])->save();

        return 1;
    }

    private function logSummary(): void
    {
        Log::info('tier.recalculate.done', ['updated' => $this->updated]);
    }

    /**
     * Явный uniqueId, чтобы поведение не зависело от дефолта Laravel
     * (по умолчанию — class name; оставляем как есть, но фиксируем
     * намерение в коде).
     */
    public function uniqueId(): string
    {
        return self::class;
    }
}
