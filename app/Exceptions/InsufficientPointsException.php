<?php

declare(strict_types=1);

namespace App\Exceptions;

final class InsufficientPointsException extends DomainException
{
    public function __construct(public readonly int $balance, public readonly int $required)
    {
        parent::__construct(
            message: 'Недостаточно баллов для операции.',
            status: 422,
            context: [
                'balance' => $balance,
                'required' => $required,
                'missing' => max($required - $balance, 0),
            ],
        );
    }
}
