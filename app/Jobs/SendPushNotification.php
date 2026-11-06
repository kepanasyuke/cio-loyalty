<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Customer;
use App\Services\Notifications\PushGateway;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class SendPushNotification implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 60, 180];

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly int $customerId,
        public readonly string $title,
        public readonly string $body,
        public readonly array $data = [],
    ) {}

    public function handle(PushGateway $push): void
    {
        $customer = Customer::query()
            ->with('pushTokens')
            ->find($this->customerId);

        if (! $customer instanceof Customer) {
            return;
        }

        foreach ($customer->pushTokens as $token) {
            $push->send($token->token, $this->title, $this->body, $this->data);

            $token->forceFill(['last_used_at' => now()])->save();
        }
    }
}
