<?php

declare(strict_types=1);

namespace App\Services\Loyalty;

use App\Enums\PointTransactionType;
use App\Exceptions\DomainException;
use App\Exceptions\InsufficientPointsException;
use App\Models\LoyaltyAccount;
use App\Models\PointTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Единственный writer журнала баллов и материализованного balance.
 *
 * Инвариант:
 * LoyaltyAccount.balance = SUM(point_transactions.points) для счёта.
 * Нарушение — баг в PointLedger, а не в моделях. Reconciliation-команда
 * (см. docs) проверяет инвариант ежедневно.
 *
 * Поток apply() (в одной транзакции):
 * 1. lockForUpdate на LoyaltyAccount (сериализация параллельных операций
 *    по одному счёту).
 * 2. Idempotency check ПОСЛЕ блокировки: если ключ уже существует —
 *    возврат existing, без изменения balance. Проверка внутри лок-
 *    секции, потому что иначе два параллельных запроса с одинаковым
 *    idempotency_key оба пройдут проверку до блокировки и оба
 *    вставят транзакцию (двойное списание).
 * 3. Проверка balance + signedPoints >= 0 → InsufficientPointsException.
 * 4. INSERT point_transactions с balance_after = balance + signedPoints.
 * 5. UPDATE loyalty_accounts: balance, lifetime_points, tier.
 * 6. После commit — $account->refresh() (синхронизация in-memory).
 *
 * lifetime_points:
 * Всего начислено за всё время (не уменьшается при debit/expiry).
 * Используется TierCalculator для расчёта тарифа: клиент, заработавший
 * 1000 и потративший 900, остаётся на тарифе по 1000, а не по 100.
 *
 * Идемпотентность:
 * - idempotencyKey должен быть UNIQUE по всей таблице point_transactions
 *   (UNIQUE INDEX в БД обязателен). Если INDEX нет — параллельные
 *   запросы с одинаковым ключом создадут дубликаты без ошибки
 *   (тихий финансовый баг).
 * - Пустая строка '' — ошибка: нормализуйте до null или UUID.
 * - Одинаковый ключ для разных аккаунтов — семантическая ошибка
 *   (idempotency_key = идентификатор операции, не аккаунта). UNIQUE
 *   INDEX не даст вставить, но это баг caller'а.
 *
 * Lock order (важно для дедлоков):
 * Всегда LoyaltyAccount → point_transactions. Не наоборот.
 * Redis/прочие локи — до DB::transaction, не внутри.
 *
 * Тип транзакции (PointTransactionType):
 * - credit() — default Accrual, но caller может передать Adjustment
 *   для ручного начисления.
 * - debit() — default Redemption, но caller передаёт Expiry для
 *   сгорания (см. ExpirePoints) или Adjustment для ручного списания.
 * - Направление определяется ЗНАКОМ signedPoints, type — метка для
 *   аудита/UI.
 */
final class PointLedger
{
    public function __construct(private readonly TierCalculator $tiers) {}

    /**
     * Начисление баллов (положительная сумма).
     *
     * @param  int  $points  > 0 (иначе DomainException)
     * @param  string  $reason  человекочитаемое описание для аудита
     * @param  Model|null  $source  источник (Payment, Redemption) или null
     * @param  string|null  $idempotencyKey  UUID, непустая строка или null
     *
     * @throws DomainException points <= 0 или idempotencyKey = ''
     * @throws InsufficientPointsException не применимо для credit
     */
    public function credit(
        LoyaltyAccount $account,
        int $points,
        string $reason,
        ?Model $source = null,
        ?string $idempotencyKey = null,
        PointTransactionType $type = PointTransactionType::Accrual,
    ): PointTransaction {
        if ($points <= 0) {
            throw new DomainException('Сумма начисления должна быть положительной.', 422);
        }

        return $this->apply(
            account: $account,
            type: $type,
            signedPoints: $points,
            reason: $reason,
            source: $source,
            idempotencyKey: $this->normalizeIdempotencyKey($idempotencyKey),
        );
    }

