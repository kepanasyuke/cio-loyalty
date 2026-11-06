<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\DomainException;
use App\Models\Customer;
use App\Support\BrandContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCustomerBrand
{
    public function __construct(private readonly BrandContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $customer = $request->user();

        if (! $customer instanceof Customer) {
            throw new DomainException('Требуется авторизация клиента.', 401);
        }

        if ($customer->is_blocked) {
            throw new DomainException('Аккаунт заблокирован.', 403);
        }

        if ((int) $customer->brand_id !== $this->context->id()) {
            throw new DomainException('Токен не принадлежит этому бренду.', 403);
        }

        return $next($request);
    }
}
