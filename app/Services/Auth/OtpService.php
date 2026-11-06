<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Support\Facades\Cache;

final class OtpService
{
    public function request(string $phone): ?string
    {
        $debug = app()->environment(['local', 'testing']);

        $code = $debug
            ? (string) config('loyalty.otp.debug_code', '000000')
            : (string) random_int(100000, 999999);

        Cache::put($this->key($phone), $code, (int) config('loyalty.otp.ttl_seconds', 300));

        // В реальном проекте здесь вызов SMS-провайдера (SMS.ru, Twilio и т.п.).
        return $debug ? $code : null;
    }

    public function verify(string $phone, string $code): bool
    {
        $stored = Cache::get($this->key($phone));

        if (! is_string($stored) || ! hash_equals($stored, $code)) {
            return false;
        }

        Cache::forget($this->key($phone));

        return true;
    }

    private function key(string $phone): string
    {
        return 'otp:'.sha1($phone);
    }
}
