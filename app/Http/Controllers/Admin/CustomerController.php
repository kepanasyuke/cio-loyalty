<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\PointTransactionType;
use App\Exceptions\InsufficientPointsException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdjustPointsRequest;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\User;
use App\Services\Loyalty\PointLedger;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Управление клиентами в админ-панели: список, карточка, ручная
 * корректировка баллов.
 *
 * Все действия ограничены брендом текущего оператора (см. ensureAccessible).
 * Оператор без brand_id (суперадмин) видит клиентов всех брендов.
 *
 * @see \App\Enums\UserRole Роли Admin/Manager
 */
final class CustomerController extends Controller
{
    /**
     * Список клиентов с поиском по телефону или имени.
     */
    public function index(Request $request): View
    {
        $search = $request->string('q')->trim()->toString();
        $brandId = $this->currentUser($request)->brand_id;

        $customers = Customer::query()
            ->with('account')
            ->when($brandId !== null, fn (Builder $query) => $query->where('brand_id', (int) $brandId))
            ->when($search !== '', $this->searchFilter($search))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.customers.index', [
            'customers' => $customers,
            'search' => $search,
        ]);
    }

    /**
     * Карточка клиента: баланс, последние транзакции, обмены.
     */
    public function show(Request $request, Customer $customer): View
    {
        $this->ensureAccessible($request, $customer);

        $account = $customer->account()->firstOrFail();

        return view('admin.customers.show', [
            'customer' => $customer->setRelation('account', $account),
            'account' => $account,
            'transactions' => $account->transactions()->latest('id')->limit(20)->get(),
            'redemptions' => $customer->redemptions()->with('reward')->latest('id')->limit(10)->get(),
        ]);
    }

    /**
     * Ручная корректировка баллов (начисление или списание).
     *
     * Требует idempotency_key из формы, чтобы повторная отправка
     * (двойной клик, retry, двойной submit) не задвоила корректировку.
     * Ключ должен генерироваться в show() через Str::uuid() и приходить
     * из формы как hidden-поле.
     *
     * @throws ValidationException Если idempotency_key отсутствует в форме.
     * @throws InsufficientPointsException Если списание превышает баланс.
     */
    public function adjustPoints(
        AdjustPointsRequest $request,
        Customer $customer,
        PointLedger $ledger,
    ): RedirectResponse {
        $this->ensureAccessible($request, $customer);

        $account = $customer->account()->firstOrFail();
        $validated = $request->validated();

        $this->applyAdjustment(
            ledger: $ledger,
            account: $account,
            points: (int) $validated['points'],
            reason: trim((string) $validated['reason']),
            operator: $this->currentUser($request)->name,
            idempotencyKey: $this->idempotencyKey($request),
        );

        return redirect()
            ->route('admin.customers.show', $customer)
            ->with('status', 'Баллы скорректированы.');
    }

    /**
     * Применяет корректировку к счёту: положительное число — credit,
     * отрицательное — debit. Ноль — no-op (защита от кривого ввода).
     */
    private function applyAdjustment(
        PointLedger $ledger,
        LoyaltyAccount $account,
        int $points,
        string $reason,
        string $operator,
        string $idempotencyKey,
    ): void {
        if ($points === 0) {
            return;
        }

        $description = "{$reason} (оператор: {$operator})";
        $amount = abs($points);

        if ($points > 0) {
            $ledger->credit(
                account: $account,
                points: $amount,
                reason: $description,
                idempotencyKey: $idempotencyKey,
                type: PointTransactionType::Adjustment,
            );

            return;
        }

        $ledger->debit(
            account: $account,
            points: $amount,
            reason: $description,
            idempotencyKey: $idempotencyKey,
            type: PointTransactionType::Adjustment,
        );
    }

    /**
     * Фильтр «поиск по телефону или имени» — вынесен из index()
     * для читаемости и тестируемости.
     */
    private function searchFilter(string $search): Closure
    {
        return function (Builder $query) use ($search): void {
            $query->where(function (Builder $inner) use ($search): void {
                $inner->where('phone', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        };
    }

    /**
     * Ключ идемпотентности из формы. Пустой ключ — fail-fast:
     * лучше показать «обновите страницу», чем тихо задвоить баллы.
     *
     * @throws ValidationException
     */
    private function idempotencyKey(Request $request): string
    {
        $key = $request->string('idempotency_key')->trim()->toString();

        if ($key === '') {
            throw ValidationException::withMessages([
                'idempotency_key' => 'Форма устарела. Обновите страницу и попробуйте снова.',
            ]);
        }

        return $key;
    }

    /**
     * Проверка, что оператор имеет доступ к клиенту своего бренда.
     * Суперадмин (brand_id = null) имеет доступ ко всем.
     */
    private function ensureAccessible(Request $request, Customer $customer): void
    {
        $user = $this->currentUser($request);

        if ($user->brand_id === null) {
            return;
        }

        if ((int) $customer->brand_id !== (int) $user->brand_id) {
            abort(403, 'Нет доступа к клиенту другого бренда.');
        }
    }

    /**
     * Сужает тип $request->user() до App\Models\User.
     * PHPStan level 8: Authenticatable не имеет brand_id/name,
     * abort() возвращает never — после if тип гарантированно User.
     */
    private function currentUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403, 'Требуется аутентификация оператора.');
        }

        return $user;
    }
}
