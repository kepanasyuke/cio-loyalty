<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\PointTransactionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdjustPointsRequest;
use App\Models\Customer;
use App\Services\Loyalty\PointLedger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class CustomerController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));

        $customers = Customer::query()
            ->with('account')
            ->when($request->user()?->brand_id, fn (Builder $query, int $brandId) => $query->where('brand_id', $brandId))
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('phone', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%");
                });
            })
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.customers.index', [
            'customers' => $customers,
            'search' => $search,
        ]);
    }

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

    public function adjustPoints(
        AdjustPointsRequest $request,
        Customer $customer,
        PointLedger $ledger,
    ): RedirectResponse {
        $this->ensureAccessible($request, $customer);

        $account = $customer->account()->firstOrFail();
        $points = (int) $request->input('points');
        $reason = trim((string) $request->string('reason'));
        $admin = (string) $request->user()?->name;

        if ($points > 0) {
            $ledger->credit(
                account: $account,
                points: $points,
                reason: "{$reason} (оператор: {$admin})",
                type: PointTransactionType::Adjustment,
            );
        } else {
            $ledger->debit(
                account: $account,
                points: abs($points),
                reason: "{$reason} (оператор: {$admin})",
                type: PointTransactionType::Adjustment,
            );
        }

        return redirect()
            ->route('admin.customers.show', $customer)
            ->with('status', 'Баллы скорректированы.');
    }

    private function ensureAccessible(Request $request, Customer $customer): void
    {
        $brandId = $request->user()?->brand_id;

        abort_if($brandId !== null && (int) $customer->brand_id !== (int) $brandId, 403);
    }
}