    /**
     * Списание баллов (положительная сумма на входе, отрицательная в журнале).
     *
     * @param  int  $points  > 0 (иначе DomainException)
     *
     * @throws DomainException points <= 0 или idempotencyKey = ''
     * @throws InsufficientPointsException balance - points < 0
     */
    public function debit(
        LoyaltyAccount $account,
        int $points,
        string $reason,
        ?Model $source = null,
        ?string $idempotencyKey = null,
        PointTransactionType $type = PointTransactionType::Redemption,
    ): PointTransaction {
        if ($points <= 0) {
            throw new DomainException('Сумма списания должна быть положительной.', 422);
        }

        return $this->apply(
            account: $account,
            type: $type,
            signedPoints: -$points,
            reason: $reason,
            source: $source,
            idempotencyKey: $this->normalizeIdempotencyKey($idempotencyKey),
        );
    }

    private function apply(
        LoyaltyAccount $account,
        PointTransactionType $type,
        int $signedPoints,
        string $reason,
        ?Model $source,
        ?string $idempotencyKey,
    ): PointTransaction {
        $transaction = DB::transaction(function () use ($account, $type, $signedPoints, $reason, $source, $idempotencyKey): PointTransaction {
            return $this->applyLocked($account, $type, $signedPoints, $reason, $source, $idempotencyKey);
        });

        $account->refresh();

        return $transaction;
    }

    /**
     * @throws InsufficientPointsException
     */
    private function applyLocked(
        LoyaltyAccount $account,
        PointTransactionType $type,
        int $signedPoints,
        string $reason,
        ?Model $source,
        ?string $idempotencyKey,
    ): PointTransaction {
        $locked = $this->lockAccount($account);

        $existing = $this->findByIdempotencyKey($idempotencyKey);

        return $existing ?? $this->persist(
            locked: $locked,
            type: $type,
            signedPoints: $signedPoints,
            reason: $reason,
            source: $source,
            idempotencyKey: $idempotencyKey,
        );
    }

    /**
     * Захватывает блокировку на счёт. Возвращает свежий экземпляр.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    private function lockAccount(LoyaltyAccount $account): LoyaltyAccount
    {
        /** @var LoyaltyAccount */
        return LoyaltyAccount::query()
            ->whereKey($account->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Idempotency check ПОСЛЕ блокировки — гарантирует, что параллельный
     * запрос увидит commit предыдущего. null ключ — пропуск проверки.
     */
    private function findByIdempotencyKey(?string $idempotencyKey): ?PointTransaction
    {
        if ($idempotencyKey === null) {
            return null;
        }

        return PointTransaction::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    /**
     * @throws InsufficientPointsException
     */
    private function persist(
        LoyaltyAccount $locked,
        PointTransactionType $type,
        int $signedPoints,
        string $reason,
        ?Model $source,
        ?string $idempotencyKey,
    ): PointTransaction {
        $balanceAfter = $locked->balance + $signedPoints;

        if ($balanceAfter < 0) {
            throw new InsufficientPointsException($locked->balance, abs($signedPoints));
        }

        $lifetime = $locked->lifetime_points + max($signedPoints, 0);

        $transaction = $this->insertTransaction(
            locked: $locked,
            type: $type,
            signedPoints: $signedPoints,
            balanceAfter: $balanceAfter,
            reason: $reason,
            source: $source,
            idempotencyKey: $idempotencyKey,
        );

        $this->updateAccount($locked, $balanceAfter, $lifetime);

        return $transaction;
    }

    private function insertTransaction(
        LoyaltyAccount $locked,
        PointTransactionType $type,
        int $signedPoints,
        int $balanceAfter,
        string $reason,
        ?Model $source,
        ?string $idempotencyKey,
    ): PointTransaction {
        return $locked->transactions()->create([
            'type' => $type,
            'points' => $signedPoints,
            'balance_after' => $balanceAfter,
            'reason' => $reason,
            'idempotency_key' => $idempotencyKey,
            'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey(),
        ]);
    }

    private function updateAccount(LoyaltyAccount $locked, int $balanceAfter, int $lifetime): void
    {
        $locked->forceFill([
            'balance' => $balanceAfter,
            'lifetime_points' => $lifetime,
            'tier' => $this->tiers->for($lifetime),
        ])->save();
    }

    /**
     * @throws DomainException пустая строка
     */
    private function normalizeIdempotencyKey(?string $idempotencyKey): ?string
    {
        if ($idempotencyKey === '') {
            throw new DomainException(
                'idempotencyKey не может быть пустой строкой: передайте null или UUID.',
                422,
            );
        }

        return $idempotencyKey;
    }
}
