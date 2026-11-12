<?php

declare(strict_types=1);

namespace App\Http\Controllers;

/**
 * Базовый контроллер приложения.
 *
 * Намеренно пустой: в Laravel 11+ traits вроде AuthorizesRequests
 * и ValidatesRequests не подключаются по умолчанию. Проект использует
 * route-level middleware (EnsureUserHasRole) и FormRequest-классы,
 * поэтому ни authorize(), ни $this->validate() в базовом классе
 * не нужны.
 *
 * Если конкретному контроллеру понадобится $this->authorize() —
 * подключать AuthorizesRequests точечно в нём, а не тянуть trait
 * через весь базовый класс.
 */
abstract class Controller
{
}
