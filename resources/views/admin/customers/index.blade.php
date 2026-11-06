@extends('admin.layout')

@section('title', 'Клиенты — CIO Loyalty')

@section('content')
    <h1>Клиенты</h1>

    <form method="GET" action="{{ route('admin.customers.index') }}" class="row" style="margin-bottom: 16px;">
        <div class="field" style="margin: 0;">
            <label for="q">Поиск по телефону или имени</label>
            <input id="q" name="q" value="{{ $search }}">
        </div>
        <button class="btn" type="submit">Найти</button>
    </form>

    @if ($customers->isEmpty())
        <div class="card muted">Клиенты не найдены.</div>
    @else
        <table>
            <thead>
            <tr>
                <th>Клиент</th>
                <th>Телефон</th>
                <th>Баланс</th>
                <th>Уровень</th>
                <th>Блокировка</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @foreach ($customers as $customer)
                <tr>
                    <td>{{ $customer->name ?? '—' }}</td>
                    <td>{{ $customer->phone }}</td>
                    <td>{{ number_format((int) $customer->account?->balance, 0, ',', ' ') }}</td>
                    <td><span class="badge">{{ $customer->account?->tier?->label() ?? '—' }}</span></td>
                    <td>{{ $customer->is_blocked ? 'да' : 'нет' }}</td>
                    <td><a class="btn" href="{{ route('admin.customers.show', $customer) }}">Открыть</a></td>
                </tr>
            @endforeach
            </tbody>
        </table>

        <div class="pager">{{ $customers->links() }}</div>
    @endif
@endsection
