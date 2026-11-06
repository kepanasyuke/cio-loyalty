<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CreatePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Customer;
use App\Models\Payment;
use App\Services\Payments\PaymentService;
use Illuminate\Http\JsonResponse;

final class PaymentController extends Controller
{
    public function store(CreatePaymentRequest $request, PaymentService $service): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();

        $payment = $service->start($customer, (string) $request->string('package'));

        return (new PaymentResource($payment))
            ->response()
            ->setStatusCode(201);
    }

    public function fakeConfirm(Payment $payment, PaymentService $service): JsonResponse
    {
        abort_unless($payment->gateway === 'fake', 404);

        $service->confirm($payment, ['source' => 'fake-confirm']);

        return response()->json([
            'status' => $payment->fresh()->status->value,
        ]);
    }
}
