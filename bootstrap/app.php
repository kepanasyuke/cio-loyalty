<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureCustomerBrand;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\EnsureWebhookFromTrustedIp;
use App\Http\Middleware\ResolveBrand;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn (): string => route('admin.login'));

        $middleware->alias([
            'brand' => ResolveBrand::class,
            'customer.brand' => EnsureCustomerBrand::class,
            'role' => EnsureUserHasRole::class,
            'webhook.ip' => EnsureWebhookFromTrustedIp::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson()
        );
    })
    ->create();
