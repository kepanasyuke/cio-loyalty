<?php

declare(strict_types=1);

namespace App\Enums;

enum RedemptionStatus: string
// TODO(i18n): label() возвращает русский текст из enum и зашит в код.
// При появлении мультиязычности — убрать это поле из API и оставить
// только code: клиент сам локализует по значению 'created'/'fulfilled'/'canceled'.
// Для обратной совместимости можно временно оставить label, но помеченный
// как deprecated в openapi.yaml.
{
    case Created = 'created';
    case Fulfilled = 'fulfilled';
    case Canceled = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Создано',
            self::Fulfilled => 'Выдано',
            self::Canceled => 'Отменено',
        };
    }

    public function isFinal(): bool
    {
        return $this !== self::Created;
    }
}
