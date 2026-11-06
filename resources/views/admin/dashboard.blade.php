@extends('admin.layout')

@section('title', 'Дашборд — CIO Loyalty')

@section('content')
    <h1>Дашборд</h1>

    <div class="grid">
        <div class="stat">
            <span>Клиентов</span>
            <strong>{{ number_format($customersCount, 0, ',', ' ') }}</strong>
        </div>
        <div class="stat">
            <span>Активных наград</span>
            <strong>{{ number_format($activeRewardsCount, 0, ',', ' ') }}</strong>
        </div>
        <div class="stat">
            <span>Ожидают выдачи</span>
            <strong>{{ number_format($pendingRedemptions, 0, ',', ' ') }}</strong>
        </div>
        <div class="stat">
            <span>Начислено сегодня</span>
            <strong>{{ number_format($pointsIssuedToday, 0, ',', ' ') }}</strong>
        </div>
    </div>

    <h2>Последние обмены</h2>

    @if ($latestRedemptions->isEmpty())
        <div class="card muted">Пока нет обменов — как только клиенты начнут тратить баллы, они появятся здесь.</div>
    @else
        <table>
            <thead>
            <tr>
                <th>Клиент</th>
                <th>Награда</th>
                <th>Баллы</th>
                <th>Код</th>
                <th>Статус</th>
                <th>Дата</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($latestRedemptions as $redemption)
                <tr>
                    <td>{{ $redemption->customer?->name ?? $redemption->customer?->phone ?? '—' }}</td>
                    <td>{{ $redemption->reward?->title ?? '—' }}</td>
                    <td>{{ $redemption->points_spent }}</td>
                    <td>{{ $redemption->code }}</td>
                    <td><span class="badge">{{ $redemption->status->label() }}</span></td>
                    <td>{{ $redemption->created_at?->format('d.m.Y H:i') }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
@endsection
