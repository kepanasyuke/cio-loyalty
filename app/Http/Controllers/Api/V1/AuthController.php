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

/**
 * Аутентификация клиентов мобильного приложения.
 *
 * Поток:
 * 1. requestOtp — клиент отправляет телефон, получает SMS с кодом.
 *    Ответ 202 Accepted (OTP-доставка асинхронна).
 * 2. issueToken — клиент отправляет телефон + код, получает Bearer-токен
 *    Sanctum и профиль клиента. Ответ 201 Created.
 *
 * Домен ограничен брендом: клиент создаётся (или находится) в рамках
 * бренда, определённого middleware ResolveBrand (X-Brand header).
 *
 * Безопасность:
 * - Rate limiting (throttle) должен быть на маршрутах — см. routes/api.php.
 * - debug_code отдаётся только в local/testing (belt-and-suspenders
 *   поверх OtpService::request(), который возвращает null в проде).
 * - Проверка is_blocked при выдаче токена — best-effort. Гарантированная
 *   защита заблокированных клиентов — middleware на защищённых маршрутах,
 *   а не этот контроллер (блокировка может произойти после выдачи токена).
 */
final class AuthController extends Controller
{
    /**
     * Отправляет OTP на телефон клиента.
     *
     * @return JsonResponse 202 Accepted: {message, ttl, debug_code?}
     */
    public function requestOtp(RequestOtpRequest $request, OtpService $otp): JsonResponse
    {
        $phone = (string) $request->validated('phone');
        $debugCode = $otp->request($phone);

        return response()->json($this->otpResponse($debugCode), 202);
    }

    /**
     * Проверяет OTP и выдаёт Bearer-токен Sanctum.
     *
     * @return JsonResponse 201 Created: {token, token_type, customer}
     *
     * @throws DomainException 401 если код неверный или истёк, 403 если аккаунт заблокирован
     */
    public function issueToken(
        IssueTokenRequest $request,
        OtpService $otp,
        BrandContext $brand,
    ): JsonResponse {
        $phone = (string) $request->validated('phone');
        $code = (string) $request->validated('code');

        if (! $otp->verify($phone, $code)) {
            throw new DomainException('Неверный или истёкший код подтверждения.', 401);
        }

        $customer = Customer::query()->firstOrCreate(
            ['brand_id' => $brand->id(), 'phone' => $phone],
            ['is_blocked' => false],
        );

        if ($customer->is_blocked) {
            throw new DomainException('Аккаунт заблокирован.', 403);
        }

        $token = $customer->createToken('mobile', ['loyalty'])->plainTextToken;

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'customer' => new CustomerResource($customer->load('account')),
        ], 201);
    }

    /**
     * @return array{message: string, ttl: int, debug_code?: string}
     */
    private function otpResponse(?string $debugCode): array
    {
        $response = [
            'message' => 'Код подтверждения отправлен.',
            'ttl' => (int) config('loyalty.otp.ttl_seconds', 300),
        ];

        if ($this->shouldExposeDebugCode($debugCode)) {
            $response['debug_code'] = $debugCode;
        }

        return $response;
    }

    /**
     * Возвращает debug_code только если OtpService его вернул (null в проде)
     * И окружение — local/testing. Двойная защита от случайной утечки OTP.
     */
    private function shouldExposeDebugCode(?string $debugCode): bool
    {
        return $debugCode !== null
            && $debugCode !== ''
            && app()->environment(['local', 'testing']);
    }
}
