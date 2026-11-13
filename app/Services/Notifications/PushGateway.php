<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Exceptions\Push\PermanentPushException;
use App\Exceptions\Push\TransientPushException;
use InvalidArgumentException;

/**
 * Контракт push-провайдера.
 *
 * Реализации:
 * - LogPushGateway — заглушка для local/testing, никогда не бросает.
 * - FcmPushGateway — FCM HTTP v1 API (реальная отправка).
 * - ApnsPushGateway — APNs HTTP/2 (реальная отправка).
 *
 * Контракт ошибок:
 * - TransientPushException — retry оправдан (5xx, 429, network timeout).
 *   SendPushNotification поймает, повторит попытку (backoff).
 * - PermanentPushException — retry бесполезен. Различается по
 *   shouldRevokeToken:
 *   * true — FCM Unregistered, APNs BadDeviceToken: токен мёртв,
 *     устройство удалило приложение или отозвало токен. Реализация
 *     (SendPushNotification) должна пометить $token->revoke()
 *     (когда PushToken получит revoked_at).
 *   * false — 401/403 (server key протух): config bug, retry бесполезен,
 *     но токен валиден. Алертить DevOps, не revoke.
 * - InvalidArgumentException — программная ошибка ($token = '').
 *   Fail-fast, не обрабатывать в SendPushNotification.
 *
 * Заглушка LogPushGateway контракт не нарушает: не бросает, значит
 * «успех». В local/testing реальные ошибки FCM/APNs не проверить —
 * для этого нужен FcmPushGateway + реальные credentials (integration
 * test, отдельный от unit).
 *
 * Безопасность $token:
 * - FCM/APNs device token, 152+ символа base64url.
 * - PII: привязан к физическому устройству (GDPR).
 * - Security: с token + server key можно отправить push на устройство.
 * - Не логировать в открытом виде (см. LogPushGateway: token_hash).
 * - Не сериализовать в очередь без $hidden (см. PushToken::$hidden).
 *
 * $data:
 * - Плоский map, keys snake_case, values scalar.
 * - FCM/APNs не поддерживают вложенные структуры в data (только
 *   top-level). Вложенное — сериализуйте в строку вызывающая сторона,
 *   если нужно.
 * - Не передавать PII (email, phone, name). Только идентификаторы
 *   (redemption_id, payment_id). См. contract в SendPushNotification.
 */
interface PushGateway
{
    /**
     * Отправляет push на одно устройство.
     *
     * @param  string  $token  Непустой FCM/APNs token. PII, не логировать.
     * @param  string  $title  Заголовок (до 100 символов для iOS lock screen).
     * @param  string  $body  Текст (до 200 символов для iOS preview).
     * @param  array<string, int|float|bool|string>  $data  Плоский map,
     *                                                       values scalar,
     *                                                       без PII.
     *
     * @throws InvalidArgumentException $token === ''
     * @throws TransientPushException retry оправдан (5xx, 429, timeout)
     * @throws PermanentPushException retry бесполезен; shouldRevokeToken
     *                                = true для Unregistered/BadDeviceToken
     */
    public function send(string $token, string $title, string $body, array $data = []): void;
}
