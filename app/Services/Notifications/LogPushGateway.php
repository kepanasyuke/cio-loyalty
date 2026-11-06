<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use Illuminate\Support\Facades\Log;

final class LogPushGateway implements PushGateway
{
    public function send(string $token, string $title, string $body, array $data = []): void
    {
        Log::channel('single')->info('push', [
            'token' => $token,
            'title' => $title,
            'body' => $body,
            'data' => $data,
        ]);
    }
}
