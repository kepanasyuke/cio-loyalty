<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Notifications\LogPushGateway;
use App\Services\Notifications\PushGateway;
use App\Services\Payments\FakeGateway;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\YooKassaGateway;
use App\Support\BrandContext;
use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use LogicException;

/**
 * Сборка приложения: сервисы и rate-limiter'ы.
 *
 * Привязки (register):
 * - BrandContext — scoped: один экземпляр на HTTP-запрос (Octane-safe).
 *   Заполняется middleware ResolveBrand по заголовку X-Brand.
 * - PaymentGateway — singleton, фабрика по config('services.payments.driver').
 *   Неизвестный драйвер — LogicException (fail-fast), не fallback на fake.
 * - PushGateway — singleton, фабрика по config('services.push.driver').
 *   В local/testing без конфига — LogPushGateway. В проде без конфига —
 *   LogicException: push не должен тихо писать в лог.
 *
 * Rate limiters (boot):
 * - api       — 60/min по user_id или IP.
 * - otp       — 5/min по IP (SMS-стоимость, DoS).
 * - login     — 5/min по email+IP + 20/час по IP (брутфорс админки).
 * - redeem    — 20/min по user_id или IP (транзакции, lockForUpdate).
 * - webhooks  — 120/min по IP (YooKassa + IP-whitelist на маршруте).
 * Все лимиты возвращают JSON-тело 429 с retry_after для клиентов API.
 */
class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(BrandContext::class);

        $this->app->singleton(PushGateway::class, fn (): PushGateway => $this->makePushGateway());

        $this->app->singleton(
            PaymentGateway::class,
            fn (Application $app): PaymentGateway => $this->makePaymentGateway(),
        );
    }

    public function boot(): void
    {
        $this->registerRateLimiters();
    }

    private function registerRateLimiters(): void
    {
        RateLimiter::for('api', fn (Request $request): Limit => $this->perMinute(
            limit: 60,
            key: $this->userOrIp($request),
        ));

        RateLimiter::for('otp', fn (Request $request): Limit => $this->perMinute(
            limit: 5,
            key: (string) $request->ip(),
        ));

        RateLimiter::for('login', fn (Request $request): array => [
            $this->perMinute(
                limit: 5,
                key: (string) $request->input('email').'|'.$request->ip(),
            ),
            $this->perHour(
                limit: 20,
                key: (string) $request->ip(),
            ),
        ]);

        RateLimiter::for('redeem', fn (Request $request): Limit => $this->perMinute(
            limit: 20,
            key: $this->userOrIp($request),
        ));

        RateLimiter::for('webhooks', fn (Request $request): Limit => $this->perMinute(
            limit: 120,
            key: (string) $request->ip(),
        ));
    }

    /**
     * Единая фабрика Limit с JSON-ответом 429 (retry_after для клиентов API).
     * Вынесено из registerRateLimiters — DRY для 6 лимитов.
     */
    private function perMinute(int $limit, string $key): Limit
    {
        return Limit::perMinute($limit)
            ->by($key)
            ->response($this->rateLimitResponse());
    }

    private function perHour(int $limit, string $key): Limit
    {
        return Limit::perHour($limit)
            ->by($key)
            ->response($this->rateLimitResponse());
    }

    private function rateLimitResponse(): Closure
    {
        return fn (Request $request, array $headers): JsonResponse => response()->json(
            [
                'message' => 'Слишком много запросов. Попробуйте позже.',
                'retry_after' => (int) ($headers['Retry-After'] ?? 60),
            ],
            429,
            $headers,
        );
    }

    private function userOrIp(Request $request): string
    {
        $identifier = $request->user()?->getAuthIdentifier();

        return $identifier !== null ? (string) $identifier : (string) $request->ip();
    }

    /**
     * PaymentGateway по config('services.payments.driver').
     *
     * Fail-fast: неизвестный драйвер → LogicException. Не fallback на
     * FakeGateway: опечатка в PAYMENT_DRIVER не должна молча принимать
     * фейковые платежи в проде. FakeGateway — только если явно указан
     * (для local/testing).
     */
    private function makePaymentGateway(): PaymentGateway
    {
        return match (config('services.payments.driver')) {
            'yookassa' => $this->yookassaGateway(),
            'fake' => new FakeGateway,
            default => throw new LogicException(
                'Unknown payment driver: '.var_export(config('services.payments.driver'), true)
                .'. Supported: yookassa, fake (local/testing only).',
            ),
        };
    }

    private function yookassaGateway(): PaymentGateway
    {
        $shopId = (string) config('services.yookassa.shop_id');
        $secretKey = (string) config('services.yookassa.secret_key');

        if ($shopId === '' || $secretKey === '') {
            throw new LogicException(
                'YooKassa credentials missing: services.yookassa.shop_id '
                .'and services.yookassa.secret_key must be non-empty.',
            );
        }

        return new YooKassaGateway(
            shopId: $shopId,
            secretKey: $secretKey,
            baseUrl: (string) config('services.yookassa.base_url', 'https://api.yookassa.ru/v3'),
            returnUrl: (string) config('services.yookassa.return_url', config('app.url')),
        );
    }

    /**
     * PushGateway по config('services.push.driver').
     *
     * В local/testing без конфига — LogPushGateway (заглушка, пишет в лог).
     * В проде без конфига — LogicException: push в лог = клиент не получает
     * push, но в laravel.log всё есть (тихий баг).
     *
     * Если FCM/APNs-драйвера ещё нет — деплой блокируется громко, а не
     * тихо логирует.
     */
    private function makePushGateway(): PushGateway
    {
        $driver = config('services.push.driver');

        if ($driver === null && app()->environment(['local', 'testing'])) {
            return new LogPushGateway(logger());
        }

        return match ($driver) {
            'log' => new LogPushGateway(logger()),
            default => throw new LogicException(
                'Unknown push driver: '.var_export($driver, true)
                .'. Supported: log (local/testing), fcm, apns (to be implemented).',
            ),
        };
    }
}
