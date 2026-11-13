<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use Illuminate\Support\Facades\Log;

/**
 * Заглушка PushGateway для local/testing: пишет push в лог вместо
 * отправки на FCM/APNs.
 *
 * Назначение:
 * - E2E-тесты: проверить, что push был «отправлен» (есть в логе).
 * - Локальная разработка: посмотреть, какой текст ушёл бы клиенту.
 *
 * Безопасность:
 * - token хешируется (sha256) — FCM/APNs device token не попадает
 *   в laravel.log в открытом виде. GDPR: device ID = PII. Security:
 *   token + server key = возможность отправить push на устройство.
 * - data не санитизируется в этом классе — ответственность
 *   SendPushNotification (контракт: только скаляры, без PII). См.
 *   PHPDoc @param.
 * - НЕ регистрировать в проде как PushGateway::class. Только local/
 *   testing через match(config('services.push.driver')) в
 *   AppServiceProvider::makePushGateway() (fail-fast в проде без
 *   явного 'log').
 *
 * Логирование:
 * - Log::info() без явного канала — используется config('logging.default').
 *   В local обычно 'stack' → 'single', в проде — 'stderr'/'daily'/
 *   Sentry. Явный Log::channel('single') игнорировал бы конфиг
 *   (прод: push в один файл, минуя stderr; single не ротируется).
 * - Если нужен отдельный канал для push-логов — добавить
 *   config('logging.channels.push') (daily, ротация) и использовать
 *   Log::channel('push')->info(). Не single.
 *
 * Sanitize data (рекомендация для SendPushNotification):
 * - Контракт @param array<string, int|float|bool|string>.
 * - Не передавать email/phone/name клиента — только идентификаторы
 *   (redemption_id, payment_id).
 * - Если нужен дебаг с PII — Log::debug с отдельным каналом и
 *   короткой ротацией (не в prod).
 */
final class LogPushGateway implements PushGateway
{
    /**
     * @param  string  $token  FCM/APNs device token (хешируется в логе)
     * @param  string  $title  Заголовок push
     * @param  string  $body  Текст push
     * @param  array<string, int|float|bool|string>  $data  Доп. данные
     *                                                      (только скаляры, без PII)
     */
    public function send(string $token, string $title, string $body, array $data = []): void
    {
        Log::info('push.sent', $this->context($token, $title, $body, $data));
    }

    /**
     * @param  array<string, int|float|bool|string>  $data
     * @return array<string, mixed>
     */
    private function context(string $token, string $title, string $body, array $data): array
    {
        return [
            'token_hash' => $this->hashToken($token),
            'title' => $title,
            'body' => $body,
            'data' => $data,
        ];
    }

    /**
     * sha256 без соли — достаточно для логов: rainbow table по
     * device token невозможна (токен — 152+ символа base64url,
     * энтропия ~150+ bits). HMAC не нужен: цель — не скрыть
     * значение от читателя лога (он и так видит title/body), а
     * исключить токен из grep-выборок и SIEM-индексов.
     */
    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
