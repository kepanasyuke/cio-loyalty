<?php

declare(strict_types=1);

namespace App\Enums;

enum Tier: string
{
    case Bronze = 'bronze';
    case Silver = 'silver';
    case Gold = 'gold';
    case Platinum = 'platinum';

    /**
     * Названия тарифов не переводятся: это часть бренда программы,
     * узнаваемая в любой локали. Не заменять на «Серебро» и т.п.
     */
    public function label(): string
    {
        return match ($this) {
            self::Bronze => 'Bronze',
            self::Silver => 'Silver',
            self::Gold => 'Gold',
            self::Platinum => 'Platinum',
        };
    }

    /**
     * Множитель в сотых долях. Единственный источник истины.
     * Bronze 1.00 → 100, Silver 1.10 → 110, Gold 1.25 → 125, Platinum 1.50 → 150.
     */
    public function multiplierHundredths(): int
    {
        return match ($this) {
            self::Bronze => 100,
            self::Silver => 110,
            self::Gold => 125,
            self::Platinum => 150,
        };
    }

    /**
     * Множитель как float — только для API-сериализации.
     * Не использовать для расчётов: float-погрешность испортит целые баллы.
     */
    public function multiplier(): float
    {
        return $this->multiplierHundredths() / 100.0;
    }

    /**
     * Применяет множитель к базовому количеству баллов.
     * Целочисленная арифметика, округление half-up.
     * 100 × 1.1 = 110, 5 × 1.1 = 6 (5.5 → 6), 2 × 1.25 = 3 (2.5 → 3).
     */
    public function applyTo(int $points): int
    {
        return intdiv($points * $this->multiplierHundredths() + 50, 100);
    }
}
