<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Генерация и проверка OTP-кодов для входа в мобильное приложение.
 *
 * Поток:
 * 1. request(phone) — генерирует 6-значный код, кеширует на TTL.
 *    В local/testing возвращает код для отладки (AuthController
 *    кладёт его в response как debug_code). В проде код должен
 *    отправляться через SMS — см. TODO ниже.
 * 2. verify(phone, code) — читает код, сравнивает hash_equals
 *    (constant-time). При успехе — forget (one-time use). При N
 *    неудачах — forget (брутфорс внутри TTL заблокирован).
 *
 * TODO: SMS-провайдер не интегрирован. request() в проде кеширует
 * код, но не отправляет SMS — клиент не может войти. В local/testing
 * работает через debug_code в response. Перед production:
 * - Добавить SmsGateway (интерфейс + реальный драйвер).
 * - Инжектировать в OtpService::request() и вызвать ->send().
 * - Либо fail-fast в проде: throw LogicException('SMS not configured').
 *
 * Безопасность:
 * - random_int(100000, 999999) — CSPRNG. 6 знаков, ~20 bits энтропии.
 * - hash_equals — constant-time, защита от timing attacks.
 * - Cache::forget после успеха — one-time use.
 * - MAX_ATTEMPTS = 5 — после 5 неудач код инвалидируется. Дополняет
 *   throttle:otp (5/min) и throttle:login (5/min) на маршрутах:
 *   throttle защищает per-IP, attempts — per-OTP (защита от
 *   распределённого брутфорса через прокси).
 * - Debug_code '000000' — fallback убран: если config пуст,
 *   генерируется случайный. Явное значение задаётся через
 *   OTP_DEBUG_CODE env.
 * - Cache key через sha1(phone) — телефон не хранится в открытом
 *   виде. Для полной защиты от known-phone-list атак заменить на
 *   hash_hmac('sha256', phone, app.key) — тогда без app.key
 *   восстановить телефон невозможно.
 *
 * Повторный request для того же phone перезаписывает код — старое
 * SMS становится невалидным. Если нужна идемпотентность «не более
 * 1 SMS в N секунд» — Cache::add() вместо Cache::put() в request().
 */
final class OtpService
{
    private const CODE_MIN = 100000;
    private const CODE_MAX = 999999;
    private const MAX_ATTEMPTS = 5;

    public function request(string $phone): ?string
    {
        $debug = $this->isDebug();
        $code = $debug ? $this->debugCode() : $this->randomCode();

        Cache::put($this->key($phone), $code, $this->ttl());
        Cache::forget($this->attemptsKey($phone));

        $this->logRequested($phone, $debug);

        // TODO: $this->smsGateway->send($phone, "Код входа: {$code}");
        // Сейчас SMS не отправляется: в проде клиент не получит код.
        return $debug ? $code : null;
    }

    public function verify(string $phone, string $code): bool
    {
        $codeKey = $this->key($phone);
        $attemptsKey = $this->attemptsKey($phone);

        $stored = Cache::get($codeKey);
        $attempts = (int) Cache::get($attemptsKey, 0);

        $valid = is_string($stored)
            && $attempts < self::MAX_ATTEMPTS
            && hash_equals($stored, $code);

        $valid
            ? $this->consume($codeKey, $attemptsKey)
            : $this->registerFailure($stored, $codeKey, $attemptsKey);

        $this->logVerified($phone, $valid, $attempts);

        return $valid;
    }

    /**
     * One-time use: forget код и счётчик попыток после успеха.
     */
    private function consume(string $codeKey, string $attemptsKey): void
    {
        Cache::forget($codeKey);
        Cache::forget($attemptsKey);
    }

    /**
     * Инкремент счётчика попыток. Cache::add — атомарный init с TTL;
     * Cache::increment — атомарный инкремент (Redis/Memcached).
     * После MAX_ATTEMPTS код инвалидируется.
     */
    private function registerFailure(mixed $stored, string $codeKey, string $attemptsKey): void
    {
        if (! is_string($stored)) {
            return;
        }

        if (Cache::add($attemptsKey, 1, $this->ttl())) {
            return;
        }

        $new = (int) Cache::increment($attemptsKey);

        if ($new >= self::MAX_ATTEMPTS) {
            Cache::forget($codeKey);
        }
    }

    private function isDebug(): bool
    {
        return app()->environment(['local', 'testing']);
    }

    /**
     * Debug-код только для local/testing. Пустое значение из config
     * (или отсутствие ключа) — fallback на randomCode(), а не '000000'.
     */
    private function debugCode(): string
    {
        $code = (string) config('loyalty.otp.debug_code', '000000');

        return $code !== '' ? $code : $this->randomCode();
    }

    private function randomCode(): string
    {
        return (string) random_int(self::CODE_MIN, self::CODE_MAX);
    }

    private function ttl(): int
    {
        return (int) config('loyalty.otp.ttl_seconds', 300);
    }

    private function key(string $phone): string
    {
        return 'otp:'.sha1($phone);
    }

    private function attemptsKey(string $phone): string
    {
        return $this->key($phone).':attempts';
    }

    private function logRequested(string $phone, bool $debug): void
    {
        Log::info('otp.requested', [
            'phone_hash' => sha1($phone),
            'debug' => $debug,
        ]);
    }

    private function logVerified(string $phone, bool $valid, int $attempts): void
    {
        Log::info('otp.verified', [
            'phone_hash' => sha1($phone),
            'valid' => $valid,
            'attempt' => $attempts + 1,
        ]);
    }
}
