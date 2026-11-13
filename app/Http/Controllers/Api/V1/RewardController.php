<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\RewardResource;
use App\Models\Brand;
use App\Services\Catalog\RewardCatalog;
use App\Support\BrandContext;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Каталог активных наград бренда (витрина для мобильного приложения).
 *
 * Инвариант бренда:
 * - Маршрут должен быть в группе с middleware 'brand' (ResolveBrand).
 *   Middleware устанавливает бренд в BrandContext по заголовку X-Brand.
 *   Если middleware не отработал — programming error (маршрут зарегистрирован
 *   без группы), возвращается 500, а не 404: клиент не виноват.
 *
 * Кеш и пагинация:
 * - RewardCatalog::active() кеширует полный список наград бренда
 *   (ключ 'brand:{id}:rewards:active'). Инвалидация — при save/delete
 *   в админке (RewardController::store/update/destroy → $catalog->forget()).
 * - Пагинации нет намеренно: обычно 10–100 активных наград на бренд,
 *   возврат целиком дешевле повторных запросов. Если каталог вырастет
 *   до 500+ позиций — вводить пагинацию с кешем по страницам, но это
 *   breaking change для мобильного приложения (формат ответа изменится).
 *
 * Rate limiting и CSRF — на уровне маршрута, не в контроллере.
 */
final class RewardController extends Controller
{
    public function index(BrandContext $brand, RewardCatalog $catalog): AnonymousResourceCollection
    {
        return RewardResource::collection(
            $catalog->active($this->resolveBrand($brand)),
        );
    }

    /**
     * Достаёт бренд из BrandContext. Если middleware ResolveBrand не
     * отработал — 500 (programming error), а не 404 (клиент не виноват).
     *
     * no-op, если BrandContext::get() уже non-nullable Brand;
     * fail-closed, если ?Brand — защита от маршрута без middleware.
     */
    private function resolveBrand(BrandContext $brand): Brand
    {
        $resolved = $brand->get();

        if (! $resolved instanceof Brand) {
            abort(500, 'BrandContext не инициализирован — проверьте middleware ResolveBrand.');
        }

        return $resolved;
    }
}
