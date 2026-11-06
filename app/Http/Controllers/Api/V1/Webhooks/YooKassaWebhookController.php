<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Payments\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class YooKassaWebhookController extends Controller
{
    public function __invoke(Request $request, PaymentService $payments): JsonResponse
    {
        $event = (string) $request->input('event', '');
        $externalId = (string) $request->input('object.id', '');

        $payment = Payment::query()->where('external_id', $externalId)->first();

        if (! $payment instanceof Payment) {
            Log::warning('webhook.payment.unknown', ['event' => $event, 'external_id' => $externalId]);

            return response()->json(['result' => 'ignored'], 202);
        }

        match ($event) {
            'payment.succeeded' => $payments->confirm($payment, $request->all()),
            'payment.canceled' => $payments->cancel($payment, $request->all()),
            default => null,
        };

        return response()->json(['result' => 'ok']);
    }
}
