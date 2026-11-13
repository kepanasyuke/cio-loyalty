<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\BrandContextNotSetException;
use App\Models\Brand;

/**
 * Текущий бренд HTTP-запроса (мультитенантный контекст).
 *
 * Жизненный цикл:
 * - Регистрируется как scoped в AppServiceProvider::register()
 *   ($this->app->scoped(BrandContext::class)).
 * - Middleware ResolveBrand читает заголовок X-Brand, находит Brand
 *   по slug (getRouteKeyName), вызывает set(). Middleware стоит в
 *   начале цепочки для всех API-маршрутов, где нужен бренд.
 * - Контроллеры/сервисы читают get()/id()/has()/idOrNull().
 * - После запроса экземпляр уничтожается (scoped), следующий запрос
 *   получает свежий экземпляр. В Octane/Swoole — тоже (scoped
 *   сбрасывается между запросами, нет утечки между тенантами).
 *
 * Использование:
 * - Только в API-контроллерах (Api\V1\*) и сервисах, которые
 *   вызываются из HTTP-запроса. Не в админке (там $user->brand_id
 *   из User-модели, User привязан к бренду через FK).
 * - НЕ использовать в queued job'ах (RecalculateTier,
 *   SendPushNotification): нет HTTP-запроса, set() не вызывался,
 *   get() бросит BrandContextNotSetException. Job'ы передают
 *   customerId/id параметром и выводят бренд из модели.
 *
 * Безопасность:
 * - get() бросает BrandContextNotSetException (LogicException),
 *   не DomainException. Отсутствие middleware — программная ошибка
 *   (route зарегистрирован без ResolveBrand), а не бизнес-нарушение.
 *   Laravel логирует трейс → DevOps видит сообщение и route.
 *   Клиент получает generic 500 без утечки внутреннего сообщения.
 *
 * set():
 * - Идемпотентен: повторный set() перезаписывает (last set wins).
 *   Если middleware вызван дважды (вложенные группы) — побеждает
 *   последний вызов.
 * - Ожидает resolved Brand (exists = true), как из
 *   Brand::firstOrFail(). Фантомный new Brand([...]) без save() —
 *   getKey() вернёт null, id() вернёт 0. Контракт: middleware
 *   передаёт реальную модель.
 *
 * has() vs idOrNull():
 * - has() — когда нужна явная проверка «установлен ли контекст»
 *   (например, в middleware/сервисах, где логика зависит от
 *   наличия бренда).
 * - idOrNull() — когда нужен только id и null приемлем (например,
 *   фильтрация по бренду опциональна). Заменяет
 *   has() ? id() : null в контроллерах.
 */
final class BrandContext
{
    private ?Brand $brand = null;

    /**
     * Устанавливает бренд. Вызывается из ResolveBrand middleware.
     * Повторный вызов перезаписывает (last set wins).
     */
    public function set(Brand $brand): void
    {
        $this->brand = $brand;
    }

    /**
     * Установлен ли бренд. Используется для conditional-логики
     * (например: if (! $context->has()) { fallback }).
     */
    public function has(): bool
    {
        return $this->brand !== null;
    }

    /**
     * Возвращает бренд. Бросает BrandContextNotSetException
     * (LogicException), если middleware ResolveBrand не отработал
     * (программная ошибка, не бизнес-нарушение).
     *
     * @throws BrandContextNotSetException
     */
    public function get(): Brand
    {
        return $this->brand ?? throw new BrandContextNotSetException();
    }

    /**
     * ID бренда. Эквивалент get()->getKey(), но с явным int cast —
     * Model::getKey() возвращает mixed (для Brand это int, но
     * контракт :int должен быть явным для PHPStan и возможного
     * перехода на UUID в будущем).
     *
     * @throws BrandContextNotSetException
     */
    public function id(): int
    {
        return (int) $this->get()->getKey();
    }

    /**
     * ID бренда или null, если контекст не установлен. Заменяет
     * has() ? id() : null в контроллерах, где фильтрация по бренду
     * опциональна.
     *
     * Безопаснее, чем get()->id() с try/catch — не бросает,
     * возвращает null для «не установлено».
     */
    public function idOrNull(): ?int
    {
        return $this->brand !== null ? (int) $this->brand->getKey() : null;
    }
}
