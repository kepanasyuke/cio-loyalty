<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\Brand;
use App\Models\Reward;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Кешируемый каталог активных наград бренда.
 *
 * Кеш: brand:{id}:rewards:active, TTL по config('loyalty.catalog_cache_ttl', 120).
 * Значение — сериализованный Eloquent Collection<Reward>. При изменении
 * схемы Reward (новые casts, новые поля) — старые кеш-записи могут
 * содержать модели со старой схемой (TTL 120с митигирует; на hot-reload
 * делать Cache::flush).
 *
 * Инвалидация (forget):
 * ОБЯЗАТЕЛЬНА после ЛЮБОГО изменения наград бренда:
 * - RewardController::store/update/destroy — admin CRUD (уже вызывает).
 * - RedemptionService::redeem() после decrementStock — ТРЕБУЕТСЯ
 *   (проверить вне этого файла). Если forget не вызывается, клиенты до
 *   TTL видят устаревший stock (кейс: последняя единица продана, клиент
 *   видит «в наличии», при попытке обмена получает 409 OutOfStock).
 * - Массовые изменения (bulk deactivate, миграции) — forgetAll() или
 *   Cache::flush.
 *
 * Инвалидация — ПОСЛЕ commit транзакции, не внутри: если транзакция
 * откатится, кеш сбросится зря (следующий запрос сделает лишний SELECT).
 *
 * Что кешируется:
 * - available() scope: is_active=true AND (stock IS NULL OR stock > 0).
 * - Soft-deleted Reward не попадают (SoftDeletes добавляет WHERE
 *   deleted_at IS NULL автоматически).
 * - Сортировка по cost_points.
 *
 * Что НЕ кешируется:
 * - Награды других брендов (ключ per-brand).
 * - Неактивные награды (is_active=false).
 * - Распроданные (stock=0).
 */
final class RewardCatalog
{
    /**
     * @return Collection<int, Reward>
     */
    public function active(Brand $brand): Collection
    {
        return Cache::remember(
            key: $this->key($brand),
            ttl: $this->ttl(),
            callback: fn (): Collection => $this->fetchActive($brand),
        );
    }

    public function forget(Brand $brand): void
    {
        Cache::forget($this->key($brand));
    }

    /**
     * @return Collection<int, Reward>
     */
    private function fetchActive(Brand $brand): Collection
    {
        return Reward::query()
            ->where('brand_id', $brand->getKey())
            ->available()
            ->orderBy('cost_points')
            ->get();
    }

    private function ttl(): int
    {
        return (int) config('loyalty.catalog_cache_ttl', 120);
    }

    private function key(Brand $brand): string
    {
        return "brand:{$brand->getKey()}:rewards:active";
    }
}
