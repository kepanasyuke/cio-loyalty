<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\RedemptionController;
use App\Http\Controllers\Api\V1\RewardController;
use App\Http\Controllers\Api\V1\Webhooks\YooKassaWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('webhooks/payments/yookassa', YooKassaWebhookController::class)
        ->middleware(['webhook.ip', 'throttle:webhooks'])
        ->name('webhooks.payments.yookassa');

    Route::get('payments/{payment}/fake-confirm', [PaymentController::class, 'fakeConfirm'])
        ->middleware('throttle:api')
        ->name('payments.fake_confirm');

    Route::middleware(['brand', 'throttle:api'])->group(function (): void {
        Route::post('auth/otp', [AuthController::class, 'requestOtp'])
            ->middleware('throttle:otp');

        Route::post('auth/token', [AuthController::class, 'issueToken'])
            ->middleware('throttle:otp');

        Route::get('rewards', [RewardController::class, 'index']);

        Route::middleware(['auth:sanctum', 'customer.brand'])->group(function (): void {
            Route::get('me', [ProfileController::class, 'show']);
            Route::get('me/transactions', [ProfileController::class, 'transactions']);
            Route::get('me/redemptions', [ProfileController::class, 'redemptions']);

            Route::post('rewards/{reward}/redeem', [RedemptionController::class, 'store'])
                ->middleware('throttle:redeem');

            Route::post('payments', [PaymentController::class, 'store']);
        });
    });
});
