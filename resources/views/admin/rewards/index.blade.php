@extends('admin.layout')

@section('title', 'Награды — CIO Loyalty')

@section('content')
    <div class="row" style="justify-content: space-between;">
        <h1 style="margin-bottom: 0;">Награды</h1>
        <a class="btn btn-primary" href="{{ route('admin.rewards.create') }}">Добавить награду</a>
    </div>

    <div style="height: 16px;"></div>

    @if ($rewards->isEmpty())
        <div class="card muted">Каталог пуст. Добавьте первую награду.</div>
    @else
        <table>
            <thead>
            <tr>
                <th>Название</th>
                <th>Бренд</th>
                <th>Стоимость</th>
                <th>Остаток</th>
                <th>Активна</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @foreach ($rewards as $reward)
                <tr>
                    <td>
                        <strong>{{ $reward->title }}</strong>
                        @if ($reward->description)
                            <div class="muted">{{ \Illuminate\Support\Str::limit($reward->description, 90) }}</div>
                        @endif
                    </td>
                    <td>{{ $reward->brand?->name ?? '—' }}</td>
                    <td>{{ number_format($reward->cost_points, 0, ',', ' ') }} баллов</td>
                    <td>{{ $reward->stock === null ? 'не ограничен' : $reward->stock }}</td>
                    <td>{{ $reward->is_active ? 'да' : 'нет' }}</td>
                    <td>
                        <a class="btn" href="{{ route('admin.rewards.edit', $reward) }}">Изменить</a>

                        <form method="POST" action="{{ route('admin.rewards.destroy', $reward) }}" style="display: inline;" onsubmit="return confirm('Удалить награду?');">
                            @csrf
                            @method('DELETE')
                            <button class="btn btn-danger" type="submit">Удалить</button>
                        </form>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>

        <div class="pager">{{ $rewards->links() }}</div>
    @endif
@endsection
