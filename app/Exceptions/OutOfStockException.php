<?php

declare(strict_types=1);

namespace App\Exceptions;

final class OutOfStockException extends DomainException
{
    public function __construct(string $rewardTitle)
    {
        parent::__construct(
            message: "Награда «{$rewardTitle}» закончилась.",
            status: 409,
        );
    }
}
