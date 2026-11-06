<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class DomainException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message,
        private readonly int $status = 422,
        private readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    public function render(): JsonResponse
    {
        $payload = [
            'message' => $this->getMessage(),
            'code' => class_basename(static::class),
        ];

        if ($this->context !== []) {
            $payload['context'] = $this->context;
        }

        return response()->json($payload, $this->status);
    }
}
