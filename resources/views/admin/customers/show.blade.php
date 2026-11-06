@extends('admin.layout')

@section('title', 'Клиент ' . $customer->phone . ' — CIO Loyalty')

@section('content')
    <h1>{{ $customer->name ?? 'Клиент' }} <span class="muted">{{ $customer->phone }}</span></h1>

    <div class="grid">
        <div class="stat">
            <span>Баланс</span>
            <strong>{{ number_format((int) $account->balance, 0, ',', ' ') }}</strong>
        </div>
        <div class="stat">
            <span>Всего начислено</span>
            <strong>{{ number_format((int) $account->lifetime_points, 0, ',', ' ') }}</strong>
        </div>
        <div class="stat">
            <span>Уровень</span>
            <strong>{{ $account->tier->label() }}</strong>
        </div>
    </div>

    <h2>Ручная корректировка баллов</h2>
    <form class="card" method="POST" action="{{ route('admin.customers.points', $customer) }}">
        @csrf
        <div class="row">
            <div class="field">
                <label for="points">Баллы (минус — списать)</label>
                <input id="points" type="number" name="points" required value="{{ old('points') }}" placeholder="например 500 или -200">
            </div>
            <div class="field">
                <label for="reason">Причина</label>
                <input id="reason" name="reason" required maxlength="255" value="{{ old('reason') }}" placeholder="компенсация, бонус за отзыв...">
            </div>
        </div>
        <button class="btn btn-primary" type="submit">Применить</button>
    </form>

    <h2>История операций</h2>
    @if ($transactions->isEmpty())
        <div class="card muted">Операций пока нет.</div>
    @else
        <table>
            <thead>
            <tr>
                <th>Дата</th>
                <th>Тип</th>
                <th>Баллы</th>
                <th>Баланс после</th>
                <th>Причина</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($transactions as $transaction)
                <tr>
                    <td>{{ $transaction->created_at?->format('d.m.Y H:i') }}</td>
                    <td>{{ $transaction->type->label() }}</td>
                    <td>{{ $transaction->points > 0 ? '+' : '' }}{{ $transaction->points }}</td>
                    <td>{{ $transaction->balance_after }}</td>
                    <td>{{ $transaction->reason }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    <h2>Обмены наград</h2>
    @if ($redemptions->isEmpty())
        <div class="card muted">Обменов пока нет.</div>
    @else
        <table>
            <thead>
            <tr>
                <th>Дата</th>
                <th>Награда</th>
                <th>Баллы</th>
                <th>Код</th>
                <th>Статус</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($redemptions as $redemption)
                <tr>
                    <td>{{ $redemption->created_at?->format('d.m.Y H:i') }}</td>
                    <td>{{ $redemption->reward?->title ?? '—' }}</td>
                    <td>{{ $redemption->points_spent }}</td>
                    <td>{{ $redemption->code }}</td>
                    <td><span class="badge">{{ $redemption->status->label() }}</span></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
@endsection
