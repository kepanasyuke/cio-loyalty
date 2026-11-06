<?php

declare(strict_types=1);

namespace App\Services\Notifications;

interface PushGateway
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function send(string $token, string $title, string $body, array $data = []): void;
}
