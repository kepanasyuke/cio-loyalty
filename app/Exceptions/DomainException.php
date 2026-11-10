<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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

    public function render(Request $request): JsonResponse|Response
    {
        return $this->isApiRequest($request)
            ? $this->toJsonResponse()
            : $this->toHtmlResponse();
    }

    private function isApiRequest(Request $request): bool
    {
        return $request->expectsJson() || $request->is('api/*');
    }

    private function toJsonResponse(): JsonResponse
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

    private function toHtmlResponse(): Response
    {
        $view = 'errors.'.$this->status;

        return view()->exists($view)
            ? response()->view($view, ['exception' => $this], $this->status)
            : response($this->getMessage(), $this->status);
    }
}
