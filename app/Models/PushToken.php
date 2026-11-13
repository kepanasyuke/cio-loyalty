<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PushTokenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FCM/APNs токен устройства для push-уведомлений.
 *
 * Один Customer может иметь несколько PushToken (несколько устройств).
 * Один физический device имеет РОВНО ОДИН токен в этой таблице —
 * UNIQUE INDEX на token обязателен. Регистрация токена должна
 * использовать updateOrCreate(['token' => $t], ['customer_id' => $id, ...]):
 * при смене пользователя на устройстве токен перепривязывается к
 * новому customer, а не создаёт дубликат. Иначе SendPushNotification
 * будет слать push старому клиенту на устройство нового — утечка.
 *
 * Жизненный цикл:
 * 1. Приложение регистрирует токен после логина (см. PushTokenController).
 * 2. SendPushNotification итерирует pushTokens, шлёт push, обновляет
 *    last_used_at успешных.
 * 3. При ошибке FCM Unregistered / BadDeviceToken токен должен быть
 *    помечен revoked_at (см. рекомендации — сейчас поля нет).
 * 4. Cleanup по last_used_at — хрупкий; активный пользователь без
 *    push несколько месяцев потеряет рабочий токен. Правильнее —
 *    revoked_at по факту Unregistered.
 *
 * Безопасность:
 * - token — чувствительный идентификатор (device ID для GDPR, ключ
 *   для отправки push). \$hidden защищает от утечки в JSON/toArray/
 *   логи. Не логировать token явно — только token_id (PK).
 * - Не выставлять token в API-ресурсах без явной необходимости.
 *
 * Рекомендации (вне этой модели):
 * - Добавить revoked_at TIMESTAMP NULL + \$casts + revoke() +
 *   scopeActive(); Customer::pushTokens() фильтровать whereNull.
 * - Добавить CHECK constraint на platform ('ios', 'android') или
 *   enum PushPlatform.
 */
class PushToken extends Model
{
    /** @use HasFactory<PushTokenFactory> */
    use HasFactory;

    /**
     * token в \$fillable legitimно (регистрация через create/updateOrCreate).
     * Защита от злоупотребления — на уровне FormRequest (валидация формата
     * FCM/APNs) и \$hidden (не отдавать токен в JSON/логи).
     *
     * @var list<string>
     */
    protected $fillable = [
        'customer_id',
        'token',
        'platform',
        'last_used_at',
    ];

    /**
     * token не должен попадать в toArray()/toJson()/логи автоматически.
     * Прямой доступ \$model->token работает — например, в
     * PushGateway::send(\$model->token, ...). Если API должен отдать
     * token клиенту (отладка, свой push-клиент) — делать это явно
     * через Resource с комментарием почему.
     *
     * @var list<string>
     */
    protected $hidden = [
        'token',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PushToken $token): void {
            // Регистрация = токен активен прямо сейчас. Без этого
            // cleanup по last_used_at (где last_used_at IS NULL
            // трактуется как «никогда не использовался») удалит
            // свежие токены сразу после регистрации.
            $token->last_used_at ??= now();
        });
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
