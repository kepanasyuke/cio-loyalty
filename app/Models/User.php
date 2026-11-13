<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Оператор админ-панели (НЕ клиент — клиент это Customer).
 *
 * Модель доступа — двухмерная:
 * 1. Роль (role):
 *    - Admin    — администратор;
 *    - Manager  — менеджер.
 * 2. Бренд (brand_id):
 *    - null  — суперадмин (видит все бренды);
 *    - N     — оператор бренда N (видит только бренд N).
 *
 * Комбинации:
 *   Admin + brand_id=null   → суперадмин (все бренды) → isSuperAdmin()
 *   Admin + brand_id=N      → админ бренда N          → isAdmin() && !isSuperAdmin()
 *   Manager + brand_id=N    → менеджер бренда N       → !isAdmin()
 *
 * Предикаты:
 * - isAdmin(): role === Admin (не различает суперадмина и админа бренда).
 * - isSuperAdmin(): role === Admin && brand_id === null.
 * Не использовать isAdmin() для проверки «все бренды» — для этого
 * isSuperAdmin(). В CustomerController/DashboardController/RewardController
 * проверка «все бренды» делается через brand_id === null.
 *
 * Аутентификация:
 * Сессионная (Auth::attempt в AdminAuthController). HasApiTokens НЕ
 * подключён: оператор не ходит в API (API — для клиентов, Sanctum).
 *
 * $fillable:
 * - role, brand_id НЕ в $fillable. Это mass-assignment risk:
 *   $user->update($request->validated()) с 'role' = 'admin' повышает
 *   Manager до Admin (privilege escalation); с 'brand_id' = 2 —
 *   горизонтальная эскалация (Manager бренда 1 видит бренд 2).
 * - Установка при создании: forceFill(['role' => ..., 'brand_id' => ...])
 *   после User::create([...]) в UserController::store() (или сидере).
 *
 * password:
 * - cast 'hashed' автоматически хэширует при любом присваивании:
 *   $user->password = 'plain' → bcrypt hash. Hash::make() вручную не нужен.
 * - $hidden защищает от утечки в JSON.
 *
 * SoftDeletes:
 * - Не подключён. Удаление оператора = hard delete (строка исчезает).
 * - Аудит: PointTransaction.reason содержит имя оператора текстом
 *   (см. CustomerController::adjustPoints), не FK на users.id — так что
 *   удаление не ломает audit trail. Но «кто был оператором N» восстановить
 *   невозможно.
 * - Рекомендация: добавить SoftDeletes для админских аккаунтов
 *   (стандартная практика для аудита). Требует миграции + проверки
 *   всех User::query() (soft-deleted не найдётся через find()).
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use Notifiable;

    /**
     * role и brand_id НЕ в $fillable — mass-assignment risk (privilege
     * escalation, горизонтальная эскалация). Установка через forceFill
     * в UserController::store() или сидере.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeForBrand(Builder $query, int $brandId): Builder
    {
        return $query->where('brand_id', $brandId);
    }

    /**
     * Роль Admin (админ бренда ИЛИ суперадмин).
     *
     * ВНИМАНИЕ: не для проверки «суперадмин / все бренды» — для этого
     * isSuperAdmin(). Admin с brand_id != null видит только свой бренд
     * (см. CustomerController::ensureAccessible, DashboardController).
     */
    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /**
     * Суперадмин: Admin + brand_id = null. Видит ВСЕ бренды.
     *
     * Используется в CustomerController, DashboardController,
     * RewardController — там, где фильтр по бренду пропускается для
     * суперадмина. Заменяет ручную проверку $user->brand_id === null
     * на самодокументируемый метод.
     */
    public function isSuperAdmin(): bool
    {
        return $this->isAdmin() && $this->brand_id === null;
    }
}
