<?php

declare(strict_types=1);

namespace App\Exceptions;

use LogicException;

/**
 * BrandContext не установлен — middleware ResolveBrand не отработал
 * для маршрута, где он обязателен.
 *
 * Наследуется от LogicException (SPL), не DomainException:
 * - LogicException = программная ошибка (route без middleware 'brand').
 * - DomainException = бизнес-нарушение (клиент запросил то, что нельзя).
 *
 * Почему не DomainException (как было):
 * - DomainException::render() возвращает JSON клиенту с внутренним
 *   сообщением ('Контекст бренда не установлен') — утечка + клиент
 *   не может исправить отсутствие middleware.
 * - render() обходит стандартный Handler → трейс НЕ логируется.
 *   DevOps не видит, какой маршрут без middleware.
 * - 500 (наша вина) с форматом 4xx (бизнес-ошибка) — семантика
 *   смешана.
 *
 * Поведение после fix:
 * - Laravel логирует трейс с сообщением и подсказкой (route,
 *   middleware group 'brand').
 * - Клиент получает generic 500 без утечки внутренних сообщений.
 * - IDE/PHPStan понимают: LogicException — не для catch
 *   (это баг, а не сценарий).
 *
 * Не маскируется кастомным render() — стандартный Handler
 * обрабатывает как серверную ошибку.
 */
final class BrandContextNotSetException extends LogicException
{
    public function __construct()
    {
        parent::__construct(
            'BrandContext is not set. '
            .'Did ResolveBrand middleware run for this route? '
            .'Check that the route is inside middleware group \'brand\'.',
        );
    }
}
