<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Notifications\LogPushGateway;
use App\Services\Notifications\PushGateway;
use App\Services\Payments\FakeGateway;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\YooKassaGateway;
use App\Support\BrandContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(BrandContext::class);

        $this->app->singleton(PushGateway::class, LogPushGateway::class);

        $this->app->singleton(PaymentGateway::class, fn (Application $app): PaymentGateway => $this->makeGateway());
    }

    public function boot(): void
    {
        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(60)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        RateLimiter::for('otp', fn (Request $request): Limit => Limit::perMinute(5)->by($request->ip()));

        RateLimiter::for('redeem', fn (Request $request): Limit => Limit::perMinute(20)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        RateLimiter::for('webhooks', fn (Request $request): Limit => Limit::perMinute(120)->by($request->ip()));
    }

    private function makeGateway(): PaymentGateway
    {
        return match (config('services.payments.driver')) {
            'yookassa' => new YooKassaGateway(
                shopId: (string) config('services.yookassa.shop_id'),
                secretKey: (string) config('services.yookassa.secret_key'),
                baseUrl: (string) config('services.yookassa.base_url', 'https://api.yookassa.ru/v3'),
                returnUrl: (string) config('services.yookassa.return_url', config('app.url')),
            ),
            default => new FakeGateway,
        };
    }
}
