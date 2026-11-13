<?php

declare(strict_types=1);

namespace App\Services\Loyalty;

use App\Enums\Tier;
use LogicException;

/**
 * Расчёт тарифа по lifetime_points.
 *
 * Config: config('loyalty.tiers') — map 'bronze' => 0, 'silver' => 500, ...
 * Все ключи должны быть валидными Tier::value (enum). Неизвестное имя
 * → LogicException при первом for()/nextThreshold() (fail-fast, не
 * ValueError из Tier::from() в середине транзакции).
 *
 * Инвариант: thresholds() отсортированы по возрастанию (asort).
 * for() полагается на этот порядок: «последний перешагнутый порог
 * побеждает». Не менять asort на arsort без переписывания for().
 *
 * Дефолт Bronze:
 * - Если config не содержит bronze, клиент с малым lifetime_points
 *   всё равно получает Bronze (implicit default).
 * - Если config пустой или все пороги > lifetime — Bronze
 *   (silent fallback; config bug, но не crash).
 *
 * nextThreshold():
 * - Возвращает следующий порог выше текущего тарифа.
 * - Если $tier отсутствует в config — LogicException (запрос о
 *   «следующем» для недостижимого tier — ошибка конфигурации).
 * - Для максимального tier в config — null (нет следующего).
 * - Не используется? Проверить grep -rn nextThreshold app/. Если
 *   нигде — удалить (YAGNI).
 *
 * Performance:
 * - thresholds() кешируется в property на lifetime инстанса.
 *   В RecalculateTier (100k for()) — 1 config() + 1 asort()
 *   вместо 100k. TierCalculator резолвится контейнером для
 *   каждого job'а отдельно (не singleton), но в рамках одного
 *   job'а — один instance, кеш живёт весь job.
 * - for() — O(k) где k = число tiers (~4). O(1) в нотации —
 *   не менять.
 */
final class TierCalculator
{
    /**
     * @var array<string, int>|null
     */
    private ?array $thresholdsCache = null;

    public function for(int $lifetimePoints): Tier
    {
        $computed = Tier::Bronze;

        foreach ($this->thresholds() as $name => $threshold) {
            $computed = $lifetimePoints >= $threshold
                ? Tier::from($name)
                : $computed;
        }

        return $computed;
    }

    public function nextThreshold(Tier $tier): ?int
    {
        $thresholds = $this->thresholds();

        return $this->findNextThreshold(
            thresholds: $thresholds,
            current: $this->currentThreshold($tier, $thresholds),
        );
    }

    /**
     * Первый порог выше current. thresholds() отсортированы
     * по возрастанию (asort), reset() возвращает минимальный
     * подходящий.
     *
     * @param  array<string, int>  $thresholds
     */
    private function findNextThreshold(array $thresholds, int $current): ?int
    {
        $candidates = array_filter(
            $thresholds,
            fn (int $threshold): bool => $threshold > $current,
        );

        return $candidates === [] ? null : (int) reset($candidates);
    }

    /**
     * @param  array<string, int>  $thresholds
     *
     * @throws LogicException tier отсутствует в config
     */
    private function currentThreshold(Tier $tier, array $thresholds): int
    {
        return $thresholds[$tier->value] ?? throw new LogicException(
            "Tier '{$tier->value}' отсутствует в config('loyalty.tiers'). "
            .'nextThreshold() для недостижимого tier — ошибка конфигурации.',
        );
    }

    /**
     * @return array<string, int>
     */
    private function thresholds(): array
    {
        return $this->thresholdsCache ??= $this->loadThresholds();
    }

    /**
     * Ascending sort обязателен — for() полагается на порядок.
     *
     * @return array<string, int>
     *
     * @throws LogicException неизвестный tier в config
     */
    private function loadThresholds(): array
    {
        /** @var array<string, int|string> $configured */
        $configured = (array) config('loyalty.tiers', []);

        $thresholds = [];
        foreach ($configured as $name => $threshold) {
            $thresholds[$name] = $this->validatedThreshold($name, $threshold);
        }

        asort($thresholds);

        return $thresholds;
    }

    /**
     * @throws LogicException неизвестный tier
     */
    private function validatedThreshold(string $name, int|string $threshold): int
    {
        return Tier::tryFrom($name) !== null
            ? (int) $threshold
            : throw new LogicException(
                "Unknown tier in config('loyalty.tiers'): '{$name}'. "
                .'Expected one of: '.implode(', ', array_column(Tier::cases(), 'value')),
            );
    }
}
