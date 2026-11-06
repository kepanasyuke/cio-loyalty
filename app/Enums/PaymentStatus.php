<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Canceled = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Ожидает оплаты',
            self::Succeeded => 'Оплачено',
            self::Canceled => 'Отменено',
        };
    }

    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }
}
