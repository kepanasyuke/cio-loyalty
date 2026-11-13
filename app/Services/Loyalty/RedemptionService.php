<?php

declare(strict_types=1);

namespace App\Services\Loyalty;

use App\Enums\RedemptionStatus;
use App\Exceptions\DomainException;
use App\Exceptions\OutOfStockException;
use App\Jobs\SendPushNotification;
use App\Models\Customer;
use App\Models\PointTransaction;
use App\Models\Redemption;
use App\Models\Reward;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Обмен наград на баллы.
 *
 * Поток redeem():
 * 1. Brand check (быстрый 404, не раскрываем существование).
 * 2. lockForUpdate на Reward (сериализация stock-изменений).
 * 3. Idempotency check — ПОСЛЕ lockForUpdate (см. ниже).
 * 4. is_active check (locked — authoritative).
 * 5. stock check (locked).
 * 6. Redemption::create + PointLedger::debit + decrement stock.
 * 7. Push — только если wasRecentlyCreated (не для idempotent return).
 *
 * Race на idempotencyKey (критично):
 * Проверка ДО lockForUpdate небезопасна: два параллельных запроса
 * с одинаковым ключом оба пройдут проверку, потом создадут ДВА
 * Redemption (второй — orphan, если PointLedger::debit вернёт
 * existing). Клиент получает два кода, второй обмен — бесплатный.
 * Проверка ПОСЛЕ lockForUpdate сериализует запросы.
 *
 * ВНИМАНИЕ (MySQL default REPEATABLE READ):
 * При REPEATABLE READ второй запрос видит snapshot на BEGIN и не
 * увидит commit первого даже после lockForUpdate(Reward).
 * Используется ->lockForUpdate() в idempotency check — locking
 * read игнорирует snapshot (всегда latest). Альтернативы:
 * - SET TRANSACTION ISOLATION LEVEL READ COMMITTED для этого
 *   соединения;
 * - catch UNIQUE violation на INSERT point_transactions и вернуть
 *   existing (самый надёжный, но требует UNIQUE INDEX).
 *
 * Lock order: Reward → LoyaltyAccount (в PointLedger::debit).
 * Не наоборот: другой код блокирует только Account (ExpirePoints,
 * RecalculateTier) или только Reward (RewardController::update) —
 * cross-lock отсутствует, deadlock'ов нет.
 *
 * Race на stock:
 * lockForUpdate(Reward) + проверка < 1 + decrement в одной tx.
 * Второй запрос видит stock = 0 после commit первого →
 * OutOfStockException 409. OK.
 *
 * Push:
 * - Dispatch ПОСЛЕ commit транзакции.
 * - afterCommit() — no-op вне транзакции, но защита, если redeem()
 *   когда-то обернут во внешнюю транзакцию.
 * - Только для wasRecentlyCreated — idempotent return существующего
 *   Redemption push не отправляет.
 *
 * UNIQUE INDEX redemptions.code:
 * Критичен. Без него generateCode() может вернуть дубликат при race
 * (шанс 36^10, но лучше unique constraint + generateCode
 * try/catch, чем тихий дубликат).
 *
 * UNIQUE INDEX point_transactions.idempotency_key:
 * Критичен. Без него race на idempotency (даже с lockForUpdate)
 * упадёт только на catch UNIQUE — иначе тихий дубликат.
 */
final class RedemptionService
{
    private const MAX_CODE_ATTEMPTS = 5;
    private const CODE_LENGTH = 10;

    public function __construct(private readonly PointLedger $ledger) {}

    /**
     * Обмен награды на баллы.
     *
     * @param  string|null  $requestKey  idempotency key от клиента (UUID)
     *
     * @throws DomainException 404 (brand mismatch), 422 (неактивна),
     *                         409 (idempotency conflict)
     * @throws OutOfStockException stock = 0
     * @throws \App\Exceptions\InsufficientPointsException недостаточно баллов
     */
    public function redeem(Customer $customer, Reward $reward, ?string $requestKey = null): Redemption
    {
        $this->ensureSameBrand($customer, $reward);

        $redemption = DB::transaction(fn (): Redemption => $this->redeemLocked(
            customer: $customer,
            reward: $reward,
            idempotencyKey: $this->buildIdempotencyKey($requestKey),
        ));

        $this->notifyCustomer($customer, $redemption);

        return $redemption->load('reward');
    }

    public function fulfill(Redemption $redemption): Redemption
    {
        if ($redemption->status->isFinal()) {
            throw new DomainException('Выдача уже закрыта.', 409);
        }

        $redemption->fulfill();

        return $redemption;
    }

    private function redeemLocked(Customer $customer, Reward $reward, ?string $idempotencyKey): Redemption
    {
        $lockedReward = $this->lockReward($reward);

        return $this->findExistingRedemption($idempotencyKey)
            ?? $this->createRedemption($customer, $lockedReward, $idempotencyKey);
    }

