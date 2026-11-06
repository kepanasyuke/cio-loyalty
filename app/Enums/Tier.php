<?php

declare(strict_types=1);

namespace App\Enums;

enum Tier: string
{
    case Bronze = 'bronze';
    case Silver = 'silver';
    case Gold = 'gold';
    case Platinum = 'platinum';

    public function label(): string
    {
        return match ($this) {
            self::Bronze => 'Bronze',
            self::Silver => 'Silver',
            self::Gold => 'Gold',
            self::Platinum => 'Platinum',
        };
    }

    public function multiplier(): float
    {
        return match ($this) {
            self::Bronze => 1.0,
            self::Silver => 1.1,
            self::Gold => 1.25,
            self::Platinum => 1.5,
        };
    }
}
