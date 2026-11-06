<?php

declare(strict_types=1);

namespace App\Enums;

enum PointTransactionType: string
{
    case Accrual = 'accrual';
    case Redemption = 'redemption';
    case Expiry = 'expiry';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Accrual => 'Начисление',
            self::Redemption => 'Списание за награду',
            self::Expiry => 'Сгорание баллов',
            self::Adjustment => 'Ручная корректировка',
        };
    }

    public function isCredit(): bool
    {
        return $this === self::Accrual;
    }
}
