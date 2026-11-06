<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IssueTokenRequest;
use App\Http\Requests\Api\V1\RequestOtpRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Services\Auth\OtpService;
use App\Support\BrandContext;
use Illuminate\Http\JsonResponse;

final class AuthController extends Controller
{
    public function requestOtp(RequestOtpRequest $request, OtpService $otp): JsonResponse
    {
        $phone = (string) $request->string('phone');
        $debugCode = $otp->request($phone);

        $response = [
            'message' => 'Код подтверждения отправлен.',
            'ttl' => (int) config('loyalty.otp.ttl_seconds', 300),
        ];

        if ($debugCode !== null) {
            $response['debug_code'] = $debugCode;
        }

        return response()->json($response, 202);
    }

    public function issueToken(IssueTokenRequest $request, OtpService $otp, BrandContext $brand): JsonResponse
    {
        $phone = (string) $request->string('phone');

        if (! $otp->verify($phone, (string) $request->string('code'))) {
            throw new DomainException('Неверный или истёкший код подтверждения.', 401);
        }

        $customer = Customer::query()->firstOrCreate(
            ['brand_id' => $brand->id(), 'phone' => $phone],
            ['is_blocked' => false],
        );

        if ($customer->is_blocked) {
            throw new DomainException('Аккаунт заблокирован.', 403);
        }

        $customer->refresh();

        $token = $customer->createToken('mobile', ['loyalty'])->plainTextToken;

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'customer' => new CustomerResource($customer->load('account')),
        ], 201);
    }
}