    private function lockReward(Reward $reward): Reward
    {
        return Reward::query()
            ->whereKey($reward->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Idempotency check ПОСЛЕ lockForUpdate(Reward). ->lockForUpdate()
     * на point_transactions — locking read игнорирует REPEATABLE READ
     * snapshot, гарантирует latest.
     */
    private function findExistingRedemption(?string $idempotencyKey): ?Redemption
    {
        return $idempotencyKey === null
            ? null
            : $this->existingRedemptionOrNull($idempotencyKey);
    }

    private function existingRedemptionOrNull(string $idempotencyKey): ?Redemption
    {
        $existing = PointTransaction::query()
            ->where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()
            ->first();

        return $existing !== null ? $this->ensureSourceIsRedemption($existing) : null;
    }

    /**
     * @throws DomainException 409 если ключ конфликтует с другой операцией
     */
    private function ensureSourceIsRedemption(PointTransaction $existing): Redemption
    {
        return $existing->source instanceof Redemption
            ? $existing->source
            : throw new DomainException(
                'Idempotency key конфликтует с другой операцией.',
                409,
            );
    }

    /**
     * @throws DomainException is_active = false
     * @throws OutOfStockException stock = 0
     * @throws \App\Exceptions\InsufficientPointsException
     */
    private function createRedemption(Customer $customer, Reward $lockedReward, ?string $idempotencyKey): Redemption
    {
        $this->ensureRewardActive($lockedReward);
        $this->ensureRewardInStock($lockedReward);

        $redemption = $this->insertRedemption($customer, $lockedReward);

        $this->ledger->debit(
            account: $customer->account()->firstOrFail(),
            points: $lockedReward->cost_points,
            reason: "Списание за награду «{$lockedReward->title}»",
            source: $redemption,
            idempotencyKey: $idempotencyKey,
        );

        $this->decrementStock($lockedReward);

        return $redemption;
    }

    private function insertRedemption(Customer $customer, Reward $lockedReward): Redemption
    {
        return Redemption::query()->create([
            'brand_id' => $customer->brand_id,
            'customer_id' => $customer->getKey(),
            'reward_id' => $lockedReward->getKey(),
            'points_spent' => $lockedReward->cost_points,
            'status' => RedemptionStatus::Created,
            'code' => $this->generateCode(),
        ]);
    }

    private function decrementStock(Reward $lockedReward): void
    {
        if ($lockedReward->stock !== null) {
            $lockedReward->decrement('stock');
        }
    }

    private function ensureSameBrand(Customer $customer, Reward $reward): void
    {
        if ((int) $reward->brand_id !== (int) $customer->brand_id) {
            throw new DomainException('Награда недоступна.', 404);
        }
    }

    private function ensureRewardActive(Reward $lockedReward): void
    {
        if (! $lockedReward->is_active) {
            throw new DomainException('Награда недоступна.', 422);
        }
    }

    private function ensureRewardInStock(Reward $lockedReward): void
    {
        if ($lockedReward->stock !== null && $lockedReward->stock < 1) {
            throw new OutOfStockException($lockedReward->title);
        }
    }

    private function buildIdempotencyKey(?string $requestKey): ?string
    {
        return $requestKey !== null ? 'redemption:'.$requestKey : null;
    }

    private function notifyCustomer(Customer $customer, Redemption $redemption): void
    {
        if ($redemption->wasRecentlyCreated) {
            SendPushNotification::dispatch(
                customerId: (int) $customer->getKey(),
                title: 'Награда оформлена',
                body: "Покажите код {$redemption->code} на кассе.",
                data: ['redemption_id' => (int) $redemption->getKey()],
            )->afterCommit();
        }
    }

    /**
     * Уникальный код выдачи. Рекурсия вместо do/while — один return
     * на функцию, защита от infinite loop при баге в БД (MAX_CODE_ATTEMPTS).
     *
     * @throws LogicException не удалось сгенерировать за MAX_CODE_ATTEMPTS
     */
    private function generateCode(): string
    {
        return $this->generateUniqueCode(self::MAX_CODE_ATTEMPTS);
    }

    private function generateUniqueCode(int $attemptsLeft): string
    {
        if ($attemptsLeft <= 0) {
            throw new LogicException(
                'Cannot generate unique redemption code after '
                .self::MAX_CODE_ATTEMPTS.' attempts.',
            );
        }

        $code = Str::upper(Str::random(self::CODE_LENGTH));

        return $this->codeExists($code)
            ? $this->generateUniqueCode($attemptsLeft - 1)
            : $code;
    }

    private function codeExists(string $code): bool
    {
        return Redemption::query()->where('code', $code)->exists();
    }
}
