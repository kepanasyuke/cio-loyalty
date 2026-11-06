<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\DomainException;
use App\Models\Brand;
use App\Support\BrandContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResolveBrand
{
    public function __construct(private readonly BrandContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $slug = (string) ($request->header('X-Brand') ?? $request->query('brand', ''));

        if ($slug === '') {
            throw new DomainException('Не указан бренд: добавьте заголовок X-Brand.', 400);
        }

        $brand = Brand::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->first();

        if (! $brand instanceof Brand) {
            throw new DomainException('Бренд не найден.', 404);
        }

        $this->context->set($brand);

        return $next($request);
    }
}
