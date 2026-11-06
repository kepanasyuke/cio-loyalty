<?php

declare(strict_types=1);

namespace App\Enums;

enum RedemptionStatus: string
{
    case Created = 'created';
    case Fulfilled = 'fulfilled';
    case Canceled = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Создано',
            self::Fulfilled => 'Выдано',
            self::Canceled => 'Отменено',
        };
    }

    public function isFinal(): bool
    {
        return $this !== self::Created;
    }
}
